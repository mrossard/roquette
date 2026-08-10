<?php

declare(strict_types=1);

namespace App\Controller\Trait;

use App\Entity\Channel;
use App\Repository\ChannelRepository;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

trait ChannelAccessTrait
{
    /**
     * Resolves the channel by slug and verifies if the current user has access to it.
     *
     * @throws NotFoundHttpException if the channel does not exist
     * @throws AccessDeniedHttpException if the user does not have view access
     */
    private function findAndAuthorizeChannel(string $slug, ChannelRepository $channelRepository): Channel
    {
        $channel = $channelRepository->findOneBy(['slug' => $slug]);
        if (!$channel) {
            throw new NotFoundHttpException('Canal non trouvé.');
        }

        $this->denyAccessUnlessGranted('VIEW', $channel);

        return $channel;
    }

    /**
     * Verifies that the current user can access the given channel.
     *
     * @throws AccessDeniedHttpException if the user cannot access the channel
     */
    private function authorizeChannelAccess(Channel $channel): void
    {
        $this->denyAccessUnlessGranted('VIEW', $channel);
    }

    /**
     * Verifies that the current user can access the channel of the given message.
     *
     * @throws AccessDeniedHttpException if the user cannot access the message's channel
     */
    private function authorizeMessageAccess(\App\Entity\Message $message): void
    {
        $channel = $message->getChannel();
        if ($channel === null) {
            throw new AccessDeniedHttpException('Non autorisé.');
        }

        $this->authorizeChannelAccess($channel);
    }
}
