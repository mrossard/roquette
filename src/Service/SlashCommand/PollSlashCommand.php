<?php

declare(strict_types=1);

namespace App\Service\SlashCommand;

use App\Entity\Channel;
use App\Entity\User;
use App\Message\LlmQueryMessage;
use App\Service\LlmRateLimiter;
use App\Service\SlashCommandResult;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final readonly class PollSlashCommand implements SlashCommandInterface
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private TranslatorInterface $translator,
        private Environment $twig,
        private LlmRateLimiter $llmRateLimiter,
        private RateLimitedOobRenderer $rateLimitedRenderer,
    ) {}

    public function getName(): string
    {
        return 'poll';
    }

    public function processPreview(string $args): ?string
    {
        return null;
    }

    public function execute(string $args, Channel $channel, User $user, ?int $workspaceId = null): SlashCommandResult
    {
        $helpMessageId = 'poll-' . uniqid();

        $formHtml = $this->twig->render('dashboard/_input_form.html.twig', [
            'activeChannel' => $channel,
        ]);

        if ($args === '') {
            $oobHtml = $this->twig->render('dashboard/_help_message_oob.html.twig', [
                'answer' => $this->translator->trans(
                    'Veuillez indiquer le sondage à créer. Exemple : `/poll Quelle option préférez-vous entre A et B ?`',
                ),
                'question' => '',
                'helpMessageId' => $helpMessageId,
                'activeChannel' => $channel,
                'timestamp' => new \DateTime(),
            ]);

            return SlashCommandResult::handled(new Response($formHtml . "\n" . $oobHtml));
        }

        if (!$this->llmRateLimiter->consume($user)) {
            return SlashCommandResult::handled($this->rateLimitedRenderer->render($helpMessageId, '/poll ' . $args, $channel));
        }

        $prompt = sprintf(
            'Appelle IMPÉRATIVEMENT l\'outil create_poll avec channelSlug="%s". Extrais la question et les options depuis la demande suivante : "%s"',
            $channel->getSlug(),
            $args,
        );
        $this->messageBus->dispatch(
            new LlmQueryMessage($prompt, $user->getId(), $channel->getSlug(), $helpMessageId, 'sondage', workspaceId: $workspaceId),
        );

        $oobHtml = $this->twig->render('dashboard/_help_message_oob.html.twig', [
            'answer' => null,
            'question' => '/poll ' . $args,
            'helpMessageId' => $helpMessageId,
            'activeChannel' => $channel,
            'timestamp' => new \DateTime(),
        ]);

        return SlashCommandResult::handled(new Response($formHtml . "\n" . $oobHtml));
    }
}
