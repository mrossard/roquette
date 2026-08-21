<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[AllowMockObjectsWithoutExpectations]
class LinkPreviewControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;

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

    private function createAndLoginUser(): User
    {
        $user = new User();
        $user->setUsername('test_preview_user');
        $user->setRoles(['ROLE_USER']);
        $container = $this->client->getContainer();
        $passwordHasher = $container->get('security.user_password_hasher');
        $user->setPassword($passwordHasher->hashPassword($user, 'my-secure-val-123'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->client->loginUser($user);

        return $user;
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
        $this->createAndLoginUser();

        $this->client->request('GET', '/api/link-preview');
        $this->assertResponseStatusCodeSame(400);
        static::assertJson($this->client->getResponse()->getContent());
    }

    #[Test]
    public function testGetPreviewDirectImageUrl(): void
    {
        $this->createAndLoginUser();

        $this->client->request('GET', '/api/link-preview?url=https://example.com/photo.png');
        $this->assertResponseIsSuccessful();
        static::assertStringContainsString(
            'image-preview-container',
            (string) $this->client->getResponse()->getContent(),
        );
        static::assertStringContainsString('photo.png', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function testGetPreviewInvalidOrUnreachableUrlReturnsEmpty200(): void
    {
        $this->createAndLoginUser();

        // 127.0.0.1 is blocked by UrlSafetyValidator (SSRF protection) so getPreviewDto returns null
        $this->client->request('GET', '/api/link-preview?url=http://127.0.0.1/secret');
        $this->assertResponseIsSuccessful();
        static::assertSame('', $this->client->getResponse()->getContent());
    }
}
