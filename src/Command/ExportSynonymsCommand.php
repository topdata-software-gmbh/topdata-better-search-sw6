<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataFoundationSW6\TopdataFoundationSW6;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataBetterSearchSW6\Service\SynonymService;

#[AsCommand(
    name: 'tdbs:synonyms:export',
    description: 'Exports synonym mappings to a file'
)]
class ExportSynonymsCommand extends TopdataFoundationSW6
{
    public function __construct(private readonly SynonymService $synonymService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Output file path');
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        CliLogger::setCliStyle($this->getCliStyle());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filePath = $input->getArgument('file');

        try {
            $content = $this->synonymService->exportToString();
            $bytesWritten = file_put_contents($filePath, $content);

            if ($bytesWritten === false) {
                CliLogger::error(sprintf('Could not write to file "%s".', $filePath));
                return self::FAILURE;
            }

            CliLogger::success(sprintf('Exported synonyms to "%s" (%d bytes).', $filePath, $bytesWritten));
        } catch (\Throwable $e) {
            CliLogger::error('Export failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
