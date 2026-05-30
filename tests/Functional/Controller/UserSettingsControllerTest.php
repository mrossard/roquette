<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use PHPUnit\Framework\Attributes\Test;

class UserSettingsControllerTest extends WebTestCase
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

        // Create a test user
        $user = new User();
        $user->setUsername('test_settings_user');
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
        $users = $userRepository->findBy(['username' => ['test_settings_user', 'other_settings_user']]);

        $channelRepository = $this->entityManager->getRepository(Channel::class);
        $channels = $channelRepository->findBy(['slug' => 'test-channel-settings']);

        $messageRepository = $this->entityManager->getRepository(Message::class);

        foreach ($channels as $channel) {
            $messages = $messageRepository->findBy(['channel' => $channel]);
            foreach ($messages as $msg) {
                $this->entityManager->remove($msg);
            }
            $this->entityManager->remove($channel);
        }

        foreach ($users as $user) {
            $this->entityManager->remove($user);
        }

        $this->entityManager->flush();
    }

    #[Test]
    public function testUpdateColorSuccess(): void
    {
        $this->client->request('POST', '/user/update-color', ['hue' => 120]);

        $this->assertResponseStatusCodeSame(204);
        $this->assertResponseHasHeader('HX-Refresh');

        // Reload user from database
        $this->entityManager->clear();
        $user = $this->entityManager->getRepository(User::class)->find($this->testUser->getId());
        $this->assertSame(120, $user->getCustomHue());
    }

    #[Test]
    public function testUpdateColorMissingHue(): void
    {
        $this->client->request('POST', '/user/update-color');

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('Teinte manquante', $this->client->getResponse()->getContent());
    }

    #[Test]
    public function testUpdateColorInvalidHue(): void
    {
        $this->client->request('POST', '/user/update-color', ['hue' => 450]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('Teinte invalide', $this->client->getResponse()->getContent());
    }

    #[Test]
    public function testUpdateStatusSuccess(): void
    {
        $this->client->request('POST', '/user/update-status', ['status' => 'busy']);

        $this->assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $user = $this->entityManager->getRepository(User::class)->find($this->testUser->getId());
        $this->assertSame('busy', $user->getStatusOverride());
    }

    #[Test]
    public function testUpdateStatusInvalid(): void
    {
        $this->client->request('POST', '/user/update-status', ['status' => 'super-busy']);

        $this->assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function testPing(): void
    {
        $this->client->request('GET', '/user/ping');
        $this->assertResponseStatusCodeSame(204);
    }

    #[Test]
    public function testApiUsersList(): void
    {
        $this->client->request('GET', '/api/users');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/json');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        
        $usernames = array_column($data, 'username');
        $this->assertContains('test_settings_user', $usernames);
    }

    #[Test]
    public function testPinMessagePermissions(): void
    {
        // Create another user
        $otherUser = new User();
        $otherUser->setUsername('other_settings_user');
        $otherUser->setRoles(['ROLE_USER']);
        
        $container = $this->client->getContainer();
        $passwordHasher = $container->get('security.user_password_hasher');
        $otherUser->setPassword($passwordHasher->hashPassword($otherUser, 'password123'));

        $this->entityManager->persist($otherUser);

        // Create channel owned by otherUser
        $channel = new Channel();
        $channel->setName('Test Channel Settings');
        $channel->setSlug('test-channel-settings');
        $channel->setCreator($otherUser);

        $this->entityManager->persist($channel);

        // Create message
        $message = new Message();
        $message->setContent('Hello unit test');
        $message->setChannel($channel);
        $message->setAuthor($otherUser);

        $this->entityManager->persist($message);
        $this->entityManager->flush();

        // As testUser, try to pin otherUser's message in otherUser's channel
        $this->client->request('POST', sprintf('/messages/%d/pin', $message->getId()));

        // Should return 403 Forbidden since testUser is not the creator
        $this->assertResponseStatusCodeSame(403);
    }
}
