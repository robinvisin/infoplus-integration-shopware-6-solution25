<?php

namespace InfoPlusCommerce\Storefront\Subscriber;

use InfoPlusCommerce\Core\Content\InfoplusFieldDefinition\InfoplusFieldDefinitionCollection;
use InfoPlusCommerce\Core\Content\InfoplusFieldDefinition\InfoplusFieldDefinitionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Account\Order\AccountOrderPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class InfoplusOrderDetailSubscriber implements EventSubscriberInterface
{
    /**
     * @param EntityRepository<InfoplusFieldDefinitionCollection> $customFieldDefinitionRepository
     */
    public function __construct(private readonly EntityRepository $customFieldDefinitionRepository)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AccountOrderPageLoadedEvent::class => 'onOrderPageLoaded',
        ];
    }
    public function onOrderPageLoaded(AccountOrderPageLoadedEvent $event): void
    {
        $orders = $event->getPage()->getOrders();
        foreach ($orders as $order) {
            $this->orderDetailLoaded($order, $event);
        }
    }
    public function orderDetailLoaded(Entity $order, AccountOrderPageLoadedEvent $event): void
    {
        if (!$order instanceof OrderEntity) {
            return;
        }

        $lineItems = $order->getNestedLineItems();
        if (!$lineItems || $lineItems->count() === 0) {
            return;
        }
        $context = $event->getSalesChannelContext();
        $assigned = [];
        /** @var OrderLineItemCollection $lineItems */
        foreach ($lineItems as $li) {
            $cf = $li->getCustomFields() ?? [];
            foreach ($cf as $key => $value) {
                if (str_starts_with((string)$key, 'infoplus_')) {
                    $assigned[] = substr((string)$key, strlen('infoplus_'));
                }
            }
        }
        $assigned = array_values(array_unique(array_filter($assigned)));
        if (empty($assigned)) {
            return;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('technicalName', $assigned));
        $criteria->addFilter(new EqualsFilter('active', true));
        $isSalesAgentImpersonation = false;
        $request = $event->getRequest();
        if ($request->hasSession()) {
            $session = $request->getSession();
            if ($session->has('sw-imitating-user-id') && $session->get('sw-imitating-user-id') !== null) {
                $isSalesAgentImpersonation = true;
            }
        }
        if (!$isSalesAgentImpersonation) {
            $criteria->addFilter(new EqualsFilter('showInStorefront', true));
        }

        $defs = $this->customFieldDefinitionRepository->search($criteria, $context->getContext())->getEntities();
        $map = [];
        foreach ($defs as $def) {
            /** @var InfoplusFieldDefinitionEntity $def */
            $map[$def->getTechnicalName()] = [
                'label' => $def->getLabel(),
                'type' => $def->getType(),
                'options' => $def->getOptions(),
                'staticPrice' => $def->getStaticPrice(),
            ];
        }

        foreach ($lineItems as $li) {
            $cf = $li->getCustomFields() ?? [];
            $list = [];
            foreach ($cf as $key => $value) {
                if (!str_starts_with((string)$key, 'infoplus_')) {
                    continue;
                }
                $tech = substr((string)$key, strlen('infoplus_'));
                $def = $map[$tech] ?? null;
                if (!$def) {
                    continue;
                }
                $formatted = $this->formatValueWithPrice($value, $def);
                $list[] = [
                    'technicalName' => $tech,
                    'label' => $def['label'],
                    'type' => $def['type'],
                    'value' => $formatted,
                ];
            }
            if (!empty($list)) {
                $li->addExtension('infoplusCustomFields', new ArrayStruct($list));
            }
        }
    }

    /**
     * @param array<string, mixed> $def
     */
    private function formatValueWithPrice(mixed $value, array $def): string
    {
        $type = (string)($def['type'] ?? '');
        $valStr = $this->formatScalar($value, $type);
        $suffix = '';

        if ($type === 'select') {
            $options = is_array($def['options'] ?? null) ? $def['options'] : [];
            foreach ($options as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $label = $row['label'] ?? ($row['name'] ?? null);
                $optVal = $row['value'] ?? $label;
                if ($optVal !== null && ((string)$optVal === (string)$value || (string)$label === (string)$value)) {
                    if (array_key_exists('price', $row) && $row['price'] !== null && $row['price'] !== '') {
                        $suffix = ' (+$' . (string)$row['price'] . ')';
                    } elseif (array_key_exists('staticPrice', $def) && $def['staticPrice'] !== null) {
                        $suffix = ' (+$' . (string)$def['staticPrice'] . ')';
                    }
                    break;
                }
            }
        } else {
            $static = $def['staticPrice'] ?? null;
            if ($static !== null) {
                $apply = false;
                if ($type === 'boolean') {
                    $apply = filter_var($value, FILTER_VALIDATE_BOOLEAN) || (string)$value === '1' || $value === 1;
                } else {
                    $apply = ($value !== null && $value !== '' && $value !== '0');
                }
                if ($apply) {
                    $suffix = ' (+$' . (string)$static . ')';
                }
            }
        }

        return trim($valStr . $suffix);
    }

    private function formatScalar(mixed $value, string $type): string
    {
        if ($type === 'boolean') {
            $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($bool === null) {
                $bool = (string)$value !== '' && $value !== '0' && $value !== 0;
            }
            return $bool ? 'Yes' : 'No';
        }
        return (string)($value ?? '');
    }
}
