<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\UserChannelRead;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class NotificationControllerTest extends WebTestCase
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

        $user = new User();
        $user->setUsername('test_notif_user');
        $user->setRoles(['ROLE_USER']);

        $passwordHasher = $container->get('security.user_password_hasher');
        $user->setPassword($passwordHasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);

        $channel = new Channel();
        $channel->setName('Test Notif Channel');
        $channel->setSlug('test-notif-channel');
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
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            'DELETE FROM reaction WHERE user_id IN (SELECT id FROM "user" WHERE username IN (?, ?))',
            [
                'test_notif_user',
                'test_notif_other',
            ],
        );
        $conn->executeStatement('DELETE FROM message WHERE channel_id IN (SELECT id FROM channel WHERE slug = ?)', [
            'test-notif-channel',
        ]);
        $conn->executeStatement(
            'DELETE FROM user_channel_read WHERE channel_id IN (SELECT id FROM channel WHERE slug = ?)',
            [
                'test-notif-channel',
            ],
        );
        $conn->executeStatement('DELETE FROM channel WHERE slug = ?', ['test-notif-channel']);
        $conn->executeStatement('DELETE FROM "user" WHERE username IN (?, ?)', ['test_notif_user', 'test_notif_other']);
    }

    // -------------------------------------------------------------------------
    // markAsRead
    // -------------------------------------------------------------------------

    #[Test]
    public function testMarkAsReadReturns204(): void
    {
        $this->client->request('POST', '/channels/test-notif-channel/read');

        $this->assertResponseStatusCodeSame(204);
    }

    #[Test]
    public function testMarkAsReadChannelNotFound(): void
    {
        $this->client->request('POST', '/channels/non-existent-channel/read');

        $this->assertResponseStatusCodeSame(404);
    }

    // -------------------------------------------------------------------------
    // search
    // -------------------------------------------------------------------------

    #[Test]
    public function testSearchEmptyQueryReturnsFeed(): void
    {
        $this->client->request('GET', '/channels/test-notif-channel/search');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('Aucun message reçu', $content);
    }

    #[Test]
    public function testSearchWithMessages(): void
    {
        $message = new Message();
        $message->setChannel($this->channel);
        $message->setAuthor($this->testUser);
        $message->setContent('Hello World');
        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $this->client->request('GET', '/channels/test-notif-channel/search');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('Hello World', $content);
    }

    #[Test]
    public function testSearchWithQueryReturnsResults(): void
    {
        $message = new Message();
        $message->setChannel($this->channel);
        $message->setAuthor($this->testUser);
        $message->setContent('Unique search term foo bar baz');
        $this->entityManager->persist($message);

        $other = new Message();
        $other->setChannel($this->channel);
        $other->setAuthor($this->testUser);
        $other->setContent('Something else entirely');
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        $this->client->request('GET', '/channels/test-notif-channel/search?q=Unique');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('Unique search term', $content);
        static::assertStringNotContainsString('Something else', $content);
    }

    #[Test]
    public function testSearchWithNoResults(): void
    {
        $this->client->request('GET', '/channels/test-notif-channel/search?q=zzzznonexistent');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('Aucun résultat', $content);
    }

    #[Test]
    public function testSearchUnreadReturnsFeed(): void
    {
        $message = new Message();
        $message->setChannel($this->channel);
        $message->setAuthor($this->testUser);
        $message->setContent('Unread message');
        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $this->client->request('GET', '/channels/test-notif-channel/search?unread=1');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('messages non lus', $content);
    }

    #[Test]
    public function testSearchUnreadWithQuery(): void
    {
        $otherUser = $this->createOtherUser();
        $message = new Message();
        $message->setChannel($this->channel);
        $message->setAuthor($otherUser);
        $message->setContent('Unread searchable content');
        $this->entityManager->persist($message);

        $other = new Message();
        $other->setChannel($this->channel);
        $other->setAuthor($otherUser);
        $other->setContent('Not matching');
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        $this->client->request('GET', '/channels/test-notif-channel/search?unread=1&q=searchable');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('Unread searchable', $content);
        static::assertStringNotContainsString('Not matching', $content);
    }

    // -------------------------------------------------------------------------
    // toggleNotifications
    // -------------------------------------------------------------------------

    #[Test]
    public function testToggleNotificationsOn(): void
    {
        $this->client->request('POST', '/channels/test-notif-channel/toggle-notifications');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('🔔', $content);
    }

    #[Test]
    public function testToggleNotificationsOff(): void
    {
        // First toggle on
        $this->client->request('POST', '/channels/test-notif-channel/toggle-notifications');

        // Second toggle off
        $this->client->request('POST', '/channels/test-notif-channel/toggle-notifications');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('🔕', $content);
    }

    #[Test]
    public function testToggleNotificationsChannelNotFound(): void
    {
        $this->client->request('POST', '/channels/non-existent/toggle-notifications');

        $this->assertResponseStatusCodeSame(404);
    }

    // -------------------------------------------------------------------------
    // typing
    // -------------------------------------------------------------------------

    private function createOtherUser(): User
    {
        $container = $this->client->getContainer();
        $otherUser = new User();
        $otherUser->setUsername('test_notif_other');
        $otherUser->setRoles(['ROLE_USER']);
        $passwordHasher = $container->get('security.user_password_hasher');
        $otherUser->setPassword($passwordHasher->hashPassword($otherUser, 'password123'));
        $this->entityManager->persist($otherUser);

        // Give the other user access to the channel
        $this->channel->addMember($otherUser);
        $this->entityManager->flush();

        return $otherUser;
    }

    #[Test]
    public function testTypingStart(): void
    {
        $otherUser = $this->createOtherUser();

        // Log in as the other user to type
        $this->client->loginUser($otherUser);
        $this->client->request('POST', '/channel/test-notif-channel/typing', [
            'isTyping' => '1',
        ]);

        // The typing user's own indicator excludes them, but the response is successful
        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function testTypingStop(): void
    {
        $otherUser = $this->createOtherUser();

        $this->client->loginUser($otherUser);
        $this->client->request('POST', '/channel/test-notif-channel/typing', [
            'isTyping' => '1',
        ]);

        $this->client->request('POST', '/channel/test-notif-channel/typing', [
            'isTyping' => '0',
        ]);

        $this->assertResponseIsSuccessful();
        // Indicator should not show anyone typing when the typing user has stopped
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('style="visibility: hidden;"', $content);
    }

    #[Test]
    public function testTypingChannelNotFound(): void
    {
        $this->client->request('POST', '/channel/non-existent/typing', [
            'isTyping' => '1',
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function testTypingIndicatorReturnsEmptyWhenNoTyping(): void
    {
        $this->client->request('GET', '/channel/test-notif-channel/typing-indicator');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('typing-indicator', $content);
    }

    #[Test]
    public function testTypingIndicatorShowsTypingUser(): void
    {
        $otherUser = $this->createOtherUser();

        // Log in as other user to type
        $this->client->loginUser($otherUser);
        $this->client->request('POST', '/channel/test-notif-channel/typing', [
            'isTyping' => '1',
        ]);

        // Log back in as test user to check the indicator
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/channel/test-notif-channel/typing-indicator');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('test_notif_other', $content);
        static::assertStringContainsString('est en train d\'écrire', $content);
    }

    #[Test]
    public function testTypingViaJsonContentType(): void
    {
        $otherUser = $this->createOtherUser();

        $this->client->loginUser($otherUser);
        $this->client->request(
            'POST',
            '/channel/test-notif-channel/typing',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['isTyping' => true]),
        );

        // The typing user's own indicator excludes them, but the response is successful
        $this->assertResponseIsSuccessful();
    }

    // -------------------------------------------------------------------------
    // globalSearch
    // -------------------------------------------------------------------------

    #[Test]
    public function testGlobalSearchEmptyQuery(): void
    {
        $this->client->request('GET', '/search');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('Astuces pour affiner', $content);
    }

    #[Test]
    public function testGlobalSearchWithQuery(): void
    {
        $message = new Message();
        $message->setChannel($this->channel);
        $message->setAuthor($this->testUser);
        $message->setContent('Global search test message');
        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $this->client->request('GET', '/search?q=Global');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('Global search test', $content);
    }

    #[Test]
    public function testGlobalSearchWithFromFilter(): void
    {
        $message = new Message();
        $message->setChannel($this->channel);
        $message->setAuthor($this->testUser);
        $message->setContent('Message from specific user');
        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $this->client->request('GET', '/search?q=from:test_notif_user Message');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('Message from specific', $content);
    }

    #[Test]
    public function testGlobalSearchWithInFilter(): void
    {
        $message = new Message();
        $message->setChannel($this->channel);
        $message->setAuthor($this->testUser);
        $message->setContent('Message in channel');
        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $this->client->request('GET', '/search?q=in:test-notif-channel Message');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('Message in channel', $content);
    }

    #[Test]
    public function testGlobalSearchNoResults(): void
    {
        $this->client->request('GET', '/search?q=xyznonexistent12345');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('Aucun résultat trouvé', $content);
    }
}
