<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use App\Entity\User;
use App\Entity\CustomEmoji;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[AllowMockObjectsWithoutExpectations]
class AdminEmojiControllerTest extends WebTestCase
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
        $this->cleanup();

        if ($container->has('cache.app')) {
            $container->get('cache.app')->delete('emojis_filesystem_list');
        }
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        $adminUser = $userRepository->findOneBy(['username' => 'test_admin_emoji_user']);
        if ($adminUser) {
            $this->entityManager->remove($adminUser);
        }

        $customEmojiRepo = $this->entityManager->getRepository(CustomEmoji::class);
        $testEmoji = $customEmojiRepo->findOneBy(['code' => 'smile']);
        if ($testEmoji) {
            $this->entityManager->remove($testEmoji);
        }

        $this->entityManager->flush();
    }

    private function loginAdmin(): User
    {
        $user = new User();
        $user->setUsername('test_admin_emoji_user');
        $user->setRoles(['ROLE_ADMIN']);
        
        $container = $this->client->getContainer();
        $passwordHasher = $container->get('security.user_password_hasher');
        $user->setPassword($passwordHasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->client->loginUser($user);
        return $user;
    }

    #[Test]
    public function testAdminEmojisAccessAndEdit(): void
    {
        $this->loginAdmin();
        $this->client->disableReboot();

        $container = $this->client->getContainer();
        $mockStorage = $this->createMock(FilesystemOperator::class);
        $mockStorage->expects($this->any())->method('listContents')->with('emojis', true)->willReturn(new \League\Flysystem\DirectoryListing([
            new \League\Flysystem\FileAttributes('emojis/smile.gif', 1024),
        ]));

        $container->set(FilesystemOperator::class, $mockStorage);
        $container->set('default.storage', $mockStorage);

        // 1. Visit admin emojis page
        $crawler = $this->client->request('GET', '/admin/emojis');
        static::assertResponseIsSuccessful();
        static::assertSelectorTextContains('h2', 'Gestion des Émojis Personnalisés');

        // 2. Add tag
        $csrfToken = $crawler->filter('form.emoji-form input[name="_csrf_token"]')->attr('value');
        $this->client->request('POST', '/admin/emojis/add-tag', [
            'code' => 'smile',
            'tag' => 'happy',
            '_csrf_token' => $csrfToken,
        ]);
        static::assertResponseRedirects('/admin/emojis');
        $crawler = $this->client->followRedirect();
        static::assertResponseIsSuccessful();

        // Check if DB updated with the tag
        $customEmoji = $this->entityManager->getRepository(CustomEmoji::class)->findOneBy(['code' => 'smile']);
        static::assertNotNull($customEmoji);
        static::assertContains('happy', $customEmoji->getTags());

        // 3. Test autocomplete searching by tag
        $this->client->request('GET', '/api/autocomplete/custom-emojis?q=happy');
        static::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('smile', $content);

        // 4. Remove tag
        // The remove form on the badge has a CSRF token. We can find it in the HTML
        $csrfTokenRemove = $crawler->filter('form[action*="remove-tag"] input[name="_csrf_token"]')->attr('value');
        $this->client->request('POST', '/admin/emojis/remove-tag', [
            'code' => 'smile',
            'tag' => 'happy',
            '_csrf_token' => $csrfTokenRemove,
        ]);
        static::assertResponseRedirects('/admin/emojis');
        $this->client->followRedirect();
        static::assertResponseIsSuccessful();

        // Check if tag is gone
        $customEmoji = $this->entityManager->getRepository(CustomEmoji::class)->findOneBy(['code' => 'smile']);
        static::assertNotNull($customEmoji);
        static::assertNotContains('happy', $customEmoji->getTags());
    }
}
