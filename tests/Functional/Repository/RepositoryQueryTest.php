<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\ChannelRepository;
use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[AllowMockObjectsWithoutExpectations]
class RepositoryQueryTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private User $user;
    private Channel $channel;
    private MessageRepository $messageRepository;
    private ChannelRepository $channelRepository;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $client = self::createClient();
        $container = $client->getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->messageRepository = $container->get(MessageRepository::class);
        $this->channelRepository = $container->get(ChannelRepository::class);

        $this->cleanup();

        $user = new User();
        $user->setUsername('test_repo_user');
        $user->setRoles(['ROLE_USER']);
        $passwordHasher = $container->get('security.user_password_hasher');
        $user->setPassword($passwordHasher->hashPassword($user, 'password123'));
        $this->entityManager->persist($user);

        $workspace = new Workspace();
        $workspace->setName('Repo WS');
        $workspace->setSlug('repo-ws');
        $workspace->setCreator($user);
        $workspace->addMember($user);
        $this->entityManager->persist($workspace);

        $channel = new Channel();
        $channel->setName('Repo Channel');
        $channel->setSlug('repo-channel');
        $channel->setCreator($user);
        $channel->addMember($user);
        $channel->setWorkspace($workspace);
        $this->entityManager->persist($channel);

        $this->entityManager->flush();

        $this->user = $user;
        $this->channel = $channel;
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement('DELETE FROM "message" WHERE content LIKE :prefix', ['prefix' => 'repo_test_%']);
        $conn->executeStatement('DELETE FROM "channel" WHERE slug = :slug', ['slug' => 'repo-channel']);
        $conn->executeStatement('DELETE FROM "workspace" WHERE slug = :slug', ['slug' => 'repo-ws']);
        $conn->executeStatement('DELETE FROM "user" WHERE username = :username', ['username' => 'test_repo_user']);
    }

    #[Test]
    public function testFindRecentReadBeforeExcludesRepliesAndOrdersCorrectly(): void
    {
        $m1 = new Message();
        $m1->setContent('repo_test_1');
        $m1->setAuthor($this->user);
        $m1->setChannel($this->channel);
        $this->entityManager->persist($m1);

        $m2 = new Message();
        $m2->setContent('repo_test_2');
        $m2->setAuthor($this->user);
        $m2->setChannel($this->channel);
        $this->entityManager->persist($m2);

        $reply = new Message();
        $reply->setContent('repo_test_reply');
        $reply->setAuthor($this->user);
        $reply->setChannel($this->channel);
        $reply->setParentMessage($m2);
        $this->entityManager->persist($reply);

        $m3 = new Message();
        $m3->setContent('repo_test_3');
        $m3->setAuthor($this->user);
        $m3->setChannel($this->channel);
        $this->entityManager->persist($m3);

        $this->entityManager->flush();

        $results = $this->messageRepository->findRecentReadBefore($this->channel, (int) $m3->getId(), limit: 5);

        static::assertNotEmpty($results);
        $ids = array_map(static fn(Message $m) => $m->getId(), $results);
        static::assertContains($m1->getId(), $ids);
        static::assertContains($m2->getId(), $ids);
        static::assertContains($m3->getId(), $ids);
        static::assertNotContains($reply->getId(), $ids, 'Reply with parentMessage should be excluded');
    }

    #[Test]
    public function testSearchByNameRespectsCustomLimit(): void
    {
        $results = $this->channelRepository->searchByName('Repo', $this->user, limit: 1);

        static::assertCount(1, $results);
        static::assertSame('Repo Channel', $results[0]->getName());
    }
}
