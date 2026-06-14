<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DocChunker;
use Symfony\AI\Store\IndexerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(
    name: 'ai:doc:index',
    description: 'Indexer le guide utilisateur dans le store vectoriel',
)]
final class IndexDocCommand extends Command
{
    public function __construct(
        private readonly DocChunker $chunker,
        private readonly IndexerInterface $indexer,
        private readonly ParameterBagInterface $parameterBag,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('reindex', null, InputOption::VALUE_NONE, 'Supprimer et recréer la table avant indexation')
            ->setHelp(<<<'EOF'
La commande <info>%command.name%</info> découpe <comment>DOC_UTILISATEUR.md</comment>
en sections, vectorise chaque section avec nomic-embed-text, et stocke
le résultat dans PostgreSQL via pgvector.

Utilisation :
    <info>php %command.full_name%</info>

Avec réindexation complète :
    <info>php %command.full_name% --reindex</info>
EOF
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectDir = $this->parameterBag->get('kernel.project_dir');

        if ($input->getOption('reindex')) {
            $io->note('Option --reindex non implémentée directement. Utilisez bin/console ai:store:drop et ai:store:setup d\'abord.');
        }

        $io->title('Indexation du guide utilisateur');

        $documents = $this->chunker->chunk($projectDir);
        $io->info(\sprintf('%d sections trouvées dans DOC_UTILISATEUR.md', \count($documents)));

        if (0 === \count($documents)) {
            $io->error('DOC_UTILISATEUR.md introuvable.');

            return Command::FAILURE;
        }

        $io->section('Vectorisation et indexation en cours...');

        try {
            $this->indexer->index($documents);
            $io->success(\sprintf('%d sections indexées avec succès.', \count($documents)));
        } catch (\Exception $e) {
            $io->error(\sprintf('Erreur lors de l\'indexation : %s', $e->getMessage()));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
