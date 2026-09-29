<?php

declare(strict_types=1);

namespace InfoPlusCommerce\Service;

use InfoPlusCommerce\Client\InfoplusApiClient;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Psr\Log\LoggerInterface;
use InfoPlusCommerce\Core\Content\InfoplusCategory\InfoplusCategoryEntity;
use InfoPlusCommerce\Core\Content\InfoplusCategory\InfoplusCategoryCollection;

class CategorySyncService
{
    /**
     * @param EntityRepository<InfoplusCategoryCollection> $infoplusCategoryRepository
     */
    public function __construct(
        private readonly ConfigService $configService,
        private readonly InfoplusApiClient $infoplusApiClient,
        private readonly LoggerInterface $logger,
        private readonly EntityRepository $infoplusCategoryRepository,
        private readonly TranslatorInterface $translator
    ) {
    }

    /**
     * @param Context $context
     * @param array<int|string>|null $ids
     * @return array<mixed>
     */
    public function syncCategories(Context $context, ?array $ids = null): array
    {
        if (!$this->configService->get('syncCategories')) {
            $this->logger->info('[InfoPlus] Category sync is disabled in configuration');
            return ['status' => 'error', 'error' => $this->translator->trans('infoplus.service.errors.categorySyncDisabled')];
        }
        $this->logger->info('[InfoPlus] Sync categories triggered', ['ids' => $ids ?? 'all']);
        $criteria = new Criteria($ids === null ? null : array_map('strval', $ids));
        $categories = $this->infoplusCategoryRepository->search($criteria, $context)->getEntities();

        $itemCategories = $this->infoplusApiClient->getItemCategories();
        $itemSubCategories = $this->infoplusApiClient->getItemSubCategories();
        //get existing categories from InfoPlus if not already fetched
        if ($categories->count() === 0) {
            $categoriesToCreate = array_map(function ($category) {
                // Cast, like getMaxCategoryId() already does further down this file.
                // idForInfoplus is a DAL StringField; if InfoPlus returns `id` as a JSON
                // number the whole category sync aborts with
                // "[/0/idForInfoplus] This value should be of type string".
                $category['idForInfoplus'] = isset($category['id']) ? (string) $category['id'] : null;
                unset($category['id']);
                unset($category['lobId']);
                unset($category['customFields']);
                $category['id'] = Uuid::randomHex();
                $category['isSubCategory'] = false;
                return $category;
            }, $itemCategories);
            if (count($categoriesToCreate) > 0) {
                $this->infoplusCategoryRepository->create($categoriesToCreate, $context);
            }
            $subCategoriesToCreate = array_map(function ($subCategory) {
                $subCategory['idForInfoplus'] = isset($subCategory['id']) ? (string) $subCategory['id'] : null;
                unset($subCategory['id']);
                unset($subCategory['lobId']);
                unset($subCategory['customFields']);
                $subCategory['id'] = Uuid::randomHex();
                $subCategory['isSubCategory'] = true;
                return $subCategory;
            }, $itemSubCategories);
            if (count($subCategoriesToCreate) > 0) {
                $this->infoplusCategoryRepository->create($subCategoriesToCreate, $context);
            }
            // Refresh categories after creation
            $categories = $this->infoplusCategoryRepository->search($criteria, $context)->getEntities();
        }
        if ($categories->count() === 0) {
            $this->logger->warning('[InfoPlus] No categories found for sync', ['ids' => $ids ?? 'all']);
            return ['status' => 'error', 'error' => $this->translator->trans('infoplus.service.errors.noCategoriesFound')];
        }
        $lobId = $this->configService->get('lobId');
        $results = [];
        $updates = [];
        foreach ($categories as $category) {
            /** @var InfoplusCategoryEntity $category */
            try {
                $entityType = $category->getIsSubCategory() ? 'itemSubCategory' : 'itemCategory';
                $data = [
                    'lobId' => $lobId,
                    'id' => $category->getIdForInfoplus(),
                    'name' => $category->getName() ?: ($entityType === 'itemCategory' ? 'Default Category' : 'Default Subcategory'),
                ];
                // Fix for undefined $existingEntity: set to null or refactor logic
                $data['internalId'] = null;
                $method = $entityType === 'itemCategory' ? 'updateItemCategory' : 'updateItemSubCategory';
                $result = $this->infoplusApiClient->$method($data);
                if (is_array($result) && isset($result['internalId'])) {
                    unset($data['lobId']);
                    $data['internalId'] = (int)$result['internalId'];
                    $data['id'] = $category->getIdForInfoplus();
                    $data['idForInfoplus'] = $category->getIdForInfoplus();
                    $data['isSubCategory'] = $category->getIsSubCategory();
                    $updates[] = $data;
                }

                $results[] = [
                    'id' => $data['id'],
                    'name' => $data['name'],
                    'type' => $entityType,
                    'success' => is_array($result),
                    'error' => is_string($result) ? $result : null
                ];
                if (is_string($result)) {
                    $this->logger->error('[InfoPlus] Failed to update ' . $entityType, [
                        'name' => $data['name'],
                        'id' => $data['id'],
                        'internalId' => $data['internalId'],
                        'data' => $data,
                        'error' => $result
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->error('[InfoPlus] Exception during category sync', [
                    'name' => $category->getName(),
                    'id' => $category->getId(),
                    'isSubCategory' => $category->getIsSubCategory(),
                    'error' => $e->getMessage()
                ]);
                $results[] = [
                    'name' => $category->getName(),
                    'type' => $category->getIsSubCategory() ? 'itemSubCategory' : 'itemCategory',
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }
        if (!empty($updates)) {
            $this->infoplusCategoryRepository->update($updates, $context);
        }

        return $results;
    }

    /**
     * @param int $isSubCategory
     * @param Context $context
     * @return array<int,array<string,mixed>>
     */
    public function getAllCategories(int $isSubCategory, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('isSubCategory', $isSubCategory));
        $categories = $this->infoplusCategoryRepository->search($criteria, $context)->getEntities();
        $result = [];
        foreach ($categories as $category) {
            /** @var InfoplusCategoryEntity $category */
            $result[] = [
                'id' => $category->getId(),
                'name' => $category->getName(),
                'internalId' => $category->getInternalId(),
                'idForInfoplus' => $category->getIdForInfoplus(),
                'isSubCategory' => $category->getIsSubCategory()
            ];
        }
        return $result;
    }

    /**
     * @param string $name
     * @param bool $isSubCategory
     * @param Context $context
     * @return array<string,mixed>
     */
    public function createCategory(string $name, bool $isSubCategory, Context $context): array
    {
        $this->logger->info('[InfoPlus] Creating category', ['name' => $name, 'isSubCategory' => $isSubCategory]);

        $id = Uuid::randomHex();
        $itemCategories = $isSubCategory ?
            $this->infoplusApiClient->getItemSubCategories() :
            $this->infoplusApiClient->getItemCategories();

        $categoryData = [
            'id' => $id,
            'name' => $name,
            'isSubCategory' => $isSubCategory,
            'idForInfoplus' => (string)$this->getMaxCategoryId($itemCategories),
        ];
        // Sync with InfoPlus if enabled
        if ($this->configService->get('syncCategories')) {
            $lobId = $this->configService->get('lobId');
            $infoplusData = [
                'id' => $categoryData['idForInfoplus'],
                'lobId' => $lobId,
                'name' => $name,
            ];

            $method = $isSubCategory ? 'createItemSubCategory' : 'createItemCategory';
            $result = $this->infoplusApiClient->$method($infoplusData);

            if (is_array($result) && isset($result['id'])) {
                $categoryData['internalId'] = (int)$result['internalId'];
                $this->infoplusCategoryRepository->create([$categoryData], $context);
                return [ "success" => true, "message" => $this->translator->trans('infoplus.api.status.categoryCreated') ];
            } else {
                $this->logger->error('[InfoPlus] Failed to create category in InfoPlus', [
                    'name' => $name,
                    'isSubCategory' => $isSubCategory,
                    'error' => is_string($result) ? $result : 'Unknown error',
                ]);
                return [ "success" => false, "message" => is_string($result) ? $result : 'Unknown error' ];
            }
        }
        return [ "success" => false, "message" => $this->translator->trans('infoplus.service.errors.categorySyncDisabled') ];
    }

    /**
     * @param string $id
     * @param Context $context
     * @return array<string,mixed>|null
     */
    public function getCategoryById(string $id, Context $context): ?array
    {
        $this->logger->info('[InfoPlus] Fetching category by ID', ['id' => $id]);

        $criteria = new Criteria([$id]);
        $category = $this->infoplusCategoryRepository->search($criteria, $context)->getEntities()->first();
        /** @var InfoplusCategoryEntity|null $category */

        if (!$category) {
            $this->logger->warning('[InfoPlus] Category not found', ['id' => $id]);
            return null;
        }

        return [
            'id' => $category->getId(),
            'name' => $category->getName(),
            'internalId' => $category->getInternalId(),
            'idForInfoplus' => $category->getIdForInfoplus(),
            'isSubCategory' => $category->getIsSubCategory(),
        ];
    }

    /**
     * @param string $id
     * @param string $name
     * @param Context $context
     * @return array<string,mixed>
     */
    public function updateCategory(string $id, string $name, Context $context): array
    {
        $this->logger->info('[InfoPlus] Updating category', ['id' => $id, 'name' => $name]);

        $criteria = new Criteria([$id]);
        $category = $this->infoplusCategoryRepository->search($criteria, $context)->getEntities()->first();
        /** @var InfoplusCategoryEntity|null $category */

        if (!$category) {
            $this->logger->error('[InfoPlus] Category not found for update', ['id' => $id]);
            throw new \Exception('Category not found');
        }

        $categoryData = [
            'id' => $id,
            'name' => $name,
            'isSubCategory' => $category->getIsSubCategory(),
            'internalId' => $category->getInternalId(),
            'idForInfoplus' => $category->getIdForInfoplus(),
        ];
        // Sync with InfoPlus if enabled
        if ($this->configService->get('syncCategories') && $categoryData['idForInfoplus'] != null) {
            $lobId = $this->configService->get('lobId');
            $infoplusData = [
                'lobId' => $lobId,
                'id' => $categoryData['idForInfoplus'],
                'name' => $name,
                'internalId' => $categoryData['internalId'],
            ];

            $method = $category->getIsSubCategory() ? 'updateItemSubCategory' : 'updateItemCategory';
            $result = $this->infoplusApiClient->$method($infoplusData);

            if (is_string($result)) {
                $this->logger->error('[InfoPlus] Failed to update category in InfoPlus', [
                    'id' => $id,
                    'name' => $name,
                    'isSubCategory' => $category->getIsSubCategory(),
                    'error' => $result,
                ]);
                return [ "success" => false, "message" => $result ];
            } else {
                // Update category locally
                $this->infoplusCategoryRepository->update([$categoryData], $context);
                return [ "success" => true, "message" => $this->translator->trans('infoplus.api.status.categoryUpdated') ];
            }
        }

        return [ "success" => false, "message" => $this->translator->trans('infoplus.service.errors.categorySyncDisabled') ];
    }

    /**
     * @param string $id
     * @param Context $context
     * @return array<string,mixed>
     */
    public function deleteCategory(string $id, Context $context): array
    {
        $this->logger->info('[InfoPlus] Delete category triggered', ['id' => $id]);

        // Fetch category to get idForInfoplus and isSubCategory
        $category = $this->infoplusCategoryRepository->search(new Criteria([$id]), $context)->getEntities()->first();
        /** @var InfoplusCategoryEntity|null $category */

        if (!$category) {
            $this->logger->error('[InfoPlus] Category not found for deletion', ['id' => $id]);
            return [ "success" => false, "message" => $this->translator->trans('infoplus.api.errors.categoryNotFound') ];
        }

        // Delete from InfoPlus if sync is enabled and idForInfoplus exists
        if ($this->configService->get('syncCategories') && $category->getInternalId()) {
            $method = $category->getIsSubCategory() ? 'deleteItemSubCategory' : 'deleteItemCategory';
            $result = $this->infoplusApiClient->$method($category->getInternalId());
                $this->infoplusCategoryRepository->delete([["id" => $id]], $context);
                return [ "success" => true, "message" => $this->translator->trans('infoplus.api.status.categoryDeleted') ];
        }
        return [ "success" => false, "message" => $this->translator->trans('infoplus.service.errors.categorySyncDisabled') ];
    }

    /**
     * @param array<mixed> $categories
     * @return int
     */
    private function getMaxCategoryId(array $categories): int
    {
        $maxId = 0;
        foreach ($categories as $category) {
            if (isset($category['id']) && is_numeric($category['id'])) {
                $maxId = max($maxId, (int)$category['id']);
            }
        }
        return $maxId + 1;
    }
}
