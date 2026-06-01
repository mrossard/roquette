<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\Reaction;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ReactionControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private $client;
    private User $testUser;
    private Channel $channel;
    private Message $message;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $container = $this->client->getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();

        $this->cleanup();

        // Create a test user
        $user = new User();
        $user->setUsername('test_reaction_user');
        $user->setRoles(['ROLE_USER']);

        $passwordHasher = $container->get('security.user_password_hasher');
        $user->setPassword($passwordHasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);

        // Create a test channel
        $channel = new Channel();
        $channel->setName('Test Channel Reaction');
        $channel->setSlug('test-channel-reaction');
        $channel->setCreator($user);
        $channel->addMember($user);

        $this->entityManager->persist($channel);

        // Create a message in the channel
        $message = new Message();
        $message->setChannel($channel);
        $message->setAuthor($user);
        $message->setContent('Hello World for reactions');

        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $this->testUser = $user;
        $this->channel = $channel;
        $this->message = $message;
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement('DELETE FROM reaction WHERE user_id IN (SELECT id FROM "user" WHERE username = ?)', [
            'test_reaction_user',
        ]);
        $conn->executeStatement('DELETE FROM message WHERE author_id IN (SELECT id FROM "user" WHERE username = ?)', [
            'test_reaction_user',
        ]);
        $conn->executeStatement('DELETE FROM user_channel_read WHERE user_id IN (SELECT id FROM "user" WHERE username = ?)', [
            'test_reaction_user',
        ]);
        $conn->executeStatement('DELETE FROM channel WHERE creator_id IN (SELECT id FROM "user" WHERE username = ?)', [
            'test_reaction_user',
        ]);
        $conn->executeStatement('DELETE FROM "user" WHERE username = ?', ['test_reaction_user']);
    }

    #[Test]
    public function testReactAndToggleOff(): void
    {
        $messageId = $this->message->getId();

        // 1. Post a valid emoji reaction
        $this->client->request('POST', sprintf('/messages/%d/react/👍', $messageId));
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $reactionRepo = $this->entityManager->getRepository(Reaction::class);
        $reactions = $reactionRepo->findBy([
            'message' => $this->message,
            'user' => $this->testUser,
            'emoji' => '👍',
        ]);
        static::assertCount(1, $reactions);

        // 2. Post same emoji reaction again (should toggle it off)
        $this->client->request('POST', sprintf('/messages/%d/react/👍', $messageId));
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $reactions = $reactionRepo->findBy([
            'message' => $this->message,
            'user' => $this->testUser,
            'emoji' => '👍',
        ]);
        static::assertCount(0, $reactions);
    }

    #[Test]
    public function testReactWithTooLongString(): void
    {
        $messageId = $this->message->getId();

        $this->client->request('POST', sprintf('/messages/%d/react/invalid_too_long_reaction_string', $messageId));
        $this->assertResponseStatusCodeSame(400);
    }
}
