<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use App\Service\FileUploadService;
use App\Service\MessageFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class MessageFactoryTest extends TestCase
{
    #[Test]
    public function createBuildsStandardMessage(): void
    {
        $messageRepo = $this->createStub(MessageRepository::class);
        $fileUploadService = $this->createMock(FileUploadService::class);
        $fileUploadService->expects($this->never())->method('uploadAndAttachToMessage');

        $factory = new MessageFactory($messageRepo, $fileUploadService);

        $channel = new Channel();
        $channel->setName('Général');

        $author = new User();
        $author->setUsername('alice');

        $msg = $factory->create($channel, $author, 'Bonjour tout le monde !');

        $this->assertSame($channel, $msg->getChannel());
        $this->assertSame($author, $msg->getAuthor());
        $this->assertSame('Bonjour tout le monde !', $msg->getContent());
        $this->assertNull($msg->getParentMessage());
    }

    #[Test]
    public function createAttachesParentMessageInThread(): void
    {
        $channel = new Channel();
        $ref = new \ReflectionProperty(Channel::class, 'id');
        $ref->setValue($channel, 10);

        $parent = new Message();
        $parent->setChannel($channel);

        $messageRepo = $this->createMock(MessageRepository::class);
        $messageRepo->expects($this->once())->method('find')->with(42)->willReturn($parent);

        $fileUploadService = $this->createStub(FileUploadService::class);
        $factory = new MessageFactory($messageRepo, $fileUploadService);

        $author = new User();
        $msg = $factory->create($channel, $author, 'Réponse en fil', null, 42);

        $this->assertSame($parent, $msg->getParentMessage());
    }

    #[Test]
    public function createAttachesFileAndSetsPendingScan(): void
    {
        $channel = new Channel();
        $author = new User();

        $messageRepo = $this->createStub(MessageRepository::class);
        $fileUploadService = $this->createMock(FileUploadService::class);

        $file = $this->createStub(UploadedFile::class);
        $fileUploadService->expects($this->once())
            ->method('uploadAndAttachToMessage')
            ->with($file, $this->isInstanceOf(Message::class));

        $factory = new MessageFactory($messageRepo, $fileUploadService);

        $msg = $factory->create($channel, $author, 'Voici le fichier', $file);

        $this->assertSame('pending', $msg->getVirusScanStatus());
    }
}
