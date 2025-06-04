<?php
declare(strict_types=1);

namespace sdkNarkos\SimpleCache\Protocol;

use JsonSerializable;
use InvalidArgumentException;

final class CommandMessage implements JsonSerializable {
    private string $authKey;
    private string $command;
    private ?string $key;
    private mixed $val;
    private float $ttl;

    public function __construct(
        string $authKey,
        string $command,
        ?string $key = null,
        mixed $val = null,
        float $ttl = 0
    ) {
        $this->authKey = $authKey;
        $this->command = $command;
        $this->key = $key;
        $this->val = $val;
        $this->ttl = $ttl;
    }

    public static function fromJson(string $json): self {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new \InvalidArgumentException('Invalid JSON payload');
        }

        return new self(
            $data['authKey'] ?? throw new \InvalidArgumentException('Missing authKey'),
            $data['command'] ?? throw new \InvalidArgumentException('Missing command'),
            $data['key'] ?? null,
            $data['val'] ?? null,
            isset($data['ttl']) ? (float) $data['ttl'] : 0
        );
    }

    public function jsonSerialize(): array {
        return [
            'authKey' => $this->authKey,
            'command' => $this->command,
            'key'     => $this->key,
            'val'     => $this->val,
            'ttl'     => $this->ttl,
        ];
    }

    public function toJson(): string {
        return json_encode($this);
    }

    public function isAuthenticated(array $authKeys): bool {
        foreach ($authKeys as $storedKey) {
            if (hash_equals($storedKey, $this->authKey)) {
                return true;
            }
        }

        return false;
    }

    // Validations
    public function verifyKey(): void {
        if (!isset($this->key) || strlen($this->key) < 1) {
            throw new \InvalidArgumentException("Missing parameter key");
        }
    }

    public function verifyTtl(): void {
        if (!isset($this->ttl) || !is_numeric($this->ttl)) {
            throw new \InvalidArgumentException("Missing parameter lifetime or incorrect type");
        }
    }

    public function verifyVal(): void {
        if (!isset($this->val)) {
            throw new \InvalidArgumentException("Missing parameter val");
        }
    }

    // Getters
    public function getAuthKey(): string {
        return $this->authKey;
    }

    public function getCommand(): string {
        return $this->command;
    }

    public function getKey(): ?string {
        return $this->key;
    }

    public function getVal(): mixed {
        return $this->val;
    }

    public function getTtl(): float {
        return $this->ttl;
    }
}