<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MessageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\JoinColumn;

#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Table(name: '`message`')]
#[ORM\Index(name: 'idx_message_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_message_author', columns: ['author_id'])]
#[ORM\Index(name: 'idx_message_channel_id', columns: ['channel_id', 'id'])]
#[ORM\Index(name: 'idx_message_channel_created_at', columns: ['channel_id', 'created_at'])]
#[ORM\Index(name: 'idx_message_content_fts', columns: ['content_tsvector'])]
class Message
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $content = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $formattedContent = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $virusScanStatus = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fileName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $filePath = null;

    #[ORM\Column(nullable: true)]
    private ?int $fileSize = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\ManyToOne(inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Channel $channel = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $author = null;

    #[ORM\ManyToOne(targetEntity: Message::class, inversedBy: 'replies')]
    #[JoinColumn(name: 'parent_message_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Message $parentMessage = null;

    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'parentMessage')]
    private Collection $replies;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $customAuthorName = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $customAuthorAvatar = null;

    #[ORM\OneToMany(targetEntity: Reaction::class, mappedBy: 'message', orphanRemoval: true)]
    private Collection $reactions;

    #[ORM\OneToOne(mappedBy: 'message', targetEntity: Poll::class, cascade: ['persist', 'remove'])]
    private ?Poll $poll = null;

    #[ORM\Column(name: 'content_tsvector', type: 'text', nullable: true, insertable: false, updatable: false)]
    private ?string $contentTsvector = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->reactions = new ArrayCollection();
        $this->replies = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): static
    {
        $this->content = $content;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getChannel(): ?Channel
    {
        return $this->channel;
    }

    public function setChannel(?Channel $channel): static
    {
        $this->channel = $channel;
        return $this;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): static
    {
        $this->author = $author;
        return $this;
    }

    public function getParentMessage(): ?self
    {
        return $this->parentMessage;
    }

    public function setParentMessage(?self $parentMessage): static
    {
        $this->parentMessage = $parentMessage;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function setFileName(?string $fileName): static
    {
        $this->fileName = $fileName;
        return $this;
    }

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    public function setFilePath(?string $filePath): static
    {
        $this->filePath = $filePath;
        return $this;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function setFileSize(?int $fileSize): static
    {
        $this->fileSize = $fileSize;
        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): static
    {
        $this->mimeType = $mimeType;
        return $this;
    }

    public function isEdited(): bool
    {
        return $this->updatedAt !== null;
    }

    /**
     * @return Collection<int, Reaction>
     */
    public function getReactions(): Collection
    {
        return $this->reactions;
    }

    /**
     * Returns an array of reactions grouped by emoji.
     */
    public function getGroupedReactions(?User $currentUser = null): array
    {
        $grouped = [];
        foreach ($this->reactions as $reaction) {
            $emoji = $reaction->getEmoji();
            if (!array_key_exists($emoji, $grouped)) {
                $grouped[$emoji] = [
                    'count' => 0,
                    'usernames' => [],
                    'reactorUsernames' => [],
                    'hasReacted' => false,
                ];
            }
            $grouped[$emoji]['count']++;
            $user = $reaction->getUser();
            $grouped[$emoji]['usernames'][] =
                $user->getDisplayName() !== null && $user->getDisplayName() !== ''
                    ? $user->getDisplayName()
                    : $user->getUsername();
            $grouped[$emoji]['reactorUsernames'][] = $reaction->getUser()->getUsername();
            if ($currentUser && $reaction->getUser()->getId() === $currentUser->getId()) {
                $grouped[$emoji]['hasReacted'] = true;
            }
        }
        return $grouped;
    }

    public function getPoll(): ?Poll
    {
        return $this->poll;
    }

    public function setPoll(?Poll $poll): static
    {
        if ($poll !== null && $poll->getMessage() !== $this) {
            $poll->setMessage($this);
        }
        $this->poll = $poll;
        return $this;
    }

    public function isPoll(): bool
    {
        return $this->poll !== null;
    }

    /**
     * @return Collection<int, Message>
     */
    public function getReplies(): Collection
    {
        return $this->replies;
    }

    public function getCustomAuthorName(): ?string
    {
        return $this->customAuthorName;
    }

    public function setCustomAuthorName(?string $customAuthorName): static
    {
        $this->customAuthorName = $customAuthorName;

        return $this;
    }

    public function getCustomAuthorAvatar(): ?string
    {
        return $this->customAuthorAvatar;
    }

    public function setCustomAuthorAvatar(?string $customAuthorAvatar): static
    {
        $this->customAuthorAvatar = $customAuthorAvatar;

        return $this;
    }

    public function getFormattedContent(): ?string
    {
        return $this->formattedContent;
    }

    public function setFormattedContent(?string $formattedContent): static
    {
        $this->formattedContent = $formattedContent;

        return $this;
    }

    public function getVirusScanStatus(): ?string
    {
        return $this->virusScanStatus;
    }

    public function setVirusScanStatus(?string $virusScanStatus): static
    {
        $this->virusScanStatus = $virusScanStatus;

        return $this;
    }
}
