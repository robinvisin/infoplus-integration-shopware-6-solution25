<?php

declare(strict_types=1);

namespace InfoPlusCommerce\Core\Content\IdMapping;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class IdMappingEntity extends Entity
{
    use EntityIdTrait;

    protected string $entityType;
    protected string $shopwareId;
    protected int $infoplusId;

    public function getId(): string
    {
        return $this->id;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function setEntityType(string $entityType): void
    {
        $this->entityType = $entityType;
    }

    public function getShopwareId(): string
    {
        return $this->shopwareId;
    }

    public function setShopwareId(string $shopwareId): void
    {
        $this->shopwareId = $shopwareId;
    }

    public function getInfoplusId(): int
    {
        return $this->infoplusId;
    }

    public function setInfoplusId(int $infoplusId): void
    {
        $this->infoplusId = $infoplusId;
    }
}
