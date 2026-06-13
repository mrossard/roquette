<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;


use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\Webhook;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[AllowMockObjectsWithoutExpectations]
class WebhookControllerTest extends WebTestCase
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

        // Create test user
        $user = new User();
        $user->setUsername('webhook_test_user');
        $user->setRoles(['ROLE_USER']);

        $passwordHasher = $container->get('security.user_password_hasher');
        $user->setPassword($passwordHasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);

        // Create test channel
        $channel = new Channel();
        $channel->setName('Webhook Test Channel');
        $channel->setSlug('webhook-test-channel');
        $channel->setCreator($user);
        $channel->addMember($user);
        $channel->addAdministrator($user);

        $this->entityManager->persist($channel);

        // Also create a robot user if not already in DB
        $robotUser = $this->entityManager->getRepository(User::class)->findOneBy(['username' => User::ROBOT_USERNAME]);
        if (!$robotUser) {
            $robot = new User();
            $robot->setUsername(User::ROBOT_USERNAME);
            $robot->setRoles(['ROLE_USER']);
            $robot->setPassword('not-needed');
            $this->entityManager->persist($robot);
        }

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
        $webhookRepository = $this->entityManager->getRepository(Webhook::class);
        $messageRepository = $this->entityManager->getRepository(Message::class);

        // Delete test webhooks
        $webhooks = $webhookRepository->findAll();
        foreach ($webhooks as $wh) {
            $this->entityManager->remove($wh);
        }

        // Delete test messages in the test channel
        $testChannel = $channelRepository->findOneBy(['slug' => 'webhook-test-channel']);
        if ($testChannel) {
            $messages = $messageRepository->findBy(['channel' => $testChannel]);
            foreach ($messages as $msg) {
                $this->entityManager->remove($msg);
            }
            $this->entityManager->remove($testChannel);
        }

        $users = $userRepository->findBy(['username' => 'webhook_test_user']);
        foreach ($users as $u) {
            $this->entityManager->remove($u);
        }

        $this->entityManager->flush();
    }

    #[Test]
    public function testCreateWebhook(): void
    {
        // Request the creation of a webhook via POST inside the channel settings
        $this->client->request('POST', sprintf('/channels/%s/webhooks/create', $this->channel->getSlug()), [
            'name' => 'GitHub Production',
        ]);

        $this->assertResponseIsSuccessful();

        // Check Webhook exists in database
        $webhookRepository = $this->entityManager->getRepository(Webhook::class);
        $webhook = $webhookRepository->findOneBy(['name' => 'GitHub Production']);

        static::assertNotNull($webhook);
        static::assertEquals($this->channel->getId(), $webhook->getChannel()->getId());
        static::assertEquals($this->testUser->getId(), $webhook->getCreator()->getId());
        static::assertTrue($webhook->isActive());
    }

    #[Test]
    public function testToggleWebhook(): void
    {
        // Setup a webhook in database
        $webhook = new Webhook();
        $webhook->setName('GitLab CI');
        $webhook->setChannel($this->channel);
        $webhook->setCreator($this->testUser);
        $this->entityManager->persist($webhook);
        $this->entityManager->flush();

        static::assertTrue($webhook->isActive());

        // Toggle active status (deactivate)
        $this->client->request('POST', sprintf('/webhooks/%d/toggle', $webhook->getId()));

        $this->assertResponseIsSuccessful();

        $webhookRepository = $this->entityManager->getRepository(Webhook::class);
        $this->entityManager->clear(); // Clear entity manager cache
        $webhook = $webhookRepository->find($webhook->getId());
        static::assertFalse($webhook->isActive());

        // Toggle again (activate)
        $this->client->request('POST', sprintf('/webhooks/%d/toggle', $webhook->getId()));

        $this->assertResponseIsSuccessful();
        $this->entityManager->clear(); // Clear entity manager cache
        $webhook = $webhookRepository->find($webhook->getId());
        static::assertTrue($webhook->isActive());
    }

    #[Test]
    public function testDeleteWebhook(): void
    {
        $webhook = new Webhook();
        $webhook->setName('Delete Me');
        $webhook->setChannel($this->channel);
        $webhook->setCreator($this->testUser);
        $this->entityManager->persist($webhook);
        $this->entityManager->flush();

        $webhookId = $webhook->getId();

        $this->client->request('POST', sprintf('/webhooks/%d/delete', $webhookId));

        $this->assertResponseIsSuccessful();

        $webhookRepository = $this->entityManager->getRepository(Webhook::class);
        static::assertNull($webhookRepository->find($webhookId));
    }

    #[Test]
    public function testIncomingWebhookValidPayload(): void
    {
        $webhook = new Webhook();
        $webhook->setName('Incoming Webhook Test');
        $webhook->setChannel($this->channel);
        $webhook->setCreator($this->testUser);
        $this->entityManager->persist($webhook);
        $this->entityManager->flush();

        // Clear cookies to simulate an unauthenticated client
        $this->client->getCookieJar()->clear();

        // Send a valid JSON payload to the incoming endpoint
        $this->client->request(
            'POST',
            sprintf('/api/webhooks/incoming/%s', $webhook->getToken()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'text' => 'New deploy succeeded! 🚀',
                'username' => 'Vercel Deployment',
                'avatar_url' => 'https://example.com/vercel.png',
            ]),
        );

        static::assertSame(201, $this->client->getResponse()->getStatusCode());

        $responseContent = json_decode($this->client->getResponse()->getContent(), true);
        static::assertTrue($responseContent['success']);
        static::assertArrayHasKey('message_id', $responseContent);

        // Verify the message exists in database with custom name and avatar
        $messageRepository = $this->entityManager->getRepository(Message::class);
        $message = $messageRepository->find($responseContent['message_id']);

        static::assertNotNull($message);
        static::assertSame('New deploy succeeded! 🚀', $message->getContent());
        static::assertSame('Vercel Deployment', $message->getCustomAuthorName());
        static::assertSame('https://example.com/vercel.png', $message->getCustomAuthorAvatar());
    }

    #[Test]
    public function testIncomingWebhookInactiveForbidden(): void
    {
        $webhook = new Webhook();
        $webhook->setName('Inactive Webhook');
        $webhook->setChannel($this->channel);
        $webhook->setCreator($this->testUser);
        $webhook->setIsActive(false);
        $this->entityManager->persist($webhook);
        $this->entityManager->flush();

        $this->client->getCookieJar()->clear();

        $this->client->request(
            'POST',
            sprintf('/api/webhooks/incoming/%s', $webhook->getToken()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['text' => 'Hello World']),
        );

        static::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    #[Test]
    public function testIncomingWebhookNotFound(): void
    {
        $this->client->getCookieJar()->clear();

        $this->client->request(
            'POST',
            '/api/webhooks/incoming/non-existent-token',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['text' => 'Hello World']),
        );

        static::assertSame(404, $this->client->getResponse()->getStatusCode());
    }
}
