<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;


use App\Entity\Channel;
use App\Entity\User;
use App\Entity\AuditLog;
use App\Enum\AuditAction;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[AllowMockObjectsWithoutExpectations]
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
            $userRepository->findBy(['username' => 'test_channel_user_2']),
        );

        $channelRepository = $this->entityManager->getRepository(Channel::class);
        $channels = array_merge(
            $channelRepository->findBy(['slug' => 'test-channel-fav']),
            $channelRepository->findBy(['slug' => 'unique-edit-channel-name']),
        );

        $messageRepository = $this->entityManager->getRepository(\App\Entity\Message::class);
        foreach ($channels as $channel) {
            $messages = $messageRepository->findBy(['channel' => $channel]);
            foreach ($messages as $message) {
                $this->entityManager->remove($message);
            }
        }
        $this->entityManager->flush();

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
        static::assertFalse($this->testUser->isChannelFavorite($this->channel));

        // 2. Send request to favorite
        $this->client->request('POST', sprintf('/channels/%s/favorite', $this->channel->getSlug()));

        $this->assertResponseStatusCodeSame(204);
        $this->assertResponseHasHeader('HX-Refresh');

        // Reload user from database
        $this->entityManager->clear();
        $user = $this->entityManager->getRepository(User::class)->find($this->testUser->getId());
        $channel = $this->entityManager->getRepository(Channel::class)->find($this->channel->getId());
        static::assertTrue($user->isChannelFavorite($channel));

        // 3. Send request to unfavorite
        $this->client->request('POST', sprintf('/channels/%s/favorite', $this->channel->getSlug()));

        $this->assertResponseStatusCodeSame(204);
        $this->assertResponseHasHeader('HX-Refresh');

        // Reload user again
        $this->entityManager->clear();
        $user = $this->entityManager->getRepository(User::class)->find($this->testUser->getId());
        static::assertFalse($user->isChannelFavorite($channel));
    }

    #[Test]
    public function testUpdateRetention(): void
    {
        // Creator updates retention to 3 months
        $this->client->request('POST', sprintf('/channels/%s/retention', $this->channel->getSlug()), [
            'messageRetentionMonths' => '3',
        ]);

        $this->assertResponseStatusCodeSame(204);
        $this->assertResponseHasHeader('HX-Refresh');

        $this->entityManager->clear();
        $channel = $this->entityManager->getRepository(Channel::class)->find($this->channel->getId());
        static::assertSame(3, $channel->getMessageRetentionMonths());

        // Update to "sans limite" (0)
        $this->client->request('POST', sprintf('/channels/%s/retention', $this->channel->getSlug()), [
            'messageRetentionMonths' => '0',
        ]);

        $this->assertResponseStatusCodeSame(204);
        $this->assertResponseHasHeader('HX-Refresh');

        $this->entityManager->clear();
        $channel = $this->entityManager->getRepository(Channel::class)->find($this->channel->getId());
        static::assertNull($channel->getMessageRetentionMonths());
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
            'messageRetentionMonths' => '3',
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function testEditChannel(): void
    {
        $this->client->request('POST', sprintf('/channels/%s/edit', $this->channel->getSlug()), [
            'name' => 'Unique Edit Channel Name',
            'description' => 'Nouvelle Description',
            'messageRetentionMonths' => '12',
        ]);

        $this->assertResponseRedirects('/channels/unique-edit-channel-name');

        $this->entityManager->clear();
        $channel = $this->entityManager->getRepository(Channel::class)->find($this->channel->getId());
        static::assertSame('Unique Edit Channel Name', $channel->getName());
        static::assertSame('unique-edit-channel-name', $channel->getSlug());
        static::assertSame('Nouvelle Description', $channel->getDescription());
        static::assertSame(12, $channel->getMessageRetentionMonths());
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
            'messageRetentionMonths' => '12',
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function testCreateTodoListChannel(): void
    {
        $this->client->request('POST', '/channels/create', [
            'name' => 'Ma Todo List',
            'description' => 'Ma super liste de tâches',
            'messageRetentionMonths' => '6',
            'isTodoList' => '1',
        ]);

        $this->assertResponseRedirects('/channels/ma-todo-list');

        $this->entityManager->clear();
        $channel = $this->entityManager->getRepository(Channel::class)->findOneBy(['slug' => 'ma-todo-list']);
        static::assertNotNull($channel);
        static::assertTrue($channel->isTodoList());

        // Assert AuditLog was created
        $auditLog = $this->entityManager->getRepository(AuditLog::class)->findOneBy([
            'action' => AuditAction::CHANNEL_CREATE,
            'performedBy' => $this->testUser,
        ]);
        static::assertNotNull($auditLog);
        static::assertSame('ma-todo-list', $auditLog->getDetails()['slug']);

        if ($auditLog) {
            $this->entityManager->remove($auditLog);
        }
        if ($channel) {
            $this->entityManager->remove($channel);
        }
        $this->entityManager->flush();
    }

    #[Test]
    public function testTransformSubChannelToTodoList(): void
    {
        $subChannel = new Channel();
        $subChannel->setName('Sub Channel');
        $subChannel->setSlug('sub-channel-test');
        $subChannel->setCreator($this->testUser);
        $subChannel->addMember($this->testUser);

        $parentMessage = new \App\Entity\Message();
        $parentMessage->setContent('Parent Message content');
        $parentMessage->setAuthor($this->testUser);
        $parentMessage->setChannel($this->channel);
        $this->entityManager->persist($parentMessage);

        $subChannel->setParentMessage($parentMessage);
        $this->entityManager->persist($subChannel);
        $this->entityManager->flush();

        static::assertFalse($subChannel->isTodoList());

        $this->client->request('POST', '/channels/sub-channel-test/edit', [
            'name' => 'Sub Channel Edited',
            'description' => 'Sub channel description',
            'messageRetentionMonths' => '6',
            'isTodoList' => '1',
        ]);

        $this->assertResponseRedirects('/channels/sub-channel-edited');

        $this->entityManager->clear();
        $updatedSubChannel = $this->entityManager->getRepository(Channel::class)->find($subChannel->getId());
        static::assertNotNull($updatedSubChannel);
        static::assertTrue($updatedSubChannel->isTodoList());

        $this->entityManager->remove($updatedSubChannel);
        $dbParentMessage = $this->entityManager
            ->getRepository(\App\Entity\Message::class)
            ->find($parentMessage->getId());
        if ($dbParentMessage) {
            $this->entityManager->remove($dbParentMessage);
        }
        $this->entityManager->flush();
    }

    #[Test]
    public function testExportChannelSuccess(): void
    {
        // 1. Create a test message in the channel
        $msg = new \App\Entity\Message();
        $msg->setAuthor($this->testUser);
        $msg->setChannel($this->channel);
        $msg->setContent('Hello World');
        $this->entityManager->persist($msg);
        $this->entityManager->flush();

        // Mock FileUploadService to prevent S3 connection errors
        $mock = $this->createMock(\App\Service\FileUploadService::class);
        $this->client->getContainer()->set(\App\Service\FileUploadService::class, $mock);

        // 2. Request export
        $this->client->request('GET', sprintf('/channels/%s/export', $this->channel->getSlug()));

        $this->assertResponseIsSuccessful();
        $response = $this->client->getResponse();

        if (class_exists(\ZipArchive::class)) {
            static::assertSame('application/zip', $response->headers->get('Content-Type'));
            static::assertStringStartsWith('PK', $response->getContent()); // ZIP file signature
        } else {
            static::assertSame('application/x-tar', $response->headers->get('Content-Type'));
        }
    }

    #[Test]
    public function testExportChannelAccessDenied(): void
    {
        // Create another user who is NOT a member/admin of the channel
        $container = $this->client->getContainer();
        $otherUser = new User();
        $otherUser->setUsername('test_channel_user_2');
        $otherUser->setRoles(['ROLE_USER']);
        $passwordHasher = $container->get('security.user_password_hasher');
        $otherUser->setPassword($passwordHasher->hashPassword($otherUser, 'password123'));
        $this->entityManager->persist($otherUser);
        $this->entityManager->flush();

        // Log in as the other user
        $this->client->loginUser($otherUser);

        // Try to export
        $this->client->request('GET', sprintf('/channels/%s/export', $this->channel->getSlug()));

        $this->assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function testDeleteChannelSuccess(): void
    {
        // 1. Create a channel to delete
        $channelToDelete = new Channel();
        $channelToDelete->setName('Channel to Delete');
        $channelToDelete->setSlug('channel-to-delete');
        $channelToDelete->setCreator($this->testUser);
        $channelToDelete->addMember($this->testUser);
        $this->entityManager->persist($channelToDelete);
        $this->entityManager->flush();

        // 2. Request delete
        $this->client->request('POST', '/channels/channel-to-delete/delete');
        $this->assertResponseRedirects('/');

        $this->entityManager->clear();
        $dbChannel = $this->entityManager->getRepository(Channel::class)->findOneBy(['slug' => 'channel-to-delete']);
        static::assertNull($dbChannel);

        // 3. Assert AuditLog was created
        $auditLog = $this->entityManager->getRepository(AuditLog::class)->findOneBy([
            'action' => 'channel_delete',
            'performedBy' => $this->testUser,
        ]);
        static::assertNotNull($auditLog);
        static::assertSame('channel-to-delete', $auditLog->getDetails()['slug']);

        if ($auditLog) {
            $this->entityManager->remove($auditLog);
            $this->entityManager->flush();
        }
    }
}

