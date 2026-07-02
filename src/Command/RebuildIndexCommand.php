<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Context;
use Topdata\TopdataFoundationSW6\TopdataFoundationSW6;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataBetterSearchSW6\Service\SearchBackendRegistry;

#[AsCommand(
    name: 'tdbs:index:rebuild',
    description: 'Rebuilds search indices for configured custom search backends'
)]
class RebuildIndexCommand extends TopdataFoundationSW6
{
    public function __construct(
        private readonly SearchBackendRegistry $backendRegistry,
        private readonly EntityRepository $productRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Processing step limit size', '100');
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        CliLogger::setCliStyle($this->getCliStyle());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int) $input->getOption('limit');
        $context = Context::createDefaultContext();

        CliLogger::title('TDBS Custom Backend Reindex Pipeline');

        $activeBackends = $this->backendRegistry->getActiveBackends();
        if (empty($activeBackends)) {
            CliLogger::warning('No custom search backends currently active.');
            return self::SUCCESS;
        }

        foreach ($activeBackends as $backend) {
            if ($backend->getName() === 'shopware_core') {
                continue;
            }

            CliLogger::section(sprintf('Indexing Backend: %s', $backend->getName()));

            $criteria = new Criteria();
            $criteria->setLimit($limit);
            $criteria->setOffset(0);

            $total = 0;
            while ($products = $this->productRepository->search($criteria, $context)) {
                if ($products->getTotal() === 0) {
                    break;
                }

                $data = [];
                foreach ($products->getElements() as $product) {
                    $data[] = [
                        'id' => $product->getId(),
                        'name' => $product->getName(),
                        'productNumber' => $product->getProductNumber(),
                        'description' => $product->getDescription(),
                    ];
                }

                $backend->index($data);
                $total += count($data);

                CliLogger::progress($total, $products->getTotal(), 'indexing products...');

                if (count($data) < $limit) {
                    break;
                }
                $criteria->setOffset($criteria->getOffset() + $limit);
            }

            CliLogger::success(sprintf('Completed sync of %d products for backend: %s', $total, $backend->getName()));
        }

        return self::SUCCESS;
    }
}
