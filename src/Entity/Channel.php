<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ChannelRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChannelRepository::class)]
#[ORM\Table(name: '`channel`')]
class Channel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isPrivate = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $isDm = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $isTodoList = false;

    #[ORM\Column(type: 'integer', nullable: true, options: ['default' => 6])]
    private ?int $messageRetentionMonths = 6;

    #[ORM\ManyToOne(targetEntity: Message::class)]
    #[ORM\JoinColumn(name: 'pinned_message_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Message $pinnedMessage = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $creator = null;

    /**
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'privateChannels')]
    #[ORM\JoinTable(name: 'channel_user')]
    private Collection $members;

    /**
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'channel_administrator')]
    private Collection $administrators;

    /**
     * @var Collection<int, Message>
     */
    #[ORM\ManyToOne(targetEntity: Message::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Message $parentMessage = null;

    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'channel', cascade: ['remove'])]
    private Collection $messages;

    public function __construct()
    {
        $this->messages = new ArrayCollection();
        $this->members = new ArrayCollection();
        $this->administrators = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
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

    /**
     * @return Collection<int, Message>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(Message $message): static
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setChannel($this);
        }
        return $this;
    }

    public function removeMessage(Message $message): static
    {
        if ($this->messages->removeElement($message)) {
            // set the owning side to null (unless already changed)
            if ($message->getChannel() === $this) {
                $message->setChannel(null);
            }
        }
        return $this;
    }

    public function isPrivate(): bool
    {
        return $this->isPrivate;
    }

    // @mago-expect no-boolean-flag-parameter
    public function setIsPrivate(bool $isPrivate): static
    {
        $this->isPrivate = $isPrivate;
        return $this;
    }

    public function isDm(): bool
    {
        return $this->isDm;
    }

    // @mago-expect no-boolean-flag-parameter
    public function setIsDm(bool $isDm): static
    {
        $this->isDm = $isDm;
        return $this;
    }

    public function isTodoList(): bool
    {
        return $this->isTodoList;
    }

    // @mago-expect no-boolean-flag-parameter
    public function setIsTodoList(bool $isTodoList): static
    {
        $this->isTodoList = $isTodoList;

        return $this;
    }

    public function isSubChannel(): bool
    {
        return $this->parentMessage !== null;
    }

    public function getParentMessage(): ?Message
    {
        return $this->parentMessage;
    }

    public function setParentMessage(?Message $parentMessage): static
    {
        $this->parentMessage = $parentMessage;

        return $this;
    }

    public function getDmPartner(User $currentUser): ?User
    {
        foreach ($this->getMembers() as $member) {
            if ($member->getId() !== $currentUser->getId()) {
                return $member;
            }
        }
        return null;
    }

    public function getCreator(): ?User
    {
        return $this->creator;
    }

    public function setCreator(?User $creator): static
    {
        $this->creator = $creator;
        return $this;
    }

    /**
     * @return Collection<int, User>
     */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function addMember(User $member): static
    {
        if (!$this->members->contains($member)) {
            $this->members->add($member);
        }
        return $this;
    }

    public function removeMember(User $member): static
    {
        $this->members->removeElement($member);
        return $this;
    }

    public function getPinnedMessage(): ?Message
    {
        return $this->pinnedMessage;
    }

    public function setPinnedMessage(?Message $pinnedMessage): static
    {
        $this->pinnedMessage = $pinnedMessage;
        return $this;
    }

    public function getLastMessage(): ?Message
    {
        $criteria = \Doctrine\Common\Collections\Criteria::create()
            ->orderBy([
                'createdAt' => \Doctrine\Common\Collections\Criteria::DESC,
                'id' => \Doctrine\Common\Collections\Criteria::DESC,
            ])
            ->setMaxResults(1);

        $result = $this->messages->matching($criteria)->first();

        return $result !== false ? $result : null;
    }

    public function getMessageRetentionMonths(): ?int
    {
        return $this->messageRetentionMonths;
    }

    public function setMessageRetentionMonths(?int $messageRetentionMonths): static
    {
        $this->messageRetentionMonths = $messageRetentionMonths;
        return $this;
    }

    /**
     * @return Collection<int, User>
     */
    public function getAdministrators(): Collection
    {
        return $this->administrators;
    }

    public function addAdministrator(User $administrator): static
    {
        if (!$this->administrators->contains($administrator)) {
            $this->administrators->add($administrator);
        }

        return $this;
    }

    public function removeAdministrator(User $administrator): static
    {
        $this->administrators->removeElement($administrator);

        return $this;
    }

    public function isAdministrator(User $user): bool
    {
        if ($this->creator && $this->creator->getId() === $user->getId()) {
            return true;
        }

        return $this->administrators->contains($user);
    }

    public function hasUserParticipated(User $user): bool
    {
        if ($this->creator && $this->creator->getId() === $user->getId()) {
            return true;
        }

        foreach ($this->messages as $message) {
            if ($message->getAuthor() && $message->getAuthor()->getId() === $user->getId()) {
                return true;
            }
        }

        if ($this->parentMessage) {
            $parentChannel = $this->parentMessage->getChannel();
            if ($parentChannel && $parentChannel->isDm()) {
                if ($parentChannel->getMembers()->contains($user)) {
                    return true;
                }
            }
        }

        return false;
    }
}
