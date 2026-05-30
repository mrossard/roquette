<?php

namespace App\Entity;

use App\Repository\UserChannelReadRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserChannelReadRepository::class)]
#[ORM\Table(name: '`user_channel_read`')]
#[ORM\UniqueConstraint(name: 'UNIQ_USER_CHANNEL', fields: ['user', 'channel'])]
class UserChannelRead
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Channel $channel = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Message $lastReadMessage = null;

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $notificationsEnabled = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function isNotificationsEnabled(): ?bool
    {
        return $this->notificationsEnabled;
    }

    public function setNotificationsEnabled(?bool $notificationsEnabled): static
    {
        $this->notificationsEnabled = $notificationsEnabled;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
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

    public function getLastReadMessage(): ?Message
    {
        return $this->lastReadMessage;
    }

    public function setLastReadMessage(?Message $lastReadMessage): static
    {
        $this->lastReadMessage = $lastReadMessage;
        return $this;
    }
}
