<?php

declare(strict_types=1);

namespace InfoPlusCommerce\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1751447250 extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1751447250;
    }

    public function update(Connection $connection): void
    {
        $schemaManager = $connection->createSchemaManager();
        $columns = $schemaManager->listTableColumns('infoplus_id_mapping');
        $indexes = $schemaManager->listTableIndexes('infoplus_id_mapping');

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
                ALTER TABLE `infoplus_id_mapping`
                MODIFY COLUMN `id` BINARY(16) NOT NULL,
                DROP PRIMARY KEY,
                ADD PRIMARY KEY (`id`)
            ');
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        $rows = $connection->fetchAllAssociative('SELECT id FROM infoplus_id_mapping');
        foreach ($rows as $row) {
            $newUuid = $connection->fetchOne('SELECT UUID_TO_BIN(UUID())');
            $connection->executeStatement(
                'UPDATE infoplus_id_mapping SET id = :newUuid WHERE id = :oldId',
                ['newUuid' => $newUuid, 'oldId' => $row['id']]
            );
        }
    }
}
