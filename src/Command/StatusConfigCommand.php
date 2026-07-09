<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataBetterSearchSW6\Service\ProfileRegistry;

#[AsCommand(
    name: 'tdbs:status',
    description: 'Diagnoses profile parsing, database connection health, and A/B configurations'
)]
class StatusConfigCommand extends AbstractTopdataCommand
{
    public function __construct(
        private readonly ProfileRegistry $profileRegistry,
        private readonly HttpClientInterface $httpClient
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        CliLogger::title('TDBS Diagnostics & Configuration Status');

        $globalConfig = $this->profileRegistry->getGlobalConfig();
        if (empty($globalConfig)) {
            CliLogger::error('No global configuration found at config/tdbs/config.yaml.');
            return self::FAILURE;
        }

        // Report YAML validation errors
        $validationErrors = $this->profileRegistry->getValidationErrors();
        if (!empty($validationErrors)) {
            CliLogger::section('Validation Errors');
            foreach ($validationErrors as $error) {
                CliLogger::error($error);
            }
        }

        CliLogger::section('Connections Health Check');
        $this->checkConnections($globalConfig);

        CliLogger::section('Loaded Profiles');
        $profiles = $this->profileRegistry->getActiveProfiles();
        if (empty($profiles)) {
            CliLogger::warning('No search profiles resolved in config/tdbs/profiles/.');
        } else {
            foreach ($profiles as $id => $profile) {
                CliLogger::writeln(sprintf(
                    ' - <info>%s</info> - %s (Pipeline: %d step(s))',
                    $id,
                    $profile['name'] ?? 'Unnamed',
                    isset($profile['pipeline']) ? count($profile['pipeline']) : 0
                ));
            }
        }

        CliLogger::section('A/B Testing');
        $abEnabled = $globalConfig['ab_testing']['enabled'] ?? false;
        if ($abEnabled) {
            CliLogger::success('A/B Testing: ENABLED');
            $distribution = $globalConfig['ab_testing']['distribution'] ?? [];
            foreach ($distribution as $profileId => $weight) {
                CliLogger::writeln(sprintf('   - %s: %d%%', $profileId, $weight));
            }
        } else {
            CliLogger::warning('A/B Testing: DISABLED');
        }

        return self::SUCCESS;
    }

    private function checkConnections(array $globalConfig): void
    {
        $connections = $globalConfig['connections'] ?? [];

        // 1. Check Meilisearch
        $meiliHost = $connections['meilisearch']['host'] ?? null;
        if ($meiliHost) {
            $meiliHostClean = rtrim($meiliHost, '/');
            $status = $this->pingUrl($meiliHostClean . '/health');
            if ($status) {
                CliLogger::success(sprintf('Meilisearch: Connected (%s)', $meiliHost));
            } else {
                CliLogger::error(sprintf('Meilisearch: UNREACHABLE (%s)', $meiliHost));
            }
        } else {
            CliLogger::info('Meilisearch: Not configured.');
        }

        // 2. Check Qdrant
        $qdrantHost = $connections['qdrant']['host'] ?? null;
        if ($qdrantHost) {
            $qdrantHostClean = rtrim($qdrantHost, '/');
            $status = $this->pingUrl($qdrantHostClean . '/readyz');
            if ($status) {
                CliLogger::success(sprintf('Qdrant: Connected (%s)', $qdrantHost));
            } else {
                CliLogger::error(sprintf('Qdrant: UNREACHABLE (%s)', $qdrantHost));
            }
        } else {
            CliLogger::info('Qdrant: Not configured.');
        }

        // 3. Check Elasticsearch
        $esHost = $connections['elasticsearch']['host'] ?? null;
        if ($esHost) {
            $esHostClean = rtrim($esHost, '/');
            $status = $this->pingUrl($esHostClean);
            if ($status) {
                CliLogger::success(sprintf('Elasticsearch: Connected (%s)', $esHost));
            } else {
                CliLogger::error(sprintf('Elasticsearch: UNREACHABLE (%s)', $esHost));
            }
        } else {
            CliLogger::info('Elasticsearch: Not configured.');
        }
    }

    private function pingUrl(string $url): bool
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 3,
            ]);
            $code = $response->getStatusCode();
            return $code >= 200 && $code < 400;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
