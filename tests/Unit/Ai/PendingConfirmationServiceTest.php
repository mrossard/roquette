<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\PendingConfirmationService;
use App\Ai\ToolActionSigner;
use App\Ai\ToolRegistry;
use App\Entity\User;
use App\Service\MessageFormatter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Twig\Environment;

#[AllowMockObjectsWithoutExpectations]
class PendingConfirmationServiceTest extends TestCase
{
    private ToolActionSigner $signer;

    protected function setUp(): void
    {
        $this->signer = new ToolActionSigner('secret-key-123');
    }

    public function testIsConfirmationText(): void
    {
        $service = new PendingConfirmationService(
            $this->signer,
            new ToolRegistry([]),
            $this->createMock(HubInterface::class),
            $this->createMock(Environment::class),
            $this->createMock(MessageFormatter::class),
            new ArrayAdapter(),
            $this->createMock(RateLimiterFactoryInterface::class),
        );

        $affirmative = [
            'ok', 'OK', 'ok!', 'okay', 'K', 'oui', 'OUI', 'Oui stp',
            'd\'accord', 'daccord', 'dac', 'd\'ac', 'confirm', 'confirmer',
            'je confirme', 'valider', 'je valide', 'go', 'ok go',
            'c\'est bon', 'ca marche', 'ça marche', 'super', 'parfait',
        ];

        foreach ($affirmative as $text) {
            static::assertTrue($service->isConfirmationText($text), "Expected '{$text}' to be recognized as confirmation.");
        }

        $nonAffirmative = [
            'non', 'non merci', 'annuler', 'pas maintenant',
            'quel temps fait-il ?', 'comment vas-tu', '',
        ];

        foreach ($nonAffirmative as $text) {
            static::assertFalse($service->isConfirmationText($text), "Expected '{$text}' NOT to be recognized as confirmation.");
        }
    }

    public function testIsConfirmationWithLlmFallback(): void
    {
        $llmService = $this->createMock(\App\Service\LlmService::class);
        $llmService->expects($this->once())
            ->method('generateText')
            ->willReturn('YES');

        $service = new PendingConfirmationService(
            $this->signer,
            new ToolRegistry([]),
            $this->createMock(HubInterface::class),
            $this->createMock(Environment::class),
            $this->createMock(MessageFormatter::class),
            new ArrayAdapter(),
            $this->createMock(RateLimiterFactoryInterface::class),
            'roquette',
            $llmService,
        );

        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, 42);

        $token = $this->signer->sign([
            'tool' => 'create_poll',
            'args' => ['question' => 'Formule ?'],
            'uid' => 42,
        ]);

        static::assertTrue($service->isConfirmation('Absolument c\'est une super idée lance le sondage', $token, $user));
    }

    public function testSaveGetAndClearPendingConfirmation(): void
    {
        $cache = new ArrayAdapter();
        $service = new PendingConfirmationService(
            $this->signer,
            new ToolRegistry([]),
            $this->createMock(HubInterface::class),
            $this->createMock(Environment::class),
            $this->createMock(MessageFormatter::class),
            $cache,
            $this->createMock(RateLimiterFactoryInterface::class),
        );

        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, 42);

        static::assertNull($service->getPendingConfirmation($user, 'general'));

        $token = 'token_abc_123';
        $service->savePendingConfirmation($user, $token, 'general');

        static::assertSame($token, $service->getPendingConfirmation($user, 'general'));
        static::assertSame($token, $service->getPendingConfirmation($user));

        $service->clearPendingConfirmation($user, 'general');
        static::assertNull($service->getPendingConfirmation($user, 'general'));
    }

    public function testExecuteConfirmationExecutesToolAndPublishesMercureUpdate(): void
    {
        $user = new User();
        $user->setUsername('manu');
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, 42);

        $payload = [
            'tool' => 'confirm_tool',
            'args' => ['channelSlug' => 'general'],
            'uid' => 42,
            'ws' => 1,
            'helpMessageId' => 'help-999',
            'channelSlug' => 'general',
        ];

        $token = $this->signer->sign($payload);

        $fakeTool = new ConfirmationFakeTool();
        $toolRegistry = new ToolRegistry([$fakeTool]);

        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->once())
            ->method('publish')
            ->with(static::isInstanceOf(Update::class));

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->willReturn('<div>Sondage créé HTML</div>');

        $formatter = $this->createMock(MessageFormatter::class);
        $formatter->method('format')->willReturn('<p>Sondage créé</p>');

        $rateLimit = $this->createMock(RateLimit::class);
        $rateLimit->method('isAccepted')->willReturn(true);

        $limiter = $this->createMock(LimiterInterface::class);
        $limiter->method('consume')->willReturn($rateLimit);

        $rateLimiterFactory = $this->createMock(RateLimiterFactoryInterface::class);
        $rateLimiterFactory->method('create')->willReturn($limiter);

        $cache = new ArrayAdapter();
        $service = new PendingConfirmationService(
            $this->signer,
            $toolRegistry,
            $hub,
            $twig,
            $formatter,
            $cache,
            $rateLimiterFactory,
            'roquette',
        );

        $service->savePendingConfirmation($user, $token, 'general');

        $result = $service->executeConfirmation($token, $user);

        static::assertTrue($result);
        static::assertTrue($fakeTool->executed);
        static::assertNull($service->getPendingConfirmation($user, 'general'));
    }
}
