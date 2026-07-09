<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Route;

use Shopware\Core\Content\Product\SalesChannel\Suggest\AbstractProductSuggestRoute;
use Shopware\Core\Content\Product\SalesChannel\Suggest\ProductSuggestRoute;
use Shopware\Core\Content\Product\SalesChannel\Suggest\ProductSuggestRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Topdata\TopdataBetterSearchSW6\Service\SearchBackendRegistry;
use Topdata\TopdataBetterSearchSW6\Service\ProfileResolver;
use Topdata\TopdataBetterSearchSW6\Service\ProfileRegistry;

#[AsDecorator(decorates: ProductSuggestRoute::class)]
class DecoratedProductSuggestRoute extends AbstractProductSuggestRoute
{
    public function __construct(
        private readonly ProductSuggestRoute $decorated,
        private readonly SearchBackendRegistry $backendRegistry,
        private readonly SystemConfigService $systemConfigService,
        private readonly ProfileResolver $profileResolver,
        private readonly ProfileRegistry $profileRegistry
    ) {}

    public function getDecorated(): ProductSuggestRoute
    {
        return $this->decorated;
    }

    public function load(Request $request, SalesChannelContext $context, Criteria $criteria): ProductSuggestRouteResponse
    {
        $this->applyCategoryExclusions($criteria, $context);

        $ids = null;
        $resolvedOptions = null;
        $profileId = $this->profileResolver->resolveActiveProfile($request);
        $profile = $this->profileRegistry->getProfile($profileId);

        if ($profile !== null && isset($profile['pipeline']) && \is_array($profile['pipeline'])) {
            foreach ($profile['pipeline'] as $step) {
                if (!isset($step['backend'])) {
                    continue;
                }

                $backend = $this->backendRegistry->getBackend($step['backend']);
                if ($backend === null) {
                    continue;
                }

                $options = $step['options'] ?? [];
                $criteria->addExtension('tdbs_options', new ArrayStruct($options));

                $resultIds = $backend->search($criteria, $context);
                if ($resultIds !== null) {
                    $ids = $resultIds;
                    $resolvedOptions = $options;
                    break;
                }
            }

            if ($resolvedOptions !== null) {
                $criteria->addExtension('tdbs_options', new ArrayStruct($resolvedOptions));
            } else {
                $criteria->removeExtension('tdbs_options');
            }
        } else {
            $criteria->removeExtension('tdbs_options');

            foreach ($this->backendRegistry->getActiveBackends() as $backend) {
                $resultIds = $backend->search($criteria, $context);
                if ($resultIds !== null) {
                    $ids = $resultIds;
                    break;
                }
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
