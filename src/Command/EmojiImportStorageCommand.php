<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CustomEmoji;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:emoji:import-storage',
    description: 'Importe dans la base les fichiers émoji présents sur le stockage mais pas encore référencés.',
)]
class EmojiImportStorageCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FilesystemOperator $defaultStorage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simule sans écrire en base.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $listing = $this->defaultStorage->listContents('emojis/', true);

        $storageFiles = [];
        foreach ($listing as $item) {
            if (!$item->isFile() || !str_ends_with($item->path(), '.gif')) {
                continue;
            }

            // Skip empty files (negative cache from failed downloads)
            if ($item->fileSize() === 0) {
                continue;
            }

            $path = $item->path();
            $relative = str_starts_with($path, 'emojis/') ? substr($path, 7) : $path;
            $storageFiles[] = $relative;
        }

        if ($storageFiles === []) {
            $io->warning('Aucun fichier GIF trouvé dans le stockage (emojis/).');

            return Command::SUCCESS;
        }

        $existingCodes = $this->em->createQueryBuilder()
            ->select('e.code')
            ->from(CustomEmoji::class, 'e')
            ->getQuery()
            ->getSingleColumnResult();

        $io->note(sprintf(
            '%d fichier(s) dans le stockage, %d code(s) existant(s) en base.',
            \count($storageFiles),
            \count($existingCodes),
        ));

        $existingCodes = array_unique($existingCodes);
        $added = 0;

        foreach ($storageFiles as $relative) {
            // Reverse of CustomEmojiService::storageFilename / DownloadEmojiMessageHandler
            $noExt = substr($relative, 0, -4);
            $parts = explode('/', $noExt);
            $filePart = (string) array_pop($parts);

            $dir = \count($parts) > 0 ? implode('/', $parts) : null;
            $code = $dir !== null ? $filePart . ':' . $dir : $filePart;
            $filename = $dir !== null ? $dir . '/' . $filePart . '.gif' : $filePart . '.gif';

            if (\in_array($code, $existingCodes, true)) {
                continue;
            }

            $io->text(sprintf('  ➕  %-30s → code: %s', $relative, $code));

            if (!$dryRun) {
                $emoji = (new CustomEmoji())
                    ->setCode($code)
                    ->setFilename($filename)
                    ->setTags([]);
                $this->em->persist($emoji);
            }

            $added++;
        }

        if ($added === 0) {
            $io->success('Tous les fichiers du stockage sont déjà référencés en base.');

            return Command::SUCCESS;
        }

        if (!$dryRun) {
            $this->em->flush();
            $io->success(sprintf('%d émoji(s) importé(s) depuis le stockage.', $added));

            return Command::SUCCESS;
        }

        $io->success(sprintf('[Simulation] %d émoji(s) seraient importés.', $added));

        return Command::SUCCESS;
    }
}
