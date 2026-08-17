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
     * Gets the currently active workspace from the session or falls back to public workspace.
     */
    public function getCurrentWorkspace(bool $fallbackToPublic = true): ?Workspace
    {
        $session = $this->getSession();
        $workspaceId = $session?->get(self::SESSION_KEY);

        if (is_int($workspaceId) || (is_string($workspaceId) && ctype_digit($workspaceId))) {
            $workspace = $this->workspaceRepository->find((int) $workspaceId);
            if ($workspace !== null) {
                return $workspace;
            }
        }

        if (!$fallbackToPublic) {
            return null;
        }

        $publicWorkspace = $this->workspaceRepository->findPublicWorkspace();
        if ($publicWorkspace !== null && $session !== null) {
            $session->set(self::SESSION_KEY, $publicWorkspace->getId());
        }

        return $publicWorkspace;
    }

    /**
     * Gets the currently active workspace ID.
     */
    public function getCurrentWorkspaceId(bool $fallbackToPublic = true): ?int
    {
        $session = $this->getSession();
        $workspaceId = $session?->get(self::SESSION_KEY);

        if (is_int($workspaceId)) {
            return $workspaceId;
        }

        if (is_string($workspaceId) && ctype_digit($workspaceId)) {
            return (int) $workspaceId;
        }

        if (!$fallbackToPublic) {
            return null;
        }

        $workspace = $this->getCurrentWorkspace(fallbackToPublic: true);

        return $workspace?->getId();
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

        return $this->getCurrentWorkspace();
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
