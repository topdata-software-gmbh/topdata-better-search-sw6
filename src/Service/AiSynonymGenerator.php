<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AiSynonymGenerator
{
    public function __construct(
        private readonly ProfileRegistry $profileRegistry,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @return string[]
     */
    public function generateSynonyms(string $term): array
    {
        $globalConfig = $this->profileRegistry->getGlobalConfig();
        $aiConfig = $globalConfig['ai'] ?? [];

        $provider = $aiConfig['provider'] ?? 'ollama';

        if ($provider === 'openai') {
            return $this->queryOpenAi($term, $aiConfig['openai'] ?? []);
        }

        return $this->queryOllama($term, $aiConfig['ollama'] ?? []);
    }

    /**
     * @return string[]
     */
    private function queryOpenAi(string $term, array $config): array
    {
        $apiKey = $config['api_key'] ?? '';
        $model = $config['model'] ?? 'gpt-4o-mini';

        if (empty($apiKey)) {
            throw new \RuntimeException('AI Synonym Service: OpenAI API Key is missing inside config.yaml.');
        }

        $prompt = $this->getSystemPrompt($term);

        try {
            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a highly-focused e-commerce SEO assistant. Respond ONLY with comma-separated values, nothing else.'],
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'temperature' => 0.2,
                ],
                'timeout' => 15,
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('OpenAI API returned status code: ' . $response->getStatusCode());
            }

            $data = $response->toArray();
            $content = $data['choices'][0]['message']['content'] ?? '';

            return $this->parseResponseString($content);
        } catch (\Throwable $e) {
            $this->logger->error('TDBS AI Synonym OpenAI Query Failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @return string[]
     */
    private function queryOllama(string $term, array $config): array
    {
        $host = rtrim($config['host'] ?? 'http://localhost:11434', '/');
        $model = $config['model'] ?? 'llama3';

        $prompt = $this->getSystemPrompt($term);

        try {
            $response = $this->httpClient->request('POST', $host . '/api/chat', [
                'json' => [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a highly-focused e-commerce SEO assistant. Respond ONLY with comma-separated values, nothing else.'],
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'options' => [
                        'temperature' => 0.2,
                    ],
                    'stream' => false,
                ],
                'timeout' => 20,
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('Ollama server returned status code: ' . $response->getStatusCode());
            }

            $data = $response->toArray();
            $content = $data['message']['content'] ?? '';

            return $this->parseResponseString($content);
        } catch (\Throwable $e) {
            $this->logger->error('TDBS AI Synonym Ollama Query Failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function getSystemPrompt(string $term): string
    {
        return sprintf(
            'Generate 4 to 8 highly-relevant e-commerce search synonyms, spelling variations, or semantic aliases ' .
            'for the following search term: "%s". ' .
            'Return them exclusively as a single, flat, comma-separated list of lowercase words (e.g., "word1, word2, word3"). ' .
            'Do not include markdown, introductions, explanations, formatting, quotes, or numbered lists.',
            $term
        );
    }

    /**
     * @return string[]
     */
    private function parseResponseString(string $content): array
    {
        $content = trim($content);
        if (empty($content)) {
            return [];
        }

        $items = explode(',', $content);
        $cleanItems = [];

        foreach ($items as $item) {
            $cleaned = mb_strtolower(trim(preg_replace('/[^a-zA-Z0-9\-\s]/', '', $item)));
            if (!empty($cleaned)) {
                $cleanItems[] = $cleaned;
            }
        }

        return array_unique($cleanItems);
    }
}
