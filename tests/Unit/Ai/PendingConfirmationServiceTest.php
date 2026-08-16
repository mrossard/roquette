<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\PendingConfirmationService;
use App\Ai\ToolActionSigner;
use App\Ai\ToolRegistry;
use App\Entity\User;
use App\Service\LlmRateLimiter;
use App\Service\MessageFormatter;
use App\Service\RobotUserProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
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
            $this->createMock(RobotUserProvider::class),
            new ArrayAdapter(),
            $this->createMock(LlmRateLimiter::class),
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
            $this->createMock(RobotUserProvider::class),
            new ArrayAdapter(),
            $this->createMock(LlmRateLimiter::class),
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
            $this->createMock(RobotUserProvider::class),
            $cache,
            $this->createMock(LlmRateLimiter::class),
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

        $llmRateLimiter = $this->createMock(LlmRateLimiter::class);
        $llmRateLimiter->method('consumeConfirmation')->willReturn(true);

        $cache = new ArrayAdapter();
        $service = new PendingConfirmationService(
            $this->signer,
            $toolRegistry,
            $hub,
            $twig,
            $formatter,
            $this->createMock(RobotUserProvider::class),
            $cache,
            $llmRateLimiter,
            'roquette',
        );

        $service->savePendingConfirmation($user, $token, 'general');

        $result = $service->executeConfirmation($token, $user);

        static::assertTrue($result);
        static::assertTrue($fakeTool->executed);
        static::assertNull($service->getPendingConfirmation($user, 'general'));
    }

    public function testExecuteConfirmationUpdatesRobotDmMessageInDatabase(): void
    {
        $user = new User();
        $user->setUsername('alice');
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, 42);

        $robotUser = new User();
        $robotUser->setUsername(User::ROBOT_USERNAME);

        $channel = new \App\Entity\Channel();
        $channel->setSlug('dm-' . User::ROBOT_USERNAME . '-alice');

        $robotMsg = new \App\Entity\Message();
        $robotMsg->setAuthor($robotUser);
        $robotMsg->setChannel($channel);
        $robotMsg->setContent('Veuillez confirmer cette action en cliquant sur le bouton de confirmation...');

        $payload = [
            'tool' => 'confirm_tool',
            'args' => ['channelSlug' => 'general'],
            'uid' => 42,
            'channelSlug' => $channel->getSlug(),
            'helpMessageId' => 'help-456',
        ];
        $token = $this->signer->sign($payload);

        $fakeTool = new ConfirmationFakeTool();
        $toolRegistry = new ToolRegistry([$fakeTool]);

        $hub = $this->createStub(HubInterface::class);
        $twig = $this->createStub(Environment::class);
        $formatter = $this->createStub(MessageFormatter::class);

        $llmRateLimiter = $this->createStub(LlmRateLimiter::class);
        $llmRateLimiter->method('consumeConfirmation')->willReturn(true);

        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $robotUserProvider = $this->createMock(RobotUserProvider::class);
        $robotUserProvider->method('isRobotDmChannel')->willReturn(true);
        $robotUserProvider->method('isRobotUser')->willReturn(true);

        $channelRepo = $this->createMock(\App\Repository\ChannelRepository::class);
        $channelRepo->expects($this->once())->method('findOneBy')->with(['slug' => $channel->getSlug()])->willReturn($channel);

        $messageRepo = $this->createMock(\App\Repository\MessageRepository::class);
        $messageRepo->expects($this->once())->method('findLatestInChannel')->with($channel, 5)->willReturn([$robotMsg]);

        $service = new PendingConfirmationService(
            $this->signer,
            $toolRegistry,
            $hub,
            $twig,
            $formatter,
            $robotUserProvider,
            new ArrayAdapter(),
            $llmRateLimiter,
            'roquette',
            null,
            $entityManager,
            $channelRepo,
            $messageRepo,
        );

        $result = $service->executeConfirmation($token, $user);

        static::assertTrue($result);
        static::assertSame('Side-effect done', $robotMsg->getContent());
    }
}
