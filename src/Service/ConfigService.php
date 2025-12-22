<?php

declare(strict_types=1);

namespace InfoPlusCommerce\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class ConfigService
{
    private const CONFIG_DOMAIN = 'InfoPlusCommerce.config.';


    private const DEFAULT_SYNCABLE_PAYMENT_STATES = ['open', 'paid', 'authorized'];

    public function __construct(private readonly SystemConfigService $systemConfigService)
    {
    }

    public function get(string $key): mixed
    {
        return $this->systemConfigService->get(self::CONFIG_DOMAIN . $key);
    }

    public function set(string $key, mixed $value): void
    {
        $this->systemConfigService->set(self::CONFIG_DOMAIN . $key, $value);
    }

    /**
     * Returns the default carrier id; supports legacy key 'carrierId' for backward compatibility.
     */
    public function getDefaultCarrierId(): mixed
    {
        return $this->get('carrierId');
    }

    /**
     * @return array<string,mixed>
     */
    public function getAll(): array
    {
        return [
            'apiKey' => $this->get('apiKey'),
            'baseDomain' => rtrim((string)$this->get('baseDomain'), '/'),
            'lobId' => $this->get('lobId'),
            'warehouseId' => $this->get('warehouseId'),
            'carrierId' => $this->get('carrierId'),
            'syncProducts' => (bool)$this->get('syncProducts'),
            'syncCategories' => (bool)$this->get('syncCategories'),
            'syncCustomers' => (bool)$this->get('syncCustomers'),
            'syncInventory' => (bool)$this->get('syncInventory'),
            'syncOrders' => (bool)$this->get('syncOrders'),
            'maxRetryAttempts' => (int)$this->get('maxRetryAttempts') ?: 3,
            'syncInProgress' => (bool)$this->get('syncInProgress'),
            'lastSyncTime' => $this->get('lastSyncTime') ?: null,
        ];
    }

    /**
     * @return list<string>
     */
    public function getSyncablePaymentStates(): array
    {
        $value = $this->get('syncablePaymentStates');

        if (!\is_array($value)) {
            return self::DEFAULT_SYNCABLE_PAYMENT_STATES;
        }

        $states = array_values(array_filter(array_map(static function ($v): ?string {
            if (!\is_string($v)) {
                return null;
            }
            $v = trim($v);
            return $v !== '' ? $v : null;
        }, $value)));

        return $states !== [] ? $states : self::DEFAULT_SYNCABLE_PAYMENT_STATES;
    }
}
