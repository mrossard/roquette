<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Channel;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[AllowMockObjectsWithoutExpectations]
class ApiAutocompleteControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private $client;
    private User $testUser;
    private Channel $testChannel;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $container = $this->client->getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();

        $this->cleanup();

        $user = new User();
        $user->setUsername('test_auto_user');
        $user->setDisplayName('Auto User Display');
        $user->setRoles(['ROLE_USER']);
        $passwordHasher = $container->get('security.user_password_hasher');
        $user->setPassword($passwordHasher->hashPassword($user, 'pass123'));
        $this->entityManager->persist($user);

        $channel = new Channel();
        $channel->setName('Auto Channel');
        $channel->setSlug('auto-channel');
        $channel->setIsPrivate(false);
        $channel->setIsDm(false);
        $this->entityManager->persist($channel);

        $this->entityManager->flush();

        $this->testUser = $user;
        $this->testChannel = $channel;
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
        $conn->executeStatement('DELETE FROM channel WHERE slug LIKE ?', ['auto-%']);
        $conn->executeStatement('DELETE FROM "user" WHERE username LIKE ?', ['test_auto_%']);
    }

    #[Test]
    public function testApiUsersJson(): void
    {
        $this->client->request('GET', '/api/users?q=test_auto');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        static::assertIsArray($data);
        static::assertNotEmpty($data);
        static::assertSame('test_auto_user', $data[0]['username']);
    }

    #[Test]
    public function testApiUsersOptions(): void
    {
        $this->client->request('GET', '/api/users-options');
        $this->assertResponseIsSuccessful();
        static::assertStringContainsString('Auto User Display', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function testApiChannelsJson(): void
    {
        $this->client->request('GET', '/api/channels?q=auto-channel');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        static::assertIsArray($data);
        static::assertNotEmpty($data);
        static::assertSame('auto-channel', $data[0]['slug']);
    }

    #[Test]
    public function testApiAutocompleteUsersHtml(): void
    {
        $this->client->request('GET', '/api/autocomplete/users?q=test_auto');
        $this->assertResponseIsSuccessful();
        static::assertStringContainsString('test_auto_user', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function testApiAutocompleteChannelsHtml(): void
    {
        $this->client->request('GET', '/api/autocomplete/channels?q=auto-channel');
        $this->assertResponseIsSuccessful();
        static::assertStringContainsString('Auto Channel', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function testApiAutocompleteCustomEmojisHtml(): void
    {
        $this->client->request('GET', '/api/autocomplete/custom-emojis?q=smile');
        $this->assertResponseIsSuccessful();
    }
}
