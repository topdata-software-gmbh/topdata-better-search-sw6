<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Entity\ZeroSearch;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class ZeroSearchEntityDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'topdata_es_zero_search';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return ZeroSearchEntity::class;
    }

    public function getCollectionClass(): string
    {
        return ZeroSearchCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new StringField('term', 'term'))->addFlags(new Required()),
            (new IntField('count', 'count'))->addFlags(new Required()),
            (new DateTimeField('created_at', 'createdAt'))->addFlags(new Required()),
            (new DateTimeField('last_searched_at', 'lastSearchedAt')),
        ]);
    }
}
