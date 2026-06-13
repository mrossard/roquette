<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\DownloadEmojiMessage;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class EmojiController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly FilesystemOperator $defaultStorage,
        #[Autowire('%env(EMOJI_BASE_URL)%')]
        private readonly string $emojiBaseUrl,
    ) {}

    #[Route('/emojis/{filename}', name: 'app_emoji_serve', requirements: ['filename' => '[a-zA-Z0-9_\-\+: ]+\.gif'], methods: ['GET'])]
    public function serve(string $filename): Response
    {
        if (empty($this->emojiBaseUrl)) {
            return new Response('Emoji base URL is not configured.', Response::HTTP_NOT_FOUND);
        }

        $storagePath = 'emojis/' . $filename;

        try {
            if ($this->defaultStorage->has($storagePath)) {
                $stream = $this->defaultStorage->readStream($storagePath);

                $response = new StreamedResponse(
                    static function () use ($stream) {
                        fpassthru($stream);
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    },
                    Response::HTTP_OK,
                    [
                        'Content-Type' => 'image/gif',
                        'Cache-Control' => 'public, max-age=31536000, immutable',
                    ]
                );

                return $response;
            }
        } catch (\Exception $e) {
            // Log or ignore connection issues, fall back to remote redirect
        }

        // Dispatch background job to download and save this emoji to Flysystem
        $this->messageBus->dispatch(new DownloadEmojiMessage($filename));

        // Instantly redirect the user's browser to the remote URL so the emoji displays immediately
        $remoteUrl = rtrim($this->emojiBaseUrl, '/') . '/' . rawurlencode($filename);

        return new RedirectResponse($remoteUrl, Response::HTTP_FOUND);
    }
}
