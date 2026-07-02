<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Service;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

interface SearchBackendInterface
{
    public function getName(): string;

    /**
     * Executes storefront search and returns a list of matching Product IDs.
     * Returning null triggers fallback to the next configured search handler.
     *
     * @return string[]|null
     */
    public function search(Criteria $criteria, SalesChannelContext $context): ?array;

    /**
     * Executes indexing of product documents.
     *
     * @param array<int, array<string, mixed>> $products
     */
    public function index(array $products): void;
}
