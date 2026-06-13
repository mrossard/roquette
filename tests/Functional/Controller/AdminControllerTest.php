<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use App\Entity\User;
use App\Entity\Channel;
use App\Entity\ChannelExport;
use App\Service\FileUploadService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[AllowMockObjectsWithoutExpectations]
class AdminControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private $client;
    private User $adminUser;
    private User $normalUser;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $container = $this->client->getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();

        $this->cleanup();

        $passwordHasher = $container->get('security.user_password_hasher');

        $admin = new User();
        $admin->setUsername('admin_user');
        $admin->setRoles(['ROLE_USER', 'ROLE_ADMIN']);
        $admin->setAdmin(true);
        $admin->setPassword($passwordHasher->hashPassword($admin, 'password123'));
        $this->entityManager->persist($admin);

        $user = new User();
        $user->setUsername('normal_user');
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($passwordHasher->hashPassword($user, 'password123'));
        $this->entityManager->persist($user);

        $this->entityManager->flush();

        $this->adminUser = $admin;
        $this->normalUser = $user;
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $exportRepo = $this->entityManager->getRepository(ChannelExport::class);
        foreach ($exportRepo->findAll() as $export) {
            $this->entityManager->remove($export);
        }

        $channelRepo = $this->entityManager->getRepository(Channel::class);
        foreach ($channelRepo->findAll() as $channel) {
            if ($channel->getSlug() !== 'general' && !str_starts_with($channel->getSlug(), 'dm-')) {
                $this->entityManager->remove($channel);
            }
        }

        $userRepo = $this->entityManager->getRepository(User::class);
        foreach ($userRepo->findAll() as $u) {
            if (in_array($u->getUsername(), ['admin_user', 'normal_user', 'banned_test_user'], true)) {
                $this->entityManager->remove($u);
            }
        }

        $this->entityManager->flush();
    }

    private function mockFileUploadService(bool $exists, string $content = 'file content'): void
    {
        $mock = $this->createMock(FileUploadService::class);
        $mock->method('exists')->willReturn($exists);

        if ($exists) {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $content);
            rewind($stream);
            $mock->method('readStream')->willReturn($stream);
        }

        $this->client->getContainer()->set(FileUploadService::class, $mock);
    }

    #[Test]
    public function testAccessDeniedForNonAdmin(): void
    {
        $this->client->loginUser($this->normalUser);
        $this->client->request('GET', '/admin/users');
        $this->assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin/exports');
        $this->assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function testListUsers(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/admin/users');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Gestion des utilisateurs');
    }

    #[Test]
    public function testBanAndUnbanUser(): void
    {
        $this->client->loginUser($this->adminUser);

        // Ban user
        $this->client->request('POST', sprintf('/admin/users/%d/ban', $this->normalUser->getId()));
        $this->assertResponseRedirects('/admin/users');

        $this->entityManager->clear();
        $updatedUser = $this->entityManager->getRepository(User::class)->find($this->normalUser->getId());
        static::assertNotNull($updatedUser->getBannedAt());

        // Unban user
        $this->client->request('POST', sprintf('/admin/users/%d/unban', $this->normalUser->getId()));
        $this->assertResponseRedirects('/admin/users');

        $this->entityManager->clear();
        $updatedUser = $this->entityManager->getRepository(User::class)->find($this->normalUser->getId());
        static::assertNull($updatedUser->getBannedAt());
    }

    #[Test]
    public function testListExports(): void
    {
        $this->client->loginUser($this->adminUser);

        // Create a test export
        $export = new ChannelExport();
        $export->setFileName('test-export.zip');
        $export->setFilePath('exports/test-export.zip');
        $export->setFileSize(100);
        $export->setChannelName('Test Channel');
        $export->setExportedBy($this->adminUser);
        $this->entityManager->persist($export);
        $this->entityManager->flush();

        $this->client->request('GET', '/admin/exports');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Gestion des exports de canaux');
        $this->assertSelectorTextContains('td', 'Test Channel');
    }

    #[Test]
    public function testDownloadExport(): void
    {
        $this->client->loginUser($this->adminUser);

        $export = new ChannelExport();
        $export->setFileName('test-export.zip');
        $export->setFilePath('exports/test-export-dl.zip');
        $export->setFileSize(12);
        $export->setChannelName('Test Channel');
        $export->setExportedBy($this->adminUser);
        $this->entityManager->persist($export);
        $this->entityManager->flush();

        $this->mockFileUploadService(true, 'zipped_data');

        $this->client->request('GET', sprintf('/admin/exports/%d/download', $export->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/zip');
        static::assertSame('zipped_data', $this->client->getResponse()->getContent());
    }

    #[Test]
    public function testDeleteExport(): void
    {
        $this->client->loginUser($this->adminUser);

        $export = new ChannelExport();
        $export->setFileName('test-export.zip');
        $export->setFilePath('exports/test-export-del.zip');
        $export->setFileSize(12);
        $export->setChannelName('Test Channel');
        $export->setExportedBy($this->adminUser);
        $this->entityManager->persist($export);
        $this->entityManager->flush();

        $exportId = $export->getId();

        $this->mockFileUploadService(true);

        $this->client->request('POST', sprintf('/admin/exports/%d/delete', $exportId));
        $this->assertResponseRedirects('/admin/exports');

        $this->entityManager->clear();
        $dbExport = $this->entityManager->getRepository(ChannelExport::class)->find($exportId);
        static::assertNull($dbExport);
    }
}
