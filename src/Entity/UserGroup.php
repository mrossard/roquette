<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserGroupRepository::class)]
#[ORM\Table(name: '`user_group`')]
#[ORM\UniqueConstraint(name: 'uniq_user_group_identifier', fields: ['groupIdentifier'])]
class UserGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 255)]
    private ?string $groupIdentifier = null;

    #[ORM\OneToOne(inversedBy: 'userGroup', targetEntity: Channel::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(name: 'channel_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Channel $channel = null;

    /**
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'user_group_user')]
    private Collection $members;

    /**
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'user_group_administrator')]
    private Collection $administrators;

    public function __construct()
    {
        $this->members = new ArrayCollection();
        $this->administrators = new ArrayCollection();
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

    public function getGroupIdentifier(): ?string
    {
        return $this->groupIdentifier;
    }

    public function setGroupIdentifier(string $groupIdentifier): static
    {
        $this->groupIdentifier = $groupIdentifier;
        return $this;
    }

    public function getChannel(): ?Channel
    {
        return $this->channel;
    }

    public function setChannel(Channel $channel): static
    {
        $this->channel = $channel;
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
        return $this->administrators->contains($user);
    }
}
