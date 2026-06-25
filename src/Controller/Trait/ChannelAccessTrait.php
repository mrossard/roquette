<?php

declare(strict_types=1);

namespace App\Controller\Trait;

use App\Entity\Channel;
use App\Entity\User;
use App\Repository\ChannelRepository;
use App\Service\ChannelAccessService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

trait ChannelAccessTrait
{
    private ChannelAccessService $channelAccessService;

    /**
     * @required
     */
    public function setChannelAccessService(ChannelAccessService $channelAccessService): void
    {
        $this->channelAccessService = $channelAccessService;
    }

    /**
     * Resolves the channel by slug and verifies if the current user has access to it.
     *
     * @throws NotFoundHttpException if the channel does not exist
     * @throws AccessDeniedHttpException if the channel is private and the user is not a member
     */
    private function findAndAuthorizeChannel(string $slug, ChannelRepository $channelRepository): Channel
    {
        /** @var User|null $currentUser */
        $currentUser = $this->getUser();

        $channel = $channelRepository->findOneBy(['slug' => $slug]);
        if (!$channel) {
            throw new NotFoundHttpException('Canal non trouvé.');
        }

        if (!$currentUser || !$this->channelAccessService->canUserAccess($channel, $currentUser)) {
            throw new AccessDeniedHttpException('Non autorisé.');
        }

        return $channel;
    }
}
