<?php

declare(strict_types=1);

namespace App\Ai;

final readonly class StreamResponseCoordinator
{
    public function __construct(
        private HelpStreamPublisher $streamPublisher,
    ) {}

    /**
     * Consumes an iterable of string chunks, throttling publishes to Mercure, and flushes the final state.
     *
     * @param iterable<string> $generator
     * @param null|(callable(): ?string) $getConfirmationToken
     * @return array{text: string, chunkCount: int}
     */
    public function streamAndPublish(
        iterable $generator,
        string $personalTopic,
        string $helpMessageId,
        string $prefix,
        string $channelSlug,
        #[\SensitiveParameter]
        ?callable $getConfirmationToken = null,
        int $burstInitialChunks = 3,
        int $throttleModulus = 3,
    ): array {
        $accumulatedText = '';
        $chunkCount = 0;

        foreach ($generator as $chunk) {
            $accumulatedText .= $chunk;
            $chunkCount++;

            if ($chunkCount <= $burstInitialChunks || ($chunkCount % $throttleModulus) === 0) {
                $this->streamPublisher->publishStreamText(
                    $personalTopic,
                    $helpMessageId,
                    $prefix,
                    $accumulatedText,
                    $channelSlug,
                );
            }
        }

        $confirmationToken = $getConfirmationToken !== null ? $getConfirmationToken() : null;

        $this->streamPublisher->publishStreamText(
            $personalTopic,
            $helpMessageId,
            $prefix,
            $accumulatedText,
            $channelSlug,
            $confirmationToken,
        );

        return [
            'text' => $accumulatedText,
            'chunkCount' => $chunkCount,
        ];
    }
}
