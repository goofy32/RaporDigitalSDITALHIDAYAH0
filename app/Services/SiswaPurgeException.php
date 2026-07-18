<?php

namespace App\Services;

use RuntimeException;

class SiswaPurgeException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly array $context = []
    ) {
        parent::__construct($message);
    }

    public function context(): array
    {
        return $this->context;
    }
}
