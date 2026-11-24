<?php

declare(strict_types=1);

namespace OCA\Assinafy\Tests\Unit;

use OCA\Assinafy\Exception\AssinafyException;
use OCA\Assinafy\Service\AssinafyClient;
use OCA\Assinafy\Service\OAuthService;
use OCA\Assinafy\Tests\ServiceTestClient;
use OCA\Assinafy\Tests\ServiceTestClientService;
use OCA\Assinafy\Tests\ServiceTestTimeFactory;
use OCP\Files\File;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function OCA\Assinafy\Tests\runServiceChecks;
use function OCA\Assinafy\Tests\serviceTestApiResponse;
use function OCA\Assinafy\Tests\serviceTestOAuth;
use function OCA\Assinafy\Tests\serviceTestSession;

require_once dirname(__DIR__) . '/ServiceChecks.php';

final class ServiceTest extends TestCase
{
    #[Test]
    public function uploadUsesTheAbsoluteApiUrlAndClosesItsStream(): void
    {
        $stream = fopen('php://temp', 'w+');
        $file = $this->createStub(File::class);
        $file->method('getName')->willReturn('example.pdf');
        $file->method('fopen')->willReturn($stream);
        $client = new ServiceTestClient([serviceTestApiResponse(['id' => 'document-test', 'name' => 'example.pdf'])]);
        $clients = new ServiceTestClientService($client);
        [$oauth] = serviceTestOAuth($clients, serviceTestSession());
        $api = new AssinafyClient($oauth, $clients, new ServiceTestTimeFactory());

        self::assertSame('document-test', $api->prepare($file, 'example')['id']);
        self::assertSame(OAuthService::API_BASE . '/accounts/account-test/documents', $client->requests[0][1]);
        self::assertSame('file', $client->requests[0][2]['multipart'][0]['name']);
        self::assertFalse($client->requests[0][2]['allow_redirects']);
        self::assertFalse(is_resource($stream));
    }

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
