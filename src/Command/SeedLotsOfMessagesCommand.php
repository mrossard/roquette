<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-lots-of-messages',
    description: 'Ajoute une grande quantité de messages dans le canal Général pour les tests de scroll infini.',
)]
class SeedLotsOfMessagesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('count', null, InputOption::VALUE_OPTIONAL, 'Le nombre de messages à insérer', '150');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = (int) $input->getOption('count');

        // Find or create channel
        $channelRepo = $this->em->getRepository(Channel::class);
        $channel = $channelRepo->findOneBy(['slug' => 'general']);

        if (!$channel) {
            $channel = new Channel();
            $channel->setName('Général');
            $channel->setSlug('general');
            $channel->setDescription('Le canal de discussion principal pour tout le monde.');
            $this->em->persist($channel);
            $io->text('Création du canal Général car il n\'existait pas.');
        }

        // Find or create user
        $userRepo = $this->em->getRepository(User::class);
        $user = $userRepo->findOneBy([]);

        if (!$user) {
            $user = new User();
            $user->setUsername('testuser');
            $user->setDisplayName('Utilisateur de Test');
            $user->setPassword('nopassword');
            $this->em->persist($user);
            $io->text('Création de l\'utilisateur testuser car aucun utilisateur n\'existait.');
        }

        $now = new \DateTimeImmutable();
        for ($i = 1; $i <= $count; $i++) {
            $message = new Message();
            $message->setChannel($channel);
            $message->setAuthor($user);
            $message->setContent(sprintf('Message de test #%d - Utile pour valider le scroll infini.', $i));

            // Offset the date to simulate messages sent in the past, e.g., 1 minute apart
            $createdAt = $now->modify(sprintf('-%d minutes', $count - $i));
            $message->setCreatedAt($createdAt);

            $this->em->persist($message);
        }

        $this->em->flush();

        $io->success(sprintf('%d messages de test ont été ajoutés avec succès au canal Général.', $count));

        return Command::SUCCESS;
    }
}
