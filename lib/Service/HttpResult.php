<?php

declare(strict_types=1);

namespace OCA\Assinafy\Service;

final class HttpResult
{
    /** @param array<string, mixed>|list<mixed>|null $data */
    public function __construct(
        public readonly int $status,
        public readonly array | null $data,
    ) {
    }
}
