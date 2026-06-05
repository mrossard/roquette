<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_USERNAME', fields: ['username'])]
#[ORM\UniqueConstraint(name: 'UNIQ_USER_SLUG', fields: ['slug'])]
#[ORM\UniqueConstraint(name: 'UNIQ_USER_OAUTH', fields: ['oauthId', 'oauthProvider'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private ?string $username = null;

    #[ORM\Column(length: 180)]
    private ?string $slug = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(nullable: true)]
    private ?int $customHue = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $displayName = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastActiveAt = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $statusOverride = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $oauthId = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $oauthProvider = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $mentionNotificationsEnabled = true;

    #[ORM\Column(length: 10, options: ['default' => 'dark'])]
    private string $theme = 'dark';

    #[ORM\Column(length: 10, options: ['default' => 'fr'])]
    private string $locale = 'fr';

    #[ORM\Column(options: ['default' => false])]
    private bool $admin = false;

    /**
     * @var Collection<int, Channel>
     */
    #[ORM\ManyToMany(targetEntity: Channel::class, mappedBy: 'members')]
    private Collection $privateChannels;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $channelOrder = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $favoriteChannelIds = null;

    /**
     * @var Collection<int, Message>
     */
    #[ORM\ManyToMany(targetEntity: Message::class)]
    #[ORM\JoinTable(name: 'user_saved_messages')]
    private Collection $savedMessages;

    public function __construct()
    {
        $this->privateChannels = new ArrayCollection();
        $this->savedMessages = new ArrayCollection();
    }

    public function getChannelOrder(): ?array
    {
        return $this->channelOrder;
    }

    public function setChannelOrder(?array $channelOrder): static
    {
        $this->channelOrder = $channelOrder;

        return $this;
    }

    public function getFavoriteChannelIds(): array
    {
        return $this->favoriteChannelIds ?? [];
    }

    public function setFavoriteChannelIds(?array $favoriteChannelIds): static
    {
        $this->favoriteChannelIds = $favoriteChannelIds;

        return $this;
    }

    public function isChannelFavorite(Channel $channel): bool
    {
        return in_array($channel->getId(), $this->getFavoriteChannelIds(), true);
    }

    public function addFavoriteChannel(Channel $channel): static
    {
        $favorites = $this->getFavoriteChannelIds();
        if (!in_array($channel->getId(), $favorites, true)) {
            $favorites[] = $channel->getId();
            $this->setFavoriteChannelIds($favorites);
        }
        return $this;
    }

    public function removeFavoriteChannel(Channel $channel): static
    {
        $favorites = $this->getFavoriteChannelIds();
        $key = array_search($channel->getId(), $favorites, true);
        if ($key !== false) {
            unset($favorites[$key]);
            $this->setFavoriteChannelIds(array_values($favorites));
        }
        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getHue(): int
    {
        return $this->customHue ?? (abs(crc32($this->username ?? '')) % 360);
    }

    public function getCustomHue(): ?int
    {
        return $this->customHue;
    }

    public function setCustomHue(?int $customHue): static
    {
        $this->customHue = $customHue;

        return $this;
    }

    public function getTheme(): string
    {
        return $this->theme;
    }

    public function setTheme(string $theme): static
    {
        if (in_array($theme, ['light', 'dark'], true)) {
            $this->theme = $theme;
        }

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        if (in_array($locale, ['fr', 'en'], true)) {
            $this->locale = $locale;
        }

        return $this;
    }

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    public function setAdmin(bool $admin): static
    {
        $this->admin = $admin;

        return $this;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): static
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;
        $this->slug = $this->generateSlug($username);

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->username;
    }

    /**
     * @see UserInterface
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        if ($this->admin) {
            $roles[] = 'ROLE_ADMIN';
        }

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(#[\SensitiveParameter] string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
    }

    /**
     * @return Collection<int, Channel>
     */
    public function getPrivateChannels(): Collection
    {
        return $this->privateChannels;
    }

    public function addPrivateChannel(Channel $privateChannel): static
    {
        if (!$this->privateChannels->contains($privateChannel)) {
            $this->privateChannels->add($privateChannel);
            $privateChannel->addMember($this);
        }
        return $this;
    }

    public function removePrivateChannel(Channel $privateChannel): static
    {
        if ($this->privateChannels->removeElement($privateChannel)) {
            $privateChannel->removeMember($this);
        }
        return $this;
    }

    public function getLastActiveAt(): ?\DateTimeImmutable
    {
        return $this->lastActiveAt;
    }

    public function setLastActiveAt(?\DateTimeImmutable $lastActiveAt): static
    {
        $this->lastActiveAt = $lastActiveAt;

        return $this;
    }

    public function getStatusOverride(): ?string
    {
        return $this->statusOverride;
    }

    public function setStatusOverride(?string $statusOverride): static
    {
        $this->statusOverride = $statusOverride;

        return $this;
    }

    public function getStatus(): string
    {
        if ($this->statusOverride !== null && $this->statusOverride !== 'auto') {
            return $this->statusOverride;
        }

        if ($this->lastActiveAt !== null) {
            $fiveMinutesAgo = new \DateTimeImmutable('-5 minutes');
            if ($this->lastActiveAt > $fiveMinutesAgo) {
                return 'online';
            }
        }

        return 'offline';
    }

    public function getStatusLabel(): string
    {
        return match ($this->getStatus()) {
            'online' => 'En ligne',
            'away' => 'Absent',
            'busy' => 'Occupé',
            'offline' => 'Hors ligne',
            default => 'Hors ligne',
        };
    }

    public function getOauthId(): ?string
    {
        return $this->oauthId;
    }

    public function setOauthId(?string $oauthId): static
    {
        $this->oauthId = $oauthId;

        return $this;
    }

    public function getOauthProvider(): ?string
    {
        return $this->oauthProvider;
    }

    public function setOauthProvider(?string $oauthProvider): static
    {
        $this->oauthProvider = $oauthProvider;

        return $this;
    }

    public function isMentionNotificationsEnabled(): bool
    {
        return $this->mentionNotificationsEnabled;
    }

    public function setMentionNotificationsEnabled(bool $mentionNotificationsEnabled): static
    {
        $this->mentionNotificationsEnabled = $mentionNotificationsEnabled;

        return $this;
    }

    /**
     * @return Collection<int, Message>
     */
    public function getSavedMessages(): Collection
    {
        return $this->savedMessages;
    }

    public function addSavedMessage(Message $message): static
    {
        if (!$this->savedMessages->contains($message)) {
            $this->savedMessages->add($message);
        }

        return $this;
    }

    public function removeSavedMessage(Message $message): static
    {
        $this->savedMessages->removeElement($message);

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

    private function generateSlug(string $username): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $username);

        return trim(strtolower($slug), '-');
    }
}
