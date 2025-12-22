<?php

namespace InfoPlusCommerce\Subscriber;

use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;

class InfoplusOrderPlacedSubscriber implements EventSubscriberInterface
{
    /**
     * @var EntityRepository<OrderLineItemCollection>
     */
    private EntityRepository $orderLineItemRepository;

    /**
     * @param EntityRepository<OrderLineItemCollection> $orderLineItemRepository
     */
    public function __construct(EntityRepository $orderLineItemRepository)
    {
        $this->orderLineItemRepository = $orderLineItemRepository;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
        ];
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $order = $event->getOrder();
        $context = $event->getContext();
        $lineItems = $order->getLineItems();
        if (!$lineItems || $lineItems->count() === 0) {
            return;
        }

        $updates = [];
        foreach ($lineItems as $lineItem) {
            $payload = $lineItem->getPayload() ?? [];
            $payloadCustomFields = $payload['infoplus_customfields'] ?? null;
            if (!\is_array($payloadCustomFields) || empty($payloadCustomFields)) {
                continue;
            }

            $currentCustom = $lineItem->getCustomFields() ?? [];
            $mergedCustom = array_merge($currentCustom, $payloadCustomFields);

            $updates[] = [
                'id' => $lineItem->getId(),
                'customFields' => $mergedCustom,
            ];
        }

        if (empty($updates)) {
            return;
        }

        $this->orderLineItemRepository->update($updates, $context);
    }
}
