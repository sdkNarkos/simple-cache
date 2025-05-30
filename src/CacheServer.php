<?php
declare(strict_types=1);

namespace sdkNarkos\SimpleCache;

class CacheServer {
    private readonly array $authKeys;
    private readonly string $protocol;
    private readonly string $host;
    private readonly int $port;
    private readonly int $usleep;
    private readonly bool $verbose;

    private $socket;
    private array $clients = array();
    private array $buffers = array();

    // value
    private array $contents = array();
    private array $expiries = array();
    private float $nextContentExpires = -1;
    // list
    private array $listContents = array();
    private array $listExpiries = array();
    private float $listNextContentExpires = -1;

    public function __construct(array $config) {
        if (!isset($config['authKeys']) || !is_array($config['authKeys'])) {
            throw new \Exception("You must provide an array of authKeys.");
        }
        $this->authKeys = $config['authKeys'];
        $this->protocol = $config['protocol'] ?? 'tcp';
        $this->host = $config['host'] ?? 'localhost';
        $this->port = $config['port'] ?? 9999;
        $this->usleep = $config['usleep'] ?? 1000;
        $this->verbose = $config['verbose'] ?? false;

        if (extension_loaded('pcntl')) {
            pcntl_signal(SIGINT, function() {
                if ($this->socket) {
                    fclose($this->socket);
                }
                foreach ($this->clients as $client) {
                    fclose($client);
                }
                $this->logLine("Cache server shut down.");
                exit;
            });
        }
    }

    public function run(): void {
        $url = $this->protocol . "://" . $this->host . ":" . $this->port;
        $errno = null;
        $errstr = '';

        $this->socket = stream_socket_server($url, $errno, $errstr);

        if (!$this->socket) {
            throw new \Exception("Not able to create the stream socket: " . $errstr . " (" . $errno . ")");
        }

        if(!stream_set_blocking($this->socket, false)) {
            throw new \Exception("Not able to set the stream non-blocking");
        }

        $this->logLine('Cache server started');
        $this->loop();
    }

    private function logLine($line): void {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL;
    }

    private function loop(): void {
        while(true) {
            $this->checkExpiredContents();
            $this->checkExpiredListContents();
            $this->checkConnections();
            $this->readMessages();

            usleep($this->usleep);
        }
    }

    private function checkExpiredContents(): void {
        if ($this->nextContentExpires === -1) return;
        $now = microtime(true);
        if ($now < $this->nextContentExpires) return;

        $newExpiry = -1;
        foreach ($this->expiries as $key => $exp) {
            if ($exp <= $now) {
                unset($this->contents[$key], $this->expiries[$key]);
                if ($this->verbose) {
                    $this->logLine("Expired key: $key");
                }
            } else {
                $newExpiry = ($newExpiry === -1 || $exp < $newExpiry) ? $exp : $newExpiry;
            }
        }

        $this->nextContentExpires = $newExpiry;
    }

    private function checkExpiredListContents(): void {
        if ($this->listNextContentExpires === -1) return;
        $now = microtime(true);
        if ($now < $this->listNextContentExpires) return;

        $newExpiry = -1;
        foreach ($this->listExpiries as $key => $exp) {
            if ($exp <= $now) {
                unset($this->listContents[$key], $this->listExpiries[$key]);
                if ($this->verbose) {
                    $this->logLine("Expired list key: $key");
                }
            } else {
                $newExpiry = ($newExpiry === -1 || $exp < $newExpiry) ? $exp : $newExpiry;
            }
        }

        $this->listNextContentExpires = $newExpiry;
    }

    private function checkConnections(): void {
        while ($conn = @stream_socket_accept($this->socket, 0)) {
            stream_set_blocking($conn, false);
            $this->clients[] = $conn;
            $this->buffers[(int) $conn] = '';
            if ($this->verbose) {
                $this->logLine("Client connected: " . stream_socket_get_name($conn, true));
            }
        }
    }

    private function removeClient($client): void {
        $key = array_search($client, $this->clients, true);
        if ($key !== false) {
            fclose($client);
            unset($this->clients[$key], $this->buffers[(int)$client]);
            if ($this->verbose) {
                $this->logLine("Client disconnected");
            }
        }
    }

    private function readMessages(): void {
        $clients = $this->clients;
        if(0 == count($clients)) return;

        $write = $except = NULL;
        if (false === ($num_changed_streams = stream_select($clients, $write, $except, 0))) {
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

                $decoded = json_decode($json);
                if ($decoded === null) {
                    $json_error = json_last_error_msg();
                    $this->sendErrorResponse($client, "Invalid JSON: {$json_error}");
                    continue;
                }

                $this->processMessage($client, $decoded);
            }
        }
    }

    private function processMessage($socket, $msg): void {
        if (!isset($msg->authKey) || !in_array($msg->authKey, $this->authKeys)) {
            $this->sendErrorResponse($socket, "Authentication failed");
            return;
        }

        if (!isset($msg->command)) {
            $this->sendErrorResponse($socket, "Missing command");
            return;
        }

        switch ($msg->command) {
            case 'exists':
                $this->sendResultsResponse($socket, isset($this->contents[$msg->key]));
                break;
            case 'get':
                $this->sendResultsResponse($socket, $this->contents[$msg->key] ?? '');
                break;
            case 'getAllKeys':
                $this->sendResultsResponse($socket, array_keys($this->contents));
                break;
            case 'getRem':
                $val = $this->contents[$msg->key] ?? '';
                unset($this->contents[$msg->key], $this->expiries[$msg->key]);
                $this->sendResultsResponse($socket, $val);
                break;
            case 'remove':
                unset($this->contents[$msg->key], $this->expiries[$msg->key]);
                $this->sendResultsResponse($socket, "OK");
                break;
            case 'set':
                if (!isset($msg->key, $msg->val, $msg->lifetime)) {
                    $this->sendErrorResponse($socket, "Missing parameters for 'set'");
                    return;
                }

                $this->contents[$msg->key] = $msg->val;

                if ($msg->lifetime > 0) {
                    $expire = microtime(true) + $msg->lifetime;
                    $this->expiries[$msg->key] = $expire;
                    if ($this->nextContentExpires === -1 || $expire < $this->nextContentExpires) {
                        $this->nextContentExpires = $expire;
                    }
                }

                $this->sendResultsResponse($socket, "OK");
                break;
            case 'listExists':
                $this->sendResultsResponse($socket, isset($this->listContents[$msg->key]));
                break;
            default:
                $this->sendErrorResponse($socket, "Unknown command");
        }
    }

    private function sendErrorResponse($socket, $error): void {
        if($this->verbose) {
            $clientName = stream_socket_get_name($socket, true);
            $this->logLine('Client: ' . $clientName . ' encountered an error: ' . $error);
        }

        $response = new \stdClass();
        $response->error = $error;

        $this->sendResponse($socket, $response);
    }

    private function sendResultsResponse($socket, $results): void {
        $response = new \stdClass();
        $response->results = $results;

        $this->sendResponse($socket, $response);
    }

    private function sendResponse($socket, $data): void {
        $jsonData = json_encode($data);
		$length = strlen($jsonData);
		$lengthBin = pack('N', $length); // 4 octets big endian

		if(false === @fwrite($socket, $lengthBin . $jsonData)) {
			// Failed to write, client may have disconnected...
			$this->removeClient($socket);
		}
    }

}