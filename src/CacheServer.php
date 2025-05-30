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
    private array $clients = [];
    private array $buffers = [];

    private array $contents = [];
    private array $expiries = [];
    private float $nextContentExpires = -1;

    private array $listContents = [];
    private array $listExpiries = [];
    private float $listNextContentExpires = -1;

    public function __construct($config) {
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
                $this->shutdown();
                exit;
            });
        }
    }

    public function run() {
        $url = "{$this->protocol}://{$this->host}:{$this->port}";
        $errno = null;
        $errstr = '';
        $this->socket = stream_socket_server($url, $errno, $errstr);

        if (!$this->socket) {
            throw new \Exception("Could not create socket: $errstr ($errno)");
        }

        stream_set_blocking($this->socket, false);
        $this->logLine("Cache server started");

        $this->loop();
    }

    private function loop() {
        while (true) {
            $this->checkExpiredContents();
            $this->checkExpiredListContents();
            $this->checkConnections();
            $this->readMessages();
            usleep($this->usleep);
        }
    }

    private function checkConnections() {
        while ($conn = @stream_socket_accept($this->socket, 0)) {
            stream_set_blocking($conn, false);
            $this->clients[] = $conn;
            $this->buffers[(int) $conn] = '';
            if ($this->verbose) {
                $this->logLine("Client connected: " . stream_socket_get_name($conn, true));
            }
        }
    }

    private function shutdown() {
        if ($this->socket) {
            fclose($this->socket);
        }
        foreach ($this->clients as $client) {
            fclose($client);
        }
        $this->logLine("Cache server shut down.");
    }

    private function logLine(string $line) {
        echo "[" . date('Y-m-d H:i:s') . "] $line\n";
    }

    private function checkExpiredContents() {
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

    private function checkExpiredListContents() {
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

    private function readMessages() {
        if (empty($this->clients)) return;

        $read = $this->clients;
        $write = $except = null;
        if (@stream_select($read, $write, $except, 0) === false) return;

        foreach ($read as $client) {
            $id = (int)$client;

            $data = fread($client, 8192);
            if ($data === false || $data === '') {
                $this->removeClient($client);
                continue;
            }

            $this->buffers[$id] .= $data;

            while (mb_strlen($this->buffers[$id], '8bit') >= 4) {
                $lengthData = substr($this->buffers[$id], 0, 4);
                $unpacked = unpack('Nlength', $lengthData);
                $messageLength = $unpacked['length'];

                if (mb_strlen($this->buffers[$id], '8bit') < 4 + $messageLength) {
                    break;
                }

                $json = substr($this->buffers[$id], 4, $messageLength);
                $this->buffers[$id] = substr($this->buffers[$id], 4 + $messageLength);

                $decoded = json_decode($json);
                if ($decoded === null) {
                    $this->sendErrorResponse($client, "Invalid JSON");
                    continue;
                }

                $this->processMessage($client, $decoded);
            }
        }
    }

    private function removeClient($client) {
        $key = array_search($client, $this->clients, true);
        if ($key !== false) {
            fclose($client);
            unset($this->clients[$key], $this->buffers[(int)$client]);
            if ($this->verbose) {
                $this->logLine("Client disconnected");
            }
        }
    }

    private function sendResponse($socket, $response) {
        $json = json_encode($response);
        $len = mb_strlen($json, '8bit');
        $header = pack('N', $len);
        $result = @fwrite($socket, $header . $json);
        if ($result === false) {
            $this->removeClient($socket);
        }
    }

    private function sendErrorResponse($socket, string $error) {
        $this->sendResponse($socket, (object)['error' => $error]);
    }

    private function sendResultsResponse($socket, $results) {
        $this->sendResponse($socket, (object)['results' => $results]);
    }

    private function processMessage($socket, $msg) {
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
}