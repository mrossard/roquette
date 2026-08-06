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
                $this->isInstanceOf(MessageBag::class),
                $this->callback(static fn($opts) => ($opts['stream'] ?? false) === true),
            )
            ->willReturn($deferredResult);

        $llmService = new LlmService($platform, 'test-model', 'System prompt');
        $generatorResult = $llmService->generateTextStream('test prompt');

        $chunks = iterator_to_array($generatorResult);
        $this->assertSame(['Hello ', 'world!'], $chunks);
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
                $this->isInstanceOf(MessageBag::class),
                $this->callback(static fn($opts) => ($opts['stream'] ?? false) === true),
            )
            ->willReturn($deferredResult);

        $llmService = new LlmService($platform, 'test-model', 'System prompt');
        $text = $llmService->generateText('test prompt');

        $this->assertSame('Hello world!', $text);
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
            ->with('test-model', $messageBag, $this->callback(static fn($opts) => ($opts['stream'] ?? false) === true))
            ->willReturn($deferredResult);

        $llmService = new LlmService($platform, 'test-model', 'System prompt');
        $text = $llmService->chat($messageBag);

        $this->assertSame('Chat response', $text);
    }
}
