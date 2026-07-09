<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataBetterSearchSW6\Service\SynonymService;

#[AsCommand(
    name: 'topdata:better-search:synonyms:clear',
    description: 'Bulk purges all active synonym mappings from the database'
)]
class ClearSynonymsCommand extends AbstractTopdataCommand
{
    public function __construct(private readonly SynonymService $synonymService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Skip the interactive confirmation safety check');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool) $input->getOption('force');

        if (!$force) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                'This will delete ALL database-stored synonyms. Are you sure you want to proceed? [y/N]: ',
                false
            );

            if (!$helper->ask($input, $output, $question)) {
                CliLogger::warning('Operation aborted.');
                return self::SUCCESS;
            }
        }

        try {
            $this->synonymService->clearAllSynonyms();
            CliLogger::success('Successfully cleared all synonym mapping definitions from the database.');
        } catch (\Throwable $e) {
            CliLogger::error('Truncate process failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
