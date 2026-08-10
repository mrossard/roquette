<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Channel;
use App\Entity\User;
use App\Service\ChannelAccessService;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter that determines if a user can view / access a channel.
 */
class ChannelAccessVoter extends Voter
{
    public const VIEW = 'VIEW';
    public const CHANNEL_VIEW = 'CHANNEL_VIEW';

    public function __construct(
        private readonly ChannelAccessService $channelAccessService,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, [self::VIEW, self::CHANNEL_VIEW], true)) {
            return false;
        }

        return $subject instanceof Channel;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, #[\SensitiveParameter] TokenInterface $token, ?\Symfony\Component\Security\Core\Authorization\Voter\Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var Channel $channel */
        $channel = $subject;

        return $this->channelAccessService->canUserAccess($channel, $user);
    }
}
