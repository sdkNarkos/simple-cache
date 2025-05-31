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

    private int $lastExpirationCheckTime = 0;

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
                $this->shutdown();
            });
        }
    }

    public function run(): void {
        $url = $this->protocol . "://" . $this->host . ":" . $this->port;
        $errno = null;
        $errstr = '';

        $this->socket = stream_socket_server($url, $errno, $errstr);

        if (!$this->socket) throw new \Exception("Not able to create the stream socket: " . $errstr . " (" . $errno . ")");

        if(!stream_set_blocking($this->socket, false)) throw new \Exception("Not able to set the stream non-blocking");

        $this->logLine('Cache server started');
        $this->loop();
    }

    private function shutdown(): void {
        if ($this->socket) fclose($this->socket);

        foreach ($this->clients as $client) fclose($client);

        $this->logLine("Cache server shut down.");
        exit;
    }

    private function logLine($line): void {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL;
    }

    private function loop(): void {
        while(true) {
            // check max once by second
            if($this->lastExpirationCheckTime < time()) {
                $this->checkExpiredContents();
                $this->checkExpiredListContents();
                $this->lastExpirationCheckTime = time();
            }
            
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
                if ($this->verbose) $this->logLine("Expired key: $key");
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
                if ($this->verbose) $this->logLine("Expired list key: $key");
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

                try {
                    $this->processMessage($client, $decoded);
                } catch (\Throwable $e) {
                    $this->sendErrorResponse($client, "Server error: " . $e->getMessage());
                }
            }
        }
    }

    private function processMessage($socket, $msg): void {
        if (!isset($msg->authKey) || !in_array($msg->authKey, $this->authKeys)) throw new \Exception("Authentication failed");

        if (!isset($msg->command)) throw new \Exception("Missing command");

        switch ($msg->command) {
            case 'exists':
                $this->commandExists($socket, $msg);
                break;
            case 'expire':
                $this->commandExpire($socket, $msg);
                break;
            case 'get':
                $this->commandGet($socket, $msg);
                break;
            case 'getAllKeys':
                $this->sendResultsResponse($socket, array_keys($this->contents));
                break;
            case 'getRem':
                $this->commandGetRem($socket, $msg);
                break;
            case 'remove':
                $this->commandRemove($socket, $msg);
                break;
            case 'set':
                $this->commandSet($socket, $msg);
                break;
            case 'listExists':
                $this->commadListExists($socket, $msg);
                break;
            case 'listExpire':
                $this->commandListExpire($socket, $msg);
                break;
            case 'listAddFirst':
                $this->commandListAddFirst($socket, $msg);
                break;
            case 'listAddLast':
                $this->commandListAddLast($socket, $msg);
                break;
            case 'listGet':
                $this->commandListGet($socket, $msg);
                break;
            case 'listGetFirst':
                $this->commandListGetFirst($socket, $msg);
                break;
            case 'listGetRemFirst':
                $this->commandListGetRemFirst($socket, $msg);
                break;
            case 'listGetLast':
                $this->commandListGetLast($socket, $msg);
                break;
            case 'listGetRemLast':
                $this->commandListGetRemLast($socket, $msg);
                break;
            case 'listGetAllKeys':
                $this->sendResultsResponse($socket, array_keys($this->listContents));
                break;
            case 'listGetRem':
                $this->commandListGetRem($socket, $msg);
                break;
            case 'listRemove':
                $this->commandListRemove($socket, $msg);
                break;
            case 'listSet':
                $this->commandListSet($socket, $msg);
                break;
            case 'ping':
                $this->sendResultsResponse($socket, 'pong');
                break;
            default:
                $this->sendErrorResponse($socket, "Unknown command");
        }
    }

    private function verifyKey(mixed $key): void {
        if (!isset($key) || strlen($key) < 1) {
            throw new \Exception("Missing parameter key");
        }
    }

    private function verifyLifetime(float $lifetime): void {
        if (!isset($lifetime) || !is_numeric($lifetime)) {
            throw new \Exception("Missing parameter lifetime or incorrect type");
        }
    }

    private function verifyVal(mixed $val) {
        if (!isset($val)) {
            throw new \Exception("Missing parameter val");
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

    private function removeExpiry($key): void {
        if (isset($this->expiries[$key])) {
            $tmpExpiry = $this->expiries[$key];
            unset($this->expiries[$key]);
            if($tmpExpiry == $this->nextContentExpires) $this->recalculateNextContentExpires();
        }
    }

    private function removeListExpiry($key): void {
        if (isset($this->listExpiries[$key])) {
            $tmpListExpiry = $this->listExpiries[$key];
            unset($this->listExpiries[$key]);
            if($tmpListExpiry == $this->listNextContentExpires) $this->recalculateListNextContentExpires();
        }
    }

    private function setExpiry($key, $timestamp) {
        if(!isset($this->expiries[$key])) {
            $this->expiries[$key] = $timestamp;
            if($timestamp < $this->nextContentExpires) {
                $this->nextContentExpires = $timestamp;
            }
        } else {
            $oldExpiry = $this->expiries[$key];
            $this->expiries[$key] = $timestamp;
            if($oldExpiry == $this->nextContentExpires) {
                $this->recalculateNextContentExpires();
            }
        }
    }

    private function setListExpiry($key, $timestamp) {
        if(!isset($this->listExpiries[$key])) {
            $this->listExpiries[$key] = $timestamp;
            if($timestamp < $this->listNextContentExpires) {
                $this->listNextContentExpires = $timestamp;
            }
        } else {
            $oldExpiry = $this->listExpiries[$key];
            if($timestamp <= microtime(true)) {
                unset($this->listExpiries[$key]);
            } else {
                $this->listExpiries[$key] = $timestamp;
            }
            
            if($oldExpiry == $this->listNextContentExpires) {
                $this->recalculateListNextContentExpires();
            }
        }
    }

    private function recalculateNextContentExpires(): void {
        $this->nextContentExpires = $this->calculateNextExpire($this->expiries);
    }

    private function recalculateListNextContentExpires(): void {
        $this->listNextContentExpires = $this->calculateNextExpire($this->listExpiries);
    }

    private function calculateNextExpire(array $expiries): float {
        $min = -1;
        foreach ($expiries as $exp) {
            if ($min === -1 || $exp < $min) {
                $min = $exp;
            }
        }
        return $min;
    }
    //////////////
    // Commands //
    private function commandExists($socket, $msg): void {
        $this->verifyKey($msg->key);
        $this->sendResultsResponse($socket, isset($this->contents[$msg->key]));
    }

    private function commandExpire($socket, $msg): void {
        $this->verifyKey($msg->key);
        $this->verifyLifetime($msg->lifetime);

        if (!isset($this->contents[$msg->key])) {
            $this->sendErrorResponse($socket, "Key does not exist");
            return;
        }

        $this->setExpiry($msg->key, microtime(true) + $msg->lifetime);

        $this->sendResultsResponse($socket, "Expiration updated");
    }

    private function commandGet($socket, $msg): void {
        $this->verifyKey($msg->key);
        $this->sendResultsResponse($socket, $this->contents[$msg->key] ?? '');
    }

    private function commandGetRem($socket, $msg): void {
        $this->verifyKey($msg->key);
        $val = $this->contents[$msg->key] ?? '';
        unset($this->contents[$msg->key]);
        
        $this->removeExpiry($msg->key);
        $this->sendResultsResponse($socket, $val);
    }

    private function commandRemove($socket, $msg): void {
        $this->verifyKey($msg->key);
        unset($this->contents[$msg->key]);

        $this->removeExpiry($msg->key);
        $this->sendResultsResponse($socket, "OK");
    }

    private function commandSet($socket, $msg): void {
        $this->verifyKey($msg->key);
        $this->verifyVal($msg->val);
        $this->verifyLifetime($msg->lifetime);

        $this->contents[$msg->key] = $msg->val;
        $this->setExpiry($msg->key, microtime(true) + $msg->lifetime);

        $this->sendResultsResponse($socket, "OK");
    }

    private function commadListExists($socket, $msg): void {
        $this->verifyKey($msg->key);
        $this->sendResultsResponse($socket, isset($this->listContents[$msg->key]));
    }

    private function commandListExpire($socket, $msg): void {
        $this->verifyKey($msg->key);
        $this->verifyLifetime($msg->lifetime);

        if (!isset($this->listContents[$msg->key])) {
            $this->sendErrorResponse($socket, "List key does not exist");
            return;
        }

        $this->setListExpiry($msg->key, microtime(true) + $msg->lifetime);

        $this->sendResultsResponse($socket, "Expiration updated");
    }

    private function commandListAddFirst($socket, $msg): void {
        $this->verifyKey($msg->key);
        $this->verifyVal($msg->val);
        $this->verifyLifetime($msg->lifetime);

        if (!isset($this->listContents[$msg->key])) {
            $this->listContents[$msg->key] = [];
        } elseif (!is_array($this->listContents[$msg->key])) {
            $this->sendErrorResponse($socket, "Target key is not a list");
            return;
        }

        $values = is_array($msg->val) ? $msg->val : [$msg->val];
        $this->listContents[$msg->key] = array_merge($values, $this->listContents[$msg->key]);
        $this->setListExpiry($msg->key, microtime(true) + $msg->lifetime);

        $this->sendResultsResponse($socket, count($this->listContents[$msg->key]));
    }

    private function commandListAddLast($socket, $msg): void {
        $this->verifyKey($msg->key);
        $this->verifyVal($msg->val);
        $this->verifyLifetime($msg->lifetime);

        if (!isset($this->listContents[$msg->key])) {
            $this->listContents[$msg->key] = [];
        } elseif (!is_array($this->listContents[$msg->key])) {
            $this->sendErrorResponse($socket, "Target key is not a list");
            return;
        }

        $values = is_array($msg->val) ? $msg->val : [$msg->val];
        array_push(...[&$this->listContents[$msg->key]], ...$values);
        $this->setListExpiry($msg->key, microtime(true) + $msg->lifetime);

        $this->sendResultsResponse($socket, count($this->listContents[$msg->key]));
    }

    private function commandListGet($socket, $msg): void {
        $this->verifyKey($msg->key);
        $this->sendResultsResponse($socket, $this->listContents[$msg->key] ?? '');
    }

    private function commandListGetFirst($socket, $msg): void {
        $this->verifyKey($msg->key);
        if (!isset($this->listContents[$msg->key]) || !is_array($this->listContents[$msg->key])) {
            $this->sendResultsResponse($socket, null);
        } else {
            $first = reset($this->listContents[$msg->key]);
            $this->sendResultsResponse($socket, $first);
        }
    }

    private function commandListGetRemFirst($socket, $msg): void {
        $this->verifyKey($msg->key);
        if (!isset($this->listContents[$msg->key]) || !is_array($this->listContents[$msg->key]) || empty($this->listContents[$msg->key])) {
            $this->sendResultsResponse($socket, null);
        } else {
            $val = array_shift($this->listContents[$msg->key]);
            if (empty($this->listContents[$msg->key])) {
                unset($this->listContents[$msg->key]);
                $this->removeListExpiry($msg->key);
            }

            $this->sendResultsResponse($socket, $val);
        }
    }

    private function commandListGetLast($socket, $msg): void {
        $this->verifyKey($msg->key);
        if (!isset($this->listContents[$msg->key]) || !is_array($this->listContents[$msg->key]) || empty($this->listContents[$msg->key])) {
            $this->sendResultsResponse($socket, null);
        } else {
            $last = end($this->listContents[$msg->key]);
            $this->sendResultsResponse($socket, $last);
        }
    }

    private function commandListGetRemLast($socket, $msg): void {
        $this->verifyKey($msg->key);
        if (!isset($this->listContents[$msg->key]) || !is_array($this->listContents[$msg->key]) || empty($this->listContents[$msg->key])) {
            $this->sendResultsResponse($socket, null);
        } else {
            $val = array_pop($this->listContents[$msg->key]);

            if (empty($this->listContents[$msg->key])) {
                unset($this->listContents[$msg->key]);
                $this->removeListExpiry($msg->key);
            }

            $this->sendResultsResponse($socket, $val);
        }
    }

    private function commandListGetRem($socket, $msg): void {
        $this->verifyKey($msg->key);

        $val = $this->listContents[$msg->key] ?? '';
        unset($this->listContents[$msg->key]);
        $this->removeListExpiry($msg->key);

        $this->sendResultsResponse($socket, $val);
    }

    private function commandListRemove($socket, $msg): void {
        $this->verifyKey($msg->key);

        unset($this->listContents[$msg->key]);
        $this->removeListExpiry($msg->key);

        $this->sendResultsResponse($socket, "OK");
    }

    private function commandListSet($socket, $msg): void {
        $this->verifyKey($msg->key);
        $this->verifyVal($msg->val);
        $this->verifyLifetime($msg->lifetime);

        if(!is_array($msg->val)) {
            $this->sendErrorResponse($socket, "The value must be an array");
            return;
        }

        $this->listContents[$msg->key] = $msg->val;
        $this->setListExpiry($msg->key, microtime(true) + $msg->lifetime);

        $this->sendResultsResponse($socket, "OK");
    }

}
