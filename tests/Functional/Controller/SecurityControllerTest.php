<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[AllowMockObjectsWithoutExpectations]
class SecurityControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private $client;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $container = $this->client->getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();

        // Nettoyer les utilisateurs de test avant chaque test
        $this->cleanupUsers();
    }

    protected function tearDown(): void
    {
        $this->cleanupUsers();
        parent::tearDown();
    }

    private function cleanupUsers(): void
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        $testUsers = $userRepository->findBy(['username' => [
            'test_user_functional',
            'test_user_login',
            'turlututu',
            'Turlututu',
        ]]);

        foreach ($testUsers as $user) {
            $this->entityManager->remove($user);
        }
        $this->entityManager->flush();
    }

    #[Test]
    public function testLoginPageIsRendered(): void
    {
        $this->client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Connexion');
    }

    #[Test]
    public function testRegistrationPageIsRendered(): void
    {
        $this->client->request('GET', '/register');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Inscription');
    }

    #[Test]
    public function testSuccessfulRegistration(): void
    {
        $crawler = $this->client->request('GET', '/register');

        $form = $crawler
            ->selectButton('Créer le compte')
            ->form([
                'registration_form[username]' => 'test_user_functional',
                'registration_form[email]' => 'test@example.com',
                'registration_form[plainPassword]' => 'password123',
            ]);

        $this->client->submit($form);

        // Une fois inscrit, on doit être redirigé vers l'écran de connexion
        $this->assertResponseRedirects('/login');

        $this->client->followRedirect();
        $this->assertSelectorTextContains('div.flash-success', 'Votre compte a été créé avec succès');
    }

    #[Test]
    public function testRegisterClashingSlugFails(): void
    {
        $crawler = $this->client->request('GET', '/register');

        $form = $crawler
            ->selectButton('Créer le compte')
            ->form([
                'registration_form[username]' => 'turlututu',
                'registration_form[email]' => 'test1@example.com',
                'registration_form[plainPassword]' => 'password123',
            ]);

        $this->client->submit($form);
        $this->assertResponseRedirects('/login');

        // Re-create client to isolate session
        self::ensureKernelShutdown();
        $this->client = self::createClient();

        $crawler = $this->client->request('GET', '/register');

        $form = $crawler
            ->selectButton('Créer le compte')
            ->form([
                'registration_form[username]' => 'Turlututu',
                'registration_form[email]' => 'test2@example.com',
                'registration_form[plainPassword]' => 'password123',
            ]);

        $this->client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('div.error-alert', 'Ce nom d\'utilisateur est déjà pris.');
    }

    #[Test]
    public function testRegisterAsRobotUserFails(): void
    {
        $crawler = $this->client->request('GET', '/register');

        $form = $crawler
            ->selectButton('Créer le compte')
            ->form([
                'registration_form[username]' => 'robot-roquette',
                'registration_form[email]' => 'robot@example.com',
                'registration_form[plainPassword]' => 'password123',
            ]);

        $this->client->submit($form);

        // Should not redirect, should render the form with validation error
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('div.error-alert', 'Ce nom d\'utilisateur est réservé par le système.');
    }

    #[Test]
    public function testRegisterAsRobotUserCaseInsensitiveFails(): void
    {
        $crawler = $this->client->request('GET', '/register');

        $form = $crawler
            ->selectButton('Créer le compte')
            ->form([
                'registration_form[username]' => 'Robot-Roquette',
                'registration_form[email]' => 'robot@example.com',
                'registration_form[plainPassword]' => 'password123',
            ]);

        $this->client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('div.error-alert', 'Ce nom d\'utilisateur est réservé par le système.');
    }

    #[Test]
    public function testLoginAsRobotUserBlocked(): void
    {
        $crawler = $this->client->request('GET', '/login');

        $form = $crawler
            ->selectButton('Se connecter')
            ->form([
                '_username' => 'robot-roquette',
                '_password' => 'some_password',
            ]);

        $this->client->submit($form);

        $this->assertResponseRedirects('/login');

        $this->client->followRedirect();
        $this->assertSelectorTextContains('div.error-alert', 'Connexion impossible avec un compte système.');
    }

    #[Test]
    public function testLoginFailure(): void
    {
        $crawler = $this->client->request('GET', '/login');

        $pwd = 'wrong-cred-99';
        $form = $crawler
            ->selectButton('Se connecter')
            ->form([
                '_username' => 'non_existent_user',
                '_password' => $pwd,
            ]);

        $this->client->submit($form);

        // Redirection vers /login après échec
        $this->assertResponseRedirects('/login');

        $this->client->followRedirect();
        $this->assertSelectorTextContains('div.error-alert', 'Identifiants invalides.');
    }

    #[Test]
    public function testSuccessfulLogin(): void
    {
        // Créer d'abord un utilisateur dans la base de données
        $user = new User();
        $user->setUsername('test_user_login');
        $user->setRoles(['ROLE_USER']);

        // Hacher le mot de passe
        $container = $this->client->getContainer();
        $passwordHasher = $container->get('security.user_password_hasher');
        $pwd = 'my-secure-val-123';
        $user->setPassword($passwordHasher->hashPassword($user, $pwd));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/login');

        $form = $crawler
            ->selectButton('Se connecter')
            ->form([
                '_username' => 'test_user_login',
                '_password' => $pwd,
            ]);

        $this->client->submit($form);

        // Redirection vers le tableau de bord (ou la directory si aucun canal)
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testFormAuthenticationDisabled(): void
    {
        self::ensureKernelShutdown();
        $_ENV['AUTH_FORM_ENABLED'] = 'false';
        $_ENV['AUTH_OAUTH_ENABLED'] = 'true';
        putenv('AUTH_FORM_ENABLED=false');
        putenv('AUTH_OAUTH_ENABLED=true');

        $client = self::createClient();
        $client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('form.input-form');
        $this->assertSelectorNotExists('a[href="/register"]');
        $this->assertSelectorExists('a[href="/oauth/connect"]');

        // Try accessing register
        $client->request('GET', '/register');
        $this->assertResponseRedirects('/login');

        // Clean up
        unset($_ENV['AUTH_FORM_ENABLED']);
        unset($_ENV['AUTH_OAUTH_ENABLED']);
        putenv('AUTH_FORM_ENABLED');
        putenv('AUTH_OAUTH_ENABLED');
    }

    #[Test]
    public function testOauthAuthenticationDisabled(): void
    {
        self::ensureKernelShutdown();
        $_ENV['AUTH_FORM_ENABLED'] = 'true';
        $_ENV['AUTH_OAUTH_ENABLED'] = 'false';
        putenv('AUTH_FORM_ENABLED=true');
        putenv('AUTH_OAUTH_ENABLED=false');

        $client = self::createClient();
        $client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form.input-form');
        $this->assertSelectorNotExists('a[href="/oauth/connect"]');

        // Try accessing oauth connect
        $client->request('GET', '/oauth/connect');
        $this->assertResponseRedirects('/login');

        // Clean up
        unset($_ENV['AUTH_FORM_ENABLED']);
        unset($_ENV['AUTH_OAUTH_ENABLED']);
        putenv('AUTH_FORM_ENABLED');
        putenv('AUTH_OAUTH_ENABLED');
    }
}
