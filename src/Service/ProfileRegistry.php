<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

class ProfileRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $profiles = [];
    private array $globalConfig = [];
    /** @var string[] */
    private array $validationErrors = [];

    public function __construct(
        private readonly string $projectDir,
        private readonly ?LoggerInterface $logger = null
    ) {
        $this->loadConfiguration();
    }

    private function loadConfiguration(): void
    {
        $configPath = $this->projectDir . '/config/tdbs';

        // 1. Load Global Settings
        $globalFile = $configPath . '/config.yaml';
        if (file_exists($globalFile)) {
            try {
                $parsed = Yaml::parseFile($globalFile);
                $this->globalConfig = \is_array($parsed) ? $parsed : [];
            } catch (\Throwable $e) {
                $this->validationErrors[] = sprintf('Failed to parse %s: %s', $globalFile, $e->getMessage());
                $this->logger?->error('topdata:better-search: Failed to parse global config', ['file' => $globalFile, 'error' => $e->getMessage()]);
            }
        }

        // 2. Load Modular Profile Definitions
        $profileDir = $configPath . '/profiles';
        if (is_dir($profileDir)) {
            $finder = new Finder();
            $finder->files()->in($profileDir)->name(['*.yaml', '*.yml']);

            foreach ($finder as $file) {
                $profileId = $file->getBasename('.' . $file->getExtension());
                try {
                    $profileData = Yaml::parseFile($file->getRealPath());
                    if (\is_array($profileData)) {
                        $validationError = $this->validateProfile($profileId, $profileData);
                        if ($validationError !== null) {
                            $this->validationErrors[] = $validationError;
                            $this->logger?->warning('topdata:better-search: Invalid profile skipped', ['profile' => $profileId, 'error' => $validationError]);
                            continue;
                        }
                        $this->profiles[$profileId] = $profileData;
                    }
                } catch (\Throwable $e) {
                    $this->validationErrors[] = sprintf('Failed to parse profile "%s": %s', $profileId, $e->getMessage());
                    $this->logger?->error('topdata:better-search: Failed to parse profile', ['profile' => $profileId, 'error' => $e->getMessage()]);
                }
            }
        }

        // 3. Validate A/B distribution references existing profiles
        $this->validateAbDistribution();
    }

    /**
     * Validates that a profile YAML file has the required structure.
     * Returns an error string or null on success.
     */
    private function validateProfile(string $profileId, array $data): ?string
    {
        if (!isset($data['pipeline']) || !\is_array($data['pipeline'])) {
            return sprintf('Profile "%s" is missing a "pipeline" key or it is not an array.', $profileId);
        }

        foreach ($data['pipeline'] as $index => $step) {
            if (!isset($step['backend']) || !\is_string($step['backend'])) {
                return sprintf('Profile "%s" pipeline step %d is missing a "backend" key.', $profileId, $index);
            }

            $backend = $step['backend'];
            $options = $step['options'] ?? [];

            if ($backend === 'elasticsearch') {
                if (!isset($options['index_name']) || !\is_string($options['index_name'])) {
                    return sprintf('Profile "%s" pipeline step %d (elasticsearch) requires a valid "index_name" option.', $profileId, $index);
                }

                $ngram = $options['ngram'] ?? [];
                if (!empty($ngram)) {
                    $type = $ngram['type'] ?? 'edge_ngram';
                    if (!\in_array($type, ['edge_ngram', 'standard', 'none'], true)) {
                        return sprintf('Profile "%s" pipeline step %d uses invalid ngram type "%s". Allowed: edge_ngram, standard, none.', $profileId, $index, $type);
                    }
                }
            }

            if ($backend === 'meilisearch') {
                if (!isset($options['index_name']) || !\is_string($options['index_name'])) {
                    return sprintf('Profile "%s" pipeline step %d (meilisearch) requires a valid "index_name" option.', $profileId, $index);
                }
            }
        }

        return null;
    }

    /**
     * Validates that A/B distribution profile IDs reference existing profiles.
     */
    private function validateAbDistribution(): void
    {
        $distribution = $this->globalConfig['ab_testing']['distribution'] ?? [];
        foreach ($distribution as $profileId => $weight) {
            if (!isset($this->profiles[$profileId])) {
                $this->validationErrors[] = sprintf(
                    'A/B distribution references non-existent profile "%s".',
                    $profileId
                );
            }
        }
    }

    /**
     * @return string[]
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    public function hasValidationErrors(): bool
    {
        return !empty($this->validationErrors);
    }

    public function getProfile(string $id): ?array
    {
        return $this->profiles[$id] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getActiveProfiles(): array
    {
        return $this->profiles;
    }

    public function getGlobalConfig(): array
    {
        return $this->globalConfig;
    }
}
