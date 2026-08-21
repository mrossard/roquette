<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Service\HybridSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class HybridSearchServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private HybridSearchService $hybridSearchService;
    private User $user1;
    private User $user2;
    private Channel $publicChannel;
    private Channel $privateChannel;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->hybridSearchService = $container->get(HybridSearchService::class);

        $this->cleanup();

        $passwordHasher = $container->get('security.user_password_hasher');

        // User 1
        $this->user1 = new User();
        $this->user1->setUsername('hybrid_test_user1');
        $this->user1->setRoles(['ROLE_USER']);
        $this->user1->setPassword($passwordHasher->hashPassword($this->user1, 'password123'));
        $this->entityManager->persist($this->user1);

        // User 2
        $this->user2 = new User();
        $this->user2->setUsername('hybrid_test_user2');
        $this->user2->setRoles(['ROLE_USER']);
        $this->user2->setPassword($passwordHasher->hashPassword($this->user2, 'password123'));
        $this->entityManager->persist($this->user2);

        // Public Channel
        $this->publicChannel = new Channel();
        $this->publicChannel->setName('Hybrid Public Channel');
        $this->publicChannel->setSlug('hybrid-public-channel');
        $this->publicChannel->setIsPrivate(false);
        $this->publicChannel->setCreator($this->user1);
        $this->publicChannel->addMember($this->user1);
        $this->publicChannel->addMember($this->user2);
        $this->entityManager->persist($this->publicChannel);

        // Private Channel (only user 1 is member)
        $this->privateChannel = new Channel();
        $this->privateChannel->setName('Hybrid Private Channel');
        $this->privateChannel->setSlug('hybrid-private-channel');
        $this->privateChannel->setIsPrivate(true);
        $this->privateChannel->setCreator($this->user1);
        $this->privateChannel->addMember($this->user1);
        $this->entityManager->persist($this->privateChannel);

        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $channelRepo = $this->entityManager->getRepository(Channel::class);
        $messageRepo = $this->entityManager->getRepository(Message::class);
        $userRepo = $this->entityManager->getRepository(User::class);

        foreach (['hybrid-public-channel', 'hybrid-private-channel'] as $slug) {
            $ch = $channelRepo->findOneBy(['slug' => $slug]);
            if ($ch) {
                $messages = $messageRepo->findBy(['channel' => $ch]);
                foreach ($messages as $msg) {
                    $this->entityManager->remove($msg);
                }
                $this->entityManager->remove($ch);
            }
        }

        foreach (['hybrid_test_user1', 'hybrid_test_user2'] as $username) {
            $u = $userRepo->findOneBy(['username' => $username]);
            if ($u) {
                $this->entityManager->remove($u);
            }
        }

        $this->entityManager->flush();
    }

    public function testFtsSearchInChannel(): void
    {
        $msg1 = new Message();
        $msg1->setChannel($this->publicChannel);
        $msg1->setAuthor($this->user1);
        $msg1->setContent('Le déploiement de la version 2.0 est un succès complet');
        $msg1->setCreatedAt(new \DateTimeImmutable('-1 hour'));
        $this->entityManager->persist($msg1);

        $msg2 = new Message();
        $msg2->setChannel($this->publicChannel);
        $msg2->setAuthor($this->user2);
        $msg2->setContent('Je prépare le café pour la réunion');
        $msg2->setCreatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($msg2);

        $this->entityManager->flush();

        $results = $this->hybridSearchService->searchInChannel($this->publicChannel, 'déploiement');
        static::assertCount(1, $results);
        static::assertSame($msg1->getId(), $results[0]->getId());
    }

    public function testGlobalSearchRespectsChannelPrivacy(): void
    {
        $msgPublic = new Message();
        $msgPublic->setChannel($this->publicChannel);
        $msgPublic->setAuthor($this->user1);
        $msgPublic->setContent('Document public sur la sécurité des serveurs');
        $msgPublic->setCreatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($msgPublic);

        $msgPrivate = new Message();
        $msgPrivate->setChannel($this->privateChannel);
        $msgPrivate->setAuthor($this->user1);
        $msgPrivate->setContent('Document ultra secret dans le canal privé sur la sécurité');
        $msgPrivate->setCreatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($msgPrivate);

        $this->entityManager->flush();

        // User 1 is in private channel -> should find both messages
        $resultsUser1 = $this->hybridSearchService->searchGlobal(currentUser: $this->user1, textQuery: 'sécurité');
        static::assertCount(2, $resultsUser1);

        // User 2 is NOT in private channel -> should only find public message
        $resultsUser2 = $this->hybridSearchService->searchGlobal(currentUser: $this->user2, textQuery: 'sécurité');
        static::assertCount(1, $resultsUser2);
        static::assertSame($msgPublic->getId(), $resultsUser2[0]->getId());
    }

    public function testEmbeddingIndexingAndCascade(): void
    {
        $msg = new Message();
        $msg->setChannel($this->publicChannel);
        $msg->setAuthor($this->user1);
        $msg->setContent('Message avec embedding vectoriel');
        $msg->setCreatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($msg);
        $this->entityManager->flush();

        $conn = $this->entityManager->getConnection();
        $dummyVector = '[' . implode(',', array_fill(0, 768, 0.123)) . ']';

        $conn->executeStatement(
            'INSERT INTO message_embedding (message_id, channel_id, embedding, created_at) VALUES (:msgId, :chId, :vec::vector, NOW())',
            [
                'msgId' => $msg->getId(),
                'chId' => $this->publicChannel->getId(),
                'vec' => $dummyVector,
            ],
        );

        $count = (int) $conn->fetchOne('SELECT COUNT(*) FROM message_embedding WHERE message_id = :msgId', [
            'msgId' => $msg->getId(),
        ]);
        static::assertSame(1, $count);

        // Deleting embedding explicitly
        $this->hybridSearchService->deleteMessageEmbedding((int) $msg->getId());
        $countAfter = (int) $conn->fetchOne('SELECT COUNT(*) FROM message_embedding WHERE message_id = :msgId', [
            'msgId' => $msg->getId(),
        ]);
        static::assertSame(0, $countAfter);
    }
}
