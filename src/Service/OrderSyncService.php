<?php

declare(strict_types=1);

namespace InfoPlusCommerce\Service;

use InfoPlusCommerce\Client\InfoplusApiClient;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Contracts\Translation\TranslatorInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderCollection;

class OrderSyncService
{
    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly ConfigService $configService,
        private readonly InfoplusApiClient $infoplusApiClient,
        private readonly LoggerInterface $logger,
        private readonly EntityRepository $orderRepository,
        private readonly IdMappingService $idMappingService,
        private readonly TranslatorInterface $translator,
        private readonly StateMachineRegistry $stateMachineRegistry,
    ) {
    }

    /**
     * @return array<mixed>
     */
    public function orderSyncStart(Context $context): array
    {
        if (!$this->configService->get('syncOrders')) {
            $this->logger->info('[InfoPlus] Order sync is disabled in configuration');
            return ['status' => 'error', 'error' => $this->translator->trans('infoplus.service.errors.orderSyncDisabled')];
        }
        $this->logger->info('[InfoPlus] Order sync command triggered via API');

        $criteria = new Criteria();
        $criteria->addAssociation('lineItems.product');
        $criteria->addAssociation('shippingAddress');
        $criteria->addAssociation('orderCustomer');

        $orders = $this->orderRepository->search($criteria, $context);
        $orderIds = array_values($orders->getEntities()->getIds());
        return $this->syncOrders($orderIds, $context);
    }

    /**
     * @param array<int|string> $orderIds
     * @return array<mixed>
     */
    public function syncOrders(array $orderIds, Context $context): array
    {
        if (!$this->configService->get('syncOrders')) {
            $this->logger->info('[InfoPlus] Order sync is disabled in configuration');
            return ['status' => 'error', 'error' => $this->translator->trans('infoplus.service.errors.orderSyncDisabled')];
        }
        $this->logger->info('[InfoPlus] Sync orders triggered', ['orderIds' => $orderIds]);
        $criteria = new Criteria(array_map('strval', $orderIds));
        $criteria->addAssociation('lineItems.product');
        $criteria->addAssociation('shippingAddress');
        $criteria->addAssociation('billingAddress');
        $criteria->addAssociation('billingAddress.country');
        $criteria->addAssociation('billingAddress.countryState');
        $criteria->addAssociation('orderCustomer');
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('deliveries.stateMachineState');
        $criteria->addAssociation('deliveries.shippingMethod');
        $criteria->addAssociation('transactions.stateMachineState');

        $criteria->addFilter(
            new EqualsAnyFilter(
                'transactions.stateMachineState.technicalName',
                $this->configService->getSyncablePaymentStates()
            )
        );

        $criteria->addFilter(
            new EqualsAnyFilter(
                'stateMachineState.technicalName',
                $this->configService->getSyncableOrderStates()
            )
        );

        $orders = $this->orderRepository->search($criteria, $context)->getEntities();

        if ($orders->count() === 0) {
            $this->logger->warning('[InfoPlus] No orders found for sync', ['orderIds' => $orderIds]);
            return ['status' => 'error', 'error' => $this->translator->trans('infoplus.service.errors.noOrdersFound')];
        }

        $results = [];
        foreach ($orders as $order) {
            /** @var OrderEntity $order */
            $currentOrderStatus = $order->getStateMachineState() ? $order->getStateMachineState()->getTechnicalName() : null;

            $firstDelivery = $order->getDeliveries()?->first();
            $currentShippingStatus = $firstDelivery && $firstDelivery->getStateMachineState()
                ? $firstDelivery->getStateMachineState()->getTechnicalName()
                : null;

            $firstTransaction = $order->getTransactions()?->first();
            $currentPaymentStatus = $firstTransaction && $firstTransaction->getStateMachineState()
                ? $firstTransaction->getStateMachineState()->getTechnicalName()
                : null;
            $syncedOrder = $this->idMappingService->getSyncedOrderId($order->getId(), $context);

            $needsSync = false;
            $existingSync = null;
            if ($syncedOrder) {
                $existingSync = $this->idMappingService->getOrderSyncRecord($order->getId(), $context);
                if ($existingSync) {
                    $needsSync = (
                        $existingSync['order_status'] !== $currentOrderStatus ||
                        $existingSync['order_shipping_status'] !== $currentShippingStatus ||
                        $existingSync['order_payment_status'] !== $currentPaymentStatus
                    );
                } else {
                    $needsSync = true;
                }
            } else {
                $needsSync = true;
            }

            if (!$needsSync) {
                $this->logger->info('[InfoPlus] Order sync skipped, no status changes', [
                    'orderId' => $order->getId(),
                    'orderNo' => $order->getOrderNumber(),
                    'currentStatuses' => [
                        'order_status' => $currentOrderStatus,
                        'shipping_status' => $currentShippingStatus,
                        'payment_status' => $currentPaymentStatus
                    ],
                    'existingStatuses' => $existingSync
                ]);
                $results[] = [
                    'orderNo' => $order->getOrderNumber(),
                    'success' => true,
                    'error' => null,
                    'syncOrderShipping' => $this->syncPaidOrders($context, $order->getId())
                ];
                continue;
            }

            $lineItems = [];
            $totalDiscount = 0.0;
            foreach ($order->getLineItems() ?? [] as $item) {
                if ($item->getProduct() && $item->getProduct()->getProductNumber()) {
                    $unitCost = $item->getPrice()?->getUnitPrice() ?? $item->getUnitPrice();
                    $unitSell = $item->getUnitPrice();
                    $unitDiscount = abs($item->getPrice()?->getListPrice()?->getDiscount() ?? 0.0);
                    $lineItems[] = [
                        'sku' => $item->getProduct()->getProductNumber(),
                        'orderedQty' => $item->getQuantity(),
                        'lobId' => $this->configService->get('lobId'),
                        'unitCost' => $unitCost,
                        'unitSell' => $unitSell,
                        'unitDiscount' => $unitDiscount,
                        'customFields' => $this->getCustomFieldsForLineItem($item)
                    ];
                } else {
                    $totalDiscount = $totalDiscount + ($item->getPrice()?->getTotalPrice() ?? 0.0);
                }
            }
            $billingAddress = $order->getBillingAddress();

            $carrierId = $this->configService->getDefaultCarrierId();
            $firstDelivery = $order->getDeliveries()?->first();
            if ($firstDelivery && $firstDelivery->getShippingMethod()) {
                $customFields = $firstDelivery->getShippingMethod()->getCustomFields() ?: [];
                if (isset($customFields['infoplus_carrier_id']) && $customFields['infoplus_carrier_id'] !== '') {
                    $carrierId = $customFields['infoplus_carrier_id'];
                }
            }
            $orderCustomFieldsObject = $this->getCustomFieldsForOrder($order);

            $data = [
                'lobId' => $this->configService->get('lobId'),
                'customerOrderNo' => $order->getOrderNumber(),
                'warehouseId' => $this->configService->get('warehouseId'),
                'orderDate' => $order->getOrderDateTime()->format('Y-m-d\\TH:i:s\\Z'),
                'customerNo' => ($order->getOrderCustomer() ? $order->getOrderCustomer()->getCustomerNumber() : ''),
                'carrierId' => $carrierId,
                'lineItems' => $lineItems,
                'subtotal' => $order->getPrice()->getNetPrice(),
                'total' => $order->getPrice()->getTotalPrice(),
                'tax' => $order->getPrice()->getCalculatedTaxes()->getAmount(),
                'totalDiscount' => $totalDiscount,
                'shippingCharge' => $order->getShippingCosts()->getTotalPrice(),
                'billToAttention' => $billingAddress ? ($billingAddress->getFirstName() . ' ' . $billingAddress->getLastName()) : '',
                'billToCompany' => $billingAddress ? ($billingAddress->getCompany() ?? $billingAddress->getFirstName() . ' ' . $billingAddress->getLastName()) : 'no company',
                'billToStreet' => $billingAddress ? $billingAddress->getStreet() : '',
                'billToStreet2' => $billingAddress ? ($billingAddress->getAdditionalAddressLine1() ?? '') : '',
                'billToStreet3' => $billingAddress ? ($billingAddress->getAdditionalAddressLine2() ?? '') : '',
                'billToCity' => $billingAddress ? $billingAddress->getCity() : '',
                'billToState' => $billingAddress && $billingAddress->getCountryState() ? $billingAddress->getCountryState()->getName() : '',
                'billToZip' => $billingAddress ? $billingAddress->getZipcode() : '',
                'billToCountry' => $billingAddress && $billingAddress->getCountry() ? $billingAddress->getCountry()->getIso() : 'US',
                'billToPhone' => $billingAddress ? ($billingAddress->getPhoneNumber() ?? '') : '',
                'billToEmail' => $order->getOrderCustomer() ? $order->getOrderCustomer()->getEmail() : '',
                'customFields' => $orderCustomFieldsObject
            ];
            //check if billToState is not US remove this field
            if (isset($data['billToCountry']) && strtoupper($data['billToCountry']) !== 'US') {
                unset($data['billToState']);
            }
            $result = null;
            if ($syncedOrder) {
                $data['orderNo'] = $syncedOrder;
                $result = $this->infoplusApiClient->updateOrder($data);
                if (is_array($result) && isset($result['orderNo'])) {
                    $this->idMappingService->updateOrderSyncStatus(
                        $order->getId(),
                        (string)$result['orderNo'],
                        $currentOrderStatus,
                        $currentShippingStatus,
                        $currentPaymentStatus,
                        $context
                    );
                } else {
                    $this->logger->error('[InfoPlus] Failed to update order sync record', ['orderNo' => $order->getOrderNumber(), 'error' => is_string($result) ? $result : 'Unknown error']);
                }
            } else {
                $result = $this->infoplusApiClient->createOrder($data);
                if (is_array($result) && isset($result['orderNo'])) {
                    $this->idMappingService->createOrderSyncRecord(
                        $order->getId(),
                        (string)$result['orderNo'],
                        $currentOrderStatus,
                        $currentShippingStatus,
                        $currentPaymentStatus,
                        $context
                    );
                } else {
                    $this->logger->error('[InfoPlus] Failed to create order sync record', ['orderNo' => $order->getOrderNumber(), 'error' => is_string($result) ? $result : 'Unknown error']);
                }
            }

            $results[] = [
                'orderNo' => $order->getOrderNumber(),
                'success' => is_array($result),
                'error' => is_string($result) ? $result : null,
                'syncOrderShipping' => $this->syncPaidOrders($context, $order->getId())
            ];
        }
        $this->logger->info('[InfoPlus] Completed background order sync', ['results' => $results]);
        return $results;
    }

    /**
     * @param Context $context
     * @param string|null $id
     * @return array<mixed>
     */
    public function syncPaidOrders(Context $context, ?string $id = null): array
    {
        if (!$this->configService->get("syncOrders")) {
            $this->logger->info("[InfoPlus] Cronjob orders sync is disabled, skipping task.");
            return [
                'status' => 'error',
                'error' => 'Order sync is disabled in configuration'
            ];
        }

        $syncedOrders = $this->idMappingService->getPendingShipmentOrders($context, $id);
        return $this->processOrders($syncedOrders, $context);
    }

    /**
     * @param array<mixed> $syncedOrders
     * @return array<mixed>
     */
    private function processOrders(array $syncedOrders, Context $context): array
    {
        $returnArray = [];

        if (empty($syncedOrders)) {
            $this->logger->info('[InfoPlus] No synced orders to process');
            return [
                'status' => 'error',
                'error' => 'No orders found for sync'
            ];
        }

        // Get $syncedOrders as array of order numbers
        $orderNos = array_map(function ($order) {
            return $order->getInfoplusId();
        }, $syncedOrders);

        // Query InfoPlus API for orders with orderNo IN ($orderNos)
        $query = ['filter' => 'orderNo in (' . implode(',', $orderNos) . ')'];
        $infoPlusOrders = $this->infoplusApiClient->searchOrders($query);

        foreach ($infoPlusOrders as $infoPlusOrder) {
            if (!isset($infoPlusOrder['orderNo']) || !isset($infoPlusOrder['status'])) {
                $this->logger->warning('[InfoPlus] Invalid order data from InfoPlus', ['order' => $infoPlusOrder]);
                $returnArray[] = [
                    'success' => false,
                    'orderNo' => $infoPlusOrder['orderNo'] ?? 'unknown',
                    'message' => 'No status change',
                    'error' => 'Invalid order data from InfoPlus'
                ];
                continue;
            }

            $orderNo = $infoPlusOrder['orderNo'];
            $shopwareOrderId = null;
            foreach ($syncedOrders as $syncedOrder) {
                if ($syncedOrder->getInfoplusId() == $orderNo) {
                    $shopwareOrderId = $syncedOrder->getShopwareOrderId();
                    break;
                }
            }

            if (!$shopwareOrderId) {
                $this->logger->warning('[InfoPlus] No Shopware order found for InfoPlus orderNo', ['orderNo' => $orderNo]);
                $returnArray[] = [
                    'success' => false,
                    'orderNo' => $orderNo,
                    'message' => 'No status change',
                    'error' => 'No Shopware order found for InfoPlus orderNo'
                ];
                continue;
            }

            $status = strtolower($infoPlusOrder['status']);
            $result = [
                'success' => false,
                'orderNo' => $orderNo,
                'message' => 'No status change',
                'error' => null
            ];

            switch ($status) {
                case 'shipped':
                    $result = $this->processOrder($shopwareOrderId, $infoPlusOrder, $context, 'order_delivery', 'ship', 'shipped');
                    break;
                case 'cancelled':
                    $result = $this->processOrder($shopwareOrderId, $infoPlusOrder, $context, 'order_transaction', 'cancel', 'cancelled');
                    if ($result['success']) {
                        $deliveryResult = $this->processOrder($shopwareOrderId, $infoPlusOrder, $context, 'order_delivery', 'cancel', 'cancelled');
                        if (!$deliveryResult['success']) {
                            $result = $deliveryResult;
                        }
                    }
                    break;
                case 'pending':
                case 'unknown':
                case 'on order':
                case 'processed':
                case 'back order':
                    $result = [
                        'success' => true,
                        'orderNo' => $orderNo,
                        'message' => "Order status $status does not require update",
                        'error' => null
                    ];
                    break;
                case 'error':
                    $result = $this->processOrder($shopwareOrderId, $infoPlusOrder, $context, 'order_transaction', 'fail', 'failed');
                    break;
                default:
                    $this->logger->warning('[InfoPlus] Unhandled InfoPlus status', ['orderId' => $shopwareOrderId, 'status' => $status]);
                    $result = [
                        'success' => false,
                        'orderNo' => $orderNo,
                        'message' => 'No status change',
                        'error' => "Unhandled InfoPlus status: $status"
                    ];
            }

            $returnArray[] = $result;
        }

        return $returnArray;
    }

    /**
     * @param string $shopwareOrderId
     * @param array<mixed> $infoPlusOrder
     * @return array<mixed>
     */
    private function processOrder(string $shopwareOrderId, array $infoPlusOrder, Context $context, string $entity, string $action, string $targetState): array
    {
        $orderNo = $infoPlusOrder['orderNo'] ?? 'unknown';

        // Load the order with necessary associations
        $criteria = new Criteria([$shopwareOrderId]);
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('deliveries');
        $order = $this->orderRepository->search($criteria, $context)->getEntities()->first();

        if (!$order instanceof OrderEntity) {
            $this->logger->warning('[InfoPlus] Shopware order not found', ['orderId' => $shopwareOrderId]);
            return [
                'success' => false,
                'orderNo' => $orderNo,
                'message' => 'No status change',
                'error' => 'Shopware order not found'
            ];
        }

        $entityId = null;
        if ($entity === 'order') {
            $entityId = $shopwareOrderId;
        } elseif ($entity === 'order_delivery') {
            $delivery = $order->getDeliveries()?->first();
            if (!$delivery) {
                $this->logger->warning('[InfoPlus] No delivery found for order', ['orderId' => $shopwareOrderId]);
                return [
                    'success' => false,
                    'orderNo' => $orderNo,
                    'message' => 'No status change',
                    'error' => 'No delivery found for order'
                ];
            }
            $entityId = $delivery->getId();
        } elseif ($entity === 'order_transaction') {
            $transaction = $order->getTransactions()?->first();
            if (!$transaction) {
                $this->logger->warning('[InfoPlus] No transaction found for order', ['orderId' => $shopwareOrderId]);
                return [
                    'success' => false,
                    'orderNo' => $orderNo,
                    'message' => 'No status change',
                    'error' => 'No transaction found for order'
                ];
            }
            $entityId = $transaction->getId();
        }

        try {
            $templateVariables = new ArrayStruct([
                'source' => 'Infoplus',
            ]);
            $context->addExtension('orderStatusToShipped', $templateVariables);
            // Perform state transition
            $transition = new Transition(
                $entity,
                (string) $entityId,
                $action,
                'stateId'
            );

            $this->stateMachineRegistry->transition($transition, $context);
            $this->idMappingService->setShippedStatus($shopwareOrderId, (int)$infoPlusOrder['orderNo'], $context);
            $this->logger->info("[InfoPlus] Order updated to $targetState in Shopware", [
                'orderId' => $shopwareOrderId,
                'entity' => $entity
            ]);

            return [
                'success' => true,
                'orderNo' => $orderNo,
                'message' => "Order updated to $targetState",
                'error' => null
            ];
        } catch (\Exception $e) {
            $this->logger->error("[InfoPlus] Failed to update order to $targetState in Shopware", [
                'orderId' => $shopwareOrderId,
                'entity' => $entity,
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'orderNo' => $orderNo,
                'message' => 'No status change',
                'error' => "Failed to update order to $targetState: {$e->getMessage()}"
            ];
        }
    }

    private function getCustomFieldsForLineItem(mixed $item): object
    {
        $payload = $item->getPayload() ?: [];
        $customFields = [];
        if (isset($payload['infoplus_customfields']) && \is_array($payload['infoplus_customfields'])) {
            $customFields = $payload['infoplus_customfields'];
        } else {
            $liCustom = $item->getCustomFields() ?: [];
            foreach ($liCustom as $k => $v) {
                if (\is_string($k) && str_starts_with($k, 'infoplus_')) {
                    $customFields[$k] = $v;
                }
            }
        }

        $result = [];
        foreach ($customFields as $key => $value) {
            if (\is_string($key) && str_starts_with($key, 'infoplus_')) {
                $newKey = substr($key, 9);
                $result[$newKey] = $value;
            }
        }
        return !empty($result) ? (object)$result : (object)[];
    }

    private function getCustomFieldsForOrder(OrderEntity $order): object
    {
        $raw = $order->getCustomFields() ?: [];
        $filtered = [];
        foreach ($raw as $key => $value) {
            if (\is_string($key) && str_starts_with($key, 'infoplus_')) {
                $newKey = substr($key, 9);
                $filtered[$newKey] = $value;
            }
        }
        return !empty($filtered) ? (object)$filtered : (object)[];
    }
}
