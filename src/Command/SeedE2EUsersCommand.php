<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Channel;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:seed-e2e-data',
    description: 'Initialise les utilisateurs et canaux nécessaires aux tests E2E Playwright.',
)]
final class SeedE2EUsersCommand extends Command
{
    private const USERS = [
        [
            'username' => 'e2e_alice',
            'email' => 'alice@e2e.test',
            'password' => 'password123',
            'roles' => ['ROLE_USER'],
            'admin' => false,
        ],
        [
            'username' => 'e2e_bob',
            'email' => 'bob@e2e.test',
            'password' => 'password123',
            'roles' => ['ROLE_USER'],
            'admin' => false,
        ],
        [
            'username' => 'e2e_admin',
            'email' => 'admin@e2e.test',
            'password' => 'password123',
            'roles' => ['ROLE_ADMIN'],
            'admin' => true,
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userRepo = $this->em->getRepository(User::class);
        $channelRepo = $this->em->getRepository(Channel::class);

        // 1. Seed or update E2E users
        $createdUsers = [];
        foreach (self::USERS as $userData) {
            $user = $userRepo->findOneBy(['username' => $userData['username']]);
            $isNew = false;
            if ($user === null) {
                $user = new User();
                $user->setUsername($userData['username']);
                $user->setEmail($userData['email']);
                $this->em->persist($user);
                $isNew = true;
            }

            $message = $isNew
                ? sprintf('  ➕ Création de l\'utilisateur <info>%s</info>', $userData['username'])
                : sprintf('  🔄 Mise à jour de l\'utilisateur <info>%s</info>', $userData['username']);
            $io->text($message);

            $user->setPassword($this->passwordHasher->hashPassword($user, $userData['password']));
            $user->setRoles($userData['roles']);
            $user->setAdmin($userData['admin']);
            $user->setEmailVerifiedAt(new \DateTimeImmutable());
            $createdUsers[$userData['username']] = $user;
        }

        // 2. Ensure general channel exists
        $generalChannel = $channelRepo->findOneBy(['slug' => 'general']);
        if ($generalChannel === null) {
            $generalChannel = new Channel();
            $generalChannel->setName('Général');
            $generalChannel->setSlug('general');
            $generalChannel->setDescription('Canal principal pour les tests E2E.');
            $this->em->persist($generalChannel);
            $io->text('  ➕ Création du canal <info>Général</info>');
        }

        $this->em->flush();
        $io->success('Données E2E initialisées avec succès !');

        return Command::SUCCESS;
    }
}
