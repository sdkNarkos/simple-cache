<?php
declare(strict_types=1);

namespace sdkNarkos\SimpleCache\Manager;

class ClientManager {
    private array $clients = array();

    public function addClient($client) {
        if(!in_array($client, $this->clients)) {
            $this->clients[] = $client;
        }
    }

    public function removeClient($client) {
        $key = array_search($client, $this->clients, true);
        if ($key !== false) {
            fclose($client);
            unset($this->clients[$key]);
            /*if ($this->verbose) {
                $this->logLine("Client disconnected");
            }*/
        }
    }
    
    public function getClients() {
        return $this->clients;
    }

    public function shutdown(): void {
        foreach ($this->clients as $client) fclose($client);

        unset($this->clients);
    }
}
