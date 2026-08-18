<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Channel;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter that determines if a user can edit, manage, or delete a channel.
 */
class ChannelAdminVoter extends Voter
{
    public const EDIT = 'EDIT';
    public const MANAGE = 'MANAGE';
    public const DELETE = 'DELETE';
    public const INVITE = 'INVITE';
    public const CHANNEL_EDIT = 'CHANNEL_EDIT';
    public const CHANNEL_MANAGE = 'CHANNEL_MANAGE';
    public const CHANNEL_DELETE = 'CHANNEL_DELETE';
    public const CHANNEL_INVITE = 'CHANNEL_INVITE';

    public function __construct(
        private readonly Security $security,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array(
            $attribute,
            [
                self::EDIT,
                self::MANAGE,
                self::DELETE,
                self::INVITE,
                self::CHANNEL_EDIT,
                self::CHANNEL_MANAGE,
                self::CHANNEL_DELETE,
                self::CHANNEL_INVITE,
            ],
            true,
        )) {
            return false;
        }

        return $subject instanceof Channel;
    }

    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        #[\SensitiveParameter]
        TokenInterface $token,
        ?\Symfony\Component\Security\Core\Authorization\Voter\Vote $vote = null,
    ): bool {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        /** @var Channel $channel */
        $channel = $subject;

        if ($channel->getWorkspace() !== null && $channel->getWorkspace()->getCreator()?->getId() === $user->getId()) {
            return true;
        }

        return $channel->isAdministrator($user);
    }
}
