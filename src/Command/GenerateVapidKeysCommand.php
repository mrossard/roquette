<?php

declare(strict_types=1);

namespace App\Command;

use Minishlink\WebPush\VAPID;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:vapid:generate-keys', description: 'Génère les clés VAPID pour les notifications push.')]
class GenerateVapidKeysCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'write',
            'w',
            InputOption::VALUE_NONE,
            'Écrit les clés générées directement dans le fichier .env.local sans confirmation.',
        )->addOption(
            'skip-existing',
            's',
            InputOption::VALUE_NONE,
            'Ne génère pas de nouvelles clés si elles sont déjà présentes.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('skip-existing') && $this->hasExistingKeys()) {
            $io->info('Les clés VAPID existent déjà. Génération ignorée.');
            return Command::SUCCESS;
        }

        $keys = VAPID::createVapidKeys();

        $publicKey = $keys['publicKey'];
        $privateKey = $keys['privateKey'];

        $io->section('Clés VAPID générées');

        $io->writeln('VAPID_PUBLIC_KEY=' . $publicKey);
        $io->writeln('VAPID_PRIVATE_KEY=' . $privateKey);
        $io->writeln('');

        $shouldWrite = $input->getOption('write') || $io->confirm('Écrire ces clés dans .env.local ?', true);

        if ($shouldWrite) {
            $envLocalPath = $this->findEnvLocal();

            if ($envLocalPath === null) {
                $path = dirname(__DIR__, 2) . '/.env.local';
                if (is_writable(dirname($path))) {
                    touch($path);
                }
                $envLocalPath = $this->findEnvLocal();
            }

            if ($envLocalPath === null) {
                $io->warning('Fichier .env.local introuvable et impossible à créer. Copiez les clés manuellement.');
                return Command::SUCCESS;
            }

            $content = file_get_contents($envLocalPath);
            $content = $this->updateOrAppend($content, 'VAPID_PUBLIC_KEY', $publicKey);
            $content = $this->updateOrAppend($content, 'VAPID_PRIVATE_KEY', $privateKey);
            $content = $this->updateOrAppend($content, 'VAPID_SUBJECT', 'mailto:admin@roquette.local');

            file_put_contents($envLocalPath, $content);

            $io->success('Clés VAPID écrites dans .env.local');
        }

        return Command::SUCCESS;
    }

    private function hasExistingKeys(): bool
    {
        $envLocalPath = $this->findEnvLocal();
        if ($envLocalPath !== null) {
            $content = file_get_contents($envLocalPath);
            if (preg_match('/^VAPID_PUBLIC_KEY=.+$/m', $content) && preg_match('/^VAPID_PRIVATE_KEY=.+$/m', $content)) {
                return true;
            }
        }

        $publicKey = $_ENV['VAPID_PUBLIC_KEY'] ?? null;
        $privateKey = $_ENV['VAPID_PRIVATE_KEY'] ?? null;

        return $publicKey !== null && $publicKey !== '' && ($privateKey !== null && $privateKey !== '');
    }

    private function findEnvLocal(): ?string
    {
        $path = dirname(__DIR__, 2) . '/.env.local';

        return file_exists($path) ? realpath($path) : null;
    }

    private function updateOrAppend(string $content, string $key, string $value): string
    {
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

        if (preg_match($pattern, $content)) {
            return preg_replace($pattern, $key . '=' . $value, $content);
        }

        return rtrim($content, "\n") . "\n" . $key . '=' . $value . "\n";
    }
}
