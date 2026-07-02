<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Entity\ZeroSearch;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void                    add(ZeroSearchEntity $entity)
 * @method void                    set(string $key, ZeroSearchEntity $entity)
 * @method ZeroSearchEntity[]      getIterator()
 * @method ZeroSearchEntity[]      getElements()
 * @method ZeroSearchEntity|null   get(string $key)
 * @method ZeroSearchEntity|null   first()
 * @method ZeroSearchEntity|null   last()
 */
class ZeroSearchCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return ZeroSearchEntity::class;
    }
}
