<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class EmojiControllerTest extends WebTestCase
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
        $testUsers = $userRepository->findBy(['username' => ['test_emoji_user']]);

        foreach ($testUsers as $user) {
            $this->entityManager->remove($user);
        }
        $this->entityManager->flush();
    }

    #[Test]
    public function testServeEmojiRedirectsToCdn(): void
    {
        $user = new User();
        $user->setUsername('test_emoji_user');
        $user->setRoles(['ROLE_USER']);
        $container = $this->client->getContainer();
        $passwordHasher = $container->get('security.user_password_hasher');
        $user->setPassword($passwordHasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->client->loginUser($user);

        // Mock Flysystem to simulate missing file
        $mockStorage = $this->createMock(FilesystemOperator::class);
        $mockStorage->method('has')->with('emojis/smile.gif')->willReturn(false);

        // Replace Flysystem service in test container
        $container->set(FilesystemOperator::class, $mockStorage);
        $container->set('default.storage', $mockStorage);

        // When requesting a missing emoji, it should dispatch the download message
        // and redirect the client to the remote CDN (defined by EMOJI_BASE_URL)
        $this->client->request('GET', '/emojis/smile.gif');

        $this->assertResponseRedirects('http://127.0.0.1:8000/uploads/emojis/smile.gif');
    }
}
