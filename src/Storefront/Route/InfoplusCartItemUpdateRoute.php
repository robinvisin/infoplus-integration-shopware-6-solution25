<?php

declare(strict_types=1);

namespace InfoPlusCommerce\Storefront\Route;

use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartItemUpdateRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartResponse;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

class InfoplusCartItemUpdateRoute extends AbstractCartItemUpdateRoute
{
    public function __construct(private readonly AbstractCartItemUpdateRoute $inner)
    {
    }

    public function getDecorated(): AbstractCartItemUpdateRoute
    {
        return $this->inner;
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

    public function change(Request $request, Cart $cart, SalesChannelContext $context): CartResponse
    {
        $path = (string) $request->getPathInfo();
        $isAdminProxy = str_contains($path, '/store-api/');
        if ($isAdminProxy) {
            $postAll = $request->request->all();
            if (empty($postAll)) {
                try {
                    $postAll = $request->toArray();
                } catch (\Throwable) {
                    $postAll = [];
                }
            }

            $items = $postAll['items'] ?? $request->request->all('items');
            if (\is_array($items)) {
                foreach ($items as $idx => $item) {
                    if (!\is_array($item)) {
                        continue;
                    }

                    $id = isset($item['id']) ? (string)$item['id'] : '';

                    $payloadCustom = $item['payload']['infoplus_customfields'] ?? [];
                    if (!\is_array($payloadCustom)) {
                        $payloadCustom = [];
                    }
                    $flat = $item['customFields'] ?? [];
                    if (!\is_array($flat)) {
                        $flat = [];
                    }
                    foreach ($flat as $k => $v) {
                        if (\is_string($k) && str_starts_with($k, 'infoplus_')) {
                            $payloadCustom[$k] = $v;
                        }
                    }

                    $existing = $id !== '' ? $cart->getLineItems()->get($id) : null;
                    if (!$existing) {
                        foreach ($cart->getLineItems() as $candidate) {
                            $aliases = $candidate->getPayload()['infoplus_alias_ids'] ?? [];
                            if (\is_array($aliases) && \in_array($id, $aliases, true)) {
                                $existing = $candidate;
                                $id = $candidate->getId();
                                break;
                            }
                        }
                    }

                    if ($existing) {
                        $item['type'] = $existing->getType();
                        $item['referencedId'] = $existing->getReferencedId();
                    }

                    $refId = $existing ? (string) $existing->getReferencedId() : (isset($item['referencedId']) && \is_string($item['referencedId']) ? $item['referencedId'] : '');

                    $effectiveRefId = $refId;
                    if (!$existing && isset($item['referencedId']) && isset($item['id']) && $item['referencedId'] === $item['id']) {
                        $pv = null;
                        if (isset($item['payload']) && \is_array($item['payload'])) {
                            $pv = $item['payload']['productNumber'] ?? $item['payload']['productnumber'] ?? $item['payload']['product_number'] ?? null;
                            if ($pv === null && isset($item['payload']['product']) && \is_array($item['payload']['product'])) {
                                $pv = $item['payload']['product']['productNumber'] ?? $item['payload']['product']['productnumber'] ?? $item['payload']['product']['product_number'] ?? null;
                            }
                        }
                        if (\is_string($pv) && $pv !== '') {
                            $effectiveRefId = $pv;
                        }
                    }

                    if (!$existing && $refId !== '') {
                        $targetId = $this->computeFingerprint($effectiveRefId, $payloadCustom);
                        $candidate = $cart->getLineItems()->get($targetId);
                        if ($candidate) {
                            $existing = $candidate;
                            $id = $targetId;
                        }
                    }

                    if ($existing) {
                        if ($refId !== '') {
                            $targetId = $this->computeFingerprint($effectiveRefId, $payloadCustom);
                            if ($targetId !== $id) {
                                $redirect = $cart->getLineItems()->get($targetId);
                                if ($redirect) {
                                    $id = $targetId;
                                }
                            }
                        }
                        $item['id'] = $id;
                    }

                    $qty = $item['quantity'] ?? null;
                    $qty = is_numeric($qty) ? (int)$qty : null;
                    if ($qty === null || $qty <= 0) {
                        if ($existing) {
                            $item['quantity'] = $existing->getQuantity();
                        } else {
                            unset($item['quantity']);
                        }
                    } else {
                        $item['quantity'] = $qty;
                    }

                    if (!empty($payloadCustom)) {
                        $basePayload = [];
                        if ($existing) {
                            $basePayload = (array) $existing->getPayload();
                        } elseif (isset($item['payload']) && \is_array($item['payload'])) {
                            $basePayload = $item['payload'];
                        }
                        $currentInfoplus = $basePayload['infoplus_customfields'] ?? [];
                        if (!\is_array($currentInfoplus)) {
                            $currentInfoplus = [];
                        }
                        $basePayload['infoplus_customfields'] = array_merge($currentInfoplus, $payloadCustom);
                        $currentGeneric = $basePayload['customFields'] ?? [];
                        if (!\is_array($currentGeneric)) {
                            $currentGeneric = [];
                        }
                        $basePayload['customFields'] = array_merge($currentGeneric, $payloadCustom);
                        $item['payload'] = $basePayload;
                        if (isset($item['customFields'])) {
                            unset($item['customFields']);
                        }
                    }

                    $items[$idx] = $item;
                }

                $request->request->set('items', $items);
            }
        }

        return $this->inner->change($request, $cart, $context);
    }
}
