<?php

declare(strict_types=1);

namespace OCA\Assinafy\Exception;

use RuntimeException;

final class AssinafyException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 400,
        private readonly string $errorCode = 'assinafy_error',
    ) {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
