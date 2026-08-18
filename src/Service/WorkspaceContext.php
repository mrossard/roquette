<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\Workspace;
use App\Repository\WorkspaceRepository;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Manages the current workspace context stored in the HTTP session,
 * providing safe fallbacks and synchronization mechanisms.
 */
class WorkspaceContext
{
    public const string SESSION_KEY = 'current_workspace_id';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly WorkspaceRepository $workspaceRepository,
    ) {}

    /**
     * Gets the currently active workspace from the session without fallback.
     */
    public function getCurrentWorkspace(): ?Workspace
    {
        $session = $this->getSession();
        $workspaceId = $session?->get(self::SESSION_KEY);

        if (is_int($workspaceId) || (is_string($workspaceId) && ctype_digit($workspaceId))) {
            return $this->workspaceRepository->find((int) $workspaceId);
        }

        return null;
    }

    /**
     * Gets the currently active workspace from session or falls back to public workspace.
     */
    public function getCurrentWorkspaceOrPublic(): ?Workspace
    {
        $workspace = $this->getCurrentWorkspace();
        if ($workspace !== null) {
            return $workspace;
        }

        $publicWorkspace = $this->workspaceRepository->findPublicWorkspace();
        $session = $this->getSession();
        if ($publicWorkspace !== null && $session !== null) {
            $session->set(self::SESSION_KEY, $publicWorkspace->getId());
        }

        return $publicWorkspace;
    }

    /**
     * Sets the active workspace in the session.
     */
    public function setCurrentWorkspace(?Workspace $workspace): void
    {
        $this->setCurrentWorkspaceId($workspace?->getId());
    }

    /**
     * Sets the active workspace ID in the session.
     */
    public function setCurrentWorkspaceId(?int $workspaceId): void
    {
        $session = $this->getSession();
        if ($session === null) {
            return;
        }

        if ($workspaceId === null) {
            $session->remove(self::SESSION_KEY);
            return;
        }

        $session->set(self::SESSION_KEY, $workspaceId);
    }

    /**
     * Synchronizes the workspace context from a channel.
     * If the channel belongs to a workspace, sets it as current.
     * If the channel has no workspace, resolves the current workspace from session/fallback.
     */
    public function syncFromChannel(?Channel $channel): ?Workspace
    {
        $workspace = $channel?->getWorkspace();
        if ($workspace !== null) {
            $this->setCurrentWorkspace($workspace);

            return $workspace;
        }

        return $this->getCurrentWorkspaceOrPublic();
    }

    private function getSession(): ?SessionInterface
    {
        try {
            return $this->requestStack->getSession();
        } catch (SessionNotFoundException) {
            return null;
        }
    }
}
