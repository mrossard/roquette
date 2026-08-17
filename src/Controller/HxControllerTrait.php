<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Channel;
use App\Repository\ChannelRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

trait HxControllerTrait
{
    /**
     * Redirects using the HX-Redirect header if the request is an HTMX request,
     * or performs a standard HTTP redirect otherwise.
     */
    protected function redirectOrHxRedirect(Request $request, string $url): Response
    {
        if ($request->headers->has('HX-Request')) {
            return new Response(null, Response::HTTP_NO_CONTENT, ['HX-Redirect' => $url]);
        }

        return $this->redirect($url);
    }

    /**
     * Resolves the active channel from the HX-Current-URL header if present.
     */
    protected function findActiveChannelFromHxRequest(Request $request, ChannelRepository $channelRepository): ?Channel
    {
        $currentUrl = $request->headers->get('HX-Current-URL');
        if (!$currentUrl) {
            return null;
        }

        $path = parse_url($currentUrl, PHP_URL_PATH);
        if ($path !== null && preg_match('#^/channels/([a-z0-9-]+)$#', $path, $matches)) {
            return $channelRepository->findOneBy(['slug' => $matches[1]]);
        }

        return null;
    }
}
