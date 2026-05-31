<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Channel;
use App\Entity\User;
use App\Entity\Message;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class MessageControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private $client;
    private User $testUser;
    private Channel $channel;

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
        $user->setUsername('test_msg_user');
        $user->setRoles(['ROLE_USER']);

        $passwordHasher = $container->get('security.user_password_hasher');
        $user->setPassword($passwordHasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);

        // Create a test channel
        $channel = new Channel();
        $channel->setName('Test Msg Channel');
        $channel->setSlug('test-msg-channel');
        $channel->setCreator($user);
        $channel->addMember($user);

        $this->entityManager->persist($channel);
        $this->entityManager->flush();

        $this->testUser = $user;
        $this->channel = $channel;
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        $channelRepository = $this->entityManager->getRepository(Channel::class);
        $messageRepository = $this->entityManager->getRepository(Message::class);

        $messages = $messageRepository->findAll();
        foreach ($messages as $msg) {
            $this->entityManager->remove($msg);
        }

        $users = $userRepository->findBy(['username' => 'test_msg_user']);
        foreach ($users as $u) {
            $this->entityManager->remove($u);
        }

        $channels = $channelRepository->findBy(['slug' => 'test-msg-channel']);
        foreach ($channels as $c) {
            $this->entityManager->remove($c);
        }

        $this->entityManager->flush();
    }

    #[Test]
    public function testPreviewMeCommand(): void
    {
        $this->client->request(
            'POST',
            '/api/message/preview',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['content' => '/me is testing preview'])
        );

        $this->assertResponseIsSuccessful();
        $responseContent = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('<em>is testing preview</em>', $responseContent);
    }

    #[Test]
    public function testPublishMeCommand(): void
    {
        $this->client->request(
            'POST',
            '/channels/test-msg-channel/publish',
            ['message' => '/me is typing a long message']
        );

        $this->assertResponseIsSuccessful();

        $messageRepository = $this->entityManager->getRepository(Message::class);
        $messages = $messageRepository->findBy(['author' => $this->testUser]);
        
        $this->assertCount(1, $messages);
        $this->assertSame('*is typing a long message*', $messages[0]->getContent());
    }
}
