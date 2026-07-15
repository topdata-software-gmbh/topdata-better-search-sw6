<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataBetterSearchSW6\Service\ProfileRegistry;

#[AsCommand(
    name: 'topdata:better-search:profiles:validate',
    description: 'Validates all search profiles and global configurations for syntactic and semantic errors'
)]
class ValidateProfilesCommand extends AbstractTopdataCommand
{
    public function __construct(private readonly ProfileRegistry $profileRegistry)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        CliLogger::title('TDBS Profile Configurations Validation');

        $errors = $this->profileRegistry->getValidationErrors();

        if (empty($errors)) {
            CliLogger::success('All search profiles and configuration files passed validation successfully.');
            return self::SUCCESS;
        }

        CliLogger::warning(sprintf('Found %d validation issue(s) in configuration files:', count($errors)));
        foreach ($errors as $error) {
            CliLogger::error($error);
        }

        return self::FAILURE;
    }
}