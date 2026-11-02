<?php

declare(strict_types=1);

namespace OCA\Assinafy\Migration;

use OCA\Assinafy\AppInfo\Application;
use OCA\Assinafy\Service\SessionStore;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Re-encrypts OAuth state written by earlier versions. It runs during the app upgrade, while Nextcloud is in
 * maintenance mode, so no request can refresh the same tokens concurrently.
 */
final class MigrateOAuthStorage implements IRepairStep
{
    public function __construct(
        private readonly IDBConnection $connection,
        private readonly SessionStore $store,
    ) {
    }

    public function getName(): string
    {
        return 'Encrypt stored Assinafy OAuth state';
    }

    public function run(IOutput $output): void
    {
        $query = $this->connection->getQueryBuilder();
        $result = $query->selectDistinct('userid')
            ->from('preferences')
            ->where($query->expr()->eq('appid', $query->createNamedParameter(Application::APP_ID)))
            ->executeQuery();
        while (($userId = $result->fetchOne()) !== false) {
            $this->store->migrate((string) $userId);
            $this->store->reload((string) $userId);
        }
        $result->closeCursor();
    }
}
