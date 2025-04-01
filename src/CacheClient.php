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
        
        if(!isset($config['maxReadingDelay'])) {
            $this->maxReadingDelay = 3;
        } else {
            $this->maxReadingDelay = $config['maxReadingDelay'];
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
        $tmpLength = (string)strlen($jsonData);
        // 2,147,483,647 max size of a 32 bits integer => 10
        $length = str_pad($tmpLength, 10, "0", STR_PAD_LEFT);
        if(false === @fwrite($this->socket, $length . $jsonData . PHP_EOL)) {
            throw new \Exception('Error when writing to the cache serveur.');
        }
    }

    private function getResponse() {
        $response = '';
        $startTime = microtime(true);

        // sometime it stop with uncomplete reading, so force it and track received length
        $keepReading = true;
        $targetLength = 0;
        while ($keepReading) {
            $line = fgets($this->socket, 4096);
            if(null != $line) {
                $response .= $line;
            }

            // message length check
            $responseTmpLength = strlen($response);
            if(!$targetLength) {
                if($responseTmpLength > 10) {
                    $targetLength = (int)substr($response, 0, 10);
                }
            }
            
            if($targetLength) {
                if($responseTmpLength >= 10 + $targetLength) {
                    $keepReading = false;
                }
            }
            
            if(microtime(true) - $startTime >= $this->maxReadingDelay) {
                // tmp, remove echo later
                echo "maxReadingDelay reached" . PHP_EOL;
                break;
            }
        }
        
        if (strlen($response) == 0) {
            throw new \Exception("No response from the server.");
        } else {
            $response = json_decode(substr($response, 10));

            if (isset($response->error)) {
                throw new \Exception($response->error);
            } else if (isset($response->results)) {
                return $response->results;
            } else {
                // Should not fall here if response is formated correctly, error or results should be returned...
                return $response;
            }
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