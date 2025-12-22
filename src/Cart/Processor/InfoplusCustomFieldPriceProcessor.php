<?php

declare(strict_types=1);

namespace InfoPlusCommerce\Cart\Processor;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use InfoPlusCommerce\Core\Content\InfoplusFieldDefinition\InfoplusFieldDefinitionEntity;

class InfoplusCustomFieldPriceProcessor implements CartProcessorInterface
{
    public function __construct(private readonly DefinitionInstanceRegistry $definitionRegistry)
    {
    }

    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        foreach ($toCalculate->getLineItems() as $lineItem) {
            $payload = $lineItem->getPayload() ?: [];
            $custom = $payload['infoplus_customfields'] ?? ($payload['customFields'] ?? []);
            if (!is_array($custom) || empty($custom)) {
                $lineItem->setPayloadValue('infoplus_price_breakdown', []);
                continue;
            }

            $presentKeys = [];
            foreach ($custom as $key => $val) {
                if (is_string($key) && str_starts_with($key, 'infoplus_')) {
                    $presentKeys[] = substr($key, strlen('infoplus_'));
                }
            }
            $presentKeys = array_values(array_unique(array_filter($presentKeys)));
            if (empty($presentKeys)) {
                $lineItem->setPayloadValue('infoplus_price_breakdown', []);
                continue;
            }

            $repo = $this->definitionRegistry->getRepository('infoplus_field_definition');
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsAnyFilter('technicalName', $presentKeys));
            $criteria->addFilter(new EqualsFilter('active', true));
            $defs = $repo->search($criteria, $context->getContext());

            $deltaPerUnit = 0.0;
            $countedKeys = [];
            $breakdown = [];

            foreach ($defs->getEntities() as $def) {
                /** @var InfoplusFieldDefinitionEntity $def */
                $type = (string) $def->getType();
                $tech = (string) $def->getTechnicalName();
                if ($tech === '') {
                    continue;
                }
                $fieldKey = 'infoplus_' . $tech;
                if ($fieldKey == 'infoplus_major_group_id' || $fieldKey == 'infoplus_sub_group_id') {
                    continue;
                }
                $rawValue = $custom[$fieldKey] ?? null;
                $label = $def->getLabel() ?: $tech;
                $show =  $def->getShowInStorefront();

                if (!isset($breakdown[$fieldKey])) {
                    $breakdown[$fieldKey] = $this->addOrAccumulate(null, $fieldKey, $tech, $label, $rawValue, 0.0, $show);
                }
                $countedKeys[$fieldKey] = true;

                if (in_array($type, ['price', 'money'], true)) {
                    if ($rawValue !== null && $rawValue !== '') {
                        $val = max(0.0, $this->toFloat($rawValue));
                        if ($val !== 0.0) {
                            $deltaPerUnit += $val;
                            $breakdown[$fieldKey] = $this->addOrAccumulate($breakdown[$fieldKey], $fieldKey, $tech, $label, $rawValue, $val, $show);
                        }
                    }
                    continue;
                }

                if ($type === 'select') {
                    if ($rawValue !== null && $rawValue !== '') {
                        $optPrice = $this->matchOptionPrice($def, (string) $rawValue);
                        if ($optPrice !== null) {
                            $optPrice = max(0.0, $optPrice);
                        }
                        if ($optPrice !== null && $optPrice !== 0.0) {
                            $deltaPerUnit += $optPrice;
                            $breakdown[$fieldKey] = $this->addOrAccumulate($breakdown[$fieldKey], $fieldKey, $tech, $label, $rawValue, $optPrice, $show);
                        } else {
                            $sp = $def->getStaticPrice();
                            if ($sp !== null) {
                                $sp = max(0.0, (float) $sp);
                            }
                            if ($sp !== null && (float) $sp !== 0.0) {
                                $deltaPerUnit += (float) $sp;
                                $breakdown[$fieldKey] = $this->addOrAccumulate($breakdown[$fieldKey], $fieldKey, $tech, $label, $rawValue, (float) $sp, $show);
                            }
                        }
                    }
                    continue;
                }

                $staticPrice = $def->getStaticPrice();
                if ($staticPrice !== null) {
                    $staticPrice = max(0.0, (float) $staticPrice);
                    $apply = false;
                    if ($type === 'boolean') {
                        $apply = filter_var($rawValue, FILTER_VALIDATE_BOOLEAN) || (string) $rawValue === '1' || $rawValue === 1;
                    } else {
                        $apply = ($rawValue !== null && $rawValue !== '' && $rawValue !== '0');
                    }
                    if ($apply && (float) $staticPrice !== 0.0) {
                        $deltaPerUnit += (float) $staticPrice;
                        $breakdown[$fieldKey] = $this->addOrAccumulate($breakdown[$fieldKey], $fieldKey, $tech, $label, $rawValue, (float) $staticPrice, $show);
                    }
                }
            }

            foreach ($custom as $key => $raw) {
                if (!is_string($key) || !str_starts_with($key, 'infoplus_')) {
                    continue;
                }
                if (isset($countedKeys[$key])) {
                    continue;
                }
                if ($key == 'infoplus_major_group_id' || $key == 'infoplus_sub_group_id') {
                    continue;
                }
                $techName = substr($key, strlen('infoplus_'));
                $label = $this->humanizeTechnicalName($techName);
                if (!isset($breakdown[$key])) {
                    $breakdown[$key] = $this->addOrAccumulate(null, $key, $techName, $label, $raw, 0.0, true);
                }
            }

            $lineItem->setPayloadValue('infoplus_price_breakdown', array_values($breakdown));

            if ($deltaPerUnit === 0.0) {
                continue;
            }

            $currentPrice = $lineItem->getPrice();
            if ($currentPrice instanceof CalculatedPrice) {
                $oldUnit = (float) $currentPrice->getUnitPrice();
                $qty = max(1, (int) $currentPrice->getQuantity());
                $newUnit = $oldUnit + $deltaPerUnit;
                $factor = $oldUnit > 0.0 ? ($newUnit / $oldUnit) : 1.0;
                $oldTaxes = $currentPrice->getCalculatedTaxes();
                $newTaxes = new CalculatedTaxCollection();
                foreach ($oldTaxes as $tax) {
                    $amount = (float) $tax->getTax() * $factor;
                    $price = (float) $tax->getPrice() * $factor;
                    $newTaxes->add(new CalculatedTax($amount, $tax->getTaxRate(), $price));
                }
                $currentPrice->overwrite($newUnit, $newUnit * $qty, $newTaxes);
                continue;
            }

            $def = $lineItem->getPriceDefinition();
            if ($def instanceof QuantityPriceDefinition) {
                $base = (float) $def->getPrice();
                $newUnit = $base + $deltaPerUnit;
                $taxRules = $def->getTaxRules();
                $quantity = $lineItem->getQuantity();
                $lineItem->setPriceDefinition(new QuantityPriceDefinition($newUnit, $taxRules, $quantity));
            }
        }
    }

    private function toFloat(mixed $raw): float
    {
        if (is_float($raw) || is_int($raw)) {
            return (float) $raw;
        }
        if (!is_string($raw)) {
            return 0.0;
        }
        $norm = str_replace(',', '.', $raw);
        if (!is_numeric($norm)) {
            $norm = preg_replace('~[^0-9.-]~', '', $raw) ?? '0';
        }
        return (float) $norm;
    }

    private function matchOptionPrice(InfoplusFieldDefinitionEntity $def, string $selected): ?float
    {
        $opts = $def->getOptions();
        if (!is_array($opts)) {
            return null;
        }
        foreach ($opts as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = isset($row['label']) ? (string) $row['label'] : (isset($row['name']) ? (string) $row['name'] : null);
            $value = isset($row['value']) ? (string) $row['value'] : ($label ?? null);
            if ($value === null) {
                continue;
            }
            if ($selected === (string) $value || $selected === (string) $label) {
                if (isset($row['price'])) {
                    $p = $row['price'];
                    if (is_numeric($p)) {
                        return max(0.0, (float) $p);
                    }
                    if (is_string($p)) {
                        $p = str_replace(',', '.', $p);
                        $p = preg_replace('~[^0-9.-]~', '', $p) ?? '';
                        if ($p !== '') {
                            return max(0.0, (float) $p);
                        }
                    }
                }
                return null;
            }
        }
        return null;
    }

    private function humanizeTechnicalName(string $tech): string
    {
        $t = str_replace(['infoplus_', '_'], ['', ' '], $tech);
        $t = preg_replace('~\s+~', ' ', $t);
        $t = trim($t);
        return $t === '' ? $tech : ucwords($t);
    }

    /**
     * @param array<string,mixed>|null $current
     * @return array<string,mixed>
     */
    private function addOrAccumulate(?array $current, string $key, string $technicalName, string $label, mixed $value, float $priceDelta, bool $showInStorefront): array
    {
        if ($current) {
            $priceDelta += (float) ($current['priceDelta'] ?? 0.0);
        }
        return [
            'key' => $key,
            'technicalName' => $technicalName,
            'label' => $label,
            'value' => $value,
            'priceDelta' => $priceDelta,
            'showInStorefront' => $showInStorefront,
        ];
    }
}
