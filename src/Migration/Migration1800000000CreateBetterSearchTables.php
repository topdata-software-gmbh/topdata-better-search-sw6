<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1800000000CreateBetterSearchTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1800000000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `tdbs_zero_search` (
                `id` BINARY(16) NOT NULL,
                `term` VARCHAR(255) NOT NULL,
                `count` INT NOT NULL DEFAULT 1,
                `created_at` DATETIME(3) NOT NULL,
                `last_searched_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.tdbs_zero_search.term` (`term`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `tdbs_synonym` (
                `id` BINARY(16) NOT NULL,
                `term` VARCHAR(255) NOT NULL,
                `synonyms` TEXT NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.tdbs_synonym.term` (`term`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        $this->migrateOldTableData($connection, 'topdata_es_zero_search', 'tdbs_zero_search');
        $this->migrateOldTableData($connection, 'topdata_es_synonym', 'tdbs_synonym');
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function migrateOldTableData(Connection $connection, string $oldTable, string $newTable): void
    {
        try {
            $schemaManager = $connection->getSchemaManager();
            if ($schemaManager->tablesExist([$oldTable])) {
                $connection->executeStatement(sprintf(
                    'INSERT IGNORE INTO `%s` SELECT * FROM `%s`',
                    $newTable,
                    $oldTable
                ));
            }
        } catch (\Throwable $e) {
        }
    }
}
