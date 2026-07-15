<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataBetterSearchSW6\Service\ProfileRegistry;

#[AsCommand(
    name: 'topdata:better-search:profiles:list',
    description: 'Lists all loaded search profiles with active status'
)]
class ListProfilesCommand extends AbstractTopdataCommand
{
    public function __construct(private readonly ProfileRegistry $profileRegistry)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        CliLogger::title('TDBS Search Profiles Registry');

        $profiles = $this->profileRegistry->getActiveProfiles();
        if (empty($profiles)) {
            CliLogger::warning('No search profiles resolved in config/tdbs/profiles/.');
            return self::SUCCESS;
        }

        $globalConfig = $this->profileRegistry->getGlobalConfig();
        $abEnabled = $globalConfig['ab_testing']['enabled'] ?? false;
        $distribution = $globalConfig['ab_testing']['distribution'] ?? [];

        $keys = array_keys($profiles);
        $defaultProfile = !empty($keys) ? $keys[0] : 'default';

        CliLogger::section('Available Search Profiles');

        foreach ($profiles as $id => $profile) {
            $isDefault = ($id === $defaultProfile);
            $abWeight = $distribution[$id] ?? null;

            $statusTags = [];
            if ($isDefault) {
                $statusTags[] = '<comment>[Default Fallback]</comment>';
            }
            if ($abEnabled && $abWeight !== null) {
                $statusTags[] = sprintf('<info>[A/B Active: %d%%]</info>', $abWeight);
            }

            $tagsString = !empty($statusTags) ? ' ' . implode(' ', $statusTags) : '';

            CliLogger::writeln(sprintf(
                '• <info>%s</info>%s',
                $id,
                $tagsString
            ));
            CliLogger::writeln(sprintf('  <comment>Name:</comment>        %s', $profile['name'] ?? 'Unnamed'));
            CliLogger::writeln(sprintf('  <comment>Description:</comment> %s', $profile['description'] ?? 'No description provided'));
            CliLogger::writeln(sprintf('  <comment>Pipeline:</comment>    %d step(s)', isset($profile['pipeline']) ? count($profile['pipeline']) : 0));
            CliLogger::writeln('');
        }

        return self::SUCCESS;
    }
}