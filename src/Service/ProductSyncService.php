<?php

declare(strict_types=1);

namespace InfoPlusCommerce\Service;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use InfoPlusCommerce\Client\InfoplusApiClient;
use InfoPlusCommerce\Core\Content\InfoplusCategory\InfoplusCategoryEntity;
use InfoPlusCommerce\Core\Content\InfoplusCategory\InfoplusCategoryCollection;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Contracts\Translation\TranslatorInterface;
use Psr\Log\LoggerInterface;

class ProductSyncService
{
    /**
     * @param EntityRepository<ProductCollection> $productRepository
     * @param EntityRepository<InfoplusCategoryCollection> $infoplusCategoryRepository
     */
    public function __construct(
        private readonly ConfigService $configService,
        private readonly InfoplusApiClient $infoplusApiClient,
        private readonly LoggerInterface $logger,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $infoplusCategoryRepository,
        private readonly IdMappingService $idMappingService,
        private readonly TranslatorInterface $translator,
        private readonly InventorySyncService $inventorySyncService,
    ) {
    }

    /**
     * @param array<int|string>|null $ids
     * @return array<mixed>
     */
    public function syncProducts(Context $context, ?array $ids = null): array
    {
        if (!($this->configService->get('syncOrders') || $this->configService->get('syncProducts'))) {
            $this->logger->info('[InfoPlus] Product sync is disabled in configuration');
            return ['status' => 'error', 'error' => $this->translator->trans('infoplus.service.errors.productSyncDisabled')];
        }
        $this->logger->info('[InfoPlus] Sync products triggered', ['ids' => $ids ?? 'all']);
        $criteria = new Criteria($ids === null ? null : array_map('strval', $ids));
        $criteria->addAssociation('price');
        $products = $this->productRepository->search($criteria, $context)->getEntities();

        if ($products->count() === 0) {
            $this->logger->warning('[InfoPlus] No products found for sync', ['ids' => $ids ?? 'all']);
            return ['status' => 'error', 'error' => 'infoplus.service.errors.noProductsFound'];
        }

        $lobId = $this->configService->get('lobId');

        $results = [];
        $productIds = [];
        $majorGroupIds = [];
        $subGroupIds = [];
        /** @var ProductEntity $product */
        foreach ($products as $product) {
            $productIds[] = $product->getId();
            $customFields = $product->getCustomFields() ?: [];
            $majorGroupId = $customFields['infoplus_major_group_id'] ?? null;
            $subGroupId = $customFields['infoplus_sub_group_id'] ?? null;
            if ($majorGroupId) {
                $majorGroupIds[] = $majorGroupId;
            }
            if ($subGroupId) {
                $subGroupIds[] = $subGroupId;
            }
        }
        // Batch fetch id mappings
        $idMappings = $this->idMappingService->getInfoplusIdsForProducts('item', $productIds, $context);
        // Batch fetch categories
        $allGroupIds = array_unique(array_merge($majorGroupIds, $subGroupIds));
        $categoryCriteria = new Criteria();
        $categoryCriteria->addFilter(new OrFilter(array_map(fn($id) => new EqualsFilter('internalId', $id), $allGroupIds)));
        /** @var InfoplusCategoryCollection|InfoplusCategoryEntity[] $categories */
        $categories = $this->infoplusCategoryRepository->search($categoryCriteria, $context)->getEntities();
        $categoriesByInternalId = [];
        foreach ($categories as $cat) {
            $categoriesByInternalId[$cat->getInternalId()] = $cat;
        }
        $productUpdates = [];
        $idMappingDeletes = [];
        $idMappingCreates = [];
        $idMappingUpdates = [];
        /** @var ProductEntity $product */
        foreach ($products as $product) {
            $sku = $product->getProductNumber();
            $itemDescription = $product->getName() ?: 'Default Product Description';
            $infoplusId = $idMappings[$product->getId()] ?? null;
            $existingItems = $this->infoplusApiClient->getBySKU((int)$lobId, $sku);

            if ($existingItems === null) {
                $this->logger->error('[InfoPlus] Failed to fetch item by SKU', ['sku' => $sku, 'error' => $existingItems]);
                $results[] = [
                    'sku' => $sku,
                    'success' => false,
                    'error' => $this->translator->trans('infoplus.service.errors.failedToFetchItemBySku')
                ];
                continue;
            }

            if ($existingItems && isset($existingItems['id'])) {
                if ($infoplusId && $infoplusId !== $existingItems['id']) {
                    $this->idMappingService->deleteInfoplusId('item', $product->getId(), $context);
                    $infoplusId = $existingItems['id'];
                    $this->idMappingService->createInfoplusId('item', $product->getId(), $context, $infoplusId);
                } else {
                    $infoplusId = $existingItems['id'];
                }
            }

            $customFields = $product->getCustomFields() ?: [];
            $majorGroupId = $customFields['infoplus_major_group_id'] ?? null;
            $subGroupId = $customFields['infoplus_sub_group_id'] ?? null;
            if (empty($majorGroupId) || empty($subGroupId)) {
                if ($existingItems) {
                    $majorGroupId = $existingItems['majorGroupId'] ?? null;
                    $subGroupId = $existingItems['subGroupId'] ?? null;
                    if ($majorGroupId && $subGroupId) {
                        $productUpdates[] = [
                            'id' => $product->getId(),
                            'customFields' => [
                                'infoplus_major_group_id' => $majorGroupId,
                                'infoplus_sub_group_id' => $subGroupId
                            ]
                        ];
                    }
                } else {
                    $this->logger->error('[InfoPlus] Missing category or subcategory for product', [
                        'sku' => $sku,
                        'majorGroupId' => $majorGroupId,
                        'subGroupId' => $subGroupId
                    ]);
                    $results[] = [
                        'sku' => $sku,
                        'success' => false,
                        'error' => 'Missing category or subcategory for SKU: ' . $sku
                    ];
                    continue;
                }
            }
            $category = $categoriesByInternalId[$majorGroupId] ?? null;
            if (!$category) {
                $this->logger->error('[InfoPlus] Major group not found in infoplus_category', [
                    'sku' => $sku,
                    'majorGroupId' => $majorGroupId
                ]);
                $results[] = [
                    'sku' => $sku,
                    'success' => false,
                    'error' => 'Major group not found for internalId: ' . $majorGroupId
                ];
                continue;
            }
            $subCategory = $categoriesByInternalId[$subGroupId] ?? null;
            if (!$subCategory) {
                $this->logger->error('[InfoPlus] Subgroup not found in infoplus_category', [
                    'sku' => $sku,
                    'subGroupId' => $subGroupId
                ]);
                $results[] = [
                    'sku' => $sku,
                    'success' => false,
                    'error' => 'Subgroup not found for internalId: ' . $subGroupId
                ];
                continue;
            }

            $receivingCriteriaSchemeId = 1;
            $data = [
                'id' => $infoplusId,
                'lobId' => $lobId,
                'sku' => $sku,
                'itemDescription' => $itemDescription,
                'majorGroupId' => (int)$majorGroupId,
                'subGroupId' => (int)$subGroupId,
                'upc' => $product->getEan() ?? '',
                'length' => $product->getLength() ?? 1.0,
                'width' => $product->getWidth() ?? 1.0,
                'height' => $product->getHeight() ?? 1.0,
                'backorder' => 'Yes',
                'chargeCode' => 'Not Chargeable',
                'status' => 'Active',
                'seasonalItem' => 'No',
                'secure' => 'No',
                'unitCode' => 'PKG',
                'sellPrice' => $product->getPrice()->first()?->getGross() ?? 0.0,
                'forwardLotMixingRule' => 'SKU',
                'storageLotMixingRule' => 'SKU',
                'forwardItemMixingRule' => 'Single',
                'storageItemMixingRule' => 'Single',
                'allocationRule' => 'Labor Optimized',
                'maxCycle' => 0,
                'maxInterim' => 0,
                'receivingCriteriaSchemeId' => $receivingCriteriaSchemeId,
                'hazmat' => 'No',
                'customFields' => (object)[]
            ];

            try {
                if ($infoplusId && !empty($existingItems)) {
                    $result = $this->infoplusApiClient->updateItem($data);
                    if (is_array($result) && isset($result['id'])) {
                        $idMappingUpdates[] = ['type' => 'item', 'shopwareId' => $product->getId()];
                        $results[] = [
                            'sku' => $sku,
                            'success' => true
                        ];
                    } else {
                        $results[] = [
                            'sku' => $sku,
                            'success' => false,
                            'error' => 'Failed to update item for SKU: ' . $sku
                        ];
                    }
                } else {
                    unset($data['id']);
                    $result = $this->infoplusApiClient->createItem($data);
                    if (is_array($result) && isset($result['id'])) {
                        $this->idMappingService->createInfoplusId('item', $product->getId(), $context, $result['id']);
                        $results[] = [
                            'sku' => $sku,
                            'success' => true,
                            'message' => 'Product created successfully',
                            'inventrorySync' => $this->inventorySyncService->syncInventory($context, [$product->getId()])
                        ];
                    } else {
                        $this->logger->error('[InfoPlus] Failed to create product', [
                            'sku' => $sku,
                            'error' => $result
                        ]);
                        $results[] = [
                            'sku' => $sku,
                            'success' => false,
                            'error' => $result
                        ];
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error('[InfoPlus] Exception during product sync', [
                    'sku' => $sku,
                    'error' => $e->getMessage()
                ]);
                $results[] = [
                    'sku' => $sku,
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }
        if (!empty($productUpdates)) {
            $this->productRepository->update($productUpdates, $context);
        }
        foreach ($idMappingUpdates as $update) {
            $this->idMappingService->updateInfoplusUpdatedAt($update['type'], $update['shopwareId'], $context);
        }

        $this->logger->info('[InfoPlus] Completed product sync', ['results' => $results]);
        return $results;
    }
}
