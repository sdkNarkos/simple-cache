<?php
declare(strict_types=1);

namespace sdkNarkos\SimpleCache\Protocol;

use JsonSerializable;
use InvalidArgumentException;

final class ResponseMessage implements JsonSerializable {
    private ?string $error;
    private mixed $results;

    private function __construct( ?string $error, mixed $results) {
        $this->error = $error;
        $this->results = $results;
    }

    public static function createError(string $error): self {
        return new self(
            $error,
            null
        );
    }

    public static function createResults(mixed $results): self {
        return new self(
            null,
            $results
        );
    }

    public static function createFromJson(string $json): self {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new \InvalidArgumentException('Invalid JSON payload');
        }

        return new self(
            $data['error'] ?? null,
            $data['results'] ?? null
        );
    }

    public function jsonSerialize(): array {
        if($this->error != null) {
            return [
                'error' => $this->error
            ];
        } else {
            return [
                'results' => $this->results
            ];
        }
    }

    public function toJson(): string {
        return json_encode($this);
    }

    // === Getters ===
    public function getError(): ?string {
        return $this->error;
    }

    public function getResults(): mixed {
        return $this->results;
    }
}