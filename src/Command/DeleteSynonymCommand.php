<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataBetterSearchSW6\Service\SynonymService;

#[AsCommand(
    name: 'topdata:better-search:synonyms:delete',
    description: 'Deletes a specific synonym mapping by term'
)]
class DeleteSynonymCommand extends AbstractTopdataCommand
{
    public function __construct(private readonly SynonymService $synonymService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('term', InputArgument::REQUIRED, 'The search term to delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $term = $input->getArgument('term');

        if ($this->synonymService->deleteSynonym($term)) {
            CliLogger::success(sprintf('Synonym for "%s" has been deleted.', $term));
        } else {
            CliLogger::warning(sprintf('No synonym found for term "%s".', $term));
        }

        return self::SUCCESS;
    }
}
