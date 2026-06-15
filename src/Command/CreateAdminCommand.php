<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'app:create-admin', description: 'Crée un nouvel utilisateur administrateur.')]
class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'username',
            InputArgument::OPTIONAL,
            'Le nom d\'utilisateur de l\'administrateur',
        )->addArgument('password', InputArgument::OPTIONAL, 'Le mot de passe de l\'administrateur');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $username = $input->getArgument('username');
        $password = $input->getArgument('password');

        if (!$username) {
            $username = $io->ask('Nom d\'utilisateur', null, function ($value) {
                if ($value === null || trim((string) $value) === '') {
                    throw new \RuntimeException($this->translator->trans('Le nom d\'utilisateur ne peut pas être vide.'));
                }
                return $value;
            });
        }

        // Check if user already exists
        $userRepo = $this->em->getRepository(User::class);
        $existingUser = $userRepo->findOneBy(['username' => $username]);
        if ($existingUser) {
            $existingUser->setAdmin(true);

            $roles = $existingUser->getRoles();
            if (!in_array('ROLE_ADMIN', $roles, true)) {
                $roles[] = 'ROLE_ADMIN';
                $existingUser->setRoles(array_unique($roles));
            }

            if ($password) {
                $hashedPassword = $this->passwordHasher->hashPassword($existingUser, $password);
                $existingUser->setPassword($hashedPassword);
                $io->note('Le mot de passe de l\'utilisateur a été mis à jour.');
            }

            $this->em->flush();
            $io->success(sprintf('L\'utilisateur existant "%s" a été promu administrateur avec succès.', $username));
            return Command::SUCCESS;
        }

        if (!$password) {
            $password = $io->askHidden('Mot de passe', function ($value) {
                if ($value === null || trim((string) $value) === '') {
                    throw new \RuntimeException($this->translator->trans('Le mot de passe ne peut pas être vide.'));
                }
                return $value;
            });
        }

        $user = new User();
        $user->setUsername($username);
        $user->setAdmin(true);
        $user->setRoles(['ROLE_USER', 'ROLE_ADMIN']);

        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('L\'administrateur "%s" a été créé avec succès.', $username));

        return Command::SUCCESS;
    }
}
