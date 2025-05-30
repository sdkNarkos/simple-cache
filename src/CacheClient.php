<?php
declare(strict_types=1);

namespace sdkNarkos\SimpleCache;

class CacheClient {
    public readonly string $authKey;
    public readonly string $protocol;
    public readonly string $host;
    public readonly int $port;
    public readonly float $maxReadingDelay;
    private readonly int $connectTimeout;

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
        $this->connectTimeout = $config['connectTimeout'] ?? 30;
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

        $this->socket = stream_socket_client($url, $errno, $errstr, $this->connectTimeout);
        if (!$this->socket) {
            throw new \Exception($errstr . "(" . $errno . ")");
        } else {
            if(!stream_set_blocking($this->socket, false)) {
                throw new \Exception("Not able to set the stream non-blocking");
            }
            $this->isConnected = true;
        }
    }

    private function disconnect(): void {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
        $this->isConnected = false;
    }

    private function checkConnection() {
        if (!$this->isConnected || !$this->isSocketAlive()) {
            $this->reconnect();
        }
    }

    private function isSocketAlive(): bool {
        return is_resource($this->socket) && !feof($this->socket);
    }

    private function reconnect(): void {
        $this->disconnect();
        $this->connect();
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
        $binLength = pack('N', $length);

        $written = @fwrite($this->socket, $binLength . $jsonData);
        if ($written === false) {
            // Try to reconnect once
            $this->reconnect();
            $written = @fwrite($this->socket, $binLength . $jsonData);
            if ($written === false) {
                throw new \Exception('Error writing to the cache server after reconnect.');
            }
        }
    }

    private function getResponse() {
		$response = '';
		$startTime = microtime(true);

		// Read 4 octets to get message length
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

		// Read the entire JSON
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