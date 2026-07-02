<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Route;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\SalesChannel\Search\AbstractProductSearchRoute;
use Shopware\Core\Content\Product\SalesChannel\Search\ProductSearchRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Topdata\TopdataBetterSearchSW6\Service\SearchBackendRegistry;

#[AsDecorator(decorates: AbstractProductSearchRoute::class)]
class DecoratedProductSearchRoute extends AbstractProductSearchRoute
{
    public function __construct(
        private readonly AbstractProductSearchRoute $decorated,
        private readonly SearchBackendRegistry $backendRegistry,
        private readonly SystemConfigService $systemConfigService,
        private readonly Connection $connection
    ) {}

    public function getDecorated(): AbstractProductSearchRoute
    {
        return $this->decorated;
    }

    public function load(Request $request, SalesChannelContext $context, Criteria $criteria): ProductSearchRouteResponse
    {
        $this->applyCategoryExclusions($criteria, $context);

        $term = $criteria->getTerm() ?? '';
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

        $response = $this->decorated->load($request, $context, $criteria);

        if ($response->getListingResult()->getTotal() === 0 && !empty($term)) {
            $this->logZeroSearchResult($term);
        }

        return $response;
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

    private function logZeroSearchResult(string $term): void
    {
        $term = mb_strtolower(trim($term));
        if (mb_strlen($term) > 255) {
            $term = mb_substr($term, 0, 255);
        }

        try {
            $this->connection->executeStatement(
                'INSERT INTO `tdbs_zero_search` (`id`, `term`, `count`, `created_at`, `last_searched_at`)
                 VALUES (:id, :term, 1, :now, :now)
                 ON DUPLICATE KEY UPDATE `count` = `count` + 1, `last_searched_at` = :now',
                [
                    'id' => Uuid::randomBytes(),
                    'term' => $term,
                    'now' => (new \DateTime())->format('Y-m-d H:i:s.v')
                ]
            );
        } catch (\Throwable $e) {
        }
    }
}
