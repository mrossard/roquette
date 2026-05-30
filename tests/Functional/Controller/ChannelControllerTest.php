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
        $users = array_merge(
            $userRepository->findBy(['username' => 'test_channel_user']),
            $userRepository->findBy(['username' => 'test_channel_user_2'])
        );

        $channelRepository = $this->entityManager->getRepository(Channel::class);
        $channels = array_merge(
            $channelRepository->findBy(['slug' => 'test-channel-fav']),
            $channelRepository->findBy(['slug' => 'unique-edit-channel-name'])
        );

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

    #[Test]
    public function testUpdateRetention(): void
    {
        // Creator updates retention to 3 months
        $this->client->request('POST', sprintf('/channels/%s/retention', $this->channel->getSlug()), [
            'messageRetentionMonths' => '3'
        ]);

        $this->assertResponseStatusCodeSame(204);
        $this->assertResponseHasHeader('HX-Refresh');

        $this->entityManager->clear();
        $channel = $this->entityManager->getRepository(Channel::class)->find($this->channel->getId());
        $this->assertSame(3, $channel->getMessageRetentionMonths());

        // Update to "sans limite" (0)
        $this->client->request('POST', sprintf('/channels/%s/retention', $this->channel->getSlug()), [
            'messageRetentionMonths' => '0'
        ]);

        $this->assertResponseStatusCodeSame(204);
        $this->assertResponseHasHeader('HX-Refresh');

        $this->entityManager->clear();
        $channel = $this->entityManager->getRepository(Channel::class)->find($this->channel->getId());
        $this->assertNull($channel->getMessageRetentionMonths());
    }

    #[Test]
    public function testUpdateRetentionAccessDeniedForNonCreator(): void
    {
        // Create another user
        $container = $this->client->getContainer();
        $user2 = new User();
        $user2->setUsername('test_channel_user_2');
        $user2->setRoles(['ROLE_USER']);
        $passwordHasher = $container->get('security.user_password_hasher');
        $user2->setPassword($passwordHasher->hashPassword($user2, 'password123'));
        $this->entityManager->persist($user2);
        $this->entityManager->flush();

        // Login as the other user
        $this->client->loginUser($user2);

        // Attempt to update retention of channel created by user 1
        $this->client->request('POST', sprintf('/channels/%s/retention', $this->channel->getSlug()), [
            'messageRetentionMonths' => '3'
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function testEditChannel(): void
    {
        $this->client->request('POST', sprintf('/channels/%s/edit', $this->channel->getSlug()), [
            'name' => 'Unique Edit Channel Name',
            'description' => 'Nouvelle Description',
            'messageRetentionMonths' => '12'
        ]);

        $this->assertResponseRedirects('/channels/unique-edit-channel-name');

        $this->entityManager->clear();
        $channel = $this->entityManager->getRepository(Channel::class)->find($this->channel->getId());
        $this->assertSame('Unique Edit Channel Name', $channel->getName());
        $this->assertSame('unique-edit-channel-name', $channel->getSlug());
        $this->assertSame('Nouvelle Description', $channel->getDescription());
        $this->assertSame(12, $channel->getMessageRetentionMonths());
    }

    #[Test]
    public function testEditChannelAccessDeniedForNonCreator(): void
    {
        $container = $this->client->getContainer();
        $user2 = new User();
        $user2->setUsername('test_channel_user_2');
        $user2->setRoles(['ROLE_USER']);
        $passwordHasher = $container->get('security.user_password_hasher');
        $user2->setPassword($passwordHasher->hashPassword($user2, 'password123'));
        $this->entityManager->persist($user2);
        $this->entityManager->flush();

        $this->client->loginUser($user2);

        $this->client->request('POST', sprintf('/channels/%s/edit', $this->channel->getSlug()), [
            'name' => 'Nouveau Nom Test',
            'description' => 'Description Test',
            'messageRetentionMonths' => '12'
        ]);

        $this->assertResponseStatusCodeSame(403);
    }
}
