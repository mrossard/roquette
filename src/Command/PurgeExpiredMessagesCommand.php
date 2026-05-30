<?php

namespace App\Command;

use App\Entity\Channel;
use App\Entity\Message;
use App\Service\FileUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:purge-expired-messages',
    description: 'Purge les messages expirés selon la durée de rétention des canaux.',
)]
class PurgeExpiredMessagesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FileUploadService $fileUploadService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche les messages qui seraient supprimés sans les supprimer réellement.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        $channels = $this->em->getRepository(Channel::class)->findAll();
        $totalPurged = 0;

        foreach ($channels as $channel) {
            $retentionMonths = $channel->getMessageRetentionMonths();
            if ($retentionMonths === null) {
                continue;
            }

            // Calculate threshold date: now minus X months
            $threshold = (new \DateTimeImmutable())->modify(sprintf('-%d months', $retentionMonths));

            $qb = $this->em->createQueryBuilder();
            $qb->select('m')
               ->from(Message::class, 'm')
               ->where('m.channel = :channel')
               ->andWhere('m.createdAt < :threshold')
               ->andWhere('NOT EXISTS (SELECT 1 FROM App\Entity\User u JOIN u.savedMessages sm WHERE sm.id = m.id OR sm.parent = m)')
               ->setParameter('channel', $channel)
               ->setParameter('threshold', $threshold);

            /** @var Message[] $messages */
            $messages = $qb->getQuery()->getResult();

            if (empty($messages)) {
                continue;
            }

            $io->section(sprintf('Canal: #%s (Rétention: %d mois, Seuil: %s)', $channel->getName(), $retentionMonths, $threshold->format('Y-m-d H:i:s')));

            foreach ($messages as $message) {
                if (!$this->em->contains($message)) {
                    continue;
                }

                $io->text(sprintf('  ❌ [%s] Message ID %d de %s : "%s"', 
                    $message->getCreatedAt()->format('Y-m-d H:i:s'),
                    $message->getId(),
                    $message->getAuthor() ? $message->getAuthor()->getUsername() : 'Inconnu',
                    substr($message->getContent() ?? '', 0, 50)
                ));

                if ($message->getFilePath()) {
                    $io->text(sprintf('     Fichier lié : %s', $message->getFilePath()));
                    if (!$dryRun) {
                        $this->fileUploadService->delete($message->getFilePath());
                    }
                }

                foreach ($message->getReplies() as $reply) {
                    if ($reply->getFilePath()) {
                        $io->text(sprintf('     Fichier lié à la réponse ID %d : %s', $reply->getId(), $reply->getFilePath()));
                        if (!$dryRun) {
                            $this->fileUploadService->delete($reply->getFilePath());
                        }
                    }
                }

                if (!$dryRun) {
                    $this->em->remove($message);
                }
                $totalPurged++;
            }
        }

        if (!$dryRun && $totalPurged > 0) {
            $this->em->flush();
            $io->success(sprintf('%d message(s) expiré(s) purgé(s) avec succès.', $totalPurged));
        } else {
            $io->success(sprintf('[Simulation] %d message(s) expiré(s) identifié(s).', $totalPurged));
        }

        return Command::SUCCESS;
    }
}
