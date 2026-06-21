<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GroupSubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GroupSubscriptionRepository::class)]
#[ORM\Table(name: '`group_subscription`')]
#[ORM\UniqueConstraint(
    name: 'uniq_group_official_channel',
    fields: ['groupIdentifier'],
    options: ['where' => 'is_group_channel = true'],
)]
class GroupSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Channel::class, inversedBy: 'groupSubscriptions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Channel $channel = null;

    #[ORM\Column(length: 255)]
    private ?string $groupIdentifier = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isGroupChannel = false;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getGroupIdentifier(): ?string
    {
        return $this->groupIdentifier;
    }

    public function setGroupIdentifier(string $groupIdentifier): static
    {
        $this->groupIdentifier = $groupIdentifier;
        return $this;
    }

    public function isGroupChannel(): bool
    {
        return $this->isGroupChannel;
    }

    // @mago-expect no-boolean-flag-parameter
    public function setIsGroupChannel(bool $isGroupChannel): static
    {
        $this->isGroupChannel = $isGroupChannel;
        return $this;
    }
}
