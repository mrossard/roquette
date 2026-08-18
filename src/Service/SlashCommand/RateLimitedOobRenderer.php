<?php

declare(strict_types=1);

namespace App\Service\SlashCommand;

use App\Entity\Channel;
use App\Service\LlmRateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

readonly class RateLimitedOobRenderer
{
    public function __construct(
        private Environment $twig,
        private TranslatorInterface $translator,
    ) {}

    public function render(
        string $helpMessageId,
        string $question,
        Channel $channel,
        string $translationKey = LlmRateLimiter::MESSAGE_KEY,
    ): Response {
        $oobHtml = $this->twig->render('dashboard/_help_message_oob.html.twig', [
            'answer' => $this->translator->trans($translationKey),
            'question' => $question,
            'helpMessageId' => $helpMessageId,
            'activeChannel' => $channel,
            'timestamp' => new \DateTime(),
        ]);

        $formHtml = $this->twig->render('dashboard/_input_form.html.twig', [
            'activeChannel' => $channel,
        ]);

        return new Response($formHtml . "\n" . $oobHtml, Response::HTTP_TOO_MANY_REQUESTS);
    }
}
