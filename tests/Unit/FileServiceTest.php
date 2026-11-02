<?php

declare(strict_types=1);

namespace OCA\Assinafy\Tests\Unit;

use OCA\Assinafy\Exception\AssinafyException;
use OCA\Assinafy\Service\FileService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class FileServiceTest extends TestCase
{
    #[Test]
    public function missingFolderUsesThePublicNotFoundError(): void
    {
        try {
            $this->service(new NotFoundException())->list('missing');
            self::fail('Missing folder was accepted.');
        } catch (AssinafyException $error) {
            self::assertSame('folder_not_found', $error->errorCode());
            self::assertSame(404, $error->httpStatus());
        }
    }

    #[Test]
    public function parentTraversalIsAMissingFolderNotAServerError(): void
    {
        try {
            $this->service(new NotPermittedException('Invalid path'))->list('Documents/../..');
            self::fail('Parent traversal was accepted.');
        } catch (AssinafyException $error) {
            self::assertSame('folder_not_found', $error->errorCode());
            self::assertSame(404, $error->httpStatus());
        }
    }

    #[Test]
    public function storageFailuresRemainOperationalErrors(): void
    {
        $failure = new RuntimeException('Synthetic storage failure.');
        $this->expectExceptionObject($failure);
        $this->service($failure)->list('contracts');
    }

    private function service(Throwable $failure): FileService
    {
        $folder = $this->createStub(Folder::class);
        $folder->method('get')->willThrowException($failure);
        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($folder);
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn('nextcloud-user');
        $session = $this->createStub(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        return new FileService($root, $session);
    }
}
