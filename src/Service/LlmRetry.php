<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;

/**
 * Streams LLM deltas while retrying transient failures that occur before any
 * chunk is produced. Failures happening mid-stream are rethrown as-is (the
 * client may already have received partial output).
 */
final readonly class LlmRetry
{
    private const int RETRY_DELAY_MS = 1000;

    public function __construct(
        private PlatformInterface $platform,
        private string $model,
        private int $maxRetries = 2,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * @return \Generator<TextDelta|ToolCallComplete>
     */
    public function stream(MessageBag $messageBag, array $options): \Generator
    {
        for ($attempt = 1;; $attempt++) {
            $produced = false;

            try {
                $result = $this->platform->invoke($this->model, $messageBag, $options);
                $this->logger?->info('LlmService invoke called (stream)', [
                    'model' => $this->model,
                    'attempt' => $attempt,
                ]);

                foreach ($result->asStream() as $delta) {
                    if (!($delta instanceof TextDelta || $delta instanceof ToolCallComplete)) {
                        continue;
                    }

                    $produced = true;
                    yield $delta;
                }

                return;
            } catch (\Throwable $e) {
                if ($produced || $attempt > $this->maxRetries) {
                    throw $e;
                }

                $this->logger?->warning('LlmService invoke failed, retrying', [
                    'model' => $this->model,
                    'attempt' => $attempt,
                    'maxRetries' => $this->maxRetries,
                    'error' => $e->getMessage(),
                ]);

                usleep(self::RETRY_DELAY_MS * 1000);
            }
        }
    }
}
