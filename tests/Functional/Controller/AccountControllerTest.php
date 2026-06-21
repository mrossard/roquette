<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[AllowMockObjectsWithoutExpectations]
class AccountControllerTest extends WebTestCase
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
        $user->setUsername('test_acc_user');
        $user->setRoles(['ROLE_USER']);
        $passwordHasher = $container->get('security.user_password_hasher');
        $password = $passwordHasher->hashPassword($user, 'currentPass1');
        $user->setPassword($password);
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
        $conn->executeStatement('DELETE FROM messenger_messages WHERE body LIKE ?', ['%test_acc_user%']);
        $conn->executeStatement('DELETE FROM "user" WHERE username LIKE ?', ['test_acc_%']);
    }

    // -------------------------------------------------------------------------
    // GET
    // -------------------------------------------------------------------------

    #[Test]
    public function testGetAccountPageRenders(): void
    {
        $this->client->request('GET', '/account');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('Mon Compte', $content);
        static::assertStringContainsString('test_acc_user', $content);
    }

    // -------------------------------------------------------------------------
    // Profile update
    // -------------------------------------------------------------------------

    #[Test]
    public function testPostProfileUpdatesDisplayName(): void
    {
        $this->client->request('POST', '/account', [
            'action' => 'profile',
            'displayName' => 'New Display Name',
            'hue' => '180',
            'statusOverride' => 'away',
            'locale' => 'en',
        ]);

        $this->assertResponseRedirects('/account');

        $this->entityManager->clear();
        $updatedUser = $this->entityManager->find(User::class, $this->testUser->getId());
        static::assertSame('New Display Name', $updatedUser->getDisplayName());
        static::assertSame(180, $updatedUser->getCustomHue());
        static::assertSame('away', $updatedUser->getStatusOverride());
        static::assertSame('en', $updatedUser->getLocale());
        static::assertSame('en', $this->client->getRequest()->getSession()->get('_locale'));
    }

    #[Test]
    public function testPostProfileClearsDisplayName(): void
    {
        $this->testUser->setDisplayName('Old Name');
        $this->entityManager->flush();

        $this->client->request('POST', '/account', [
            'action' => 'profile',
            'displayName' => '',
        ]);

        $this->assertResponseRedirects('/account');

        $this->entityManager->clear();
        $updatedUser = $this->entityManager->find(User::class, $this->testUser->getId());
        static::assertNull($updatedUser->getDisplayName());
    }

    #[Test]
    public function testPostProfileWithInvalidHue(): void
    {
        $this->client->request('POST', '/account', [
            'action' => 'profile',
            'hue' => '999',
        ]);

        $this->assertResponseRedirects('/account');

        $this->entityManager->clear();
        $updatedUser = $this->entityManager->find(User::class, $this->testUser->getId());
        static::assertNull($updatedUser->getCustomHue());
    }

    #[Test]
    public function testPostProfileWithInvalidStatusOverride(): void
    {
        $this->client->request('POST', '/account', [
            'action' => 'profile',
            'statusOverride' => 'invalid_status',
        ]);

        $this->assertResponseRedirects('/account');

        $this->entityManager->clear();
        $updatedUser = $this->entityManager->find(User::class, $this->testUser->getId());
        static::assertNull($updatedUser->getStatusOverride());
    }

    #[Test]
    public function testPostProfileWithAutoStatus(): void
    {
        $this->client->request('POST', '/account', [
            'action' => 'profile',
            'statusOverride' => 'auto',
        ]);

        $this->assertResponseRedirects('/account');

        $this->entityManager->clear();
        $updatedUser = $this->entityManager->find(User::class, $this->testUser->getId());
        static::assertNull($updatedUser->getStatusOverride());
    }

    #[Test]
    public function testPostProfileWithInvalidLocale(): void
    {
        $this->client->request('POST', '/account', [
            'action' => 'profile',
            'locale' => 'de',
        ]);

        $this->assertResponseRedirects('/account');

        $this->entityManager->clear();
        $updatedUser = $this->entityManager->find(User::class, $this->testUser->getId());
        static::assertSame('fr', $updatedUser->getLocale());
    }

    #[Test]
    public function testPostProfileWithLocaleFr(): void
    {
        $this->client->request('POST', '/account', [
            'action' => 'profile',
            'locale' => 'fr',
        ]);

        $this->assertResponseRedirects('/account');

        $this->entityManager->clear();
        $updatedUser = $this->entityManager->find(User::class, $this->testUser->getId());
        static::assertSame('fr', $updatedUser->getLocale());
    }

    // -------------------------------------------------------------------------
    // Notification preferences
    // -------------------------------------------------------------------------

    #[Test]
    public function testPostNotificationsEnabled(): void
    {
        $this->client->request('POST', '/account', [
            'action' => 'notifications',
            'mentionNotificationsEnabled' => '1',
        ]);

        $this->assertResponseRedirects('/account');

        $this->entityManager->clear();
        $updatedUser = $this->entityManager->find(User::class, $this->testUser->getId());
        static::assertTrue($updatedUser->isMentionNotificationsEnabled());
    }

    #[Test]
    public function testPostNotificationsDisabled(): void
    {
        $this->testUser->setMentionNotificationsEnabled(true);
        $this->entityManager->flush();

        $this->client->request('POST', '/account', [
            'action' => 'notifications',
            'mentionNotificationsEnabled' => '0',
        ]);

        $this->assertResponseRedirects('/account');

        $this->entityManager->clear();
        $updatedUser = $this->entityManager->find(User::class, $this->testUser->getId());
        static::assertFalse($updatedUser->isMentionNotificationsEnabled());
    }

    // -------------------------------------------------------------------------
    // Password change
    // -------------------------------------------------------------------------

    #[Test]
    public function testPostPasswordSuccess(): void
    {
        $this->client->request('POST', '/account', [
            'action' => 'password',
            'currentPassword' => 'currentPass1',
            'newPassword' => 'newPass123',
            'confirmPassword' => 'newPass123',
        ]);

        $this->assertResponseRedirects('/account');

        $this->client->followRedirect();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('mot de passe a été modifié', $content);

        $this->entityManager->clear();
        $updatedUser = $this->entityManager->find(User::class, $this->testUser->getId());

        $passwordHasher = $this->client->getContainer()->get('security.user_password_hasher');
        static::assertTrue($passwordHasher->isPasswordValid($updatedUser, 'newPass123'));
    }

    #[Test]
    public function testPostPasswordWrongCurrent(): void
    {
        $this->client->request('POST', '/account', [
            'action' => 'password',
            'currentPassword' => 'wrongPassword',
            'newPassword' => 'newPass123',
            'confirmPassword' => 'newPass123',
        ]);

        $this->assertResponseRedirects('/account');

        $this->client->followRedirect();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('mot de passe actuel est incorrect', $content);
    }

    #[Test]
    public function testPostPasswordMismatch(): void
    {
        $this->client->request('POST', '/account', [
            'action' => 'password',
            'currentPassword' => 'currentPass1',
            'newPassword' => 'newPass123',
            'confirmPassword' => 'differentPass',
        ]);

        $this->assertResponseRedirects('/account');

        $this->client->followRedirect();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('ne correspondent pas', $content);
    }

    #[Test]
    public function testPostPasswordTooShort(): void
    {
        $this->client->request('POST', '/account', [
            'action' => 'password',
            'currentPassword' => 'currentPass1',
            'newPassword' => '12345',
            'confirmPassword' => '12345',
        ]);

        $this->assertResponseRedirects('/account');

        $this->client->followRedirect();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('au moins 6 caractères', $content);
    }

    #[Test]
    public function testPostPasswordEmptyFields(): void
    {
        $this->client->request('POST', '/account', [
            'action' => 'password',
            'currentPassword' => '',
            'newPassword' => '',
            'confirmPassword' => '',
        ]);

        $this->assertResponseRedirects('/account');

        $this->client->followRedirect();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('obligatoires', $content);
    }

    // -------------------------------------------------------------------------
    // Unknown action
    // -------------------------------------------------------------------------

    #[Test]
    public function testPostUnknownAction(): void
    {
        $this->client->request('POST', '/account', [
            'action' => 'unknown_action',
        ]);

        $this->assertResponseRedirects('/account');
    }
}
