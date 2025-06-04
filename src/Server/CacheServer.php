<?php
declare(strict_types=1);

namespace sdkNarkos\SimpleCache\Server;

use sdkNarkos\SimpleCache\Manager\ClientManager;
use sdkNarkos\SimpleCache\Manager\StorageManager;
use sdkNarkos\SimpleCache\Protocol\CommandMessage;
use sdkNarkos\SimpleCache\Protocol\ResponseMessage;

class CacheServer {
    private readonly array $authKeys;
    private readonly string $protocol;
    private readonly string $host;
    private readonly int $port;
    private readonly int $usleep;
    private readonly bool $verbose;

    /** @var callable|null */
    private $loggerCallback = null;

    private $socket;
    private array $buffers = array();

    private ClientManager $clientManager;
    private StorageManager $storageManager;

    public function __construct(array $config) {
        if (!isset($config['authKeys']) || !is_array($config['authKeys'])) {
            throw new \Exception("You must provide an array of authKeys.");
        }
        $this->authKeys = array_map(fn($key) => hash('sha256', $key), $config['authKeys']);
        $this->protocol = $config['protocol'] ?? 'tcp';
        $this->host = $config['host'] ?? 'localhost';
        $this->port = $config['port'] ?? 9999;
        $this->usleep = $config['usleep'] ?? 1000;
        $this->verbose = $config['verbose'] ?? false;

        if (isset($config['logger']) && is_callable($config['logger'])) {
            $this->loggerCallback = $config['logger'];
        }

        $this->clientManager = new ClientManager();
        $this->storageManager = new StorageManager();

        if (php_sapi_name() === 'cli' && extension_loaded('pcntl')) {
            pcntl_signal(SIGINT, function() {
                $this->shutdown();
            });
        }
    }

    public function run(): void {
        $url = $this->protocol . "://" . $this->host . ":" . $this->port;
        $errno = null;
        $errstr = '';

        $this->socket = stream_socket_server($url, $errno, $errstr);

        if (!$this->socket) {
            $this->log('error', "Socket creation failed: $errstr ($errno)");
            throw new \RuntimeException("Unable to start server on $url");
        }

        if(!stream_set_blocking($this->socket, false)) throw new \Exception("Not able to set the stream non-blocking");

        $this->log("info", "Cache server started");
        $this->loop();
    }

    private function shutdown(): void {
        if ($this->socket) fclose($this->socket);

        $this->clientManager->shutdown();

        $this->log("info", "Cache server shut down.");
        exit;
    }

    private function log(string $level, string $message): void {
        if ($this->loggerCallback) {
            call_user_func($this->loggerCallback, $level, $message);
        } else {
            echo '[' . date('Y-m-d H:i:s') . '] ' . strtoupper($level) . ': ' . $message . PHP_EOL;
        }
    }

    private function loop(): void {
        while(true) {
            try {
                $this->storageManager->checkExpiries();
                
                $this->checkConnections();
                $this->readMessages();
            } catch(\Exception $e) {
                $this->log("error", $e->getMessage());
            }

            usleep($this->usleep);
        }
    }

    private function checkConnections(): void {
        while ($client = @stream_socket_accept($this->socket, 0)) {
            stream_set_blocking($client, false);
            $this->clientManager->addClient($client);
            $this->buffers[(int) $client] = '';
            if ($this->verbose) {
                $this->log("info", "Client connected: " . stream_socket_get_name($client, true));
            }
        }
    }

    private function removeClient($client): void {
        unset($this->buffers[(int)$client]);
        $this->clientManager->removeClient($client);
        if ($this->verbose) {
            $this->log("info", "Client disconnected");
        }
    }

    private function readMessages(): void {
        $clients = $this->clientManager->getClients();
        if(0 == count($clients)) return;

        $write = $except = NULL;
        if (false === stream_select($clients, $write, $except, 0)) {
            throw new \Exception("Error while trying stream_select");
        }

        foreach($clients as $client) {
            $clientId = (int)$client;

            if(feof($client)) {
                // Client disconnected
                $this->removeClient($client);
                return;
            }

            $rawMessage = '';
            while (($line = fgets($client, 8192)) !== false) {
                $rawMessage .= $line;
            }

            if (strlen($rawMessage) == 0) return;

            $this->buffers[$clientId] .= $rawMessage;

            while (strlen($this->buffers[$clientId]) >= 4) {
                $lengthData = substr($this->buffers[$clientId], 0, 4);
                $unpacked = unpack('Nlength', $lengthData);
                $messageLength = $unpacked['length'];

                if (strlen($this->buffers[$clientId]) < 4 + $messageLength) {
                    break;
                }

                $json = substr($this->buffers[$clientId], 4, $messageLength);
                $this->buffers[$clientId] = substr($this->buffers[$clientId], 4 + $messageLength);

                try {
                    $commandMessage = CommandMessage::fromJson($json);
                } catch(\InvalidArgumentException $e) {
                    $responseMessage = ResponseMessage::createError($e->getMessage());
                    $this->sendResponse($client, $responseMessage);
                    continue;
                }

                if(!$commandMessage->isAuthenticated($this->authKeys)) {
                    $this->sendResponse($client, ResponseMessage::createError("Authentication failed"));
                    continue;
                }

                try {
                    $responseMessage = $this->storageManager->processCommand($commandMessage);
                    $this->sendResponse($client, $responseMessage);
                } catch (\Exception $e) {
                    $this->sendResponse($client, ResponseMessage::createError("Server error: " . $e->getMessage()));
                }
            }
        }
    }

    private function sendResponse($client, ResponseMessage $responseMessage): void {
        $jsonData = $responseMessage->toJson();
		$length = strlen($jsonData);
		$lengthBin = pack('N', $length);

		if(false === @fwrite($client, $lengthBin . $jsonData)) {
			// Failed to write, client may have disconnected...
			$this->removeClient($client);
		}
    }

}
