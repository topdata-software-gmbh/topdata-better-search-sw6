<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class ElasticsearchAnalysisCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('elasticsearch.analysis')) {
            return;
        }

        $analysis = $container->getParameter('elasticsearch.analysis');
        if (!\is_array($analysis)) {
            $analysis = [];
        }

        $analysis['filter'] = $analysis['filter'] ?? [];
        $analysis['analyzer'] = $analysis['analyzer'] ?? [];

        $analysis['filter']['topdata_word_delimiter'] = [
            'type' => 'word_delimiter_graph',
            'preserve_original' => true,
            'catenate_all' => true,
            'catenate_words' => true,
            'generate_word_parts' => true,
            'split_on_case_change' => true,
        ];

        $analyzersToModify = [
            'sw_german_analyzer',
            'sw_english_analyzer',
            'sw_default_analyzer',
        ];

        foreach ($analyzersToModify as $analyzerName) {
            if (!isset($analysis['analyzer'][$analyzerName])) {
                $analysis['analyzer'][$analyzerName] = [
                    'type' => 'custom',
                    'tokenizer' => 'standard',
                    'filter' => ['lowercase'],
                ];
            }

            $filters = $analysis['analyzer'][$analyzerName]['filter'] ?? [];
            if (!\in_array('topdata_word_delimiter', $filters, true)) {
                $lowercaseIndex = \array_search('lowercase', $filters, true);
                if ($lowercaseIndex !== false) {
                    \array_splice($filters, $lowercaseIndex, 0, 'topdata_word_delimiter');
                } else {
                    $filters[] = 'topdata_word_delimiter';
                    $filters[] = 'lowercase';
                }
                $analysis['analyzer'][$analyzerName]['filter'] = $filters;
            }
        }

        $container->setParameter('elasticsearch.analysis', $analysis);
    }
}
