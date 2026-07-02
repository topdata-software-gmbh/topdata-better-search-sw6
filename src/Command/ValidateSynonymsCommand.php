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
    name: 'tdbs:synonyms:validate',
    description: 'Validates a synonym mapping file format'
)]
class ValidateSynonymsCommand extends TopdataFoundationSW6
{
    public function __construct(private readonly SynonymService $synonymService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Path to the synonym file to validate');
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        CliLogger::setCliStyle($this->getCliStyle());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filePath = $input->getArgument('file');

        $errors = $this->synonymService->validateFile($filePath);

        if (empty($errors)) {
            CliLogger::success(sprintf('File "%s" is valid.', $filePath));
        } else {
            foreach ($errors as $error) {
                CliLogger::error(sprintf('Line %d: %s (content: %s)', $error['line'], $error['error'], $error['content']));
            }
            CliLogger::warning(sprintf('Found %d error(s) in file "%s".', count($errors), $filePath));
        }

        return empty($errors) ? self::SUCCESS : self::FAILURE;
    }
}
