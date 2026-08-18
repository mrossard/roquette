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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:seed-load-test-data',
    description: 'Génère des données de test à grande échelle (utilisateurs, canaux privés, DM, messages) pour les tests de charge.',
)]
class SeedLoadTestDataCommand extends Command
{
    private const PUBLIC_CHANNELS = [
        ['Général', 'general', 'Canal principal de discussion.'],
        ['Symfony', 'symfony', 'Discussion sur le framework Symfony.'],
        ['HTMX',    'htmx',    'Astuces et questions autour de HTMX.'],
        ['Mercure', 'mercure', 'Tout sur le protocole SSE et le hub Mercure.'],
    ];

    private const MESSAGE_TEMPLATES = [
        'Salut, comment ça va ?',
        'Tu as vu le nouveau déploiement ?',
        'On avance bien sur le sprint en cours.',
        'J\'ai push les dernières modifs sur la branche.',
        'Quelqu\'un peut review ma PR ?',
        'Merci pour ton retour sur le ticket #%d.',
        'La réunion est à 14h aujourd\'hui.',
        'Est-ce que les tests passent bien en CI ?',
        'On pourrait améliorer la perf de cette requête.',
        'J\'ai trouvé un bug intéressant dans le module.',
        'N\'oubliez pas de mettre à jour la doc.',
        'Super, ça marche !',
        'Je viens de déployer en staging.',
        'Bonne journée à tous !',
        'On fait un point demain matin ?',
        'Les métriques sont dans le vert aujourd\'hui.',
        'Attention, la prod est sensible sur ce sujet.',
        'Quel est le plan pour la release de vendredi ?',
        'J\'ai besoin d\'aide sur une intégration.',
        'Le message a bien été livré.',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('users', null, InputOption::VALUE_REQUIRED, 'Nombre d\'utilisateurs', '2000')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Mot de passe commun', 'loadtest_pass')
            ->addOption('private-channels', null, InputOption::VALUE_REQUIRED, 'Nombre de canaux privés', '100')
            ->addOption('dms', null, InputOption::VALUE_REQUIRED, 'Nombre de conversations DM', '1600')
            ->addOption('dm-messages', null, InputOption::VALUE_REQUIRED, 'Messages dans les DM', '40000')
            ->addOption(
                'channel-messages',
                null,
                InputOption::VALUE_REQUIRED,
                'Messages dans les canaux publics et privés',
                '30000',
            )
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Taille des lots pour les flushs', '100')
            ->addOption(
                'output-mapping',
                null,
                InputOption::VALUE_REQUIRED,
                'Fichier JSON de sortie pour le mapping utilisateur->canal',
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Supprimer les données de test existantes avant de générer',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Génération des données de test de charge');

        $numUsers = (int) $input->getOption('users');
        $password = (string) $input->getOption('password');
        $numPrivateChannels = (int) $input->getOption('private-channels');
        $numDms = (int) $input->getOption('dms');
        $numDmMessages = (int) $input->getOption('dm-messages');
        $numChannelMessages = (int) $input->getOption('channel-messages');
        $batchSize = (int) $input->getOption('batch-size');
        $outputMapping = $input->getOption('output-mapping');
        if ($force) {
            $this->purgeExistingData($io);
        }

        if (!$force && $this->hasExistingData()) {
            $io->warning('Des données de test existent déjà. Utilisez --force pour les supprimer et les recréer.');

            return Command::FAILURE;
        }

        $io->section('Phase 1 : Création des utilisateurs');
        $users = $this->createUsers($numUsers, $password, $batchSize, $io);

        $io->section('Phase 2 : Création des canaux publics');
        $this->createPublicChannels($io);

        $io->section('Phase 3 : Création des canaux privés');
        $privateChannelData = $this->createPrivateChannels($numPrivateChannels, $users, $batchSize, $io);

        $io->section('Phase 4 : Création des conversations DM');
        $dmData = $this->createDms($numDms, $users, $batchSize, $io);

        $this->em->clear();

        $io->section('Phase 5 : Création des messages dans les DM');
        $this->createMessagesInChannels($dmData, $numDmMessages, $batchSize, $io);

        $io->section('Phase 6 : Création des messages dans les canaux');
        $general = $this->em->getRepository(Channel::class)->findOneBy(['slug' => 'general']);
        $generalEntry = ['channelId' => $general->getId(), 'slug' => 'general', 'memberIds' => array_keys($users)];
        $allChannels = array_merge([$generalEntry], $privateChannelData);
        $this->createMessagesInChannels($allChannels, $numChannelMessages, $batchSize, $io);

        if ($outputMapping) {
            $this->writeMappingFile($outputMapping, array_keys($users), $privateChannelData, $dmData, $io);
        }

        $this->displaySummary(
            $io,
            $numUsers,
            $password,
            $numPrivateChannels,
            $numDms,
            $numDmMessages,
            $numChannelMessages,
        );

        return Command::SUCCESS;
    }

    private function hasExistingData(): bool
    {
        $existing = (int) $this->em
            ->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('u.username LIKE :prefix')
            ->setParameter('prefix', 'loadtest_%')
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return $existing > 0;
    }

    private function purgeExistingData(SymfonyStyle $io): void
    {
        $io->note('Suppression des données de test existantes...');
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'DELETE FROM "message" WHERE author_id IN (SELECT id FROM "user" WHERE username LIKE \'loadtest_%\')',
        );
        $conn->executeStatement(
            'DELETE FROM "channel_user" WHERE channel_id IN (SELECT id FROM "channel" WHERE slug LIKE \'private-channel-%\' OR slug LIKE \'dm-%\')',
        );
        $conn->executeStatement('DELETE FROM "channel" WHERE slug LIKE \'private-channel-%\' OR slug LIKE \'dm-%\'');
        $conn->executeStatement('DELETE FROM "user" WHERE username LIKE \'loadtest_%\'');
        $this->em->clear();
        $io->info('Données de test supprimées.');
    }

    private function displaySummary(
        SymfonyStyle $io,
        int $numUsers,
        #[\SensitiveParameter]
        string $password,
        int $numPrivateChannels,
        int $numDms,
        int $numDmMessages,
        int $numChannelMessages,
    ): void {
        $io->success('Génération des données de test terminée avec succès !');
        $io->table(['Ressource', 'Quantité', 'Détail'], [
            ['Utilisateurs', (string) $numUsers, sprintf('loadtest_1 … loadtest_%d / %s', $numUsers, $password)],
            ['Canaux publics', '4', 'general, symfony, htmx, mercure'],
            [
                'Canaux privés',
                (string) $numPrivateChannels,
                sprintf('%d membres par canal', (int) ceil($numUsers / $numPrivateChannels)),
            ],
            ['Conversations DM', (string) $numDms, '2 utilisateurs par DM'],
            [
                'Messages DM',
                number_format($numDmMessages),
                sprintf('~%d par DM', (int) ceil($numDmMessages / max($numDms, 1))),
            ],
            ['Messages canaux', number_format($numChannelMessages), 'répartis dans tous les canaux'],
        ]);
    }

    /**
     * @return array<int, User> indexed by userId (int)
     */
    private function createUsers(
        int $numUsers,
        #[\SensitiveParameter]
        string $password,
        int $batchSize,
        SymfonyStyle $io,
    ): array {
        $dummy = new User();
        $dummy->setUsername('_hash_dummy_');
        $commonHash = $this->passwordHasher->hashPassword($dummy, $password);

        $users = [];
        $progress = $io->createProgressBar($numUsers);
        $progress->start();

        for ($i = 1; $i <= $numUsers; $i++) {
            $user = new User();
            $user->setUsername("loadtest_{$i}");
            $user->setDisplayName("Utilisateur Test {$i}");
            $user->setPassword($commonHash);
            $this->em->persist($user);
            $users[] = $user;

            if (($i % $batchSize) === 0) {
                $this->em->flush();
                $progress->advance($batchSize);
            }
        }

        $this->em->flush();
        $progress->finish();
        $io->newLine(2);

        $indexed = [];
        foreach ($users as $user) {
            $indexed[$user->getId()] = $user;
        }

        return $indexed;
    }

    private function createPublicChannels(SymfonyStyle $io): void
    {
        $repo = $this->em->getRepository(Channel::class);

        foreach (self::PUBLIC_CHANNELS as [$name, $slug, $description]) {
            if ($repo->findOneBy(['slug' => $slug])) {
                $io->text(sprintf('  ~ %s (existe déjà)', $slug));

                continue;
            }

            $channel = new Channel();
            $channel->setName($name);
            $channel->setSlug($slug);
            $channel->setDescription($description);
            $this->em->persist($channel);
            $io->text(sprintf('  + %s', $slug));
        }

        $this->em->flush();
        $io->newLine();
    }

    /**
     * @param array<int, User> $users indexed by userId
     *
     * @return list<array{channelId: int, slug: string, memberIds: int[]}>
     */
    private function createPrivateChannels(int $numChannels, array $users, int $batchSize, SymfonyStyle $io): array
    {
        $userIds = array_keys($users);
        $membersPerChannel = max(2, (int) ceil(count($userIds) / $numChannels));
        $progress = $io->createProgressBar($numChannels);
        $progress->start();

        $idx = 0;
        for ($c = 1; $c <= $numChannels; $c++) {
            $channel = new Channel();
            $channel->setName("Canal Privé {$c}");
            $channel->setSlug("private-channel-{$c}");
            $channel->setDescription("Canal privé de test n°{$c}.");
            $channel->setIsPrivate(true);

            for ($m = 0; $m < $membersPerChannel && $idx < count($userIds); $m++) {
                $channel->addMember($users[$userIds[$idx]]);
                ++$idx;
            }

            $this->em->persist($channel);

            if (($c % $batchSize) === 0) {
                $this->em->flush();
                $progress->advance($batchSize);
            }
        }

        $this->em->flush();
        $progress->finish();
        $io->newLine(2);

        $result = [];
        $channels = $this->em->getRepository(Channel::class)->findBy([], ['id' => 'ASC'], $numChannels);
        foreach ($channels as $ch) {
            if (!str_starts_with((string) $ch->getSlug(), 'private-channel-')) {
                continue;
            }
            $memberIds = array_map(static fn(User $u) => $u->getId(), $ch->getMembers()->toArray());
            $result[] = ['channelId' => $ch->getId(), 'slug' => $ch->getSlug(), 'memberIds' => $memberIds];
        }

        return $result;
    }

    /**
     * @param array<int, User> $users indexed by userId
     *
     * @return list<array{channelId: int, slug: string, memberIds: int[]}>
     */
    private function createDms(int $numDms, array $users, int $batchSize, SymfonyStyle $io): array
    {
        $userIds = array_keys($users);
        $pairs = $this->generateUniquePairs($userIds, $numDms);
        $progress = $io->createProgressBar(count($pairs));
        $progress->start();

        foreach ($pairs as $i => [$a, $b]) {
            $min = min($a, $b);
            $max = max($a, $b);

            $channel = new Channel();
            $channel->setName(sprintf('%s & %s', $users[$a]->getUsername(), $users[$b]->getUsername()));
            $channel->setSlug("dm-{$min}-{$max}");
            $channel->setDescription(
                "Conversation privée entre {$users[$a]->getUsername()} et {$users[$b]->getUsername()}.",
            );
            $channel->setIsPrivate(true);
            $channel->setIsDm(true);
            $channel->setCreator($users[$a]);
            $channel->addMember($users[$a]);
            $channel->addMember($users[$b]);
            $this->em->persist($channel);

            if ((($i + 1) % $batchSize) === 0) {
                $this->em->flush();
                $progress->advance($batchSize);
            }
        }

        $this->em->flush();
        $progress->finish();
        $io->newLine(2);

        $result = [];
        $channels = $this->em->getRepository(Channel::class)->findBy([], ['id' => 'ASC']);
        foreach ($channels as $ch) {
            if (!str_starts_with((string) $ch->getSlug(), 'dm-')) {
                continue;
            }
            $memberIds = array_map(static fn(User $u) => $u->getId(), $ch->getMembers()->toArray());
            $result[] = ['channelId' => $ch->getId(), 'slug' => $ch->getSlug(), 'memberIds' => $memberIds];
        }

        return $result;
    }

    /**
     * @param int[] $userIds
     *
     * @return list<array{int, int}>
     */
    private function generateUniquePairs(array $userIds, int $numPairs): array
    {
        $pairs = [];
        $used = [];
        $n = count($userIds);

        while (count($pairs) < $numPairs) {
            $a = $userIds[random_int(0, $n - 1)];
            $b = $userIds[random_int(0, $n - 1)];

            if ($a === $b) {
                continue;
            }

            $key = $a < $b ? "{$a}-{$b}" : "{$b}-{$a}";
            if (array_key_exists($key, $used)) {
                continue;
            }

            $used[$key] = true;
            $pairs[] = [$a, $b];
        }

        return $pairs;
    }

    /**
     * @param list<array{channelId: int, slug: string, memberIds: int[]}> $channels
     */
    private function createMessagesInChannels(
        array $channels,
        int $totalMessages,
        int $batchSize,
        SymfonyStyle $io,
    ): void {
        if ($channels === [] || $totalMessages === 0) {
            $io->note('Aucun canal ou aucun message à générer.');

            return;
        }

        $numTemplates = count(self::MESSAGE_TEMPLATES);
        $numChannels = count($channels);
        $progress = $io->createProgressBar($totalMessages);
        $progress->start();
        $count = 0;

        for ($i = 0; $i < $totalMessages; $i++) {
            $channelInfo = $channels[$i % $numChannels];
            $memberIds = $channelInfo['memberIds'];
            $authorId = $memberIds[random_int(0, count($memberIds) - 1)];

            $template = self::MESSAGE_TEMPLATES[$i % $numTemplates];

            $message = new Message();
            $message->setChannel($this->em->getReference(Channel::class, $channelInfo['channelId']));
            $message->setAuthor($this->em->getReference(User::class, $authorId));
            $message->setContent(sprintf($template, random_int(1000, 9999)));
            $message->setCreatedAt(
                new \DateTimeImmutable()
                    ->modify('-' . random_int(0, 90) . ' days')
                    ->modify('-' . random_int(0, 86_400) . ' seconds'),
            );
            $this->em->persist($message);
            ++$count;

            if (($count % $batchSize) === 0) {
                $this->em->flush();
                $this->em->clear();
                $progress->advance($batchSize);
            }
        }

        $remainder = $count % $batchSize;
        if ($remainder !== 0) {
            $this->em->flush();
            $this->em->clear();
            $progress->advance($remainder);
        }

        $progress->finish();
        $io->newLine(2);
    }

    /**
     * @param int[]                                                                  $userIds
     * @param list<array{channelId: int, slug: string, memberIds: int[]}>            $privateChannels
     * @param list<array{channelId: int, slug: string, memberIds: int[]}>            $dmData
     */
    private function writeMappingFile(
        string $path,
        array $userIds,
        array $privateChannels,
        array $dmData,
        SymfonyStyle $io,
    ): void {
        $mapping = [];
        foreach ($userIds as $uid) {
            $entry = ['id' => $uid, 'username' => "loadtest_{$uid}", 'privateChannelSlug' => null, 'dmSlugs' => []];

            foreach ($privateChannels as $pc) {
                if (!in_array($uid, $pc['memberIds'], true)) {
                    continue;
                }
                $entry['privateChannelSlug'] = $pc['slug'];
                break;
            }

            foreach ($dmData as $dm) {
                if (!in_array($uid, $dm['memberIds'], true)) {
                    continue;
                }
                $entry['dmSlugs'][] = $dm['slug'];
            }

            $mapping[] = $entry;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        file_put_contents($path, json_encode(
            $mapping,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
        $io->info(sprintf('Mapping écrit dans %s (%d entrées)', $path, count($mapping)));
    }
}
