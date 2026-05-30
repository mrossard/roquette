<?php

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use PHPUnit\Framework\Attributes\Test;

class LinkPreviewControllerTest extends WebTestCase
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
        $testUsers = $userRepository->findBy(['username' => ['test_preview_user']]);

        foreach ($testUsers as $user) {
            $this->entityManager->remove($user);
        }
        $this->entityManager->flush();
    }

    #[Test]
    public function testGetPreviewRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/link-preview?url=https://github.com');
        $this->assertResponseRedirects('/login');
    }

    #[Test]
    public function testGetPreviewMissingUrl(): void
    {
        $user = new User();
        $user->setUsername('test_preview_user');
        $user->setRoles(['ROLE_USER']);
        $container = $this->client->getContainer();
        $passwordHasher = $container->get('security.user_password_hasher');
        $user->setPassword($passwordHasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->client->loginUser($user);

        $this->client->request('GET', '/api/link-preview');
        $this->assertResponseStatusCodeSame(400);
        $this->assertJson($this->client->getResponse()->getContent());
    }
}
