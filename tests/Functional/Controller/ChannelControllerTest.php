<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Channel;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use PHPUnit\Framework\Attributes\Test;

class ChannelControllerTest extends WebTestCase
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
        $user->setUsername('test_channel_user');
        $user->setRoles(['ROLE_USER']);
        
        $passwordHasher = $container->get('security.user_password_hasher');
        $user->setPassword($passwordHasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);

        // Create a test channel
        $channel = new Channel();
        $channel->setName('Test Channel Fav');
        $channel->setSlug('test-channel-fav');
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
        $users = $userRepository->findBy(['username' => 'test_channel_user']);

        $channelRepository = $this->entityManager->getRepository(Channel::class);
        $channels = $channelRepository->findBy(['slug' => 'test-channel-fav']);

        foreach ($channels as $channel) {
            $this->entityManager->remove($channel);
        }

        foreach ($users as $user) {
            $this->entityManager->remove($user);
        }

        $this->entityManager->flush();
    }

    #[Test]
    public function testToggleFavoriteChannel(): void
    {
        // 1. Initially it should not be favorite
        $this->assertFalse($this->testUser->isChannelFavorite($this->channel));

        // 2. Send request to favorite
        $this->client->request('POST', sprintf('/channels/%s/favorite', $this->channel->getSlug()));

        $this->assertResponseStatusCodeSame(204);
        $this->assertResponseHasHeader('HX-Refresh');

        // Reload user from database
        $this->entityManager->clear();
        $user = $this->entityManager->getRepository(User::class)->find($this->testUser->getId());
        $channel = $this->entityManager->getRepository(Channel::class)->find($this->channel->getId());
        $this->assertTrue($user->isChannelFavorite($channel));

        // 3. Send request to unfavorite
        $this->client->request('POST', sprintf('/channels/%s/favorite', $this->channel->getSlug()));

        $this->assertResponseStatusCodeSame(204);
        $this->assertResponseHasHeader('HX-Refresh');

        // Reload user again
        $this->entityManager->clear();
        $user = $this->entityManager->getRepository(User::class)->find($this->testUser->getId());
        $this->assertFalse($user->isChannelFavorite($channel));
    }
}
