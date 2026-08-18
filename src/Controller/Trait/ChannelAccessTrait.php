<?php

declare(strict_types=1);

namespace App\Controller\Trait;

use App\Entity\Channel;
use App\Service\ChannelManager;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

trait ChannelAccessTrait
{
    /**
     * Resolves the channel by slug and verifies that the current user has the
     * given attribute on it.
     *
     * @throws NotFoundHttpException if the channel does not exist
     * @throws AccessDeniedHttpException if the user does not have access
     */
    private function findAuthorizedChannel(
        string $slug,
        ChannelManager $channelManager,
        string $attribute = 'VIEW',
    ): Channel {
        $channel = $channelManager->findChannelBySlug($slug);

        $this->denyAccessUnlessGranted($attribute, $channel);

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
