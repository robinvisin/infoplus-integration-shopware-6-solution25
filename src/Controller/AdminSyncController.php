<?php

declare(strict_types=1);

namespace InfoPlusCommerce\Controller;

use InfoPlusCommerce\Service\ConfigService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use InfoPlusCommerce\Service\SyncService;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

#[Route(defaults: ['_routeScope' => ['api']])]
class AdminSyncController extends AbstractController
{
    /**
     * @param EntityRepository $stateMachineRepository
     */
    public function __construct(
        private readonly SyncService $syncService,
        private readonly TranslatorInterface $translator,
        private readonly EntityRepository $stateMachineRepository,
        private readonly EntityRepository $stateMachineStateRepository,
    ) {
    }

    #[Route(path: '/api/_action/infoplus/sync/products', name: 'api.infoplus.sync.products', methods: ['POST'])]
    public function syncProducts(Context $context): JsonResponse
    {
        try {
            $results = $this->syncService->syncProducts($context);
            $trTxt = $this->translator->trans('infoplus.api.status.productSyncCompleted');
            return new JsonResponse(['status' => $trTxt, 'results' => $results]);
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.productSyncFailed') . ' ' . $e->getMessage());
        }
    }

    #[Route(path: '/api/_action/infoplus/sync/categories', name: 'api.infoplus.sync.categories', methods: ['POST'])]
    public function syncCategories(Context $context): JsonResponse
    {
        try {
            $results = $this->syncService->syncCategories($context);
            $trTxt = $this->translator->trans('infoplus.api.status.categorySyncCompleted');
            return new JsonResponse(['status' => $this->translator->trans('infoplus.api.status.categorySyncCompleted'), 'results' => $results]);
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.categorySyncFailed') . ' ' . $e->getMessage());
        }
    }

    #[Route(path: '/api/_action/infoplus/sync/customers', name: 'api.infoplus.sync.customers', methods: ['POST'])]
    public function syncCustomers(Context $context): JsonResponse
    {
        try {
            $results = $this->syncService->syncCustomers($context);
            return new JsonResponse(['status' => empty($results['status']) ? $this->translator->trans('infoplus.api.status.customerSyncCompleted') : $results['status'], 'results' => $results['results'] ?? $results]);
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.customerSyncFailed') . ' ' . $e->getMessage());
        }
    }

    #[Route(path: '/api/_action/infoplus/sync/inventory', name: 'api.infoplus.sync.inventory', methods: ['POST'])]
    public function syncInventory(Context $context): JsonResponse
    {
        try {
            $results = $this->syncService->syncInventory($context);
            return new JsonResponse($results);
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.inventorySyncFailed') . ' ' . $e->getMessage());
        }
    }

    #[Route(path: '/api/_action/infoplus/sync/paidOrders', name: 'api.infoplus.sync.paidOrders', methods: ['POST'])]
    public function syncPaidOrders(Context $context): JsonResponse
    {
        try {
            $results = $this->syncService->syncPaidOrders($context);
            return new JsonResponse($results);
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.inventorySyncFailed') . ' ' . $e->getMessage());
        }
    }

    #[Route(path: '/api/_action/infoplus/sync/all', name: 'api.infoplus.sync.all', methods: ['POST'])]
    public function syncAll(): JsonResponse
    {
        try {
            $this->syncService->returnResponseContinueExecution();
            $result = $this->syncService->startSync();
            return new JsonResponse([$result]);
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.syncFailed') . ' ' . $e->getMessage());
        }
    }

    #[Route(path: '/api/_action/infoplus/sync/orders', name: 'api.infoplus.sync.orders', methods: ['POST'])]
    public function syncOrders(Context $context): JsonResponse
    {
        try {
            $this->syncService->returnResponseContinueExecution();
            $result = $this->syncService->orderSyncStart($context);
            return new JsonResponse([$result]);
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.orderSyncFailed') . ' ' . $e->getMessage());
        }
    }

    #[Route(path: '/api/_action/infoplus/delete/item/{id}', name: 'api.infoplus.delete.item', methods: ['DELETE'])]
    public function deleteItem(int $id): JsonResponse
    {
        try {
            $result = $this->syncService->deleteItem($id);
            return new JsonResponse(['status' => 'item deletion ' . ($result ? $this->translator->trans('infoplus.api.status.itemDeletionSucceeded') : $this->translator->trans('infoplus.api.status.itemDeletionFailed'))]);
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.itemDeletionFailed') . ' ' . $e->getMessage());
        }
    }

    #[Route(path: '/api/_action/infoplus/delete/category/{id}', name: 'api.infoplus.delete.category', methods: ['DELETE'])]
    public function deleteCategory(string $id, Context $context): JsonResponse
    {
        try {
            $result = $this->syncService->deleteCategory($id, $context);
            return new JsonResponse($result);
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.categoryDeletionFailed') . ' ' . $e->getMessage());
        }
    }

    #[Route(path: '/api/_action/infoplus/delete/customer/{id}', name: 'api.infoplus.delete.customer', methods: ['DELETE'])]
    public function deleteCustomer(int $id): JsonResponse
    {
        try {
            $result = $this->syncService->deleteCustomer($id);
            return new JsonResponse(['status' => 'customer deletion ' . ($result ? $this->translator->trans('infoplus.api.status.customerDeletionSucceeded') : $this->translator->trans('infoplus.api.status.customerDeletionFailed'))]);
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.customerDeletionFailed') . ' ' . $e->getMessage());
        }
    }

    #[Route(path: '/api/_action/infoplus/delete/order/{id}', name: 'api.infoplus.delete.order', methods: ['DELETE'])]
    public function deleteOrder(int $id): JsonResponse
    {
        try {
            $result = $this->syncService->deleteOrder($id);
            return new JsonResponse(['status' => 'order deletion ' . ($result ? $this->translator->trans('infoplus.api.status.orderDeletionSucceeded') : $this->translator->trans('infoplus.api.status.orderDeletionFailed'))]);
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.orderDeletionFailed') . ' ' . $e->getMessage());
        }
    }

    #[Route(path: '/api/_action/infoplus/getLobs', name: 'api.infoplus.get.lobs', methods: ['GET'])]
    public function lobTest(): JsonResponse
    {
        try {
            return new JsonResponse($this->syncService->syncLobTest());
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.lobTestFailed') . ' ' . $e->getMessage());
        }
    }

    #[Route(path: '/api/_action/infoplus/warehouses', name: 'api.infoplus.warehouses', methods: ['GET'])]
    public function syncWarehouses(): JsonResponse
    {
        try {
            return new JsonResponse($this->syncService->syncWarehouses());
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.warehouseSyncFailed') . ' ' . $e->getMessage());
        }
    }

    #[Route(path: '/api/_action/infoplus/carriers', name: 'api.infoplus.carriers', methods: ['GET'])]
    public function syncCarriers(): JsonResponse
    {
        try {
            return new JsonResponse($this->syncService->syncCarriers());
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.carrierSyncFailed') . ' ' . $e->getMessage());
        }
    }

    #[Route(path: '/api/_action/infoplus/getItemCategories', name: 'api.infoplus.getItemCategories', methods: ['GET'])]
    public function getItemCategories(): JsonResponse
    {
        try {
            return new JsonResponse($this->syncService->getItemCategories());
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.itemCategoriesFetchFailed') . ' ' . $e->getMessage());
        }
    }

    #[Route(path: '/api/_action/infoplus/getItemSubCategories', name: 'api.infoplus.getItemSubCategories', methods: ['GET'])]
    public function getItemSubCategories(): JsonResponse
    {
        try {
            return new JsonResponse($this->syncService->getItemSubCategories());
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.itemSubCategoriesFetchFailed') . ' ' . $e->getMessage());
        }
    }

    #[Route(path: '/api/_action/infoplus/getItems', name: 'api.infoplus.getItems', methods: ['GET'])]
    public function getItems(): JsonResponse
    {
        try {
            return new JsonResponse($this->syncService->getItems());
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.itemsFetchFailed') . ' ' . $e->getMessage());
        }
    }

    #[Route(path: '/api/_action/infoplus/getOrders', name: 'api.infoplus.getOrders', methods: ['GET'])]
    public function getOrders(): JsonResponse
    {
        try {
            return new JsonResponse($this->syncService->getOrders());
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.ordersFetchFailed') . ' ' . $e->getMessage());
        }
    }

    #[Route(path: '/api/_action/infoplus/getCustomers', name: 'api.infoplus.getCustomers', methods: ['GET'])]
    public function getCustomers(): JsonResponse
    {
        try {
            return new JsonResponse($this->syncService->getCustomers());
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.customersFetchFailed') . ' ' . $e->getMessage());
        }
    }

    #[Route(path: '/api/_action/infoplus/getInventories', name: 'api.infoplus.getInventories', methods: ['GET'])]
    public function getInventories(): JsonResponse
    {
        try {
            return new JsonResponse($this->syncService->getInventories());
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.inventoryFetchFailed') . ' ' . $e->getMessage());
        }
    }

    #[Route(path: '/api/_action/infoplus/sync/order/{id}', name: 'api.infoplus.sync.order', methods: ['POST'])]
    public function syncOrder(string $id, Context $context): JsonResponse
    {
        try {
            $result = $this->syncService->syncOrders([$id], $context);
            if (isset($result['status'])) {
                $result = ['success' => $result['status'] != 'error', 'error' => $result['error']];
            } elseif (count($result) > 0 && is_array($result[0])) {
                $result = $result[0];
            } else {
                $result = ['success' => false, 'error' => 'infoplus.api.errors.orderNotPaidOrAvailable'];
            }
            return new JsonResponse($result);
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.orderSyncFailed') . ' ' . $e->getMessage());
        }
    }

    #[Route(path: '/api/_action/infoplus/sync/product/{id}', name: 'api.infoplus.sync.product', methods: ['POST'])]
    public function syncProduct(string $id, Context $context): JsonResponse
    {
        try {
            $result = $this->syncService->syncProducts($context, [$id]);
            if (isset($result['status'])) {
                $result = ['success' => $result['status'] != 'error', 'error' => $result['error']];
            } elseif (count($result) > 0 && is_array($result[0])) {
                $result = $result[0];
            } else {
                $result = ['success' => false, 'error' => 'infoplus.api.errors.productNotAvailable'];
            }
            return new JsonResponse($result);
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.productSyncFailed') . ' ' . $e->getMessage());
        }
    }

    #[Route(path: '/api/_action/infoplus/sync/customer/{id}', name: 'api.infoplus.sync.customer', methods: ['POST'])]
    public function syncCustomer(string $id, Context $context): JsonResponse
    {
        try {
            $result = $this->syncService->syncCustomers($context, [$id]);
            if (isset($result['status'])) {
                $result = ['success' => $result['status'] != 'error', 'error' => $result['error']];
            } elseif (count($result) > 0 && is_array($result[0])) {
                $result = $result[0];
            } else {
                $result = ['success' => false, 'error' => 'infoplus.api.errors.customerNotAvailable'];
            }
            return new JsonResponse($result);
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.customerSyncFailed') . ' ' . $e->getMessage());
        }
    }

    #[Route(path: '/api/_action/infoplus/sync/category/{id}', name: 'api.infoplus.sync.category', methods: ['POST'])]
    public function syncCategory(string $id, Context $context): JsonResponse
    {
        try {
            $result = $this->syncService->syncCategories($context, [$id]);
            if (isset($result['status'])) {
                $result = ['success' => $result['status'] != 'error', 'error' => $result['error']];
            } elseif (count($result) > 0 && is_array($result[0])) {
                $result = $result[0];
            } else {
                $result = ['success' => false, 'error' => 'infoplus.api.errors.categoryNotAvailable'];
            }
            return new JsonResponse($result);
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.categorySyncFailed') . ' ' . $e->getMessage());
        }
    }

    #[Route(path: '/api/_action/infoplus/getAllCategories/{isSubCategory}', name: 'api.infoplus.getAllCategories', methods: ['GET'])]
    public function getAllCategories(int $isSubCategory, Context $context): JsonResponse
    {
        try {
            return new JsonResponse($this->syncService->getAllCategories($isSubCategory, $context));
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.allCategoriesFetchFailed') . ' ' . $e->getMessage());
        }
    }

    #[Route(path: '/api/_action/infoplus/createCategory', name: 'api.infoplus.createCategory', methods: ['POST'])]
    public function createCategory(Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $name = $data['name'] ?? '';
            $isSubCategory = (bool)($data['isSubCategory'] ?? false);

            if (empty($name)) {
                throw new HttpException(400, $this->translator->trans('infoplus.api.errors.categoryNameRequired'));
            }
            $category = $this->syncService->createCategory($name, $isSubCategory, $context);
            return new JsonResponse($category);
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.categoryCreationFailed') . ' ' . $e->getMessage());
        }
    }

    #[Route(path: '/api/_action/infoplus/getCategory/{id}', name: 'api.infoplus.getCategory', methods: ['GET'])]
    public function getCategory(string $id, Context $context): JsonResponse
    {
        try {
            $category = $this->syncService->getCategoryById($id, $context);
            if (!$category) {
                throw new HttpException(404, $this->translator->trans('infoplus.api.errors.categoryNotFound'));
            }
            return new JsonResponse($category);
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.categoryFetchFailed') . ' ' . $e->getMessage());
        }
    }

    #[Route(path: '/api/_action/infoplus/updateCategory/{id}', name: 'api.infoplus.updateCategory', methods: ['POST'])]
    public function updateCategory(string $id, Request $request, Context $context): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $name = $data['name'] ?? '';

            if (empty($name)) {
                throw new HttpException(400, $this->translator->trans('infoplus.api.errors.categoryNameRequired'));
            }

            $category = $this->syncService->updateCategory($id, $name, $context);
            return new JsonResponse($category);
        } catch (\Exception $e) {
            throw new HttpException(500, $this->translator->trans('infoplus.api.errors.categoryUpdateFailed') . ' ' . $e->getMessage());
        }
    }
    #[Route(path: '/api/_action/infoplus/config', name: 'api.action.infoplus.config', methods: ['GET'])]
    public function config(ConfigService $configService): JsonResponse
    {
        return new JsonResponse($configService->getAll());
    }

    #[Route(path: '/api/_action/infoplus/payment-states', name: 'api.infoplus.paymentStates', methods: ['GET'])]
    public function getPaymentStates(Context $context): JsonResponse
    {
        $machineCriteria = new Criteria();
        $machineCriteria->addFilter(new EqualsFilter('technicalName', 'order_transaction.state'));
        $stateMachine = $this->stateMachineRepository->search($machineCriteria, $context)->first();

        if (!$stateMachine) {
            return new JsonResponse(['data' => []]);
        }

        $stateCriteria = new Criteria();
        $stateCriteria->addFilter(new EqualsFilter('stateMachineId', $stateMachine->getId()));

        $states = $this->stateMachineStateRepository->search($stateCriteria, $context)->getEntities();

        $data = [];
        foreach ($states as $state) {
            $data[] = [
                'id' => $state->getTechnicalName(),
                'name' => $state->getTechnicalName(),
            ];
        }

        usort($data, static fn (array $a, array $b) => strcmp((string) $a['name'], (string) $b['name']));
        return new JsonResponse(['data' => $data]);
    }
}
