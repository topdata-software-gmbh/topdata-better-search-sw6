<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Service\Backend;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Topdata\TopdataBetterSearchSW6\Service\SearchBackendInterface;

#[AutoconfigureTag('tdbs.search_backend')]
class ShopwareCoreBackend implements SearchBackendInterface
{
    public function getName(): string
    {
        return 'shopware_core';
    }

    public function search(Criteria $criteria, SalesChannelContext $context): ?array
    {
        return null;
    }

    public function index(array $products): void
    {
    }
}
