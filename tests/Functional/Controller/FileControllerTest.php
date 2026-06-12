<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Service\FileUploadService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class FileControllerTest extends WebTestCase
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
        $user->setUsername('test_file_user');
        $user->setRoles(['ROLE_USER']);

        $passwordHasher = $container->get('security.user_password_hasher');
        $user->setPassword($passwordHasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);

        $channel = new Channel();
        $channel->setName('Test File Channel');
        $channel->setSlug('test-file-channel');
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
        $conn->executeStatement('DELETE FROM message WHERE channel_id IN (SELECT id FROM channel WHERE slug LIKE ?)', [
            'test-file-%',
        ]);
        $conn->executeStatement('DELETE FROM channel WHERE slug LIKE ?', ['test-file-%']);
        $conn->executeStatement('DELETE FROM "user" WHERE username IN (?, ?)', ['test_file_user', 'test_file_other']);
    }

    private function createMessageWithFile(): Message
    {
        $message = new Message();
        $message->setChannel($this->channel);
        $message->setAuthor($this->testUser);
        $message->setContent('File attached');
        $message->setFileName('document.pdf');
        $message->setFilePath('uploads/document-abc123.pdf');
        $message->setFileSize(1024);
        $message->setMimeType('application/pdf');
        $this->entityManager->persist($message);
        $this->entityManager->flush();

        return $message;
    }

    private function createOtherUser(): User
    {
        $container = $this->client->getContainer();
        $other = new User();
        $other->setUsername('test_file_other');
        $other->setRoles(['ROLE_USER']);
        $passwordHasher = $container->get('security.user_password_hasher');
        $other->setPassword($passwordHasher->hashPassword($other, 'password123'));
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        return $other;
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

    // -------------------------------------------------------------------------
    // downloadFile
    // -------------------------------------------------------------------------

    #[Test]
    public function testDownloadSuccess(): void
    {
        $message = $this->createMessageWithFile();
        $this->mockFileUploadService(true, 'fake pdf content');

        $this->client->request('GET', sprintf('/messages/%d/download', $message->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/pdf');
        $this->assertResponseHeaderSame('Content-Disposition', 'attachment; filename=document.pdf');
    }

    #[Test]
    public function testDownloadNonAsciiFilename(): void
    {
        $message = new Message();
        $message->setChannel($this->channel);
        $message->setAuthor($this->testUser);
        $message->setContent('File attached');
        $message->setFileName('décument_étonnant.pdf');
        $message->setFilePath('uploads/document-abc123.pdf');
        $message->setFileSize(1024);
        $message->setMimeType('application/pdf');
        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $this->mockFileUploadService(true, 'fake pdf content');

        $this->client->request('GET', sprintf('/messages/%d/download', $message->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/pdf');

        $disposition = $this->client->getResponse()->headers->get('Content-Disposition');
        static::assertStringContainsString('attachment', $disposition);
        static::assertStringContainsString("filename*=utf-8''d%C3%A9cument_%C3%A9tonnant.pdf", $disposition);
        // Expecting transliteration to make it decument_etonnant.pdf
        static::assertStringContainsString('filename=decument_etonnant.pdf', $disposition);
    }

    #[Test]
    public function testDownloadMessageNotFound(): void
    {
        $this->client->request('GET', '/messages/999999/download');

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function testDownloadNoFilePath(): void
    {
        $message = new Message();
        $message->setChannel($this->channel);
        $message->setAuthor($this->testUser);
        $message->setContent('No file');
        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $this->client->request('GET', sprintf('/messages/%d/download', $message->getId()));

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function testDownloadFileNotInStorage(): void
    {
        $message = $this->createMessageWithFile();
        $this->mockFileUploadService(false);

        $this->client->request('GET', sprintf('/messages/%d/download', $message->getId()));

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function testDownloadAccessDeniedOnPrivateChannel(): void
    {
        $privateChannel = new Channel();
        $privateChannel->setName('Private Channel');
        $privateChannel->setSlug('test-file-private');
        $privateChannel->setCreator($this->testUser);
        $privateChannel->addMember($this->testUser);
        $privateChannel->setIsPrivate(true);
        $this->entityManager->persist($privateChannel);

        $message = new Message();
        $message->setChannel($privateChannel);
        $message->setAuthor($this->testUser);
        $message->setContent('Private file');
        $message->setFileName('secret.txt');
        $message->setFilePath('uploads/secret.txt');
        $message->setMimeType('text/plain');
        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $otherUser = $this->createOtherUser();
        $this->client->loginUser($otherUser);

        $this->client->request('GET', sprintf('/messages/%d/download', $message->getId()));

        $this->assertResponseStatusCodeSame(403);
    }

    // -------------------------------------------------------------------------
    // previewFile
    // -------------------------------------------------------------------------

    #[Test]
    public function testPreviewSuccess(): void
    {
        $message = $this->createMessageWithFile();
        $this->mockFileUploadService(true, 'image data');

        $this->client->request('GET', sprintf('/messages/%d/preview', $message->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/pdf');
        // Inline disposition (not attachment)
        static::assertStringContainsString('inline', $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    #[Test]
    public function testPreviewMessageNotFound(): void
    {
        $this->client->request('GET', '/messages/999999/preview');

        $this->assertResponseStatusCodeSame(404);
    }

    // -------------------------------------------------------------------------
    // textPreview
    // -------------------------------------------------------------------------

    #[Test]
    public function testTextPreviewSuccess(): void
    {
        $message = $this->createMessageWithFile();
        $this->mockFileUploadService(true, 'Hello this is a text file');

        $this->client->request('GET', sprintf('/messages/%d/text-preview', $message->getId()));

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('Hello this is a text file', $content);
    }

    #[Test]
    public function testTextPreviewTruncated(): void
    {
        $message = $this->createMessageWithFile();
        // Content longer than 10000 chars
        $longContent = str_repeat('A', 10_001);
        $this->mockFileUploadService(true, $longContent);

        $this->client->request('GET', sprintf('/messages/%d/text-preview', $message->getId()));

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('Contenu tronqué', $content);
    }

    #[Test]
    public function testTextPreviewMessageNotFound(): void
    {
        $this->client->request('GET', '/messages/999999/text-preview');

        $this->assertResponseStatusCodeSame(404);
    }

    // -------------------------------------------------------------------------
    // textPreviewHide
    // -------------------------------------------------------------------------

    #[Test]
    public function testTextPreviewHideSuccess(): void
    {
        $message = $this->createMessageWithFile();

        $this->client->request('GET', sprintf('/messages/%d/text-preview/hide', $message->getId()));

        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function testTextPreviewHideNotFound(): void
    {
        $this->client->request('GET', '/messages/999999/text-preview/hide');

        $this->assertResponseStatusCodeSame(404);
    }

    // -------------------------------------------------------------------------
    // lightbox
    // -------------------------------------------------------------------------

    #[Test]
    public function testLightboxSuccess(): void
    {
        $message = $this->createMessageWithFile();

        $this->client->request('GET', sprintf('/messages/%d/lightbox', $message->getId()));

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        static::assertStringContainsString('document.pdf', $content);
        static::assertStringContainsString('/preview', $content);
        static::assertStringContainsString('/download', $content);
    }

    #[Test]
    public function testLightboxMessageNotFound(): void
    {
        $this->client->request('GET', '/messages/999999/lightbox');

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function testLightboxNoFilePath(): void
    {
        $message = new Message();
        $message->setChannel($this->channel);
        $message->setAuthor($this->testUser);
        $message->setContent('No file');
        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $this->client->request('GET', sprintf('/messages/%d/lightbox', $message->getId()));

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function testLightboxAccessDeniedOnPrivateChannel(): void
    {
        $privateChannel = new Channel();
        $privateChannel->setName('Private Channel');
        $privateChannel->setSlug('test-file-private');
        $privateChannel->setCreator($this->testUser);
        $privateChannel->addMember($this->testUser);
        $privateChannel->setIsPrivate(true);
        $this->entityManager->persist($privateChannel);

        $message = new Message();
        $message->setChannel($privateChannel);
        $message->setAuthor($this->testUser);
        $message->setContent('Private file');
        $message->setFileName('secret.txt');
        $message->setFilePath('uploads/secret.txt');
        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $otherUser = $this->createOtherUser();
        $this->client->loginUser($otherUser);

        $this->client->request('GET', sprintf('/messages/%d/lightbox', $message->getId()));

        $this->assertResponseStatusCodeSame(403);
    }
}
