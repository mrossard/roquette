<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: '`poll`')]
class Poll
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 255)]
    private ?string $question = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $allowMultiple = false;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\OneToOne(targetEntity: Message::class, inversedBy: 'poll', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Message $message = null;

    /**
     * @var Collection<int, PollOption>
     */
    #[ORM\OneToMany(
        targetEntity: PollOption::class,
        mappedBy: 'poll',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $options;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->options = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuestion(): ?string
    {
        return $this->question;
    }

    public function setQuestion(string $question): static
    {
        $this->question = $question;
        return $this;
    }

    public function isAllowMultiple(): bool
    {
        return $this->allowMultiple;
    }

    public function setAllowMultiple(bool $allowMultiple): static
    {
        $this->allowMultiple = $allowMultiple;
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

    public function getMessage(): ?Message
    {
        return $this->message;
    }

    public function setMessage(?Message $message): static
    {
        $this->message = $message;
        return $this;
    }

    /**
     * @return Collection<int, PollOption>
     */
    public function getOptions(): Collection
    {
        return $this->options;
    }

    public function addOption(PollOption $option): static
    {
        if (!$this->options->contains($option)) {
            $this->options->add($option);
            $option->setPoll($this);
        }
        return $this;
    }

    public function removeOption(PollOption $option): static
    {
        if ($this->options->removeElement($option)) {
            if ($option->getPoll() === $this) {
                $option->setPoll(null);
            }
        }
        return $this;
    }

    public function getTotalVotes(): int
    {
        $total = 0;
        foreach ($this->options as $option) {
            $total += $option->getVoteCount();
        }
        return $total;
    }
}
