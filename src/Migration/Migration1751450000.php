<?php

namespace InfoPlusCommerce\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1751450000 extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1751450000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            ALTER TABLE `infoplus_field_definition`
            ADD COLUMN `static_price` DECIMAL(10,2) NULL DEFAULT NULL AFTER `show_in_storefront`
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
        // no destructive changes
    }
}
