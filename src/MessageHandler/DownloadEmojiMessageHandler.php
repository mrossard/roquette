<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\DownloadEmojiMessage;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsMessageHandler]
class DownloadEmojiMessageHandler
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly FilesystemOperator $defaultStorage,
        #[Autowire('%env(EMOJI_BASE_URL)%')]
        private readonly string $emojiBaseUrl,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(DownloadEmojiMessage $message): void
    {
        $filename = basename($message->getFilename());
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return;
        }

        $storagePath = 'emojis/' . $filename;

        // If it's already in Flysystem, don't download it again
        if ($this->defaultStorage->has($storagePath)) {
            return;
        }

        if (empty($this->emojiBaseUrl)) {
            return;
        }

        $url = rtrim($this->emojiBaseUrl, '/') . '/' . rawurlencode($filename);
        $this->logger->info(sprintf('Asynchronously downloading emoji "%s" from "%s" to local storage.', $filename, $url));

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 5.0,
            ]);
            if ($response->getStatusCode() === 200) {
                $content = $response->getContent();
                $this->defaultStorage->write($storagePath, $content);
                $this->logger->info(sprintf('Emoji "%s" saved successfully to local storage.', $filename));
            } else {
                // Save empty content as negative cache
                $this->defaultStorage->write($storagePath, '');
                $this->logger->warning(sprintf('Failed to download emoji "%s": HTTP %d.', $filename, $response->getStatusCode()));
            }
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Exception while downloading emoji "%s": %s', $filename, $e->getMessage()));
        }
    }
}
