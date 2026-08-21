<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Message;
use App\Service\HybridSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'ai:messages:index',
    description: 'Indexe les messages textuels dans PostgreSQL pgvector pour la recherche sémantique / hybride',
)]
final class IndexMessagesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HybridSearchService $hybridSearchService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'all',
                null,
                InputOption::VALUE_NONE,
                'Réindexer tous les messages (écrase les embeddings existants)',
            )
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Nombre maximal de messages à indexer', '500')
            ->setHelp(<<<'EOF'
                La commande <info>%command.name%</info> génère les embeddings vectoriels des messages textuels
                avec le modèle nomic-embed-text via Ollama et les stocke dans PostgreSQL (pgvector).

                Indexation des messages non encore indexés :
                    <info>php %command.full_name%</info>

                Réindexation complète de tous les messages :
                    <info>php %command.full_name% --all</info>
                EOF);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Indexation vectorielle des messages (pgvector + nomic-embed-text)');

        $reindexAll = (bool) $input->getOption('all');
        $limit = (int) $input->getOption('limit');

        $conn = $this->entityManager->getConnection();

        $messageIds = [];
        if ($reindexAll) {
            $qb = $this->entityManager->createQueryBuilder();
            $qb
                ->select('m.id')
                ->from(Message::class, 'm')
                ->where('m.content IS NOT NULL')
                ->andWhere('m.poll IS NULL')
                ->orderBy('m.id', 'ASC')
                ->setMaxResults($limit);

            $messageIds = array_map('intval', array_column($qb->getQuery()->getScalarResult(), 'id'));
        }

        if (!$reindexAll) {
            // Find messages with content that are NOT YET in message_embedding
            $sql = <<<SQL
                    SELECT m.id
                    FROM "message" m
                    LEFT JOIN message_embedding me ON me.message_id = m.id
                    WHERE m.content IS NOT NULL
                      AND m.content != ''
                      AND me.message_id IS NULL
                    ORDER BY m.id ASC
                    LIMIT :limit
                SQL;

            $rows = $conn->fetchAllAssociative($sql, ['limit' => $limit]);
            $messageIds = array_map('intval', array_column($rows, 'id'));
        }

        $count = count($messageIds);
        if ($count === 0) {
            $io->success('Tous les messages éligibles sont déjà indexés.');

            return Command::SUCCESS;
        }

        $io->info(sprintf('%d message(s) à vectoriser et indexer.', $count));
        $progressBar = new ProgressBar($output, $count);
        $progressBar->start();

        $indexed = 0;
        $failed = 0;

        foreach ($messageIds as $messageId) {
            if ($this->hybridSearchService->indexMessage($messageId)) {
                $indexed++;
                $progressBar->advance();
                continue;
            }

            $failed++;
            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine(2);

        $io->success(sprintf('Indexation terminée : %d indexé(s), %d ignoré(s) ou en échec.', $indexed, $failed));

        return Command::SUCCESS;
    }
}
