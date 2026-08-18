<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Entity\Workspace;
use App\Service\WorkspaceManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter that determines permissions on Workspace entities.
 */
class WorkspaceVoter extends Voter
{
    public const VIEW = 'VIEW';
    public const EDIT = 'EDIT';
    public const DELETE = 'DELETE';
    public const INVITE = 'INVITE';
    public const MANAGE_MEMBERS = 'MANAGE_MEMBERS';

    public const WORKSPACE_VIEW = 'WORKSPACE_VIEW';
    public const WORKSPACE_EDIT = 'WORKSPACE_EDIT';
    public const WORKSPACE_DELETE = 'WORKSPACE_DELETE';
    public const WORKSPACE_INVITE = 'WORKSPACE_INVITE';
    public const WORKSPACE_MANAGE_MEMBERS = 'WORKSPACE_MANAGE_MEMBERS';

    public function __construct(
        private readonly WorkspaceManager $workspaceManager,
        private readonly Security $security,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array(
            $attribute,
            [
                self::VIEW,
                self::EDIT,
                self::DELETE,
                self::INVITE,
                self::MANAGE_MEMBERS,
                self::WORKSPACE_VIEW,
                self::WORKSPACE_EDIT,
                self::WORKSPACE_DELETE,
                self::WORKSPACE_INVITE,
                self::WORKSPACE_MANAGE_MEMBERS,
            ],
            true,
        )) {
            return false;
        }

        return $subject instanceof Workspace;
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

        /** @var Workspace $workspace */
        $workspace = $subject;

        return match ($attribute) {
            self::VIEW, self::WORKSPACE_VIEW => $this->workspaceManager->isUserMember($workspace, $user),
            self::EDIT,
            self::DELETE,
            self::INVITE,
            self::MANAGE_MEMBERS,
            self::WORKSPACE_EDIT,
            self::WORKSPACE_DELETE,
            self::WORKSPACE_INVITE,
            self::WORKSPACE_MANAGE_MEMBERS,
                => $this->security->isGranted('ROLE_ADMIN') || $workspace->getCreator() === $user,
            default => false,
        };
    }
}
