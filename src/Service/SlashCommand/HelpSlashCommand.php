<?php

declare(strict_types=1);

namespace App\Service\SlashCommand;

use App\Ai\PendingConfirmationService;
use App\Entity\Channel;
use App\Entity\User;
use App\Message\LlmQueryMessage;
use App\Service\LlmRateLimiter;
use App\Service\SlashCommandResult;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final readonly class HelpSlashCommand implements SlashCommandInterface
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private TranslatorInterface $translator,
        private Environment $twig,
        private LlmRateLimiter $llmRateLimiter,
        private RateLimitedOobRenderer $rateLimitedRenderer,
        private PendingConfirmationService $pendingConfirmationService,
    ) {}

    public function getName(): string
    {
        return 'help';
    }

    public function processPreview(string $args): ?string
    {
        return null;
    }

    public function execute(string $args, Channel $channel, User $user, ?int $workspaceId = null): SlashCommandResult
    {
        $helpMessageId = 'help-' . uniqid();

        $token = $this->pendingConfirmationService->getPendingConfirmation($user, $channel->getSlug());
        if ($token !== null && $this->pendingConfirmationService->isConfirmation($args, $token, $user)) {
            if ($this->pendingConfirmationService->executeConfirmation($token, $user)) {
                $formHtml = $this->twig->render('dashboard/_input_form.html.twig', [
                    'activeChannel' => $channel,
                ]);

                return SlashCommandResult::handled(new Response($formHtml));
            }
        }

        $formHtml = $this->twig->render('dashboard/_input_form.html.twig', [
            'activeChannel' => $channel,
        ]);

        if ($args === '') {
            $oobHtml = $this->twig->render('dashboard/_help_message_oob.html.twig', [
                'answer' => $this->translator->trans(
                    'Veuillez poser une question. Exemple : `/help Comment créer un sondage ?`',
                ),
                'question' => '',
                'helpMessageId' => $helpMessageId,
                'activeChannel' => $channel,
                'timestamp' => new \DateTime(),
            ]);

            return SlashCommandResult::handled(new Response($formHtml . "\n" . $oobHtml));
        }

        if (!$this->llmRateLimiter->consume($user)) {
            return SlashCommandResult::handled($this->rateLimitedRenderer->render($helpMessageId, $args, $channel));
        }

        $this->messageBus->dispatch(
            new LlmQueryMessage(
                $args,
                $user->getId(),
                $channel->getSlug(),
                $helpMessageId,
                \App\Ai\AssistantIntent::Help,
                workspaceId: $workspaceId,
            ),
        );

        $oobHtml = $this->twig->render('dashboard/_help_message_oob.html.twig', [
            'answer' => null,
            'question' => $args,
            'helpMessageId' => $helpMessageId,
            'activeChannel' => $channel,
            'timestamp' => new \DateTime(),
        ]);

        return SlashCommandResult::handled(new Response($formHtml . "\n" . $oobHtml));
    }
}
