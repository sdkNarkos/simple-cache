<?php
declare(strict_types=1);

namespace sdkNarkos\SimpleCache;

class CacheServer {
    public readonly array $authKeys;
    public readonly string $protocol;
    public readonly string $host;
    public readonly int $port;
    public readonly int $pingCheckInterval;
    public readonly int $usleep;
    public readonly bool $verbose;

    private $socket;
    private array $clients = array();
    private array $partialIncomingMessages = array();

    // value
    private array $contents = array();
    private array $expiries = array();
    private float $nextContentExpires = -1;
    // list
    private array $listContents = array();
    private array $listExpiries = array();
    private float $listNextContentExpires = -1;

    public function __construct($config) {
        if(!isset($config['authKeys'])) {
            throw new \Exception("No authKeys found, You have to provide an array with at least one cacheKey");
        }
        if(!is_array($config['authKeys'])) {
            throw new \Exception("You have to provide an array with at least one cacheKey");
        }
        $this->authKeys = $config['authKeys'];

        if(!isset($config['protocol'])) {
            $this->protocol = 'tcp';
        } else {
            $this->protocol = $config['protocol'];
        }

        if(!isset($config['host'])) {
            $this->host = 'localhost';
        } else {
            $this->host = $config['host'];
        }

        if(!isset($config['port'])) {
            $this->port = 9999;
        } else {
            $this->port = $config['port'];
        }
        
        if(!isset($config['pingCheckInterval'])) {
            $this->pingCheckInterval = 2;
        } else {
            $this->pingCheckInterval = $config['pingCheckInterval'];
        }

        if(!isset($config['usleep'])) {
            $this->usleep = 1000;
        } else {
            $this->usleep = $config['usleep'];
        }

        if(!isset($config['verbose'])) {
            $this->verbose = false;
        } else {
            $this->verbose = $config['verbose'];
        }
        
    }

    // I can't find a way to detect script interrupted with ctrl+C or killed.
    // Have to find a way to properly shutdown things when an interruption happen
    public function __destruct() {
        if($this->socket) {
            // stream_socket_shutdown($this->socket);
            fclose($this->socket);
            $this->logLine('Cache server closed');
        }
    }

    public function run() {
        $url = $this->protocol . "://" . $this->host . ":" . $this->port;
        $errno = null;
        $errstr = '';

        $this->socket = stream_socket_server($url, $errno, $errstr);

        if (!$this->socket) {
            throw new \Exception("Not able to create the stream socket: " . $errstr . " (" . $errno . ")");
        } else {
            if(!stream_set_blocking($this->socket, false)) {
                throw new \Exception("Not able to set the stream non-blocking");
            }

            $this->logLine('Cache server started');
            $this->loop();
        }
    }

    private function logLine($line) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL;
    }

    private function loop() {
        while(true) {
            $this->checkExpiredContents();
            $this->checkExpiredListContents();
            $this->checkConnections();
            $this->readMessages();

            /*
                Crazy things happend with usleep on windows...
                with a usleep(1) in a loop, I can loop around 65 times per second on my windows 10, but on archlinux it does more than 17000 times...
            */ 
            usleep($this->usleep);
        }
    }

    private function checkExpiredContents() {
        if($this->nextContentExpires == -1) {
            return;
        }

        $currentTime = microtime(true);
        if($currentTime < $this->nextContentExpires) {
            return;
        }
        
        $newExpiry = -1;
        $remList = array();
        foreach($this->expiries as $key => $expiry) {
            if($expiry <= $currentTime) {
                $remList[] = $key;
            } else {
                if($newExpiry == -1) {
                    $newExpiry = $expiry;
                } else if($newExpiry > $expiry) {
                    $newExpiry = $expiry;
                }
            }
        }

        foreach($remList as $expiredKey) {
            if(isset($this->contents[$expiredKey])) {
                unset($this->contents[$expiredKey]);
            }
            unset($this->expiries[$expiredKey]);

            if($this->verbose) {
                $this->logLine('Expired key: ' . $expiredKey);
            }
        }

        $this->nextContentExpires = $newExpiry;
    }

    private function checkExpiredListContents() {
        if($this->listNextContentExpires == -1) {
            return;
        }
        
        $currentTime = microtime(true);
        if($currentTime < $this->listNextContentExpires) {
            return;
        }
        
        $newExpiry = -1;
        $remList = array();
        foreach($this->listExpiries as $key => $expiry) {
            if($expiry <= $currentTime) {
                $remList[] = $key;
            } else {
                if($newExpiry == -1) {
                    $newExpiry = $expiry;
                } else if($newExpiry > $expiry) {
                    $newExpiry = $expiry;
                }
            }
        }

        foreach($remList as $expiredKey) {
            if(isset($this->listContents[$expiredKey])) {
                unset($this->listContents[$expiredKey]);
            }
            unset($this->listExpiries[$expiredKey]);

            if($this->verbose) {
                $this->logLine('Expired list key: ' . $expiredKey);
            }
        }

        $this->listNextContentExpires = $newExpiry;
    }

    // Checks for new incoming connections
    private function checkConnections() {
        while ($conn = @stream_socket_accept($this->socket, 0)) {
            $this->addClient($conn);
        }
    }

    private function addClient($conn) {
        $clientName = stream_socket_get_name($conn, true);
        $this->clients[] = $conn;

        if($this->verbose) {
            $this->logLine('Client added: ' . $clientName);
        }
    }

    private function removeClient($conn) {
        $clientName = stream_socket_get_name($conn, true);
        $clientsCount = count($this->clients);
        for($i = 0; $i < $clientsCount; $i++) {
            if($this->clients[$i] == $conn) {
                array_splice($this->clients, $i, 1);

                if($this->verbose) {
                    $this->logLine('Client removed: ' . $clientName);
                }
                break;
            }
        }
    }

    private function readMessages() {
        $clients = $this->clients;
        if(count($clients) == 0) {
            return;
        }
        $write  = NULL;
        $except = NULL;
        if (false === ($num_changed_streams = stream_select($clients, $write, $except, 0))) {
            throw new \Exception("Error while trying stream_select");
        } elseif ($num_changed_streams > 0) {
            foreach($clients as $client) {
                $this->readMessage($client);
            }
        }
    }

    private function readMessage($socket) {
        if(feof($socket)) {
            // Client disconnected
            $this->removeClient($socket);
            return;
        }

        $rawMessage = '';
        while (($line = fgets($socket, 4096)) !== false) {
            $rawMessage .= $line;
        }

        if (strlen($rawMessage) == 0) {
            return;
        }

        $socketName = stream_socket_get_name($socket, true);
        if(isset($this->partialIncomingMessages[$socketName])) {
            $this->partialIncomingMessages[$socketName] .= $rawMessage;
        } else {
            $this->partialIncomingMessages[$socketName] = $rawMessage;
        }

        $currentMessageLength = strlen($this->partialIncomingMessages[$socketName]);
		if($currentMessageLength > 4) {
			// Lire les 4 premiers octets (big endian)
			$lengthBin = substr($this->partialIncomingMessages[$socketName], 0, 4);
			$unpacked = unpack('Nlength', $lengthBin);
			$targetLength = $unpacked['length'];

			if($currentMessageLength >= 4 + $targetLength) {
				$messageToProcess = substr($this->partialIncomingMessages[$socketName], 4, $targetLength);

				$this->partialIncomingMessages[$socketName] = substr($this->partialIncomingMessages[$socketName], 4 + $targetLength);
				if(empty($this->partialIncomingMessages[$socketName])) {
					unset($this->partialIncomingMessages[$socketName]);
				}

				$this->processMessage($socket, $messageToProcess);
			}
		}
    }

    private function processMessage($socket, $message) {
        $message = json_decode($message);

        if(!$this->verifyParamAuth($socket, $message)) {
            return;
        }

        if(!$this->verifyParamCommand($socket, $message)) {
            return;
        }
        
        switch($message->command) {
            // Value
            case 'exists':
                $this->commandExists($socket, $message);
                break;
            case 'get':
                $this->commandGet($socket, $message);
                break;
            case 'getAllKeys':
                $this->commandGetAllKeys($socket);
                break;
            case 'getRem':
                $this->commandGetRem($socket, $message);
                break;
            case 'remove':
                $this->commandRemove($socket, $message);
                break;
            case 'set':
                $this->commandSet($socket, $message);
                break;
            // List
            case 'listExists':
                $this->commandListExists($socket, $message);
                break;
            default:
                $this->sendErrorResponse($socket, "Command unknown");
                break;
        }
    }

    private function verifyParamAuth($socket, $message) {
        if (!isset($message->authKey) || !in_array($message->authKey, $this->authKeys)) {
            $this->sendErrorResponse($socket, "Authentication failed");
            return false;
        }
        return true;
    }

    private function verifyParamCommand($socket, $message) {
        if(!isset($message->command)) {
            $this->sendErrorResponse($socket, "command is missing");
            return false;
        }
        return true;
    }

    private function verifyParamKey($socket, $message) {
        if(!isset($message->key)) {
            $this->sendErrorResponse($socket, "key is missing");
            return false;
        }
        return true;
    }

    private function sendErrorResponse($socket, $error) {
        if($this->verbose) {
            $clientName = stream_socket_get_name($socket, true);
            $this->logLine('Client: ' . $clientName . ' encountered an error: ' . $error);
        }

        $response = new \stdClass();
        $response->error = $error;

        $this->sendResponse($socket, $response);
    }

    private function sendResultsResponse($socket, $results) {
        $response = new \stdClass();
        $response->results = $results;

        $this->sendResponse($socket, $response);
    }

    private function sendResponse($socket, $data) {
        $jsonData = json_encode($data);
		$length = strlen($jsonData);
		$lengthBin = pack('N', $length); // 4 octets big endian

		if(false === @fwrite($socket, $lengthBin . $jsonData)) {
			// Failed to write, client may have disconnected...
			$this->removeClient($socket);
		}
    }

    ///////////////
    // Commands //
    /////////////

    // Commands for key-value //
    private function commandExists($socket, $message) {
        $this->verifyParamKey($socket, $message);

        $results = isset($this->contents[$message->key]);

        $this->sendResultsResponse($socket, $results);
    }

    private function commandGet($socket, $message) {
        $this->verifyParamKey($socket, $message);

        if(isset($this->contents[$message->key])) {
            $results = $this->contents[$message->key];
        } else {
            $results = '';
        }

        $this->sendResultsResponse($socket, $results);
    }

    private function commandGetAllKeys($socket) {
        $results = array_keys($this->contents);
        $this->sendResultsResponse($socket, $results);
    }

    private function commandGetRem($socket, $message) {
        $this->verifyParamKey($socket, $message);

        if(isset($this->contents[$message->key])) {
            $results = $this->contents[$message->key];
            unset($this->contents[$message->key]);
            if(isset($this->expiries[$message->key])) {
                unset($this->expiries[$message->key]);
            }
        } else {
            $results = '';
        }

        $this->sendResultsResponse($socket, $results);
    }

    private function commandRemove($socket, $message) {
        $this->verifyParamKey($socket, $message);

        if(isset($this->expiries[$message->key])) {
            unset($this->expiries[$message->key]);
        }

        if(isset($this->contents[$message->key])) {
            unset($this->contents[$message->key]);
        }

        $this->sendResultsResponse($socket, "OK");
    }

    private function commandSet($socket, $message) {
        if(!isset($message->val)) {
            $this->sendResultsResponse($socket, "val is missing");
            return;
        }

        if(!isset($message->lifetime)) {
            $this->sendResultsResponse($socket, "lifetime is missing");
            return;
        }

        $this->contents[$message->key] = $message->val;

        if($message->lifetime > 0) {
            $expireTime = microtime(true) + $message->lifetime;
            $this->expiries[$message->key] = $expireTime;

            if($this->nextContentExpires == -1) {
                $this->nextContentExpires = $expireTime;
            } else if($expireTime < $this->nextContentExpires) {
                $this->nextContentExpires = $expireTime;
            }
        }

        $this->sendResultsResponse($socket, "OK");
    }

    // Commands for key-list //
    public function commandListExists($socket, $message) {
        $this->verifyParamKey($socket, $message);

        $results = isset($this->listContents[$message->key]);

        $this->sendResultsResponse($socket, $results);
    }
}