<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Message;
use App\Service\MessageFormatter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(
    name: 'app:backfill-message-formatting',
    description: 'Calcule et enregistre le contenu formaté HTML (formattedContent) pour tous les anciens messages.',
)]
class BackfillMessageFormattingCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageFormatter $formatter,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Forcer le reformatage de tous les messages.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = $input->getOption('force');

        $qb = $this->em->createQueryBuilder();
        $qb->select('COUNT(m.id)')->from(Message::class, 'm');
        if (!$force) {
            $qb->where('m.formattedContent IS NULL');
        }

        $total = (int) $qb->getQuery()->getSingleScalarResult();

        if ($total === 0) {
            $io->success($this->translator->trans('Tous les messages sont déjà formatés !'));
            return Command::SUCCESS;
        }

        $io->info(sprintf('%d message(s) à formater.', $total));

        $batchSize = 100;
        $processed = 0;

        while ($processed < $total) {
            $qbFetch = $this->em->createQueryBuilder();
            $qbFetch->select('m')->from(Message::class, 'm');
            if (!$force) {
                $qbFetch->where('m.formattedContent IS NULL');
            } else {
                $qbFetch->setFirstResult($processed);
            }
            $qbFetch->setMaxResults($batchSize);

            /** @var Message[] $messages */
            $messages = $qbFetch->getQuery()->getResult();

            if ($messages === []) {
                break;
            }

            foreach ($messages as $message) {
                $content = $message->getContent();
                if ($content === null || $content === '') {
                    $message->setFormattedContent('');
                } else {
                    if (str_starts_with($content, '/me ') || $content === '/me') {
                        $meContent = $content === '/me' ? '' : substr($content, 4);
                        $message->setFormattedContent($this->formatter->format($meContent));
                    } else {
                        $message->setFormattedContent($this->formatter->format($content));
                    }
                }
                $processed++;
            }

            $this->em->flush();
            $this->em->clear(); // Libérer la mémoire
            $io->text(sprintf('Progression : %d/%d messages...', $processed, $total));
        }

        $io->success($this->translator->trans('Formatage rétroactif terminé avec succès !'));

        return Command::SUCCESS;
    }
}
