<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1800000001CreateSearchLogTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1800000001;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `tdbs_search_log` (
                `id` BINARY(16) NOT NULL,
                `term` VARCHAR(255) NOT NULL,
                `profile` VARCHAR(100) NOT NULL,
                `sales_channel_id` BINARY(16) NULL,
                `hits_count` INT NOT NULL,
                `execution_time_ms` INT NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                INDEX `idx.tdbs_search_log.term` (`term`),
                INDEX `idx.tdbs_search_log.profile` (`profile`),
                INDEX `idx.tdbs_search_log.sales_channel_id` (`sales_channel_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
