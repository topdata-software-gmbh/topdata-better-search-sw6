<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Route;

use Shopware\Core\Content\Product\SalesChannel\Suggest\AbstractProductSuggestRoute;
use Shopware\Core\Content\Product\SalesChannel\Suggest\ProductSuggestRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Topdata\TopdataBetterSearchSW6\Service\SearchBackendRegistry;

#[AsDecorator(decorates: AbstractProductSuggestRoute::class)]
class DecoratedProductSuggestRoute extends AbstractProductSuggestRoute
{
    public function __construct(
        private readonly AbstractProductSuggestRoute $decorated,
        private readonly SearchBackendRegistry $backendRegistry,
        private readonly SystemConfigService $systemConfigService
    ) {}

    public function getDecorated(): AbstractProductSuggestRoute
    {
        return $this->decorated;
    }

    public function load(Request $request, SalesChannelContext $context, Criteria $criteria): ProductSuggestRouteResponse
    {
        $this->applyCategoryExclusions($criteria, $context);

        $ids = null;
        foreach ($this->backendRegistry->getActiveBackends() as $backend) {
            $resultIds = $backend->search($criteria, $context);
            if ($resultIds !== null) {
                $ids = $resultIds;
                break;
            }
        }

        if ($ids !== null) {
            if (empty($ids)) {
                $ids = ['00000000000000000000000000000000'];
            }
            $criteria->addFilter(new EqualsAnyFilter('id', $ids));
            $criteria->setTerm(null);
        }

        return $this->decorated->load($request, $context, $criteria);
    }

    private function applyCategoryExclusions(Criteria $criteria, SalesChannelContext $context): void
    {
        /** @var string[]|null $excludedCategories */
        $excludedCategories = $this->systemConfigService->get(
            'TopdataBetterSearchSW6.config.excludedCategories',
            $context->getSalesChannelId()
        );

        if (!empty($excludedCategories) && \is_array($excludedCategories)) {
            $criteria->addFilter(
                new NotFilter(NotFilter::CONNECTION_AND, [
                    new EqualsAnyFilter('categoryTree', $excludedCategories)
                ])
            );
        }
    }
}
