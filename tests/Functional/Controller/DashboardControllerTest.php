<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;


use App\Entity\Channel;
use App\Entity\Invitation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[AllowMockObjectsWithoutExpectations]
class DashboardControllerTest extends WebTestCase
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
        $user->setUsername('test_dash_user');
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
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement('DELETE FROM invitation WHERE invitee_id IN (SELECT id FROM "user" WHERE username LIKE ?)', [
            'test_dash_%',
        ]);
        $conn->executeStatement('DELETE FROM user_channel_read WHERE user_id IN (SELECT id FROM "user" WHERE username LIKE ?)', [
            'test_dash_%',
        ]);
        $conn->executeStatement('DELETE FROM message WHERE channel_id IN (SELECT id FROM channel WHERE slug LIKE ? OR slug = ?)', [
            'test-dash-%',
            'general',
        ]);
        $conn->executeStatement('DELETE FROM channel WHERE slug LIKE ? OR slug = ?', ['test-dash-%', 'general']);
        $conn->executeStatement('DELETE FROM "user" WHERE username LIKE ?', ['test_dash_%']);
    }

    private function createChannel(string $slug, string $name, bool $public = true): Channel
    {
        $channel = new Channel();
        $channel->setName($name);
        $channel->setSlug($slug);
        $channel->setCreator($this->testUser);
        $channel->addMember($this->testUser);
        if (!$public) {
            $channel->setIsPrivate(true);
        }
        $this->entityManager->persist($channel);
        $this->entityManager->flush();

        return $channel;
    }

    // -------------------------------------------------------------------------
    // index
    // -------------------------------------------------------------------------

    #[Test]
    public function testIndexRedirectsToGeneral(): void
    {
        $this->createChannel('test-dash-other', 'Other Channel');
        $this->createChannel('test-dash-general', 'General');

        // Manually update slug to 'general' (otherwise cleanup would delete it)
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement("UPDATE channel SET slug = 'general' WHERE slug = 'test-dash-general'");
        $this->entityManager->clear();

        $this->client->request('GET', '/');

        $this->assertResponseRedirects('/channels/general');
    }

    // Note: findAllForUser() auto-creates a 'general' channel, so scenarios
    // without a general channel or without any channels are impossible here.

    // -------------------------------------------------------------------------
    // directory
    // -------------------------------------------------------------------------

    #[Test]
    public function testDirectoryRenders(): void
    {
        $this->createChannel('test-dash-channel', 'Channel For Directory');

        $this->client->request('GET', '/channels/directory');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('Channel For Directory', $content);
    }

    #[Test]
    public function testDirectoryShowsPublicChannels(): void
    {
        $this->createChannel('test-dash-public', 'Public Channel');
        $this->createChannel('test-dash-private', 'Private Channel', false);

        $this->client->request('GET', '/channels/directory');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('Public Channel', $content);
    }

    #[Test]
    public function testDirectoryPanelShowsUsers(): void
    {
        $container = $this->client->getContainer();
        $otherUser = new User();
        $otherUser->setUsername('test_dash_other');
        $otherUser->setRoles(['ROLE_USER']);
        $passwordHasher = $container->get('security.user_password_hasher');
        $otherUser->setPassword($passwordHasher->hashPassword($otherUser, 'password123'));
        $this->entityManager->persist($otherUser);
        $this->entityManager->flush();

        $this->client->request('GET', '/channels/directory/panel/members');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('test_dash_other', $content);
        static::assertStringNotContainsString('Robot', $content);
    }

    #[Test]
    public function testDirectoryShowsPendingInvitations(): void
    {
        $channel = $this->createChannel('test-dash-invite', 'Invite Channel');

        $invitation = new Invitation();
        $invitation->setChannel($channel);
        $invitation->setInvitee($this->testUser);
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        $this->client->request('GET', '/channels/directory');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('Invite Channel', $content);
    }

    // -------------------------------------------------------------------------
    // directoryPanel
    // -------------------------------------------------------------------------

    #[Test]
    public function testDirectoryPanelChannels(): void
    {
        $this->client->request('GET', '/channels/directory/panel/channels');

        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function testDirectoryPanelMembers(): void
    {
        $this->client->request('GET', '/channels/directory/panel/members');

        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function testDirectoryPanelInvalidType(): void
    {
        $this->client->request('GET', '/channels/directory/panel/invalid');

        $this->assertResponseIsSuccessful();
    }
}
