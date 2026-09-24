<?php

declare(strict_types=1);

namespace InfoPlusCommerce\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1751447251 extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1751447251;
    }

    public function update(Connection $connection): void
    {
        $schemaManager = $connection->createSchemaManager();
        $columns = $schemaManager->listTableColumns('infoplus_order_sync');
        $indexes = $schemaManager->listTableIndexes('infoplus_order_sync');

        $needsAlter = false;
        if (isset($columns['id'])) {
            $idColumn = $columns['id'];
            if (strtolower(\Doctrine\DBAL\Types\Type::lookupName($idColumn->getType())) !== 'binary' || $idColumn->getLength() !== 16) {
                $needsAlter = true;
            }
        } else {
            return;
        }

        $hasPrimary = false;
        if (isset($indexes['primary'])) {
            $hasPrimary = $indexes['primary']->isPrimary();
        }
        if (!$hasPrimary) {
            $needsAlter = true;
        }

        if ($needsAlter) {
            $connection->executeStatement('
                ALTER TABLE `infoplus_order_sync`
                MODIFY COLUMN `id` BINARY(16) NOT NULL,
                DROP PRIMARY KEY,
                ADD PRIMARY KEY (`id`)
            ');
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        $rows = $connection->fetchAllAssociative('SELECT id FROM infoplus_order_sync');
        foreach ($rows as $row) {
            $newUuid = $connection->fetchOne('SELECT UUID_TO_BIN(UUID())');
            $connection->executeStatement(
                'UPDATE infoplus_order_sync SET id = :newUuid WHERE id = :oldId',
                ['newUuid' => $newUuid, 'oldId' => $row['id']]
            );
        }
    }
}
