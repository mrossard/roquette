<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CustomEmojiRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CustomEmojiRepository::class)]
#[ORM\Table(name: '`custom_emoji`')]
#[ORM\UniqueConstraint(name: 'UNIQ_CUSTOM_EMOJI_CODE', columns: ['code'])]
class CustomEmoji
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 191)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 191)]
    #[Assert\Regex(pattern: '/^:[a-zA-Z0-9_\-]+:$/', message: 'Le code émoji doit commencer et se terminer par ":" (ex: :mon_emoji:)')]
    private ?string $code = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $filename = null;

    /**
     * @var array<string>
     */
    #[ORM\Column(type: 'json')]
    private array $tags = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;
        return $this;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): static
    {
        $this->filename = $filename;
        return $this;
    }

    /**
     * @return array<string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * @param array<string> $tags
     */
    public function setTags(array $tags): static
    {
        $this->tags = array_values(array_unique(array_filter(array_map('trim', $tags))));
        return $this;
    }
}
