<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Channel;
use App\Entity\User;
use App\Message\LlmQueryMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class SlashCommandHandler
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly Environment $twig,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(env: 'TENOR_API_KEY')]
        #[\SensitiveParameter]
        private readonly string $tenorApiKey,
    ) {}

    /**
     * Transforms /shrug and /me commands for preview display.
     */
    public function processPreview(string $content): string
    {
        if (str_starts_with(trim($content), '/shrug')) {
            $parts = explode(' ', trim($content), 2);
            $args = ($parts[1] ?? null) !== null ? trim($parts[1]) : '';

            return ($args !== '' ? $args . ' ' : '') . '¯\_(ツ)_/¯';
        }

        if (str_starts_with(trim($content), '/me ')) {
            $parts = explode(' ', trim($content), 2);

            return '*' . trim($parts[1]) . '*';
        }

        if (trim($content) === '/me') {
            return '';
        }

        return $content;
    }

    /**
     * Processes a slash command in the context of publishing a message.
     *
     * @param string  $messageText the raw message text (may be mutated by reference for /shrug and /me)
     * @param Channel $channel     the active channel
     * @param User    $user        the current user
     *
     * @return Response|null a Response when the command was handled and should abort normal message sending,
     *                       null when the message should be sent normally (with $messageText possibly mutated)
     */
    public function process(string &$messageText, Channel $channel, User $user): ?Response
    {
        $trimmedMsg = trim($messageText);
        $parts = explode(' ', $trimmedMsg, 2);
        $command = strtolower(substr($parts[0], 1));
        $args = ($parts[1] ?? null) !== null ? trim($parts[1]) : '';

        if ($command === 'color') {
            return $this->handleColor($args, $user);
        }

        if ($command === 'help') {
            return $this->handleHelp($args, $user, $channel);
        }

        if ($command === 'shrug') {
            $messageText = ($args !== '' ? $args . ' ' : '') . '¯\_(ツ)_/¯';

            return null;
        }

        if ($command === 'me') {
            $messageText = '/me' . ($args !== '' ? ' ' . $args : '');

            return null;
        }

        if ($command === 'giphy') {
            return $this->handleGiphy($args, $channel);
        }

        return null;
    }

    private function handleColor(string $args, User $user): Response
    {
        $hueVal = $args !== '' && is_numeric($args) ? (int) $args : rand(0, 360);
        if ($hueVal < 0 || $hueVal > 360) {
            return new Response('', 400);
        }

        $user->setCustomHue($hueVal);
        $this->entityManager->flush();

        return new Response(
            $this->twig->render('dashboard/_input_form.html.twig', ['activeChannel' => null]),
            200,
            ['HX-Refresh' => 'true'],
        );
    }

    private function handleHelp(string $args, User $user, Channel $channel): Response
    {
        $helpMessageId = 'help-' . uniqid();

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
        } else {
            $this->messageBus->dispatch(
                new LlmQueryMessage($args, $user->getId(), $channel->getSlug(), $helpMessageId),
            );

            $oobHtml = $this->twig->render('dashboard/_help_message_oob.html.twig', [
                'answer' => null,
                'question' => $args,
                'helpMessageId' => $helpMessageId,
                'activeChannel' => $channel,
                'timestamp' => new \DateTime(),
            ]);
        }

        $formHtml = $this->twig->render('dashboard/_input_form.html.twig', [
            'activeChannel' => $channel,
        ]);

        return new Response($formHtml . "\n" . $oobHtml);
    }

    private function handleGiphy(string $args, Channel $channel): Response
    {
        if ($args === '') {
            $args = 'funny';
        }

        $giphyPreviews = [];
        try {
            $url = 'https://g.tenor.com/v1/search?q=' . urlencode($args) . '&key=' . $this->tenorApiKey . '&limit=6';
            $response = $this->httpClient->request('GET', $url, ['timeout' => 3]);
            $data = $response->toArray();
            if (($data['results'] ?? null) !== null && $data['results'] !== []) {
                foreach ($data['results'] as $result) {
                    if (($result['media'][0]['gif']['url'] ?? null) === null || $result['media'][0]['gif']['url'] === '') {
                        continue;
                    }

                    $giphyPreviews[] = [
                        'url' => $result['media'][0]['gif']['url'],
                        'preview' => $result['media'][0]['tinygif']['url'] ?? $result['media'][0]['gif']['preview'] ?? $result['media'][0]['gif']['url'],
                    ];
                }
            }
        } catch (\Exception) {
            $giphyPreviews = [];
        }

        return new Response(
            $this->twig->render('dashboard/_input_form.html.twig', [
                'activeChannel' => $channel,
                'giphyPreviews' => $giphyPreviews,
                'giphyQuery' => $args,
            ]),
        );
    }
}
