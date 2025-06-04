<?php
declare(strict_types=1);

namespace sdkNarkos\SimpleCache\Manager;

use sdkNarkos\SimpleCache\Protocol\CommandMessage;
use sdkNarkos\SimpleCache\Protocol\ResponseMessage;

class StorageManager {
    // value
    private array $contents = array();
    private array $expiries = array();
    private float $nextContentExpires = -1;
    // list
    private array $listContents = array();
    private array $listExpiries = array();
    private float $listNextContentExpires = -1;

    private int $lastExpirationCheckTime = 0;

    public function checkExpiries() {
        // check max once by second
        if($this->lastExpirationCheckTime < time()) {
            $this->checkExpiredContents();
            $this->checkExpiredListContents();
            $this->lastExpirationCheckTime = time();
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
            } else {
                $newExpiry = ($newExpiry === -1 || $exp < $newExpiry) ? $exp : $newExpiry;
            }
        }

        $this->listNextContentExpires = $newExpiry;
    }

    public function processCommand(CommandMessage $commandMessage): ResponseMessage {
        try {
            switch ($commandMessage->getCommand()) {
                case 'exists':
                    return $this->commandExists($commandMessage);
                case 'expire':
                    return $this->commandExpire($commandMessage);
                case 'get':
                    return $this->commandGet($commandMessage);
                case 'getAllKeys':
                    return ResponseMessage::createResults(array_keys($this->contents));
                case 'getRem':
                    return $this->commandGetRem($commandMessage);
                case 'remove':
                    return $this->commandRemove($commandMessage);
                case 'set':
                    return $this->commandSet($commandMessage);
                case 'listExists':
                    return $this->commandListExists($commandMessage);
                case 'listExpire':
                    return $this->commandListExpire($commandMessage);
                case 'listAddFirst':
                    return $this->commandListAddFirst($commandMessage);
                case 'listAddLast':
                    return $this->commandListAddLast($commandMessage);
                case 'listGet':
                    return $this->commandListGet($commandMessage);
                case 'listGetFirst':
                    return $this->commandListGetFirst($commandMessage);
                case 'listGetRemFirst':
                    return $this->commandListGetRemFirst($commandMessage);
                case 'listGetLast':
                    return $this->commandListGetLast($commandMessage);
                case 'listGetRemLast':
                    return $this->commandListGetRemLast($commandMessage);
                case 'listGetAllKeys':
                    return ResponseMessage::createResults(array_keys($this->listContents));
                case 'listGetRem':
                    return $this->commandListGetRem($commandMessage);
                case 'listRemove':
                    return $this->commandListRemove($commandMessage);
                case 'listSet':
                    return $this->commandListSet($commandMessage);
                case 'ping':
                    return ResponseMessage::createResults("pong");
                case 'stats':
                    return ResponseMessage::createResults([
                        'keys' => count($this->contents),
                        'lists' => count($this->listContents),
                        'heap_size' => memory_get_usage(true)
                    ]);
                default:
                    return ResponseMessage::createError("Unknown command");
            }
        } catch(\Exception $e) {
            return ResponseMessage::createError($e->getMessage());
        }
    }
    
    private function removeExpiry($key): void {
        if (isset($this->expiries[$key])) {
            $tmpExpiry = $this->expiries[$key];
            unset($this->expiries[$key]);
            if($tmpExpiry == $this->nextContentExpires) $this->nextContentExpires = $this->calculateNextExpire($this->expiries);;
        }
    }

    private function removeListExpiry($key): void {
        if (isset($this->listExpiries[$key])) {
            $tmpListExpiry = $this->listExpiries[$key];
            unset($this->listExpiries[$key]);
            if($tmpListExpiry == $this->listNextContentExpires) $this->listNextContentExpires = $this->calculateNextExpire($this->listExpiries);
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
            if($oldExpiry == $this->nextContentExpires) $this->nextContentExpires = $this->calculateNextExpire($this->expiries);
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
            
            if($oldExpiry == $this->listNextContentExpires) $this->listNextContentExpires = $this->calculateNextExpire($this->listExpiries);
        }
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
    private function commandExists(commandMessage $commandMessage): ResponseMessage {
        $commandMessage->verifyKey();
        return ResponseMessage::createResults(isset($this->contents[$commandMessage->getKey()]));
    }

    private function commandExpire(commandMessage $commandMessage): ResponseMessage {
        $commandMessage->verifyKey();
        $commandMessage->verifyTtl();

        if (!isset($this->contents[$commandMessage->getKey()])) {
            return ResponseMessage::createError("Key does not exist");
        }

        $this->setExpiry($commandMessage->getKey(), microtime(true) + $commandMessage->getTtl());

        return ResponseMessage::createResults("Expiration updated");
    }

    private function commandGet(commandMessage $commandMessage): ResponseMessage {
        $commandMessage->verifyKey();
        return ResponseMessage::createResults($this->contents[$commandMessage->getKey()] ?? '');
    }

    private function commandGetRem(commandMessage $commandMessage): ResponseMessage {
        $commandMessage->verifyKey();
        $val = $this->contents[$commandMessage->getKey()] ?? '';
        unset($this->contents[$commandMessage->getKey()]);
        
        $this->removeExpiry($commandMessage->getKey());
        return ResponseMessage::createResults($val);
    }

    private function commandRemove(commandMessage $commandMessage): ResponseMessage {
        $commandMessage->verifyKey();
        unset($this->contents[$commandMessage->getKey()]);

        $this->removeExpiry($commandMessage->getKey());
        return ResponseMessage::createResults("ACK");
    }

    private function commandSet(commandMessage $commandMessage): ResponseMessage {
        $commandMessage->verifyKey();
        $commandMessage->verifyVal();
        $commandMessage->verifyTtl();

        $this->contents[$commandMessage->getKey()] = $commandMessage->getVal();
        $this->setExpiry($commandMessage->getKey(), microtime(true) + $commandMessage->getTtl());

        return ResponseMessage::createResults("ACK");
    }

    private function commandListExists(commandMessage $commandMessage): ResponseMessage {
        $commandMessage->verifyKey();
        return ResponseMessage::createResults(isset($this->listContents[$commandMessage->getKey()]));
    }

    private function commandListExpire(commandMessage $commandMessage): ResponseMessage {
        $commandMessage->verifyKey();
        $commandMessage->verifyTtl();

        if (!isset($this->listContents[$commandMessage->getKey()])) {
            return ResponseMessage::createError("List key does not exist");
        }

        $this->setListExpiry($commandMessage->getKey(), microtime(true) + $commandMessage->getTtl());

        return ResponseMessage::createResults("Expiration updated");
    }

    private function commandListAddFirst(commandMessage $commandMessage): ResponseMessage {
        $commandMessage->verifyKey();
        $commandMessage->verifyVal();
        $commandMessage->verifyTtl();

        if (!isset($this->listContents[$commandMessage->getKey()])) {
            $this->listContents[$commandMessage->getKey()] = [];
        } elseif (!is_array($this->listContents[$commandMessage->getKey()])) {
            return ResponseMessage::createError("Target key is not a list");
        }

        $values = is_array($commandMessage->getVal()) ? $commandMessage->getVal() : [$commandMessage->getVal()];
        $this->listContents[$commandMessage->getKey()] = array_merge($values, $this->listContents[$commandMessage->getKey()]);
        $this->setListExpiry($commandMessage->getKey(), microtime(true) + $commandMessage->getTtl());

        return ResponseMessage::createResults(count($this->listContents[$commandMessage->getKey()]));
    }

    private function commandListAddLast(commandMessage $commandMessage): ResponseMessage {
        $commandMessage->verifyKey();
        $commandMessage->verifyVal();
        $commandMessage->verifyTtl();

        if (!isset($this->listContents[$commandMessage->getKey()])) {
            $this->listContents[$commandMessage->getKey()] = [];
        } elseif (!is_array($this->listContents[$commandMessage->getKey()])) {
            return ResponseMessage::createError("Target key is not a list");
        }

        $values = is_array($commandMessage->getVal()) ? $commandMessage->getVal() : [$commandMessage->getVal()];
        array_push(...[&$this->listContents[$commandMessage->getKey()]], ...$values);
        $this->setListExpiry($commandMessage->getKey(), microtime(true) + $commandMessage->getTtl());

        return ResponseMessage::createResults(count($this->listContents[$commandMessage->getKey()]));
    }

    private function commandListGet(commandMessage $commandMessage): ResponseMessage {
        $commandMessage->verifyKey();
        return ResponseMessage::createResults($this->listContents[$commandMessage->getKey()] ?? '');
    }

    private function commandListGetFirst(commandMessage $commandMessage): ResponseMessage {
        $commandMessage->verifyKey();
        if (!isset($this->listContents[$commandMessage->getKey()]) || !is_array($this->listContents[$commandMessage->getKey()])) {
            return ResponseMessage::createResults(null);
        } else {
            $first = reset($this->listContents[$commandMessage->getKey()]);
            return ResponseMessage::createResults($first);
        }
    }

    private function commandListGetRemFirst(commandMessage $commandMessage): ResponseMessage {
        $commandMessage->verifyKey();
        if (!isset($this->listContents[$commandMessage->getKey()]) || !is_array($this->listContents[$commandMessage->getKey()]) || empty($this->listContents[$commandMessage->getKey()])) {
            return ResponseMessage::createResults(null);
        } else {
            $val = array_shift($this->listContents[$commandMessage->getKey()]);
            if (empty($this->listContents[$commandMessage->getKey()])) {
                unset($this->listContents[$commandMessage->getKey()]);
                $this->removeListExpiry($commandMessage->getKey());
            }

            return ResponseMessage::createResults($val);
        }
    }

    private function commandListGetLast(commandMessage $commandMessage): ResponseMessage {
        $commandMessage->verifyKey();
        if (!isset($this->listContents[$commandMessage->getKey()]) || !is_array($this->listContents[$commandMessage->getKey()]) || empty($this->listContents[$commandMessage->getKey()])) {
            return ResponseMessage::createResults(null);
        } else {
            $last = end($this->listContents[$commandMessage->getKey()]);
            return ResponseMessage::createResults($last);
        }
    }

    private function commandListGetRemLast(commandMessage $commandMessage): ResponseMessage {
        $commandMessage->verifyKey();
        if (!isset($this->listContents[$commandMessage->getKey()]) || !is_array($this->listContents[$commandMessage->getKey()]) || empty($this->listContents[$commandMessage->getKey()])) {
            return ResponseMessage::createResults(null);
        } else {
            $val = array_pop($this->listContents[$commandMessage->getKey()]);

            if (empty($this->listContents[$commandMessage->getKey()])) {
                unset($this->listContents[$commandMessage->getKey()]);
                $this->removeListExpiry($commandMessage->getKey());
            }

            return ResponseMessage::createResults($val);
        }
    }

    private function commandListGetRem(commandMessage $commandMessage): ResponseMessage {
        $commandMessage->verifyKey();

        $val = $this->listContents[$commandMessage->getKey()] ?? '';
        unset($this->listContents[$commandMessage->getKey()]);
        $this->removeListExpiry($commandMessage->getKey());

        return ResponseMessage::createResults($val);
    }

    private function commandListRemove(commandMessage $commandMessage): ResponseMessage {
        $commandMessage->verifyKey();

        unset($this->listContents[$commandMessage->getKey()]);
        $this->removeListExpiry($commandMessage->getKey());

        return ResponseMessage::createResults("ACK");
    }

    private function commandListSet(commandMessage $commandMessage): ResponseMessage {
        $commandMessage->verifyKey();
        $commandMessage->verifyVal();
        $commandMessage->verifyTtl();

        if(!is_array($commandMessage->getVal())) {
            return ResponseMessage::createError("The value must be an array");
        }

        $this->listContents[$commandMessage->getKey()] = $commandMessage->getVal();
        $this->setListExpiry($commandMessage->getKey(), microtime(true) + $commandMessage->getTtl());

        return ResponseMessage::createResults("ACK");
    }
}
