<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Context;
use Doctrine\DBAL\Connection;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataBetterSearchSW6\Service\ProfileRegistry;
use Topdata\TopdataBetterSearchSW6\Service\SearchBackendRegistry;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;

#[AsCommand(
    name: 'topdata:better-search:search',
    description: 'Executes a playground search query against a specific search profile'
)]
class SearchPlaygroundCommand extends AbstractTopdataCommand
{
    public function __construct(
        private readonly ProfileRegistry $profileRegistry,
        private readonly SearchBackendRegistry $backendRegistry,
        private readonly Connection $connection,
        private readonly EntityRepository $productRepository,
        private readonly SalesChannelContextFactory $contextFactory
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('term', InputArgument::REQUIRED, 'The search term to query');
        $this->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'Target search profile ID (from profiles/)');
        $this->addOption('resolve-products', null, InputOption::VALUE_NONE, 'Resolve and display product names for returned IDs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $term = $input->getArgument('term');
        $profileId = $input->getOption('profile');
        $resolveProducts = (bool) $input->getOption('resolve-products');

        if (!$profileId) {
            $profiles = $this->profileRegistry->getActiveProfiles();
            $keys = array_keys($profiles);
            $profileId = !empty($keys) ? $keys[0] : null;
        }

        if (!$profileId || $this->profileRegistry->getProfile($profileId) === null) {
            CliLogger::error(sprintf('Profile "%s" is invalid or could not be found.', $profileId));
            return self::FAILURE;
        }

        CliLogger::title(sprintf('Executing Search: "%s" via profile "%s"', $term, $profileId));

        $salesChannelContext = $this->getSalesChannelContext();
        if ($salesChannelContext === null) {
            CliLogger::error('No active sales channel found to run search context.');
            return self::FAILURE;
        }

        $profile = $this->profileRegistry->getProfile($profileId);
        $pipeline = $profile['pipeline'] ?? [];

        $ids = null;
        $resolvedBackend = null;
        $resolvedOptions = null;

        $startTime = microtime(true);
        foreach ($pipeline as $step) {
            $backendName = $step['backend'] ?? null;
            if (!$backendName) {
                continue;
            }

            $backend = $this->backendRegistry->getBackend($backendName);
            if ($backend === null) {
                continue;
            }

            $criteria = new Criteria();
            $criteria->setTerm($term);

            $options = $step['options'] ?? [];
            $criteria->addExtension('tdbs_options', new ArrayStruct($options));

            CliLogger::info(sprintf('Evaluating Backend pipeline step: "%s"...', $backendName));
            $resultIds = $backend->search($criteria, $salesChannelContext);

            if ($resultIds !== null) {
                $ids = $resultIds;
                $resolvedBackend = $backendName;
                $resolvedOptions = $options;
                break;
            }
        }
        $duration = (int) ((microtime(true) - $startTime) * 1000);

        if ($ids === null) {
            CliLogger::warning('Pipeline resolved with NULL fallback (Default Shopware Core Search is bypassed).');
        } elseif (empty($ids)) {
            CliLogger::warning('Pipeline resolved successfully but returned 0 matches.');
        } else {
            CliLogger::success(sprintf(
                'Success! Resolved by backend <info>%s</info> — <info>%d matches</info> in <info>%d ms</info>',
                $resolvedBackend,
                count($ids),
                $duration
            ));

            CliLogger::section('Result IDs (first 10)');
            foreach (array_slice($ids, 0, 10) as $index => $id) {
                CliLogger::writeln(sprintf(' [%d] %s', $index + 1, $id));
            }

            if ($resolveProducts) {
                $this->resolveProductDetails($ids, $salesChannelContext->getContext());
            }
        }

        return self::SUCCESS;
    }

    /**
     * Resolves product names for the returned IDs using the DAL.
     */
    private function resolveProductDetails(array $ids, Context $context): void
    {
        $criteria = new Criteria($ids);
        $criteria->addFields(['id', 'name', 'productNumber']);
        $criteria->setLimit(10);

        $result = $this->productRepository->search($criteria, $context);

        if ($result->count() === 0) {
            CliLogger::warning('No product details could be resolved for the returned IDs.');
            return;
        }

        CliLogger::section('Product Details (resolved, first 10)');
        /** @var ProductEntity $product */
        foreach ($result->getEntities() as $product) {
            CliLogger::writeln(sprintf(
                '  <info>%s</info> — %s (%s)',
                $product->getProductNumber(),
                $product->getName(),
                $product->getId()
            ));
        }
    }

    private function getSalesChannelContext(): ?\Shopware\Core\System\SalesChannel\SalesChannelContext
    {
        try {
            $salesChannel = $this->connection->fetchAssociative('SELECT id FROM sales_channel WHERE active = 1 LIMIT 1');
            if (!$salesChannel) {
                return null;
            }

            $salesChannelId = Uuid::fromBytesToHex($salesChannel['id']);

            return $this->contextFactory->create(Uuid::randomHex(), $salesChannelId);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
