<?php

declare(strict_types=1);

namespace InfoPlusCommerce\Service;

use InfoPlusCommerce\Client\InfoplusApiClient;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Contracts\Translation\TranslatorInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\ProductCollection;

class InventorySyncService
{
    /**
     * @param EntityRepository<ProductCollection> $productRepository
     */
    public function __construct(
        private readonly ConfigService $configService,
        private readonly InfoplusApiClient $infoplusApiClient,
        private readonly LoggerInterface $logger,
        private readonly EntityRepository $productRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param Context $context
     * @param array<int|string>|null $ids
     * @return array<mixed>
     */
    public function syncInventory(Context $context, ?array $ids = null): array
    {
        if (!($this->configService->get('syncProducts') || $this->configService->get('syncInventory'))) {
            $this->logger->info('[InfoPlus] Inventory sync is disabled in configuration');
            return ['status' => 'error', 'error' => $this->translator->trans('infoplus.service.errors.inventorySyncDisabled')];
        }
        $criteria = new Criteria($ids === null ? null : array_map('strval', $ids));
        $products = $this->productRepository->search($criteria, $context)->getEntities();
        if ($products->count() === 0) {
            $this->logger->info('[InfoPlus] No active products found, skipping inventory sync.');
            return ['status' => 'error', 'error' => $this->translator->trans('infoplus.service.errors.noProductsFoundForInventory')];
        }
        $skus = [];
        foreach ($products as $product) {
            /** @var ProductEntity $product */
            if ($product->getProductNumber()) {
                $skus[] = $product->getProductNumber();
            }
        }
        if (empty($skus)) {
            $this->logger->info('[InfoPlus] No SKUs found for products, skipping inventory sync.');
            return ['status' => 'error', 'error' => $this->translator->trans('infoplus.service.errors.noSkusFoundForInventory')];
        }
        $lobId = $this->configService->get('lobId');
        $query = ['filter' => 'sku in (\'' . implode('\',\'', $skus) . '\')'];
        $infoPlusInventory = $this->infoplusApiClient->searchItems($query);
        $returnArray = [];
        foreach ($infoPlusInventory as $infoPlusItem) {
            $returnArray[] = $this->processProduct($infoPlusItem, $context);
        }
        return $returnArray;
    }

    /**
     * @param array<string,mixed> $infoPlusItem
     * @param Context $context
     * @return array<mixed>
     */
    private function processProduct(array $infoPlusItem, Context $context): array
    {
        $infoPlusProductId = $infoPlusItem['id'] ?? null;
        $newStock = $infoPlusItem['availableQuantity'] ?? 0;
        $sku = $infoPlusItem['sku'] ?? null;
        if (!$infoPlusProductId || !$sku) {
            $this->logger->warning('[InfoPlus] Invalid InfoPlus product data: ID or SKU missing', ['item' => $infoPlusItem]);
            return [
                'success' => false,
                'sku' => $sku,
                'message' => 'No stock change',
                'error' => 'Invalid InfoPlus product data: ID or SKU missing'
            ];
        }
        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('productNumber', $sku));
            $product = $this->productRepository->search($criteria, $context)->getEntities()->first();
            /** @var ProductEntity|null $product */
            if (!$product) {
                $this->logger->warning('[InfoPlus] No Shopware product found with SKU ' . $sku);
                return [
                    'success' => false,
                    'sku' => $sku,
                    'message' => 'No stock change',
                    'error' => 'No Shopware product found with SKU ' . $sku
                ];
            }
            $currentStock = $product->getStock();
            if ($currentStock !== $newStock) {
                $this->productRepository->update([
                    [
                        'id' => $product->getId(),
                        'stock' => $newStock,
                    ],
                ], $context);
                $this->logger->info('[InfoPlus] Updated stock for product ' . $sku . ' to ' . $newStock);
                return [
                    'success' => true,
                    'sku' => $sku,
                    'message' => 'Stock updated from ' . $currentStock . ' to ' . $newStock,
                    'error' => null
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error('[InfoPlus] Failed to update product SKU: ' . $sku . ': ' . $e->getMessage());
            return [
                'success' => false,
                'sku' => $sku,
                'message' => 'No stock change',
                'error' => 'Failed to update product SKU: ' . $sku . ': ' . $e->getMessage()
            ];
        }
        return [
            'success' => true,
            'sku' => $sku,
            'message' => 'No stock change',
            'error' => null
        ];
    }
}
