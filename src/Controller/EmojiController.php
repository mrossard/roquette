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

    #[Route('/emojis/{path}', name: 'app_emoji_serve', requirements: ['path' => '.+\.gif'], methods: ['GET'])]
    public function serve(string $path): Response
    {
        if (empty($this->emojiBaseUrl)) {
            return new Response('Emoji base URL is not configured.', Response::HTTP_NOT_FOUND);
        }

        $sanitizedPath = $this->sanitizeEmojiPath($path);
        if ($sanitizedPath === '') {
            return new Response('Invalid emoji path.', Response::HTTP_NOT_FOUND);
        }

        $storagePath = 'emojis/' . $sanitizedPath;

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
        $this->messageBus->dispatch(new DownloadEmojiMessage($sanitizedPath));

        // Instantly redirect the user's browser to the remote URL so the emoji displays immediately
        $remoteUrl = $this->buildEmojiRemoteUrl($sanitizedPath);

        return new RedirectResponse($remoteUrl, Response::HTTP_FOUND);
    }

    private function sanitizeEmojiPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $parts = explode('/', $path);
        $clean = [];
        foreach ($parts as $part) {
            if ($part === '..' || $part === '' || $part === '.') {
                continue;
            }
            $clean[] = $part;
        }

        return implode('/', $clean);
    }

    private function buildEmojiRemoteUrl(string $path): string
    {
        $parts = explode('/', $path);

        return rtrim($this->emojiBaseUrl, '/') . '/' . implode('/', array_map('rawurlencode', $parts));
    }
}
