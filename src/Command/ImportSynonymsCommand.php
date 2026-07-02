<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataFoundationSW6\TopdataFoundationSW6;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataBetterSearchSW6\Service\SynonymService;

#[AsCommand(
    name: 'tdbs:synonyms:import',
    description: 'Imports synonym mappings from a file'
)]
class ImportSynonymsCommand extends TopdataFoundationSW6
{
    public function __construct(private readonly SynonymService $synonymService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Input file path');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only validate and count, do not persist');
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        CliLogger::setCliStyle($this->getCliStyle());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filePath = $input->getArgument('file');
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $count = $this->synonymService->importFromFile($filePath, $dryRun);

            if ($dryRun) {
                CliLogger::success(sprintf('Validation passed. File contains %d synonym mappings (not persisted).', $count));
            } else {
                CliLogger::success(sprintf('Successfully imported %d synonym mappings from "%s".', $count, $filePath));
            }
        } catch (\Throwable $e) {
            CliLogger::error('Import failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
