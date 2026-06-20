<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: '`poll_option`')]
class PollOption
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 255)]
    private ?string $text = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\ManyToOne(targetEntity: Poll::class, inversedBy: 'options')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Poll $poll = null;

    /**
     * @var Collection<int, PollVote>
     */
    #[ORM\OneToMany(targetEntity: PollVote::class, mappedBy: 'option', cascade: ['remove'], orphanRemoval: true)]
    private Collection $votes;

    public function __construct()
    {
        $this->votes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function setText(string $text): static
    {
        $this->text = $text;
        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;
        return $this;
    }

    public function getPoll(): ?Poll
    {
        return $this->poll;
    }

    public function setPoll(?Poll $poll): static
    {
        $this->poll = $poll;
        return $this;
    }

    /**
     * @return Collection<int, PollVote>
     */
    public function getVotes(): Collection
    {
        return $this->votes;
    }

    public function addVote(PollVote $vote): static
    {
        if (!$this->votes->contains($vote)) {
            $this->votes->add($vote);
            $vote->setOption($this);
        }
        return $this;
    }

    public function removeVote(PollVote $vote): static
    {
        if ($this->votes->removeElement($vote)) {
            if ($vote->getOption() === $this) {
                $vote->setOption(null);
            }
        }
        return $this;
    }

    public function getVoteCount(): int
    {
        return $this->votes->count();
    }

    public function hasVotedBy(User $user): bool
    {
        foreach ($this->votes as $vote) {
            if ($vote->getUser()->getId() === $user->getId()) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return Collection<int, User>
     */
    public function getVoters(): Collection
    {
        $voters = new ArrayCollection();
        foreach ($this->votes as $vote) {
            $user = $vote->getUser();
            if (!$voters->contains($user)) {
                $voters->add($user);
            }
        }
        return $voters;
    }
}
