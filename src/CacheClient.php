<?php
declare(strict_types=1);

namespace sdkNarkos\SimpleCache;

class CacheClient {
    public readonly string $authKey;
    public readonly string $protocol;
    public readonly string $host;
    public readonly int $port;
    public readonly float $maxReadingDelay;

    private $socket;
    private bool $isConnected = false;

    public function __construct($config) {
        if(!isset($config['authKey'])) {
            throw new \Exception("No authKey found");
        }
        $this->authKey = $config['authKey'];

        $this->protocol = $config['protocol'] ?? 'tcp';
        $this->host = $config['host'] ?? 'localhost';
        $this->port = $config['port'] ?? 9999;
        $this->maxReadingDelay = $config['maxReadingDelay'] ?? 3;
    }

    public function __destruct() {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
            $this->isConnected = false;
        }
    }

    private function connect() {
        $url = $this->protocol . "://" . $this->host . ":" . $this->port;
        $errno = null;
        $errstr = '';

        $this->socket = stream_socket_client($url, $errno, $errstr, 30);
        if (!$this->socket) {
            throw new \Exception($errstr . "(" . $errno . ")");
        } else {
            if(!stream_set_blocking($this->socket, false)) {
                throw new \Exception("Not able to set the stream non-blocking");
            }
            $this->isConnected = true;
        }
    }

    private function checkConnection() {
        if(!$this->isConnected) {
            $this->connect();
        }
    }

    private function createCallObject(string $command) {
        $data = new \stdClass();
        $data->authKey = $this->authKey;
        $data->command = $command;
        return $data;
    }

    private function doCall(\stdClass $data) {
        $jsonData = json_encode($data);
		$length = strlen($jsonData);
		$binLength = pack('N', $length);  // 4 octets big-endian
		if(false === @fwrite($this->socket, $binLength . $jsonData)) {
			throw new \Exception('Error when writing to the cache server.');
		}
    }

    private function getResponse() {
		$response = '';
		$startTime = microtime(true);

		// Lire d'abord 4 octets de longueur
		$lengthData = '';
		while (strlen($lengthData) < 4) {
			$chunk = fread($this->socket, 4 - strlen($lengthData));
			if ($chunk === false || $chunk === '') {
				if (microtime(true) - $startTime >= $this->maxReadingDelay) {
					throw new \Exception("Timeout while reading message length");
				}
				usleep(1000);
				continue;
			}
			$lengthData .= $chunk;
		}
		$unpacked = unpack('Nlength', $lengthData);
		$targetLength = $unpacked['length'];

		// Lire le JSON complet
		$responseData = '';
		while (strlen($responseData) < $targetLength) {
			$chunk = fread($this->socket, $targetLength - strlen($responseData));
			if ($chunk === false || $chunk === '') {
				if (microtime(true) - $startTime >= $this->maxReadingDelay) {
					throw new \Exception("Timeout while reading message data");
				}
				usleep(1000);
				continue;
			}
			$responseData .= $chunk;
		}

		$response = json_decode($responseData);

		if ($response === null) {
			throw new \Exception("Failed to decode JSON response");
		}

		if (isset($response->error)) {
			throw new \Exception($response->error);
		} elseif (isset($response->results)) {
			return $response->results;
		} else {
			return $response;
		}
    }

    ///////////////
    // Commands //
    /////////////

    ////////////
    // value //
    public function get(string $key) {
        $this->checkConnection();

        $data = $this->createCallObject('get');
        $data->key = $key;

        $this->doCall($data);
        return $this->getResponse();
    }

    public function getAllKeys() {
        $this->checkConnection();
        
        $data = $this->createCallObject('getAllKeys');

        $this->doCall($data);
        return $this->getResponse();
    }

    public function getRem(string $key) {
        $this->checkConnection();
        
        $data = $this->createCallObject('getRem');
        $data->key = $key;

        $this->doCall($data);
        return $this->getResponse();
    }

    public function exists(string $key) {
        $this->checkConnection();
        
        $data = $this->createCallObject('exists');
        $data->key = $key;

        $this->doCall($data);
        return $this->getResponse();
    }

    public function remove(string $key) {
        $this->checkConnection();
        
        $data = $this->createCallObject('remove');
        $data->key = $key;

        $this->doCall($data);
        return $this->getResponse();
    }

    public function set(string $key, string $val, int $lifetime = 0) {
        $this->checkConnection();
        
        $data = $this->createCallObject('set');
        $data->key = $key;
        $data->val = $val;
        $data->lifetime = $lifetime;

        $this->doCall($data);
        return $this->getResponse();
    }

    ///////////
    // list //

    
}