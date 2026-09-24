<?php

declare(strict_types=1);

namespace InfoPlusCommerce\Core\Content\InfoplusCategory;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class InfoplusCategoryEntity extends Entity
{
    use EntityIdTrait;


    protected int $internalId;
    protected string $name;
    protected string $idForInfoplus;
    protected bool $isSubCategory;

    public function getId(): string
    {
        return $this->id;
    }
    public function setId(string $id): void
    {
        $this->id = $id;
    }
    public function getInternalId(): int
    {
        return $this->internalId;
    }
    public function getIdForInfoplus(): string
    {
        return $this->idForInfoplus;
    }
    public function setIdForInfoplus(string $idForInfoplus): void
    {
        $this->idForInfoplus = $idForInfoplus;
    }

    public function setInternalId(int $internalId): void
    {
        $this->internalId = $internalId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getIsSubCategory(): bool
    {
        return $this->isSubCategory;
    }

    public function setIsSubCategory(bool $isSubCategory): void
    {
        $this->isSubCategory = $isSubCategory;
    }
}
