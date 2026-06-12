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

#[AsCommand(name: 'app:reset-user-password', description: 'Réinitialise le mot de passe d\'un utilisateur.')]
class ResetUserPasswordCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::OPTIONAL, 'Le nom d\'utilisateur')
            ->addArgument('password', InputArgument::OPTIONAL, 'Le nouveau mot de passe');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $username = $input->getArgument('username');
        $password = $input->getArgument('password');

        if (!$username) {
            $username = $io->ask('Nom d\'utilisateur', null, static function ($value) {
                if ($value === null || trim((string) $value) === '') {
                    throw new \RuntimeException('Le nom d\'utilisateur ne peut pas être vide.');
                }

                return $value;
            });
        }

        /** @var User|null $user */
        $user = $this->em->getRepository(User::class)->findOneBy(['username' => $username]);

        if ($user === null) {
            $io->error(sprintf('Aucun utilisateur trouvé avec le nom d\'utilisateur "%s".', $username));

            return Command::FAILURE;
        }

        if (!$password) {
            $password = $io->askHidden('Nouveau mot de passe', static function ($value) {
                if ($value === null || trim((string) $value) === '') {
                    throw new \RuntimeException('Le mot de passe ne peut pas être vide.');
                }

                return $value;
            });

            $io->askHidden('Confirmer le mot de passe', static function ($value) use ($password) {
                if ($value !== $password) {
                    throw new \RuntimeException('Les mots de passe ne correspondent pas.');
                }

                return $value;
            });
        }

        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        $this->em->flush();

        $io->success(sprintf('Le mot de passe de l\'utilisateur "%s" a été réinitialisé avec succès.', $username));

        return Command::SUCCESS;
    }
}
