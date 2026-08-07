<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\LlmService;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\ResultConverterInterface;

class LlmServiceTest extends TestCase
{
    public function testGenerateTextStreamStreamsAndYieldsText(): void
    {
        $platform = $this->createMock(PlatformInterface::class);
        $resultConverter = $this->createStub(ResultConverterInterface::class);
        $rawResult = $this->createStub(RawResultInterface::class);

        $generator = (static function () {
            yield new TextDelta('Hello ');
            yield new TextDelta('world!');
        })();

        $streamResult = new StreamResult($generator);
        $resultConverter->method('convert')->willReturn($streamResult);

        $deferredResult = new DeferredResult($resultConverter, $rawResult);

        $platform
            ->expects($this->once())
            ->method('invoke')
            ->with(
                'test-model',
                static::isInstanceOf(MessageBag::class),
                static::callback(static fn($opts) => ($opts['stream'] ?? false) === true),
            )
            ->willReturn($deferredResult);

        $llmService = new LlmService($platform, 'test-model', 'System prompt');
        $generatorResult = $llmService->generateTextStream('test prompt');

        $chunks = iterator_to_array($generatorResult);
        static::assertSame(['Hello ', 'world!'], $chunks);
    }

    public function testGenerateTextUsesStreamingAndReturnsConcatenatedText(): void
    {
        $platform = $this->createMock(PlatformInterface::class);
        $resultConverter = $this->createStub(ResultConverterInterface::class);
        $rawResult = $this->createStub(RawResultInterface::class);

        $generator = (static function () {
            yield new TextDelta('Hello ');
            yield new TextDelta('world!');
        })();

        $streamResult = new StreamResult($generator);
        $resultConverter->method('convert')->willReturn($streamResult);

        $deferredResult = new DeferredResult($resultConverter, $rawResult);

        $platform
            ->expects($this->once())
            ->method('invoke')
            ->with(
                'test-model',
                static::isInstanceOf(MessageBag::class),
                static::callback(static fn($opts) => ($opts['stream'] ?? false) === true),
            )
            ->willReturn($deferredResult);

        $llmService = new LlmService($platform, 'test-model', 'System prompt');
        $text = $llmService->generateText('test prompt');

        static::assertSame('Hello world!', $text);
    }

    public function testChatUsesStreamingAndReturnsConcatenatedText(): void
    {
        $platform = $this->createMock(PlatformInterface::class);
        $resultConverter = $this->createStub(ResultConverterInterface::class);
        $rawResult = $this->createStub(RawResultInterface::class);

        $generator = (static function () {
            yield new TextDelta('Chat ');
            yield new TextDelta('response');
        })();

        $streamResult = new StreamResult($generator);
        $resultConverter->method('convert')->willReturn($streamResult);

        $deferredResult = new DeferredResult($resultConverter, $rawResult);

        $messageBag = new MessageBag();

        $platform
            ->expects($this->once())
            ->method('invoke')
            ->with('test-model', $messageBag, static::callback(static fn($opts) => ($opts['stream'] ?? false) === true))
            ->willReturn($deferredResult);

        $llmService = new LlmService($platform, 'test-model', 'System prompt');
        $text = $llmService->chat($messageBag);

        static::assertSame('Chat response', $text);
    }

    public function testRetriesTransientFailureBeforeStreaming(): void
    {
        $platform = $this->createMock(PlatformInterface::class);
        $invocations = 0;
        $goodDeferred = $this->makeDeferred('Hello ');

        $platform
            ->expects($this->exactly(2))
            ->method('invoke')
            ->willReturnCallback(function () use (&$invocations, $goodDeferred): DeferredResult {
                $invocations++;

                if (1 === $invocations) {
                    $failingConverter = $this->createStub(ResultConverterInterface::class);
                    $failingConverter
                        ->method('convert')
                        ->willThrowException(new \RuntimeException('transient failure'));

                    return new DeferredResult($failingConverter, $this->createStub(RawResultInterface::class));
                }

                return $goodDeferred;
            });

        $llmService = new LlmService($platform, 'test-model', 'System prompt', null, maxRetries: 2);
        $text = $llmService->generateText('test prompt');

        static::assertSame('Hello ', $text);
        static::assertSame(2, $invocations);
    }

    public function testRethrowsWhenAllRetriesAreExhausted(): void
    {
        $platform = $this->createMock(PlatformInterface::class);

        $platform->expects($this->exactly(3))->method('invoke')->willReturn($this->makeFailingDeferred());

        $llmService = new LlmService($platform, 'test-model', 'System prompt', null, maxRetries: 2);

        $this->expectException(\RuntimeException::class);
        $llmService->generateText('test prompt');
    }

    public function testDoesNotRetryAfterPartialOutputWasStreamed(): void
    {
        $platform = $this->createMock(PlatformInterface::class);
        $failingConverter = $this->createStub(ResultConverterInterface::class);
        $failingConverter
            ->method('convert')
            ->willReturn(
                new StreamResult(
                    (static function () {
                        yield new TextDelta('partial ');
                        throw new \RuntimeException('mid-stream failure');
                    })(),
                ),
            );

        $platform
            ->expects($this->once())
            ->method('invoke')
            ->willReturn(new DeferredResult($failingConverter, $this->createStub(RawResultInterface::class)));

        $llmService = new LlmService($platform, 'test-model', 'System prompt', null, maxRetries: 2);

        $this->expectException(\RuntimeException::class);
        $llmService->generateText('test prompt');
    }

    private function makeDeferred(string $text): DeferredResult
    {
        $resultConverter = $this->createStub(ResultConverterInterface::class);
        $resultConverter
            ->method('convert')
            ->willReturn(
                new StreamResult(
                    (static function () use ($text) {
                        yield new TextDelta($text);
                    })(),
                ),
            );

        return new DeferredResult($resultConverter, $this->createStub(RawResultInterface::class));
    }

    private function makeFailingDeferred(): DeferredResult
    {
        $resultConverter = $this->createStub(ResultConverterInterface::class);
        $resultConverter->method('convert')->willThrowException(new \RuntimeException('transient failure'));

        return new DeferredResult($resultConverter, $this->createStub(RawResultInterface::class));
    }
}
