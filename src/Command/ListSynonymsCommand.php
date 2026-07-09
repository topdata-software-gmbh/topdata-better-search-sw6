<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataBetterSearchSW6\Service\SynonymService;

#[AsCommand(
    name: 'tdbs:synonyms:list',
    description: 'Lists all synonym mappings stored in the database'
)]
class ListSynonymsCommand extends AbstractTopdataCommand
{
    public function __construct(private readonly SynonymService $synonymService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('filter', 'f', InputOption::VALUE_REQUIRED, 'Optional text filter');
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum results', '50');
        $this->addOption('offset', 'o', InputOption::VALUE_REQUIRED, 'Result offset', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filter = $input->getOption('filter');
        $limit = (int) $input->getOption('limit');
        $offset = (int) $input->getOption('offset');

        $synonyms = $this->synonymService->listSynonyms($filter, $limit, $offset);

        if (empty($synonyms)) {
            CliLogger::warning('No synonyms found matching the given criteria.');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($synonyms as $synonym) {
            $rows[] = sprintf('%s => %s', $synonym['term'], $synonym['synonyms']);
        }

        $output->writeln(implode("\n", $rows));
        CliLogger::success(sprintf('Found %d synonym mappings.', count($synonyms)));

        return self::SUCCESS;
    }
}
