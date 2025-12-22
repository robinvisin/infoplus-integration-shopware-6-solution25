<?php

namespace InfoPlusCommerce\Service;

use InfoPlusCommerce\Core\Content\InfoplusFieldDefinition\InfoplusFieldDefinitionCollection;
use InfoPlusCommerce\Core\Content\InfoplusFieldDefinition\InfoplusFieldDefinitionEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;

class AdminCustomFieldService
{
    /**
     * @var EntityRepository<InfoplusFieldDefinitionCollection>
     */
    private EntityRepository $customFieldRepository;

    /**
     * @param EntityRepository<InfoplusFieldDefinitionCollection> $customFieldRepository
     */
    public function __construct(EntityRepository $customFieldRepository)
    {
        $this->customFieldRepository = $customFieldRepository;
    }

    /**
     * @param bool $all
     * @param Context $context
     * @return array<int,array<string,mixed>>
     */
    public function list(bool $all, Context $context): array
    {
        $criteria = new Criteria();
        if (!$all) {
            $criteria->addFilter(new EqualsFilter('active', true));
        }
        $criteria->addSorting(new FieldSorting('position', 'ASC'));
        $result = $this->customFieldRepository->search($criteria, $context);

        $data = [];
        foreach ($result->getEntities() as $entity) {
            /** @var InfoplusFieldDefinitionEntity $entity */
            $data[] = [
                'id' => $entity->getId(),
                'technicalName' => $entity->getTechnicalName(),
                'label' => $entity->getLabel(),
                'type' => $entity->getType(),
                'isRequired' => $entity->getIsRequired(),
                'options' => $entity->getOptions(),
                'position' => $entity->getPosition(),
                'active' => $entity->isActive(),
                'showInStorefront' => $entity->getShowInStorefront(),
                'staticPrice' => $entity->getStaticPrice(),
            ];
        }

        return $data;
    }

    /**
     * @param string $id
     * @param Context $context
     * @return array<string,mixed>|null
     */
    public function get(string $id, Context $context): ?array
    {
        $criteria = new Criteria([$id]);
        $result = $this->customFieldRepository->search($criteria, $context);
        /** @var InfoplusFieldDefinitionEntity|null $entity */
        $entity = $result->get($id);

        if (!$entity) {
            return null;
        }

        return [
            'id' => $entity->getId(),
            'technicalName' => $entity->getTechnicalName(),
            'label' => $entity->getLabel(),
            'type' => $entity->getType(),
            'isRequired' => $entity->getIsRequired(),
            'options' => $entity->getOptions(),
            'position' => $entity->getPosition(),
            'active' => $entity->isActive(),
            'showInStorefront' => $entity->getShowInStorefront(),
            'staticPrice' => $entity->getStaticPrice(),
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @param Context $context
     * @return void
     */
    public function create(array $data, Context $context): void
    {
        $allowedTypes = ['text', 'textarea', 'number', 'money', 'boolean', 'select'];
        if (!isset($data['type']) || !in_array($data['type'], $allowedTypes, true)) {
            throw new \InvalidArgumentException('Invalid type. Allowed values: ' . implode(', ', $allowedTypes));
        }

        $data['id'] = $data['id'] ?? Uuid::randomHex();
        $data['createdAt'] = (new \DateTime())->format('Y-m-d H:i:s');

        if (isset($data['options'])) {
            $data['options'] = $this->normalizeOptions($data['options']);
        }

        if (isset($data['staticPrice'])) {
            $data['staticPrice'] = $this->toFloatOrNull($data['staticPrice']);
        }

        $data['showInStorefront'] = $data['showInStorefront'] ?? false;
        $this->customFieldRepository->create([$data], $context);
    }

    /**
     * @param string $id
     * @param array<string,mixed> $data
     * @param Context $context
     * @return void
     */
    public function update(string $id, array $data, Context $context): void
    {
        $allowedTypes = ['text', 'textarea', 'number', 'money', 'boolean', 'select'];
        if (!isset($data['type']) || !in_array($data['type'], $allowedTypes, true)) {
            throw new \InvalidArgumentException('Invalid type. Allowed values: ' . implode(', ', $allowedTypes));
        }

        if (isset($data['options'])) {
            $data['options'] = $this->normalizeOptions($data['options']);
        }

        if (isset($data['staticPrice'])) {
            $data['staticPrice'] = $this->toFloatOrNull($data['staticPrice']);
        }

        $data['id'] = $id;
        $data['showInStorefront'] = $data['showInStorefront'] ?? false;
        $this->customFieldRepository->update([$data], $context);
    }

    /**
     * @param string $id
     * @param Context $context
     * @return void
     */
    public function delete(string $id, Context $context): void
    {
        $this->customFieldRepository->delete([[ 'id' => $id ]], $context);
    }

    /**
     * @param string|array<mixed> $options
     * @return array<int,mixed>
     */
    private function normalizeOptions($options): array
    {
        if (is_string($options)) {
            $raw = trim($options);
            if ($raw === '') {
                return [];
            }
            $lines = preg_split("~\r?\n~", $raw) ?: [];
            if (count($lines) === 1 && str_contains($raw, ',')) {
                $lines = array_map('trim', explode(',', $raw));
            }
            $normalized = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $label = $line;
                $price = null;
                if (str_contains($line, ':')) {
                    [$left, $right] = array_map('trim', explode(':', $line, 2));
                    $label = $left;
                    $price = $this->toFloatOrNull($right);
                }
                $entry = ['label' => $label, 'value' => $label];
                if ($price !== null) {
                    $entry['price'] = $price;
                }
                $normalized[] = $entry;
            }
            return $normalized;
        }

        $arrayOptions = (array)$options;
        $normalized = [];
        foreach ($arrayOptions as $v) {
            if (is_array($v)) {
                $label = isset($v['label']) ? (string)$v['label'] : (isset($v['name']) ? (string)$v['name'] : '');
                if ($label === '') {
                    continue;
                }
                $price = null;
                if (isset($v['price'])) {
                    $price = $this->toFloatOrNull($v['price']);
                }
                $value = isset($v['value']) ? (string)$v['value'] : $label;
                $row = ['label' => $label, 'value' => $value];
                if ($price !== null) {
                    $row['price'] = $price;
                }
                $normalized[] = $row;
            } elseif (is_string($v)) {
                $label = trim($v);
                if ($label === '') {
                    continue;
                }
                $normalized[] = ['label' => $label, 'value' => $label];
            }
        }

        return $normalized;
    }

    private function toFloatOrNull(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_float($raw) || is_int($raw)) {
            return (float)$raw;
        }
        if (!is_string($raw)) {
            return null;
        }
        $s = trim($raw);
        $s = str_replace(',', '.', $s);
        // strip currency symbols/letters
        $s = preg_replace('~[^0-9.\-]~', '', $s) ?? '';
        if ($s === '') {
            return null;
        }
        $f = (float)$s;
        return is_finite($f) ? $f : null;
    }
}
