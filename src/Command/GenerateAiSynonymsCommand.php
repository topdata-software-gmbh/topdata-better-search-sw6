<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataBetterSearchSW6\Service\AiSynonymGenerator;
use Topdata\TopdataBetterSearchSW6\Service\SynonymService;

#[AsCommand(
    name: 'topdata:better-search:synonyms:generate-ai',
    description: 'Generates related search synonyms using LLM context'
)]
class GenerateAiSynonymsCommand extends AbstractTopdataCommand
{
    public function __construct(
        private readonly AiSynonymGenerator $aiSynonymGenerator,
        private readonly SynonymService $synonymService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('term', InputArgument::REQUIRED, 'The primary e-commerce search term to evaluate');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Persist generated synonyms directly to database without interactive confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $term = mb_strtolower(trim($input->getArgument('term')));
        $force = (bool) $input->getOption('force');

        CliLogger::title(sprintf('TDBS AI-Assisted Synonym Pipeline: "%s"', $term));

        try {
            CliLogger::info('Querying active Artificial Intelligence LLM endpoint...');
            $synonyms = $this->aiSynonymGenerator->generateSynonyms($term);

            if (empty($synonyms)) {
                CliLogger::warning('LLM responded but no valid clean synonyms could be parsed.');
                return self::SUCCESS;
            }

            CliLogger::section('AI Recommended Synonyms');
            foreach ($synonyms as $index => $synonym) {
                CliLogger::writeln(sprintf('  [%d] <info>%s</info>', $index + 1, $synonym));
            }

            $mappingLine = sprintf('%s => %s', $term, implode(', ', $synonyms));
            CliLogger::writeln('');
            CliLogger::writeln(sprintf('Proposed Mapping: <comment>%s</comment>', $mappingLine));
            CliLogger::writeln('');

            if (!$force) {
                $helper = $this->getHelper('question');
                $question = new ConfirmationQuestion(
                    'Do you want to write this synonym mapping directly to the database? [y/N]: ',
                    false
                );

                if (!$helper->ask($input, $output, $question)) {
                    CliLogger::warning('Operation aborted. Synonym mapping was not saved.');
                    return self::SUCCESS;
                }
            }

            $this->synonymService->importFromString($mappingLine, false);
            CliLogger::success(sprintf('Successfully written synonym mapping for "%s" to database.', $term));

        } catch (\Throwable $e) {
            CliLogger::error('AI synonym lookup pipeline failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
