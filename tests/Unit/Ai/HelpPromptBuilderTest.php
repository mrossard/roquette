<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\DocumentContextBuilder;
use App\Ai\HelpPromptBuilder;
use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\ChannelRepository;
use App\Repository\MessageRepository;
use App\Service\DocChunker;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\RetrieverInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class HelpPromptBuilderTest extends TestCase
{
    private function createDocumentContextBuilder(array $documents = []): DocumentContextBuilder
    {
        $retriever = $this->createStub(RetrieverInterface::class);
        $retriever->method('retrieve')->willReturn($documents);

        $parameterBag = $this->createStub(ParameterBagInterface::class);
        $parameterBag->method('get')->willReturn('/tmp');

        return new DocumentContextBuilder(
            $retriever,
            new DocChunker(),
            new NullLogger(),
            $parameterBag,
        );
    }

    public function testBuildDefaultPrompts(): void
    {
        $metadata = new Metadata(['_title' => 'Salons', '_text' => 'Doc: Allez dans le menu...']);
        $doc = new TextDocument('doc-1', 'Doc: Allez dans le menu...', $metadata);

        $docBuilder = $this->createDocumentContextBuilder([$doc]);

        $channelRepo = $this->createStub(ChannelRepository::class);
        $messageRepo = $this->createStub(MessageRepository::class);

        $builder = new HelpPromptBuilder($docBuilder, $channelRepo, $messageRepo, 10);

        $workspace = new Workspace();
        $workspace->setName('Mon Workspace');

        $channel = new Channel();
        $channel->setName('Général');
        $channel->setSlug('general');
        $channel->setWorkspace($workspace);

        [$prompt, $systemPrompt] = $builder->buildDefaultPrompts(
            'Comment créer un salon ?',
            [$channel],
            $workspace,
            $channel,
        );

        self::assertSame('Comment créer un salon ?', $prompt);
        self::assertStringContainsString('Mon Workspace', $systemPrompt);
        self::assertStringContainsString('CANAL ACTUEL : L\'utilisateur est actuellement dans le canal "Général"', $systemPrompt);
        self::assertStringContainsString('Doc: Allez dans le menu...', $systemPrompt);
        self::assertStringContainsString('create_poll', $systemPrompt);
    }

    public function testAddConversationContext(): void
    {
        $docBuilder = $this->createDocumentContextBuilder();
        $channelRepo = $this->createMock(ChannelRepository::class);
        $messageRepo = $this->createMock(MessageRepository::class);

        $channel = new Channel();
        $channel->setSlug('general');

        $channelRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['slug' => 'general'])
            ->willReturn($channel);

        $user = new User();
        $user->setUsername('alice');

        $msg1 = new Message();
        $msg1->setContent('Premier message');
        $msg1->setAuthor($user);

        $msg2 = new Message();
        $msg2->setContent('Question actuelle');
        $msg2->setAuthor($user);

        $messageRepo->expects($this->once())
            ->method('findLatestInChannel')
            ->with($channel, 11)
            ->willReturn([$msg2, $msg1]); // findLatestInChannel returns DESC

        $builder = new HelpPromptBuilder($docBuilder, $channelRepo, $messageRepo, 10);

        $result = $builder->addConversationContext('Prompt de base', 'general', 'Question actuelle');

        self::assertStringContainsString('Historique de la conversation', $result);
        self::assertStringContainsString('alice: Premier message', $result);
        self::assertStringNotContainsString('Question actuelle', $result); // Current question is filtered
        self::assertStringContainsString('Prompt de base', $result);
    }

    public function testAddConversationContextExcludesConfirmationPrompts(): void
    {
        $docBuilder = $this->createDocumentContextBuilder();
        $channelRepo = $this->createStub(ChannelRepository::class);
        $messageRepo = $this->createStub(MessageRepository::class);

        $channel = new Channel();
        $channel->setSlug('general');

        $channelRepo->method('findOneBy')->willReturn($channel);

        $robotUser = new User();
        $robotUser->setUsername(User::ROBOT_USERNAME);

        $msgConfirm = new Message();
        $msgConfirm->setContent('Veuillez confirmer cette action en cliquant sur le bouton de confirmation affiché ci-dessus ou simplement en répondant ok.');
        $msgConfirm->setAuthor($robotUser);

        $msgOk = new Message();
        $msgOk->setContent('Message utile précédent');
        $msgOk->setAuthor($robotUser);

        $messageRepo->method('findLatestInChannel')->willReturn([$msgConfirm, $msgOk]);

        $builder = new HelpPromptBuilder($docBuilder, $channelRepo, $messageRepo, 10);

        $result = $builder->addConversationContext('Prompt', 'general', 'Autre question');

        self::assertStringContainsString('Message utile précédent', $result);
        self::assertStringNotContainsString('bouton de confirmation', $result);
    }
}
