<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Route;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\SalesChannel\Search\AbstractProductSearchRoute;
use Shopware\Core\Content\Product\SalesChannel\Search\ProductSearchRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Topdata\TopdataBetterSearchSW6\Service\SearchBackendRegistry;
use Topdata\TopdataBetterSearchSW6\Service\ProfileResolver;
use Topdata\TopdataBetterSearchSW6\Service\ProfileRegistry;

#[AsDecorator(decorates: AbstractProductSearchRoute::class)]
class DecoratedProductSearchRoute extends AbstractProductSearchRoute
{
    public function __construct(
        private readonly AbstractProductSearchRoute $decorated,
        private readonly SearchBackendRegistry $backendRegistry,
        private readonly SystemConfigService $systemConfigService,
        private readonly Connection $connection,
        private readonly ProfileResolver $profileResolver,
        private readonly ProfileRegistry $profileRegistry
    ) {}

    public function getDecorated(): AbstractProductSearchRoute
    {
        return $this->decorated;
    }

    public function load(Request $request, SalesChannelContext $context, Criteria $criteria): ProductSearchRouteResponse
    {
        $startTime = (int) (microtime(true) * 1000);
        $this->applyCategoryExclusions($criteria, $context);

        $term = $criteria->getTerm() ?? '';
        $ids = null;
        $resolvedOptions = null;

        // Resolve search profile
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

            // Re-inject the winning backend's options so $this->decorated->load()
            // sees the correct options, not the last pipeline step's options.
            if ($resolvedOptions !== null) {
                $criteria->addExtension('tdbs_options', new ArrayStruct($resolvedOptions));
            } else {
                $criteria->removeExtension('tdbs_options');
            }
        } else {
            $criteria->removeExtension('tdbs_options');

            // Fallback to active backend list loop
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

        $response = $this->decorated->load($request, $context, $criteria);

        $totalHits = $response->getListingResult()->getTotal();
        $executionTime = (int) (microtime(true) * 1000) - $startTime;

        if (!empty($term)) {
            // New: per-query profiling
            $this->logSearchResult($term, $profileId, $context->getSalesChannelId(), $totalHits, $executionTime);

            // Backward compat: zero-result tracking (retained for admin panel module)
            if ($totalHits === 0) {
                $this->logZeroSearchResult($term);
            }
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

    private function logSearchResult(string $term, string $profileId, string $salesChannelId, int $hitsCount, int $executionTimeMs): void
    {
        $term = mb_strtolower(trim($term));
        if (mb_strlen($term) > 255) {
            $term = mb_substr($term, 0, 255);
        }

        try {
            $this->connection->executeStatement(
                'INSERT INTO `tdbs_search_log` (`id`, `term`, `profile`, `sales_channel_id`, `hits_count`, `execution_time_ms`, `created_at`)
                 VALUES (:id, :term, :profile, :salesChannelId, :hits, :execution_time, :now)',
                [
                    'id' => Uuid::randomBytes(),
                    'term' => $term,
                    'profile' => $profileId,
                    'salesChannelId' => Uuid::fromHexToBytes($salesChannelId),
                    'hits' => $hitsCount,
                    'execution_time' => $executionTimeMs,
                    'now' => (new \DateTime())->format('Y-m-d H:i:s.v')
                ]
            );
        } catch (\Throwable $e) {
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
