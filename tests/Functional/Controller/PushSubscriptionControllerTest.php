<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PushSubscriptionControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private $client;
    private User $testUser;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $container = $this->client->getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();

        $this->cleanup();

        $user = new User();
        $user->setUsername('push_test_user');
        $user->setRoles(['ROLE_USER']);

        $passwordHasher = $container->get('security.user_password_hasher');
        $user->setPassword($passwordHasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->testUser = $user;
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
        $subscriptionRepository = $this->entityManager->getRepository(PushSubscription::class);

        $subscriptions = $subscriptionRepository->findAll();
        foreach ($subscriptions as $sub) {
            $this->entityManager->remove($sub);
        }

        $users = $userRepository->findBy(['username' => 'push_test_user']);
        foreach ($users as $u) {
            $this->entityManager->remove($u);
        }

        $this->entityManager->flush();
    }

    #[Test]
    public function testSubscribe(): void
    {
        $this->client->request(
            'POST',
            '/push/subscribe',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'endpoint' => 'https://example.com/push/abc123',
                'keys' => [
                    'p256dh' => 'test_public_key_123',
                    'auth' => 'test_auth_token_456',
                ],
            ]),
        );

        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        $response = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('subscribed', $response['status']);

        $subscriptionRepository = $this->entityManager->getRepository(PushSubscription::class);
        $subscription = $subscriptionRepository->findOneBy(['endpoint' => 'https://example.com/push/abc123']);

        self::assertNotNull($subscription);
        self::assertSame('test_public_key_123', $subscription->getPublicKey());
        self::assertSame('test_auth_token_456', $subscription->getAuthToken());
        self::assertSame($this->testUser->getId(), $subscription->getUser()->getId());
    }

    #[Test]
    public function testSubscribeDuplicate(): void
    {
        $subscription = new PushSubscription();
        $subscription->setUser($this->testUser);
        $subscription->setEndpoint('https://example.com/push/dup');
        $subscription->setPublicKey('old_key');
        $subscription->setAuthToken('old_token');
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        $this->client->request(
            'POST',
            '/push/subscribe',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'endpoint' => 'https://example.com/push/dup',
                'keys' => [
                    'p256dh' => 'new_key',
                    'auth' => 'new_token',
                ],
            ]),
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $response = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('updated', $response['status']);

        $subscriptionRepository = $this->entityManager->getRepository(PushSubscription::class);
        $sub = $subscriptionRepository->findOneBy(['endpoint' => 'https://example.com/push/dup']);

        self::assertNotNull($sub);
        self::assertSame('new_key', $sub->getPublicKey());
        self::assertSame('new_token', $sub->getAuthToken());
    }

    #[Test]
    public function testSubscribeInvalidData(): void
    {
        $this->client->request(
            'POST',
            '/push/subscribe',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['endpoint' => 'https://example.com/push/bad']),
        );

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    #[Test]
    public function testUnsubscribe(): void
    {
        $subscription = new PushSubscription();
        $subscription->setUser($this->testUser);
        $subscription->setEndpoint('https://example.com/push/to_delete');
        $subscription->setPublicKey('key');
        $subscription->setAuthToken('token');
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        $this->client->request(
            'POST',
            '/push/unsubscribe',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['endpoint' => 'https://example.com/push/to_delete']),
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $subscriptionRepository = $this->entityManager->getRepository(PushSubscription::class);
        $sub = $subscriptionRepository->findOneBy(['endpoint' => 'https://example.com/push/to_delete']);

        self::assertNull($sub);
    }

    #[Test]
    public function testPublicKey(): void
    {
        $this->client->request('GET', '/push/public-key');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $response = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('publicKey', $response);
    }
}
