<?php

namespace HiEvents\Services\Infrastructure\Fastn\Exception;

use RuntimeException;

class FastnRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    public function isCustomerNotFound(): bool
    {
        return $this->status === 404;
    }
}
