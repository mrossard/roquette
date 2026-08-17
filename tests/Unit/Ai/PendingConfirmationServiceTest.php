<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\PendingConfirmationService;
use App\Ai\Tool\AiToolInterface;
use App\Ai\ToolActionSigner;
use App\Ai\ToolRegistry;
use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ChannelRepository;
use App\Repository\MessageRepository;
use App\Service\LlmRateLimiter;
use App\Service\LlmService;
use App\Service\MercurePublisher;
use App\Service\MessageFormatter;
use App\Service\RobotDmMessageService;
use App\Service\RobotUserProvider;
use Doctrine\ORM\EntityManagerInterface;
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

    private function createFakeTool(): AiToolInterface
    {
        return new class implements AiToolInterface {
            public bool $executed = false;

            public function getName(): string
            {
                return 'confirm_tool';
            }

            public function getDescription(): string
            {
                return 'Fake tool requiring confirmation';
            }

            public function getParametersSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function requiresConfirmation(): bool
            {
                return true;
            }

            public function __invoke(string $channelSlug = 'general', ?int $authorUserId = null, ?int $workspaceId = null): string
            {
                $this->executed = true;

                return 'Side-effect done';
            }
        };
    }

    private function createService(
        ?ToolRegistry $toolRegistry = null,
        ?HubInterface $hub = null,
        ?Environment $twig = null,
        ?MessageFormatter $messageFormatter = null,
        ?ArrayAdapter $cache = null,
        ?LlmRateLimiter $llmRateLimiter = null,
        ?LlmService $llmService = null,
        ?RobotDmMessageService $robotDmMessageService = null,
        ?MercurePublisher $mercurePublisher = null,
    ): PendingConfirmationService {
        $bus = $this->createStub(\Symfony\Component\Messenger\MessageBusInterface::class);
        $translator = $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class);
        $hubMock = $hub ?? $this->createStub(HubInterface::class);

        return new PendingConfirmationService(
            $this->signer,
            $toolRegistry ?? new ToolRegistry([]),
            $hubMock,
            $twig ?? $this->createStub(Environment::class),
            $messageFormatter ?? $this->createStub(MessageFormatter::class),
            $cache ?? new ArrayAdapter(),
            $llmRateLimiter ?? $this->createStub(LlmRateLimiter::class),
            $llmService ?? $this->createStub(LlmService::class),
            $robotDmMessageService ?? $this->createStub(RobotDmMessageService::class),
            $mercurePublisher ?? new MercurePublisher($bus, 'roquette', $translator, $hubMock),
        );
    }

    public function testIsConfirmationText(): void
    {
        $service = $this->createService();

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
        $llmService = $this->createMock(LlmService::class);
        $llmService->expects($this->once())
            ->method('generateText')
            ->willReturn('YES');

        $service = $this->createService(llmService: $llmService);

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
        $service = $this->createService(cache: $cache);

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

        $fakeTool = $this->createFakeTool();
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
        $service = $this->createService(
            toolRegistry: $toolRegistry,
            hub: $hub,
            twig: $twig,
            messageFormatter: $formatter,
            cache: $cache,
            llmRateLimiter: $llmRateLimiter,
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

        $channel = new Channel();
        $channel->setSlug('dm-' . User::ROBOT_USERNAME . '-alice');

        $robotMsg = new Message();
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

        $fakeTool = $this->createFakeTool();
        $toolRegistry = new ToolRegistry([$fakeTool]);

        $hub = $this->createStub(HubInterface::class);
        $twig = $this->createStub(Environment::class);
        $formatter = $this->createStub(MessageFormatter::class);

        $llmRateLimiter = $this->createStub(LlmRateLimiter::class);
        $llmRateLimiter->method('consumeConfirmation')->willReturn(true);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $robotUserProvider = $this->createMock(RobotUserProvider::class);
        $robotUserProvider->method('isRobotDmChannel')->willReturn(true);
        $robotUserProvider->method('isRobotUser')->willReturn(true);

        $channelRepo = $this->createMock(ChannelRepository::class);
        $channelRepo->expects($this->once())->method('findOneBy')->with(['slug' => $channel->getSlug()])->willReturn($channel);

        $messageRepo = $this->createMock(MessageRepository::class);
        $messageRepo->expects($this->once())->method('findLatestInChannel')->with($channel, 5)->willReturn([$robotMsg]);

        $robotDmMessageService = new RobotDmMessageService(
            $entityManager,
            $channelRepo,
            $messageRepo,
            $robotUserProvider,
        );

        $service = $this->createService(
            toolRegistry: $toolRegistry,
            hub: $hub,
            twig: $twig,
            messageFormatter: $formatter,
            llmRateLimiter: $llmRateLimiter,
            robotDmMessageService: $robotDmMessageService,
        );

        $result = $service->executeConfirmation($token, $user);

        static::assertTrue($result);
        static::assertSame('Side-effect done', $robotMsg->getContent());
    }
}
