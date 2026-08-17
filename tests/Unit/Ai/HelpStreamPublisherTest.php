<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\HelpStreamPublisher;
use App\Entity\User;
use App\Service\MercurePublisher;
use App\Service\MessageFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class HelpStreamPublisherTest extends TestCase
{
    private function createMercurePublisher(): MercurePublisher
    {
        return new MercurePublisher(
            $this->createStub(MessageBusInterface::class),
            'roquette',
            $this->createStub(TranslatorInterface::class),
        );
    }

    public function testGetPersonalTopic(): void
    {
        $hub = $this->createStub(HubInterface::class);
        $formatter = $this->createStub(MessageFormatter::class);
        $twig = $this->createStub(Environment::class);

        $publisher = new HelpStreamPublisher($hub, $formatter, $twig, $this->createMercurePublisher());

        $user = new User();
        $user->setUsername('alice');

        static::assertSame('roquette/users/alice', $publisher->getPersonalTopic($user));
    }

    public function testPublishStatusFormatsMarkdownAndPublishesUpdate(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub
            ->expects($this->once())
            ->method('publish')
            ->with(static::callback(
                static fn(Update $update): bool => (
                    $update->getTopics() === ['roquette/users/alice']
                    && $update->getType() === 'help_stream_update'
                ),
            ));

        $formatter = $this->createMock(MessageFormatter::class);
        $formatter
            ->expects($this->once())
            ->method('format')
            ->with('Traitement en cours... ⏳')
            ->willReturn('<p>Traitement en cours... ⏳</p>');

        $twig = $this->createMock(Environment::class);
        $twig
            ->expects($this->once())
            ->method('render')
            ->with(
                'dashboard/_help_message_update.html.twig',
                static::callback(
                    static fn(array $context): bool => (
                        ($context['helpMessageId'] ?? null) === 'msg-123'
                        && ($context['html'] ?? null) === '<p>Traitement en cours... ⏳</p>'
                        && ($context['channelSlug'] ?? null) === 'general'
                    ),
                ),
            )
            ->willReturn('<div>rendered</div>');

        $publisher = new HelpStreamPublisher($hub, $formatter, $twig, $this->createMercurePublisher());
        $publisher->publishStatus('roquette/users/alice', 'msg-123', 'Traitement en cours... ⏳', 'general');
    }

    public function testPublishStreamTextIncludesConfirmationWhenTokenPresent(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->once())->method('publish');

        $formatter = $this->createStub(MessageFormatter::class);
        $formatter->method('format')->willReturn('<p>Voulez-vous confirmer ?</p>');

        $twig = $this->createMock(Environment::class);
        $twig
            ->expects($this->exactly(2))
            ->method('render')
            ->willReturnCallback(static function (string $template, array $context = []): string {
                if ($template === 'dashboard/_tool_confirmation.html.twig') {
                    return '<button>Confirmer</button>';
                }
                if ($template === 'dashboard/_help_message_update.html.twig') {
                    return '<div>full rendered</div>';
                }
                return '';
            });

        $publisher = new HelpStreamPublisher($hub, $formatter, $twig, $this->createMercurePublisher());
        $publisher->publishStreamText(
            'roquette/users/alice',
            'msg-123',
            '',
            'Voulez-vous confirmer ?',
            'general',
            'token-xyz',
        );
    }

    public function testPublishErrorPublishesStandardErrorMessage(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->once())->method('publish');

        $formatter = $this->createStub(MessageFormatter::class);
        $twig = $this->createMock(Environment::class);
        $twig
            ->expects($this->once())
            ->method('render')
            ->with('dashboard/_help_message_update.html.twig', static::callback(static fn(array $context): bool => str_contains(
                (string) ($context['html'] ?? ''),
                'Désolé, une erreur est survenue',
            )))
            ->willReturn('<div>error rendered</div>');

        $publisher = new HelpStreamPublisher($hub, $formatter, $twig, $this->createMercurePublisher());
        $publisher->publishError('roquette/users/alice', 'msg-123', 'general');
    }
}
