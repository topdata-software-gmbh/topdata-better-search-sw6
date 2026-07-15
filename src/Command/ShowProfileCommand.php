<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataBetterSearchSW6\Service\ProfileRegistry;

#[AsCommand(
    name: 'topdata:better-search:profiles:show',
    description: 'Displays detailed configuration parameters for a specific search profile'
)]
class ShowProfileCommand extends AbstractTopdataCommand
{
    public function __construct(private readonly ProfileRegistry $profileRegistry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('profile_id', InputArgument::REQUIRED, 'The ID/filename of the search profile to inspect');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $profileId = $input->getArgument('profile_id');
        $profile = $this->profileRegistry->getProfile($profileId);

        if ($profile === null) {
            CliLogger::error(sprintf('Search profile with ID "%s" could not be found.', $profileId));
            return self::FAILURE;
        }

        CliLogger::title(sprintf('Inspect Search Profile: %s', $profileId));

        CliLogger::section('Metadata');
        CliLogger::writeln(sprintf('  <info>Name:</info>        %s', $profile['name'] ?? 'Unnamed'));
        CliLogger::writeln(sprintf('  <info>Description:</info> %s', $profile['description'] ?? 'No description provided.'));

        $pipeline = $profile['pipeline'] ?? [];
        CliLogger::section(sprintf('Execution Pipeline (%d step(s))', count($pipeline)));

        if (empty($pipeline)) {
            CliLogger::warning('The pipeline contains no steps. Queries will fall back directly to Shopware Core.');
            return self::SUCCESS;
        }

        foreach ($pipeline as $index => $step) {
            $backend = $step['backend'] ?? 'unknown';
            CliLogger::writeln(sprintf('  <comment>Step %d:</comment> Backend => <info>%s</info>', $index + 1, $backend));

            $options = $step['options'] ?? [];
            if (!empty($options)) {
                CliLogger::writeln('          Options:');
                $this->printOptionsRecursively($options, 10);
            } else {
                CliLogger::writeln('          Options: None');
            }
            CliLogger::writeln('');
        }

        return self::SUCCESS;
    }

    private function printOptionsRecursively(array $options, int $indentation = 10): void
    {
        $indent = str_repeat(' ', $indentation);
        foreach ($options as $key => $value) {
            if (\is_array($value)) {
                CliLogger::writeln(sprintf('%s<comment>%s:</comment>', $indent, $key));
                $this->printOptionsRecursively($value, $indentation + 2);
            } else {
                $displayValue = \is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
                CliLogger::writeln(sprintf('%s<info>%s:</info> %s', $indent, $key, $displayValue));
            }
        }
    }
}