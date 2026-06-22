<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[AllowMockObjectsWithoutExpectations]
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

        if ($container->has('cache.app')) {
            $container->get('cache.app')->delete('emojis_filesystem_list');
        }
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

        $emojiRepo = $this->entityManager->getRepository(\App\Entity\CustomEmoji::class);
        foreach ($emojiRepo->findBy(['code' => ['smile', 'sad', 'invalid']]) as $emoji) {
            $this->entityManager->remove($emoji);
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
        $mockStorage->method('has')->willReturn(false);

        // Replace Flysystem service in test container
        $container->set(FilesystemOperator::class, $mockStorage);
        $container->set('default.storage', $mockStorage);

        // When requesting a missing emoji, it should dispatch the download message
        // and redirect the client to the remote CDN (defined by EMOJI_BASE_URL)
        $this->client->request('GET', '/emojis/smile.gif');

        $this->assertResponseRedirects('http://127.0.0.1:8000/uploads/emojis/smile.gif');
    }

    #[Test]
    public function testAutocompleteCustomEmojis(): void
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
        $this->client->disableReboot();

        // Create custom emojis in database to align with database-driven custom emoji list
        $smileEmoji = new \App\Entity\CustomEmoji();
        $smileEmoji->setCode('smile');
        $smileEmoji->setFilename('smile.gif');
        $this->entityManager->persist($smileEmoji);

        $sadEmoji = new \App\Entity\CustomEmoji();
        $sadEmoji->setCode('sad');
        $sadEmoji->setFilename('sad.gif');
        $this->entityManager->persist($sadEmoji);

        $this->entityManager->flush();

        $container = $this->client->getContainer();

        // Mock Flysystem listing
        $mockStorage = $this->createMock(FilesystemOperator::class);
        $mockStorage
            ->method('listContents')
            ->willReturn(new \League\Flysystem\DirectoryListing([
                new \League\Flysystem\FileAttributes('emojis/smile.gif', 1024),
                new \League\Flysystem\FileAttributes('emojis/sad.gif', 2048),
                new \League\Flysystem\FileAttributes('emojis/invalid.gif', 0), // empty file / negative cache
            ]));

        $container->set(FilesystemOperator::class, $mockStorage);
        $container->set('default.storage', $mockStorage);

        // Request with query matching "sm"
        $this->client->request('GET', '/api/autocomplete/custom-emojis?q=sm');
        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('smile', $content);
        $this->assertStringNotContainsString('sad', $content);
        $this->assertStringNotContainsString('invalid', $content);

        // Request with empty query
        $this->client->request('GET', '/api/autocomplete/custom-emojis');
        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('smile', $content);
        $this->assertStringContainsString('sad', $content);
        $this->assertStringNotContainsString('invalid', $content);
    }
}
