<?php

declare(strict_types=1);

namespace OCA\Assinafy\Controller;

use Throwable;

/** Records where an unexpected failure happened, without its message, which can carry request data. */
trait LogsUnexpectedFailures
{
    private function logUnexpected(string $message, Throwable $failure): void
    {
        $this->logger->error($message, [
            'exceptionClass' => $failure::class,
            'exceptionFile' => $failure->getFile(),
            'exceptionLine' => $failure->getLine(),
        ]);
    }
}
