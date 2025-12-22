<?php

declare(strict_types=1);

namespace InfoPlusCommerce\Storefront\Route;

use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartItemAddRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartResponse;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Uuid\Uuid;

class InfoplusCartItemAddRoute extends AbstractCartItemAddRoute
{
    public function __construct(private readonly AbstractCartItemAddRoute $inner)
    {
    }

    public function getDecorated(): AbstractCartItemAddRoute
    {
        return $this->inner;
    }

    /**
     * @param array<int, LineItem>|null $items
     */
    public function add(Request $request, Cart $cart, SalesChannelContext $context, ?array $items): CartResponse
    {
        $postAll = $request->request->all();
        // @phpstan-ignore-next-line
        if (empty($postAll) && !empty($_POST)) {
            // @phpstan-ignore-next-line
            $postAll = $_POST;
        }
        if (empty($postAll)) {
            try {
                $postAll = $request->toArray();
            } catch (\Throwable) {
                $postAll = [];
            }
        }
        $isAdminProxy = str_contains((string)$request->getPathInfo(), '/store-api/');
        $postLineItems = [];
        $tmpLineItems = $postAll['lineItems'] ?? null;
        if (\is_array($tmpLineItems)) {
            $postLineItems = $tmpLineItems;
        } else {
            $postLineItems = $request->request->all('lineItems');
        }

        if (\is_array($items) && !empty($items)) {
            foreach ($items as $idx => $li) {
                $refId = (string) $li->getReferencedId();
                if ($refId === '') {
                    continue;
                }

                $payload = (array) $li->getPayload();
                $payloadCustom = $payload['infoplus_customfields'] ?? [];
                if (!\is_array($payloadCustom)) {
                    $payloadCustom = [];
                }

                foreach ($postAll as $k => $v) {
                    if (\is_string($k) && str_starts_with($k, 'infoplus_')) {
                        $payloadCustom[$k] = $v;
                    }
                }

                if (!empty($postLineItems)) {
                    foreach ($postLineItems as $key => $row) {
                        if (!\is_array($row)) {
                            continue;
                        }
                        $rowRef = $row['referencedId'] ?? $key;
                        if ($rowRef && $rowRef === $refId) {
                            $flat = $row['customFields'] ?? [];
                            if (!\is_array($flat)) {
                                $flat = [];
                            }
                            foreach ($flat as $fk => $fv) {
                                if (\is_string($fk) && str_starts_with($fk, 'infoplus_')) {
                                    $payloadCustom[$fk] = $fv;
                                }
                            }
                            $pl = $row['payload']['infoplus_customfields'] ?? [];
                            if (\is_array($pl)) {
                                foreach ($pl as $pk => $pv) {
                                    if (\is_string($pk) && str_starts_with($pk, 'infoplus_')) {
                                        $payloadCustom[$pk] = $pv;
                                    }
                                }
                            }
                            break;
                        }
                    }
                }

                $content = $request->getContent();
                if ($content !== '') {
                    try {
                        $json = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                    } catch (\Throwable) {
                        $json = null;
                    }
                    if (\is_array($json)) {
                        foreach ($json as $jk => $jv) {
                            if (\is_string($jk) && str_starts_with($jk, 'infoplus_')) {
                                $payloadCustom[$jk] = $jv;
                            }
                        }
                        if (isset($json['items']) && \is_array($json['items'])) {
                            foreach ($json['items'] as $row) {
                                if (!\is_array($row)) {
                                    continue;
                                }
                                $rowRef = $row['referencedId'] ?? null;
                                if ($rowRef && $rowRef === $refId) {
                                    $flat = $row['customFields'] ?? [];
                                    if (!\is_array($flat)) {
                                        $flat = [];
                                    }
                                    foreach ($flat as $fk => $fv) {
                                        if (\is_string($fk) && str_starts_with($fk, 'infoplus_')) {
                                            $payloadCustom[$fk] = $fv;
                                        }
                                    }
                                    $pl = $row['payload']['infoplus_customfields'] ?? [];
                                    if (\is_array($pl)) {
                                        foreach ($pl as $pk => $pv) {
                                            if (\is_string($pk) && str_starts_with($pk, 'infoplus_')) {
                                                $payloadCustom[$pk] = $pv;
                                            }
                                        }
                                    }
                                    break;
                                }
                            }
                        }
                    }
                }

                if (!empty($payloadCustom)) {
                    ksort($payloadCustom);
                    $li->setPayloadValue('infoplus_customfields', $payloadCustom);
                    $existingCF = $li->getPayloadValue('customFields') ?? [];
                    if (!\is_array($existingCF)) {
                        $existingCF = [];
                    }
                    $li->setPayloadValue('customFields', array_merge($existingCF, $payloadCustom));

                    $baseRef = $refId;
                    if ($isAdminProxy && $li->getId() && $li->getId() === $refId) {
                        $pv = $payload['productNumber'] ?? $payload['productnumber'] ?? $payload['product_number'] ?? null;
                        if (\is_string($pv) && $pv !== '') {
                            $baseRef = $pv;
                        }
                    }

                    $fingerprint = substr(hash('sha256', $baseRef . '|' . json_encode($payloadCustom)), 0, 32);
                    $li->setId($fingerprint);
                } elseif ($isAdminProxy) {
                    $li->setId(Uuid::randomHex());
                    $li->setStackable(false);
                    $li->setPayloadValue('infoplus_pending', 1);
                }

                $items[$idx] = $li;
            }

            return $this->inner->add($request, $cart, $context, $items);
        }

        if ($items === null) {
            $rawItems = $request->request->all('items');
            if (empty($rawItems) && isset($postAll['items']) && \is_array($postAll['items'])) {
                $rawItems = $postAll['items'];
            }
            foreach ($rawItems as $idx => $item) {
                if (!\is_array($item)) {
                    continue;
                }
                $refId = $item['referencedId'] ?? null;
                if (!\is_string($refId) || $refId === '') {
                    continue;
                }

                $payloadCustom = $item['payload']['infoplus_customfields'] ?? [];
                if (!\is_array($payloadCustom)) {
                    $payloadCustom = [];
                }
                $flatCustom = $item['customFields'] ?? [];
                if (!\is_array($flatCustom)) {
                    $flatCustom = [];
                }
                foreach ($flatCustom as $k => $v) {
                    if (\is_string($k) && str_starts_with($k, 'infoplus_')) {
                        $payloadCustom[$k] = $v;
                    }
                }
                ksort($payloadCustom);

                if (!empty($payloadCustom)) {
                    $item['payload'] = is_array($item['payload'] ?? null) ? $item['payload'] : [];
                    $item['payload']['infoplus_customfields'] = $payloadCustom;
                    $item['customFields'] = is_array($item['customFields'] ?? null) ? $item['customFields'] : [];
                    foreach ($payloadCustom as $k => $v) {
                        $item['customFields'][$k] = $v;
                    }

                    $baseRef = $refId;
                    if ($isAdminProxy && isset($item['id']) && $item['id'] === $refId) {
                        $pv = $item['payload']['productNumber'] ?? $item['payload']['productnumber'] ?? $item['productNumber'] ?? $item['productnumber'] ?? null;
                        if (\is_string($pv) && $pv !== '') {
                            $baseRef = $pv;
                        }
                    }

                    $fingerprint = substr(hash('sha256', $baseRef . '|' . json_encode($payloadCustom)), 0, 32);
                    $item['id'] = $fingerprint;
                } elseif ($isAdminProxy) {
                    $item['id'] = Uuid::randomHex();
                    $item['stackable'] = false;
                    $item['payload'] = is_array($item['payload'] ?? null) ? $item['payload'] : [];
                    $item['payload']['infoplus_pending'] = 1;
                }

                $rawItems[$idx] = $item;
            }

            $request->request->set('items', $rawItems);
        }

        return $this->inner->add($request, $cart, $context, $items);
    }
}
