<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Subscriber;

use Shopware\Core\Content\Product\Events\ProductSearchCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSuggestCriteriaEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * This subscriber modifies search criteria to exclude certain categories from search results.
 * It listens to ProductSearchCriteriaEvent and ProductSuggestCriteriaEvent events.
 */
class SearchCriteriaSubscriber implements EventSubscriberInterface
{
    private SystemConfigService $systemConfigService;

    /**
     * Initializes the SearchCriteriaSubscriber with the system configuration service.
     *
     * @param SystemConfigService $systemConfigService The service to access system configuration
     */
    public function __construct(SystemConfigService $systemConfigService)
    {
        $this->systemConfigService = $systemConfigService;
    }

    /**
     * Returns an array of subscribed events.
     *
     * @return array<string, string> The subscribed events and their corresponding methods
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ProductSearchCriteriaEvent::class => 'onSearch',
            ProductSuggestCriteriaEvent::class => 'onSearch',
        ];
    }

    /**
     * Modifies the search criteria to exclude certain categories based on system configuration.
     * 
     * @param ProductSearchCriteriaEvent|ProductSuggestCriteriaEvent $event The event containing search criteria
     */
    public function onSearch($event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannel()->getId();

        /** @var string[]|null $excludedCategories */
        $excludedCategories = $this->systemConfigService->get('TopdataElasticsearchHacksSW6.config.excludedCategories', $salesChannelId);

        if (empty($excludedCategories) || !\is_array($excludedCategories)) {
            return;
        }

        $criteria = $event->getCriteria();
        $criteria->addFilter(
            new NotFilter(
                NotFilter::CONNECTION_AND,
                [
                    new EqualsAnyFilter('categoryTree', $excludedCategories)
                ]
            )
        );
    }
}