<?php

declare(strict_types=1);

namespace OCA\Assinafy\Tests\Unit;

use OCA\Assinafy\Exception\AssinafyException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function OCA\Assinafy\Tests\runServiceChecks;

require_once dirname(__DIR__) . '/ServiceChecks.php';

final class ServiceTest extends TestCase
{
    #[Test]
    public function serviceContractsAndCompatibilityPaths(): void
    {
        runServiceChecks(
            static function (bool $condition, string $message): void {
                self::assertTrue($condition, $message);
            },
            static function (callable $operation, string $message): void {
                try {
                    $operation();
                } catch (AssinafyException) {
                    return;
                }
                throw new RuntimeException($message);
            },
        );
    }
}
