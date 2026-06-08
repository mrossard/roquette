<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Message;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MessageTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Constructor defaults
    // -------------------------------------------------------------------------

    #[Test]
    public function constructorSetsCreatedAt(): void
    {
        $before = new \DateTimeImmutable();
        $message = new Message();
        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $message->getCreatedAt());
        $this->assertLessThanOrEqual($after, $message->getCreatedAt());
    }

    #[Test]
    public function reactionsCollectionIsEmptyByDefault(): void
    {
        $message = new Message();
        $this->assertCount(0, $message->getReactions());
    }

    // -------------------------------------------------------------------------
    // isEdited()
    // -------------------------------------------------------------------------

    #[Test]
    public function isEditedReturnsFalseWhenUpdatedAtIsNull(): void
    {
        $message = new Message();
        $this->assertFalse($message->isEdited());
    }

    #[Test]
    public function isEditedReturnsTrueWhenUpdatedAtIsSet(): void
    {
        $message = new Message();
        $message->setUpdatedAt(new \DateTimeImmutable());

        $this->assertTrue($message->isEdited());
    }

    // -------------------------------------------------------------------------
    // File metadata
    // -------------------------------------------------------------------------

    #[Test]
    public function fileMetadataIsNullByDefault(): void
    {
        $message = new Message();

        $this->assertNull($message->getFileName());
        $this->assertNull($message->getFilePath());
        $this->assertNull($message->getFileSize());
        $this->assertNull($message->getMimeType());
    }

    #[Test]
    public function fileMetadataCanBeSetAndRetrieved(): void
    {
        $message = new Message();
        $message->setFileName('photo.jpg');
        $message->setFilePath('uploads/photo-abc123.jpg');
        $message->setFileSize(204_800);
        $message->setMimeType('image/jpeg');

        $this->assertSame('photo.jpg', $message->getFileName());
        $this->assertSame('uploads/photo-abc123.jpg', $message->getFilePath());
        $this->assertSame(204_800, $message->getFileSize());
        $this->assertSame('image/jpeg', $message->getMimeType());
    }

    // -------------------------------------------------------------------------
    // Content
    // -------------------------------------------------------------------------

    #[Test]
    public function contentIsNullByDefault(): void
    {
        $message = new Message();
        $this->assertNull($message->getContent());
    }

    #[Test]
    public function setContentUpdatesContent(): void
    {
        $message = new Message();
        $message->setContent('Bonjour le monde !');

        $this->assertSame('Bonjour le monde !', $message->getContent());
    }

    #[Test]
    public function setContentAcceptsNull(): void
    {
        $message = new Message();
        $message->setContent('something');
        $message->setContent(null);

        $this->assertNull($message->getContent());
    }

    // -------------------------------------------------------------------------
    // getGroupedReactions()
    // -------------------------------------------------------------------------

    #[Test]
    public function getGroupedReactionsReturnsEmptyArrayWhenNoReactions(): void
    {
        $message = new Message();
        $this->assertSame([], $message->getGroupedReactions());
    }
}
