<?php

namespace InfoPlusCommerce\Storefront\Subscriber;

use InfoPlusCommerce\Core\Content\InfoplusFieldDefinition\InfoplusFieldDefinitionCollection;
use InfoPlusCommerce\Core\Content\InfoplusFieldDefinition\InfoplusFieldDefinitionEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class InfoplusProductPageSubscriber implements EventSubscriberInterface
{
    /**
     * @var EntityRepository<InfoplusFieldDefinitionCollection>
     */
    private EntityRepository $customFieldDefinitionRepository;

    /**
     * @param EntityRepository<InfoplusFieldDefinitionCollection> $customFieldDefinitionRepository
     */
    public function __construct(EntityRepository $customFieldDefinitionRepository)
    {
        $this->customFieldDefinitionRepository = $customFieldDefinitionRepository;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductPageLoadedEvent::class => 'onProductPageLoaded',
        ];
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $product = $event->getPage()->getProduct();
        $customFields = $product->getCustomFields() ?? [];
        $assigned = [];
        foreach ($customFields as $key => $value) {
            if (str_starts_with($key, 'infoplus_') && $value) {
                $assigned[] = substr($key, strlen('infoplus_'));
            }
        }
        if (empty($assigned)) {
            $event->getPage()->addExtension('infoplusCustomFields', new ArrayStruct([]));
            return;
        }
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('technicalName', $assigned));
        $criteria->addFilter(new EqualsFilter('active', true));

        $request = $event->getRequest();
        $isSalesAgentImpersonation = false;
        if ($request->hasSession()) {
            $session = $request->getSession();
            if ($session->has('sw-imitating-user-id') && $session->get('sw-imitating-user-id') !== null) {
                $isSalesAgentImpersonation = true;
            }
        }
        if (!$isSalesAgentImpersonation) {
            $criteria->addFilter(new EqualsFilter('showInStorefront', true));
        }
        $criteria->addSorting(new FieldSorting('position'));
        $fields = $this->customFieldDefinitionRepository->search($criteria, $event->getContext())->getEntities();
        $result = [];
        foreach ($fields as $field) {
            /** @var InfoplusFieldDefinitionEntity $field */
            $result[] = [
                'technicalName' => $field->getTechnicalName(),
                'label' => $field->getLabel(),
                'type' => $field->getType(),
                'isRequired' => $field->getIsRequired(),
                'options' => $field->getOptions(),
                'staticPrice' => $field->getStaticPrice(),
            ];
        }

        $event->getPage()->addExtension('infoplusCustomFields', new ArrayStruct($result));
    }
}
