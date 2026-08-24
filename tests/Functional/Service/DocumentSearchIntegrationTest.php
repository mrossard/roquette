<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Ai\Tool\SearchMessagesTool;
use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Service\FileUploadService;
use App\Service\HybridSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class DocumentSearchIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private HybridSearchService $hybridSearchService;
    private FileUploadService $fileUploadService;
    private User $user;
    private Channel $channel;
    private string $testFilePath = 'test_doc_indexed_123.txt';

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->hybridSearchService = $container->get(HybridSearchService::class);
        $this->fileUploadService = $container->get(FileUploadService::class);

        $this->cleanup();

        $passwordHasher = $container->get('security.user_password_hasher');

        $this->user = new User();
        $this->user->setUsername('doc_search_user');
        $this->user->setRoles(['ROLE_USER']);
        $this->user->setPassword($passwordHasher->hashPassword($this->user, 'password123'));
        $this->entityManager->persist($this->user);

        $this->channel = new Channel();
        $this->channel->setName('Doc Search Channel');
        $this->channel->setSlug('doc-search-channel');
        $this->channel->setIsPrivate(false);
        $this->channel->setCreator($this->user);
        $this->channel->addMember($this->user);
        $this->entityManager->persist($this->channel);

        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        if (($this->fileUploadService ?? null) !== null && $this->fileUploadService->exists($this->testFilePath)) {
            $this->fileUploadService->delete($this->testFilePath);
        }

        $channelRepo = $this->entityManager->getRepository(Channel::class);
        $messageRepo = $this->entityManager->getRepository(Message::class);
        $userRepo = $this->entityManager->getRepository(User::class);

        $ch = $channelRepo->findOneBy(['slug' => 'doc-search-channel']);
        if ($ch) {
            $messages = $messageRepo->findBy(['channel' => $ch]);
            foreach ($messages as $msg) {
                $this->entityManager->remove($msg);
            }
            $this->entityManager->remove($ch);
        }

        $u = $userRepo->findOneBy(['username' => 'doc_search_user']);
        if ($u) {
            $this->entityManager->remove($u);
        }

        $this->entityManager->flush();
    }

    public function testIndexAndSearchAttachedDocument(): void
    {
        // 1. Create a dummy file in storage
        $fileContent = "Projet Phoenix : Le budget total validé par la direction est de 45 000 euros HT.\nLivraison prévue pour fin septembre.";
        $this->fileUploadService->write($this->testFilePath, $fileContent);

        // 2. Create message referencing the file
        $msg = new Message();
        $msg->setChannel($this->channel);
        $msg->setAuthor($this->user);
        $msg->setContent('Voici le document du projet en pièce jointe.');
        $msg->setFileName('cahier_des_charges.txt');
        $msg->setFilePath($this->testFilePath);
        $msg->setMimeType('text/plain');
        $msg->setFileSize(strlen($fileContent));
        $msg->setCreatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($msg);
        $this->entityManager->flush();

        // 3. Index message and attachment
        $indexed = $this->hybridSearchService->indexMessage((int) $msg->getId());
        static::assertTrue($indexed);

        // Check that message_document_chunk table has a chunk
        $conn = $this->entityManager->getConnection();
        $chunkCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM message_document_chunk WHERE message_id = :msgId', [
            'msgId' => $msg->getId(),
        ]);
        static::assertGreaterThanOrEqual(1, $chunkCount);

        // 4. Search in channel for a term only present inside the document ("Phoenix" or "budget")
        $results = $this->hybridSearchService->searchInChannel($this->channel, 'Phoenix');
        static::assertCount(1, $results);
        static::assertSame($msg->getId(), $results[0]->getId());

        // 5. Global search for a term inside document
        $globalResults = $this->hybridSearchService->searchGlobal(currentUser: $this->user, textQuery: 'budget');
        static::assertCount(1, $globalResults);
        static::assertSame($msg->getId(), $globalResults[0]->getId());

        // 6. Test excerpt retrieval
        $excerpt = $this->hybridSearchService->getMatchingDocumentExcerpt((int) $msg->getId(), 'budget');
        static::assertNotNull($excerpt);
        static::assertStringContainsString('45 000 euros', $excerpt);

        // 7. Test SearchMessagesTool formatting with document excerpt
        $tool = self::getContainer()->get(SearchMessagesTool::class);
        $toolResult = $tool->execute(['query' => 'Phoenix'], (int) $this->user->getId());
        static::assertSame(1, $toolResult['count']);
        static::assertStringContainsString('cahier_des_charges.txt', $toolResult['results']);
        static::assertStringContainsString('Extrait:', $toolResult['results']);

        // 8. Delete message embedding / cascade
        $this->hybridSearchService->deleteMessageEmbedding((int) $msg->getId());
        $chunkCountAfter =
            (int) $conn->fetchOne('SELECT COUNT(*) FROM message_document_chunk WHERE message_id = :msgId', [
                'msgId' => $msg->getId(),
            ]);
        static::assertSame(0, $chunkCountAfter);
    }
}
