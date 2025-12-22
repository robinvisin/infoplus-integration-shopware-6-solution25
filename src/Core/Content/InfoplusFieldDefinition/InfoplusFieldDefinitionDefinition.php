<?php

namespace InfoPlusCommerce\Core\Content\InfoplusFieldDefinition;

use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;

class InfoplusFieldDefinitionDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'infoplus_field_definition';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return InfoplusFieldDefinitionCollection::class;
    }

    public function getEntityClass(): string
    {
        return InfoplusFieldDefinitionEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new ApiAware(), new Required(), new PrimaryKey()),
            new StringField('technical_name', 'technicalName'),
            new StringField('label', 'label'),
            new StringField('type', 'type'),
            (new BoolField('is_required', 'isRequired'))->addFlags(new Required()),
            new JsonField('options', 'options'),
            (new IntField('position', 'position'))->addFlags(new ApiAware()),
            (new BoolField('active', 'active'))->addFlags(new ApiAware()),
            (new DateTimeField('created_at', 'createdAt'))->addFlags(new Required()),
            new DateTimeField('updated_at', 'updatedAt'),
            (new BoolField('show_in_storefront', 'showInStorefront'))->addFlags(new ApiAware()),
            (new FloatField('static_price', 'staticPrice'))->addFlags(new ApiAware()),
        ]);
    }
}
