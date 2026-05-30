<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Channel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-default-channels',
    description: 'Crée les canaux par défaut s\'ils n\'existent pas encore.',
)]
class SeedDefaultChannelsCommand extends Command
{
    private const DEFAULT_CHANNELS = [
        ['Général', 'general', 'Le canal de discussion principal pour tout le monde.'],
        ['Symfony', 'symfony', 'Discussion sur le framework Symfony, ses bundles et son écosystème.'],
        ['HTMX',    'htmx',    'Astuces, conseils et questions autour de l\'utilisation de HTMX.'],
        ['Mercure', 'mercure', 'Tout sur le protocole Server-Sent Events et le hub Mercure.'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $repo = $this->em->getRepository(Channel::class);
        $existing = $repo->findAll();

        if ($existing !== []) {
            $io->note(sprintf('%d canal(aux) déjà présent(s) en base. Aucune action effectuée.', count($existing)));
            return Command::SUCCESS;
        }

        foreach (self::DEFAULT_CHANNELS as [$name, $slug, $description]) {
            $channel = new Channel();
            $channel->setName($name);
            $channel->setSlug($slug);
            $channel->setDescription($description);
            $this->em->persist($channel);
            $io->text(sprintf('  ➕ Création du canal <info>%s</info> (slug: %s)', $name, $slug));
        }

        $this->em->flush();
        $io->success(sprintf('%d canaux par défaut créés avec succès.', count(self::DEFAULT_CHANNELS)));

        return Command::SUCCESS;
    }
}
