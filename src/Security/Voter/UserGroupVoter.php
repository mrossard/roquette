<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Entity\UserGroup;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter that determines permissions on UserGroup entities.
 */
class UserGroupVoter extends Voter
{
    public const VIEW = 'VIEW';
    public const EDIT = 'EDIT';
    public const MANAGE = 'MANAGE';
    public const DELETE = 'DELETE';

    public const GROUP_VIEW = 'GROUP_VIEW';
    public const GROUP_EDIT = 'GROUP_EDIT';
    public const GROUP_MANAGE = 'GROUP_MANAGE';
    public const GROUP_DELETE = 'GROUP_DELETE';

    public function __construct(
        private readonly Security $security,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array(
            $attribute,
            [
                self::VIEW,
                self::EDIT,
                self::MANAGE,
                self::DELETE,
                self::GROUP_VIEW,
                self::GROUP_EDIT,
                self::GROUP_MANAGE,
                self::GROUP_DELETE,
            ],
            true,
        )) {
            return false;
        }

        return $subject instanceof UserGroup;
    }

    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        #[\SensitiveParameter]
        TokenInterface $token,
        ?Vote $vote = null,
    ): bool {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        /** @var UserGroup $userGroup */
        $userGroup = $subject;

        return match ($attribute) {
            self::VIEW, self::GROUP_VIEW => $userGroup->isAdministrator($user)
                || $userGroup->getMembers()->contains($user),
            self::EDIT, self::MANAGE, self::GROUP_EDIT, self::GROUP_MANAGE => $userGroup->isAdministrator($user),
            self::DELETE, self::GROUP_DELETE => false,
            default => false,
        };
    }
}
