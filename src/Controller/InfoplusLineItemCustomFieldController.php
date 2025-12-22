<?php

namespace InfoPlusCommerce\Controller;

use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class InfoplusLineItemCustomFieldController extends AbstractController
{
    public function __construct(private readonly CartService $cartService)
    {
    }

    /**
     * @param array<string, mixed> $custom
     */
    private function computeFingerprint(string $referencedId, array $custom): string
    {
        $filtered = [];
        foreach ($custom as $k => $v) {
            if (str_starts_with($k, 'infoplus_')) {
                $filtered[$k] = $v;
            }
        }
        ksort($filtered);

        return substr(hash('sha256', $referencedId . '|' . json_encode($filtered)), 0, 32);
    }

    private function addAliasId(LineItem $li, string $alias): void
    {
        $payload = (array) $li->getPayload();
        $aliases = $payload['infoplus_alias_ids'] ?? [];
        if (!\is_array($aliases)) {
            $aliases = [];
        }
        if (!\in_array($alias, $aliases, true)) {
            $aliases[] = $alias;
        }
        $li->setPayloadValue('infoplus_alias_ids', $aliases);
    }

    #[Route(path: '/store-api/infoplus/cart/line-item/custom-fields', name: 'store-api.infoplus.cart.line-item.custom-fields', defaults: ['_routeScope' => ['store-api']], methods: ['POST'])]
    public function upsertCustomFields(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?: [];
        $items = $data['items'] ?? [];
        if (!\is_array($items) || empty($items)) {
            return new JsonResponse(['success' => true, 'updated' => 0]);
        }
        $cart = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);
        $updated = 0;
        foreach ($items as $row) {
            if (!isset($row['id']) || !\is_string($row['id'])) {
                continue;
            }
            $li = $cart->getLineItems()->get($row['id']);
            if (!$li) {
                foreach ($cart->getLineItems() as $candidate) {
                    $aliases = $candidate->getPayload()['infoplus_alias_ids'] ?? [];
                    if (\is_array($aliases) && \in_array($row['id'], $aliases, true)) {
                        $li = $candidate;
                        break;
                    }
                }
                if (!$li) {
                    continue;
                }
            }
            $custom = $row['customFields'] ?? [];
            if (!\is_array($custom) || empty($custom)) {
                continue;
            }

            $existingPayloadCustom = $li->getPayload()['infoplus_customfields'] ?? [];
            if (!\is_array($existingPayloadCustom)) {
                $existingPayloadCustom = [];
            }

            $mergedPayload = array_merge($existingPayloadCustom, $custom);

            $li->setPayloadValue('infoplus_customfields', $mergedPayload);
            $generic = $li->getPayload()['customFields'] ?? [];
            if (!\is_array($generic)) {
                $generic = [];
            }
            $li->setPayloadValue('customFields', array_merge($generic, $mergedPayload));

            $referencedId = (string) $li->getReferencedId();
            if ($referencedId !== '') {
                $targetId = $this->computeFingerprint($referencedId, $mergedPayload);

                if ($li->getId() !== $targetId) {
                    $existingTarget = $cart->getLineItems()->get($targetId);
                    try {
                        if ($existingTarget) {
                            $newQty = $existingTarget->getQuantity() + $li->getQuantity();
                            $this->addAliasId($existingTarget, $li->getId());
                            $this->cartService->changeQuantity($cart, $existingTarget->getId(), $newQty, $salesChannelContext);
                            $this->cartService->remove($cart, $li->getId(), $salesChannelContext);
                        } else {
                            $new = new LineItem($targetId, $li->getType(), $referencedId);
                            $new->setQuantity($li->getQuantity());
                            $new->setLabel($li->getLabel());
                            $new->setStackable($li->isStackable());
                            foreach ((array) $li->getPayload() as $k => $v) {
                                $new->setPayloadValue($k, $v);
                            }
                            $new->setPayloadValue('infoplus_customfields', $mergedPayload);
                            $genericNew = $new->getPayload()['customFields'] ?? [];
                            if (!\is_array($genericNew)) {
                                $genericNew = [];
                            }
                            $new->setPayloadValue('customFields', array_merge($genericNew, $mergedPayload));
                            if ($li->getPriceDefinition()) {
                                $new->setPriceDefinition($li->getPriceDefinition());
                            }
                            $this->addAliasId($new, $li->getId());

                            $this->cartService->add($cart, $new, $salesChannelContext);
                            $this->cartService->remove($cart, $li->getId(), $salesChannelContext);
                        }
                    } catch (\Throwable $e) {
                    }
                }
            }

            $updated++;
        }

        $this->cartService->recalculate($cart, $salesChannelContext);

        return new JsonResponse(['success' => true, 'updated' => $updated]);
    }

    #[Route(path: '/store-api/infoplus/cart/line-item/add-multiple', name: 'store-api.infoplus.cart.line-item.add-multiple', defaults: ['_routeScope' => ['store-api']], methods: ['POST'])]
    public function addMultipleConfiguredItems(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?: [];
        $items = $data['items'] ?? [];
        if (!\is_array($items) || empty($items)) {
            return new JsonResponse(['success' => true, 'added' => 0]);
        }

        $cart = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);
        $added = 0;
        foreach ($items as $row) {
            $referencedId = $row['referencedId'] ?? null;
            if (!is_string($referencedId) || $referencedId === '') {
                continue;
            }
            $quantity = isset($row['quantity']) && is_int($row['quantity']) && $row['quantity'] > 0 ? $row['quantity'] : 1;
            $payloadCustom = $row['payload']['infoplus_customfields'] ?? ($row['customFields'] ?? []);
            if (!is_array($payloadCustom)) {
                $payloadCustom = [];
            }

            $matchingLineItem = null;
            foreach ($cart->getLineItems() as $existing) {
                if ($existing->getReferencedId() !== $referencedId) {
                    continue;
                }
                $existingPayload = $existing->getPayload()['infoplus_customfields'] ?? [];
                if (!is_array($existingPayload)) {
                    $existingPayload = [];
                }
                if (json_encode($existingPayload) === json_encode($payloadCustom)) {
                    $matchingLineItem = $existing;
                    break;
                }
            }

            if ($matchingLineItem) {
                try {
                    $newQty = $matchingLineItem->getQuantity() + $quantity;
                    $this->cartService->changeQuantity($cart, $matchingLineItem->getId(), $newQty, $salesChannelContext);
                    $added += $quantity;
                } catch (\Throwable $e) {
                    continue;
                }

                continue;
            }

            try {
                $newId = $this->computeFingerprint($referencedId, $payloadCustom);
                $lineItem = new LineItem($newId, LineItem::PRODUCT_LINE_ITEM_TYPE, $referencedId);
                $lineItem->setQuantity($quantity);
                $lineItem->setLabel($row['label'] ?? '');
                $lineItem->setPayloadValue('infoplus_customfields', $payloadCustom);
                $generic = $lineItem->getPayload()['customFields'] ?? [];
                if (!is_array($generic)) {
                    $generic = [];
                }
                $lineItem->setPayloadValue('customFields', array_merge($generic, $payloadCustom));

                $this->cartService->add($cart, $lineItem, $salesChannelContext);
                $added += $quantity;
            } catch (\Throwable $e) {
                continue;
            }
        }

        $this->cartService->recalculate($cart, $salesChannelContext);

        return new JsonResponse(['success' => true, 'added' => $added]);
    }

    #[Route(path: '/store-api/infoplus/cart/line-item/add-configured', name: 'store-api.infoplus.cart/line-item/add-configured', defaults: ['_routeScope' => ['store-api']], methods: ['POST'])]
    public function addConfiguredItem(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?: [];
        $referencedId = $data['referencedId'] ?? null;
        if (!is_string($referencedId) || $referencedId === '') {
            return new JsonResponse(['success' => false, 'message' => 'missing_referencedId'], 400);
        }

        $quantity = isset($data['quantity']) && is_int($data['quantity']) && $data['quantity'] > 0 ? $data['quantity'] : 1;
        $payloadCustom = $data['payload']['infoplus_customfields'] ?? ($data['customFields'] ?? []);
        if (!is_array($payloadCustom)) {
            $payloadCustom = [];
        }

        $cart = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);

        $matchingLineItem = null;
        foreach ($cart->getLineItems() as $existing) {
            if ($existing->getReferencedId() !== $referencedId) {
                continue;
            }
            $existingPayload = $existing->getPayload()['infoplus_customfields'] ?? [];
            if (!is_array($existingPayload)) {
                $existingPayload = [];
            }
            if (json_encode($existingPayload) === json_encode($payloadCustom)) {
                $matchingLineItem = $existing;
                break;
            }
        }

        try {
            if ($matchingLineItem) {
                $newQty = $matchingLineItem->getQuantity() + $quantity;
                $this->cartService->changeQuantity($cart, $matchingLineItem->getId(), $newQty, $salesChannelContext);
                $this->cartService->recalculate($cart, $salesChannelContext);
                return new JsonResponse(['success' => true, 'action' => 'incremented', 'lineItemId' => $matchingLineItem->getId(), 'newQuantity' => $newQty]);
            }

            $newId = $this->computeFingerprint($referencedId, $payloadCustom);
            $lineItem = new LineItem($newId, LineItem::PRODUCT_LINE_ITEM_TYPE, $referencedId);
            $lineItem->setQuantity($quantity);
            $lineItem->setLabel($data['label'] ?? '');
            $lineItem->setPayloadValue('infoplus_customfields', $payloadCustom);
            $generic = $lineItem->getPayload()['customFields'] ?? [];
            if (!is_array($generic)) {
                $generic = [];
            }
            $lineItem->setPayloadValue('customFields', array_merge($generic, $payloadCustom));

            $this->cartService->add($cart, $lineItem, $salesChannelContext);
            $this->cartService->recalculate($cart, $salesChannelContext);

            return new JsonResponse(['success' => true, 'action' => 'added', 'lineItemId' => $newId, 'quantity' => $quantity]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
