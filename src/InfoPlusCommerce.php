<?php

declare(strict_types=1);

namespace InfoPlusCommerce;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\CustomField\CustomFieldTypes;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Doctrine\DBAL\Connection;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetEntity;
use Shopware\Core\System\CustomField\CustomFieldEntity;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection;
use Shopware\Core\System\CustomField\CustomFieldCollection;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSetRelation\CustomFieldSetRelationCollection;

class InfoPlusCommerce extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        $this->createCustomFields($installContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $this->deleteCustomFields($uninstallContext->getContext());
        $this->dropPluginData($uninstallContext->getContext());
    }

    public function activate(ActivateContext $activateContext): void
    {
        $this->createCustomFields($activateContext->getContext());
    }

    public function update(UpdateContext $updateContext): void
    {
        $this->createCustomFields($updateContext->getContext());
    }
    private function createCustomFields(Context $context): void
    {
        if ($this->container === null) {
            throw new \RuntimeException('Container not available');
        }
        /** @var ContainerInterface $container */
        $container = $this->container;
        /** @var EntityRepository<CustomFieldSetCollection> $customFieldSetRepository */
        $customFieldSetRepository = $container->get('custom_field_set.repository');
        /** @var EntityRepository<CustomFieldCollection> $customFieldRepository */
        $customFieldRepository = $container->get('custom_field.repository');
        /** @var EntityRepository<CustomFieldSetRelationCollection> $relationRepository */
        $relationRepository = $container->get('custom_field_set_relation.repository');

        $setName = 'product_infoplus_data';

        // Ensure the Custom Field Set exists (search by name, reuse ID if found)
        $setCriteria = (new Criteria())->addFilter(new EqualsFilter('name', $setName));
        $existingSet = $customFieldSetRepository->search($setCriteria, $context)->getEntities()->first();
        /** @var CustomFieldSetEntity|null $existingSet */
        $setId = $existingSet ? $existingSet->getId() : Uuid::randomHex();

        $setPayload = [
            'id' => $setId,
            'name' => $setName,
            'config' => [
                'label' => [
                    'en-GB' => 'InfoPlus Product Data',
                    'de-DE' => 'InfoPlus Produktdaten',
                ],
            ],
        ];
        if (!$existingSet) {
            $setPayload['relations'] = [
                [
                    'entityName' => 'product',
                ],
            ];
        }

        $customFieldSetRepository->upsert([$setPayload], $context);

        // If set already existed, ensure the relation to 'product' exists
        if ($existingSet) {
            $relCriteria = (new Criteria())
                ->addFilter(new EqualsFilter('customFieldSetId', $setId))
                ->addFilter(new EqualsFilter('entityName', 'product'));
            $existingRel = $relationRepository->search($relCriteria, $context)->getEntities()->first();
            if (!$existingRel) {
                $relationRepository->create([[
                    'id' => Uuid::randomHex(),
                    'customFieldSetId' => $setId,
                    'entityName' => 'product',
                ]], $context);
            }
        }

        // Define custom fields to create/update and always include the set ID and a stable ID
        $fields = [
            'infoplus_major_group_id' => [
                'type' => CustomFieldTypes::INT,
                'config' => [
                    'label' => [
                        'en-GB' => 'InfoPlus Major Group ID',
                        'de-DE' => 'InfoPlus Hauptkategorie ID',
                    ],
                    'type' => 'number',
                    'min' => 0,
                    'placeholder' => [
                        'en-GB' => 'Enter major group ID...',
                        'de-DE' => 'Hauptkategorie ID eingeben...',
                    ],
                ],
            ],
            'infoplus_sub_group_id' => [
                'type' => CustomFieldTypes::INT,
                'config' => [
                    'label' => [
                        'en-GB' => 'InfoPlus Sub Group ID',
                        'de-DE' => 'InfoPlus Unterkategorie ID',
                    ],
                    'type' => 'number',
                    'min' => 0,
                    'placeholder' => [
                        'en-GB' => 'Enter sub group ID...',
                        'de-DE' => 'Unterkategorie ID eingeben...',
                    ],
                ],
            ],
        ];

        $upserts = [];
        $fieldNames = array_keys($fields);
        $criteria = (new Criteria())->addFilter(new EqualsFilter('customFieldSetId', $setId));
        $criteria->addFilter(new OrFilter(array_map(fn($name) => new EqualsFilter('name', $name), $fieldNames)));
        /** @var CustomFieldEntity[] $existingFields */
        $existingFields = $customFieldRepository->search($criteria, $context)->getEntities();
        $existingByName = [];
        foreach ($existingFields as $field) {
            $existingByName[$field->getName()] = $field;
        }
        foreach ($fields as $name => $def) {
            $existing = $existingByName[$name] ?? null;
            $upserts[] = array_merge(
                [
                    'id' => $existing ? $existing->getId() : Uuid::randomHex(),
                    'name' => $name,
                    'type' => $def['type'],
                    'customFieldSetId' => $setId,
                ],
                ['config' => $def['config']]
            );
        }

        // Upsert fields individually to respect the unique constraint on name
        $customFieldRepository->upsert($upserts, $context);
    }

    private function deleteCustomFields(Context $context): void
    {
        if ($this->container === null) {
            throw new \RuntimeException('Container not available');
        }
        /** @var ContainerInterface $container */
        $container = $this->container;
        /** @var EntityRepository<CustomFieldSetCollection> $customFieldSetRepository */
        $customFieldSetRepository = $container->get('custom_field_set.repository');
        /** @var EntityRepository<CustomFieldCollection> $customFieldRepository */
        $customFieldRepository = $container->get('custom_field.repository');

        $setName = 'product_infoplus_data';

        // Find set ID by name
        $setCriteria = (new Criteria())->addFilter(new EqualsFilter('name', $setName));
        $existingSet = $customFieldSetRepository->search($setCriteria, $context)->getEntities()->first();
        /** @var CustomFieldSetEntity|null $existingSet */
        if (!$existingSet) {
            return; // Nothing to delete
        }

        $setId = $existingSet->getId();

        // Delete the two custom fields if they exist (by ID)
        $deleteNames = ['infoplus_major_group_id', 'infoplus_sub_group_id'];
        $criteria = (new Criteria())->addFilter(new EqualsFilter('customFieldSetId', $setId));
        $criteria->addFilter(new OrFilter(array_map(fn($name) => new EqualsFilter('name', $name), $deleteNames)));
        /** @var CustomFieldEntity[] $fieldsToDelete */
        $fieldsToDelete = $customFieldRepository->search($criteria, $context)->getEntities();
        $deletePayload = [];
        foreach ($fieldsToDelete as $field) {
            $deletePayload[] = ['id' => $field->getId()];
        }
        if (!empty($deletePayload)) {
            $customFieldRepository->delete($deletePayload, $context);
        }

        // Finally, delete the set itself by ID
         $customFieldSetRepository->delete([
             ['id' => $setId],
         ], $context);
    }

    private function dropPluginData(Context $context): void
    {
        if ($this->container === null) {
            throw new \RuntimeException('Container not available');
        }
        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);
        $schemaManager = $connection->createSchemaManager();

        $tables = [
            'infoplus_field_definition',
            'infoplus_category',
            'infoplus_order_sync',
            'infoplus_id_mapping',
        ];

        foreach ($tables as $table) {
            if ($schemaManager->tablesExist([$table])) {
                $connection->executeStatement('DROP TABLE IF EXISTS `' . $table . '`');
            }
        }

         // Clean plugin migration entries from migration tracking table
        if ($schemaManager->tablesExist(['migration'])) {
            // Remove all migration rows for this plugin namespace
            $connection->executeStatement(
                'DELETE FROM `migration` WHERE `class` LIKE :classPrefix',
                ['classPrefix' => 'InfoPlusCommerce\\Migration\\%']
            );
        }
    }
}
