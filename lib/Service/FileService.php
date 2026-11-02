<?php

declare(strict_types=1);

namespace OCA\Assinafy\Service;

use OCA\Assinafy\Exception\AssinafyException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IUserSession;

final class FileService
{
    public const MAX_BYTES = 25 * 1024 * 1024;

    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly IUserSession $userSession,
    ) {
    }

    /** @return list<array{id: int, name: string, path: string, type: 'file'|'folder', size: int|float, mime: string}> */
    public function list(string $path): array
    {
        $folder = $this->userFolder();
        $relativePath = trim($path, '/');
        // Nextcloud rejects parent traversal with NotPermittedException; it is invalid input, not a storage failure.
        if (in_array('..', explode('/', $relativePath), true)) {
            throw new AssinafyException('Folder not found.', 404, 'folder_not_found');
        }
        try {
            $node = $relativePath === '' ? $folder : $folder->get($relativePath);
        } catch (NotFoundException) {
            throw new AssinafyException('Folder not found.', 404, 'folder_not_found');
        }
        if (!$node instanceof Folder) {
            throw new AssinafyException('Folder not found.', 404, 'folder_not_found');
        }

        $nodes = array_values(array_filter(
            $node->getDirectoryListing(),
            static fn (Node $item): bool => $item instanceof Folder || $item instanceof File && self::isPdf($item),
        ));
        usort($nodes, static fn (Node $a, Node $b): int => [!$a instanceof Folder, mb_strtolower($a->getName())] <=> [!$b instanceof Folder, mb_strtolower($b->getName())]);

        return array_map(static fn (Node $item): array => [
            'id' => $item->getId(),
            'name' => $item->getName(),
            'path' => ltrim($relativePath . '/' . $item->getName(), '/'),
            'type' => $item instanceof Folder ? 'folder' : 'file',
            'size' => $item instanceof File ? $item->getSize() : 0,
            'mime' => $item->getMimetype(),
        ], $nodes);
    }

    public function pdf(int $fileId): File
    {
        $nodes = $this->userFolder()->getById($fileId);
        if (count($nodes) !== 1 || !$nodes[0] instanceof File || !self::isPdf($nodes[0])) {
            throw new AssinafyException('PDF file not found.', 404, 'file_not_found');
        }
        if ($nodes[0]->getSize() <= 0 || $nodes[0]->getSize() > self::MAX_BYTES) {
            throw new AssinafyException('The PDF must be between 1 byte and 25 MB.', 400, 'invalid_file_size');
        }

        return $nodes[0];
    }

    private function userFolder(): Folder
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new AssinafyException('A Nextcloud login is required.', 401, 'not_authenticated');
        }
        return $this->rootFolder->getUserFolder($user->getUID());
    }

    private static function isPdf(File $file): bool
    {
        return $file->getMimetype() === 'application/pdf' || str_ends_with(mb_strtolower($file->getName()), '.pdf');
    }
}
