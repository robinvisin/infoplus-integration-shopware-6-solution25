<?php

declare(strict_types=1);

namespace InfoPlusCommerce\Core\Content\OrderSync;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class OrderSyncEntity extends Entity
{
    use EntityIdTrait;

    protected string $shopwareOrderId;
    protected int $infoplusId;
    protected \DateTimeInterface $syncDate;
    protected ?string $orderPaymentStatus;
    protected ?string $orderShippingStatus;
    protected ?string $orderStatus;
    public function getId(): string
    {
        return $this->id;
    }

    public function getShopwareOrderId(): string
    {
        return $this->shopwareOrderId;
    }

    public function setShopwareOrderId(string $shopwareOrderId): void
    {
        $this->shopwareOrderId = $shopwareOrderId;
    }

    public function getInfoplusId(): int
    {
        return $this->infoplusId;
    }

    public function setInfoplusId(int $infoplusId): void
    {
        $this->infoplusId = $infoplusId;
    }

    public function getSyncDate(): \DateTimeInterface
    {
        return $this->syncDate;
    }

    public function setSyncDate(\DateTimeInterface $syncDate): void
    {
        $this->syncDate = $syncDate;
    }

    public function getOrderPaymentStatus(): ?string
    {
        return $this->orderPaymentStatus;
    }

    public function setOrderPaymentStatus(?string $orderPaymentStatus): void
    {
        $this->orderPaymentStatus = $orderPaymentStatus;
    }

    public function getOrderShippingStatus(): ?string
    {
        return $this->orderShippingStatus;
    }

    public function setOrderShippingStatus(?string $orderShippingStatus): void
    {
        $this->orderShippingStatus = $orderShippingStatus;
    }

    public function getOrderStatus(): ?string
    {
        return $this->orderStatus;
    }

    public function setOrderStatus(?string $orderStatus): void
    {
        $this->orderStatus = $orderStatus;
    }
}
