<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto\Webhook;

use App\Dto\Webhook\CreateWebhookDto;
use App\Dto\Webhook\IncomingWebhookPayloadDto;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class WebhookDtoTest extends TestCase
{
    #[Test]
    public function incomingPayloadWithTextAndSlackKeys(): void
    {
        $json = json_encode([
            'text' => 'Deploy completed',
            'username' => 'CI Bot',
            'avatar_url' => 'https://example.com/bot.png',
        ], JSON_THROW_ON_ERROR);

        $request = Request::create(
            '/api/webhooks/incoming/token',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $json,
        );

        $dto = IncomingWebhookPayloadDto::fromRequest($request);

        static::assertSame('Deploy completed', $dto->content);
        static::assertSame('CI Bot', $dto->customAuthorName);
        static::assertSame('https://example.com/bot.png', $dto->customAuthorAvatar);
        static::assertFalse($dto->isPayloadTooLarge);
        static::assertTrue($dto->hasValidContent());
    }

    #[Test]
    public function incomingPayloadWithContentAndCustomAuthorKeys(): void
    {
        $json = json_encode([
            'content' => 'Server status alert',
            'customAuthorName' => 'Monitor Bot',
            'customAuthorAvatar' => 'https://example.com/alert.png',
        ], JSON_THROW_ON_ERROR);

        $request = Request::create(
            '/api/webhooks/incoming/token',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $json,
        );

        $dto = IncomingWebhookPayloadDto::fromRequest($request);

        static::assertSame('Server status alert', $dto->content);
        static::assertSame('Monitor Bot', $dto->customAuthorName);
        static::assertSame('https://example.com/alert.png', $dto->customAuthorAvatar);
        static::assertFalse($dto->isPayloadTooLarge);
        static::assertTrue($dto->hasValidContent());
    }

    #[Test]
    public function incomingPayloadEmptyOrMissingContent(): void
    {
        $json = json_encode(['username' => 'Ghost'], JSON_THROW_ON_ERROR);

        $request = Request::create(
            '/api/webhooks/incoming/token',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $json,
        );

        $dto = IncomingWebhookPayloadDto::fromRequest($request);

        static::assertNull($dto->content);
        static::assertSame('Ghost', $dto->customAuthorName);
        static::assertNull($dto->customAuthorAvatar);
        static::assertFalse($dto->hasValidContent());
        static::assertFalse($dto->isPayloadTooLarge);
    }

    #[Test]
    public function incomingPayloadTooLarge(): void
    {
        $bigContent = str_repeat('A', IncomingWebhookPayloadDto::MAX_PAYLOAD_SIZE + 10);
        $json = json_encode(['text' => $bigContent], JSON_THROW_ON_ERROR);

        $request = Request::create(
            '/api/webhooks/incoming/token',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $json,
        );

        $dto = IncomingWebhookPayloadDto::fromRequest($request);

        static::assertTrue($dto->isPayloadTooLarge);
        static::assertFalse($dto->hasValidContent());
    }

    #[Test]
    public function incomingPayloadInvalidJson(): void
    {
        $request = Request::create(
            '/api/webhooks/incoming/token',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            'not-a-valid-json',
        );

        $dto = IncomingWebhookPayloadDto::fromRequest($request);

        static::assertNull($dto->content);
        static::assertFalse($dto->hasValidContent());
    }

    #[Test]
    public function createWebhookDtoFromRequestValidAndInvalid(): void
    {
        $validRequest = new Request(request: ['name' => '  GitHub Action  ']);
        $validDto = CreateWebhookDto::fromRequest($validRequest);
        static::assertSame('GitHub Action', $validDto->name);
        static::assertTrue($validDto->isValid());

        $invalidRequest = new Request(request: ['name' => '   ']);
        $invalidDto = CreateWebhookDto::fromRequest($invalidRequest);
        static::assertSame('', $invalidDto->name);
        static::assertFalse($invalidDto->isValid());
    }
}
