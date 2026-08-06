<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ContentModerationService;
use App\Service\LlmService;
use PHPUnit\Framework\TestCase;

final class ContentModerationServiceTest extends TestCase
{
    public function testCleanMessage(): void
    {
        $service = new ContentModerationService();
        $result = $service->moderate('Bonjour à tous, comment allez-vous ?');

        static::assertFalse($result->isFlagged());
        static::assertFalse($result->isMasked());
        static::assertSame('clean', $result->getStatus());
    }

    public function testDetectOpenAiSecretKey(): void
    {
        $service = new ContentModerationService();
        $secretMessage = 'Voici ma clé API pour tester : sk-proj-123456789012345678901234567890';
        $result = $service->moderate($secretMessage);

        static::assertTrue($result->isFlagged());
        static::assertTrue($result->isMasked());
        static::assertSame('masked', $result->getStatus());
        static::assertStringContainsString('[SECRET MASQUÉ]', $result->getMaskedContent() ?? '');
        static::assertSame($secretMessage, $result->getOriginalContent());
    }

    public function testDetectAwsKey(): void
    {
        $service = new ContentModerationService();
        $secretMessage = 'Mon accès AWS : AKIAIOSFODNN7EXAMPLE';
        $result = $service->moderate($secretMessage);

        static::assertTrue($result->isFlagged());
        static::assertTrue($result->isMasked());
        static::assertStringContainsString('[SECRET MASQUÉ]', $result->getMaskedContent() ?? '');
    }

    public function testDetectJwtToken(): void
    {
        $service = new ContentModerationService();
        $jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIiwiaWF0IjoxNTE2MjM5MDIyfQ.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';
        $result = $service->moderate('Voici mon token Bearer ' . $jwt);

        static::assertTrue($result->isFlagged());
        static::assertTrue($result->isMasked());
    }

    public function testToxicityWithLlmService(): void
    {
        $llmService = $this->createMock(LlmService::class);
        $llmService->expects($this->once())
            ->method('generateText')
            ->willReturn('TOXIC');

        $service = new ContentModerationService($llmService);
        $result = $service->moderate('Message agressif et insultant');

        static::assertTrue($result->isFlagged());
        static::assertFalse($result->isMasked());
        static::assertSame('flagged', $result->getStatus());
        static::assertStringContainsString('toxique', $result->getReason() ?? '');
    }
}


