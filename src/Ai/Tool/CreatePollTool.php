<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use App\Entity\Message;
use App\Entity\Poll;
use App\Entity\PollOption;
use App\Repository\ChannelRepository;
use App\Repository\UserRepository;
use App\Service\MercurePublisher;
use App\Service\MessageFormatter;
use App\Service\MessageRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Tool\Attribute\AsTool;
use Twig\Environment;

#[AsTool(
    name: 'create_poll',
    description: 'Crée un sondage interactif dans un canal spécifié de l\'application Roquette.'
)]
final readonly class CreatePollTool
{
    public function __construct(
        private EntityManagerInterface $em,
        private ChannelRepository $channelRepository,
        private UserRepository $userRepository,
        private MercurePublisher $mercurePublisher,
        private MessageFormatter $messageFormatter,
        private Environment $twig,
        private MessageRenderer $messageRenderer,
    ) {}

    /**
     * @param string $channelSlug Le slug du canal où publier le sondage (ex: "general").
     * @param string $question La question du sondage.
     * @param array<string> $options La liste des choix possibles (ex: ["Choix A", "Choix B"]).
     * @param bool $allowMultiple Indique si l'utilisateur peut sélectionner plusieurs choix.
     * @param int|null $authorUserId ID de l'auteur du sondage (optionnel).
     */
    public function __invoke(
        string $channelSlug,
        string $question,
        array $options,
        bool $allowMultiple = false,
        ?int $authorUserId = null,
    ): string {
        $channel = $this->channelRepository->findOneBy(['slug' => strtolower($channelSlug)]);
        if (!$channel) {
            $channels = $this->channelRepository->findAll();
            foreach ($channels as $c) {
                if (
                    strtolower($c->getSlug()) === strtolower($channelSlug)
                    || strtolower($c->getName()) === strtolower($channelSlug)
                    || str_contains(strtolower($c->getName()), strtolower($channelSlug))
                ) {
                    $channel = $c;
                    break;
                }
            }
        }

        if (!$channel) {
            return sprintf("Impossible de créer le sondage : le canal '%s' n'existe pas.", $channelSlug);
        }

        $author = null;
        if ($authorUserId !== null) {
            $author = $this->userRepository->find($authorUserId);
        }
        if (!$author) {
            $author = $this->userRepository->findOneBy(['username' => 'robot-roquette'])
                ?? $this->userRepository->findOneBy([]);
        }

        // Fallback: If LLM failed to split options properly into an array, attempt regex extraction from question/prompt
        if (count($options) < 2) {
            $parsedOptions = [];
            // Try splitting by ' ou ', ',', ' / ', ' vs '
            $parts = preg_split('/\s+(?:ou|or|vs|\/)\s+|,\s*/i', $question);
            if ($parts && count($parts) >= 2) {
                foreach ($parts as $p) {
                    $cleaned = trim(preg_replace('/^(?:plutôt|plutot|choix|option|sondage|\?|:)+/i', '', trim($p)));
                    $cleaned = trim(preg_replace('/\?+$/', '', $cleaned));
                    if ($cleaned !== '') {
                        $parsedOptions[] = ucfirst($cleaned);
                    }
                }
            }

            if (count($parsedOptions) >= 2) {
                $options = array_values(array_unique($parsedOptions));
                $question = 'Quel est votre choix ?';
            }
        }

        if (count($options) < 2) {
            return 'Impossible de créer un sondage avec moins de 2 options.';
        }

        if (!$allowMultiple && preg_match('/plusieurs\s+(?:choix|réponses|options)|choix\s+multiples/i', $question)) {
            $allowMultiple = true;
        }

        $message = new Message();
        $message->setChannel($channel);
        $message->setAuthor($author);
        $rawText = '📊 **Sondage** : ' . $question;
        $message->setContent($rawText);
        $message->setFormattedContent($this->messageFormatter->format($rawText));
        $message->setCreatedAt(new \DateTimeImmutable());

        $poll = new Poll();
        $poll->setQuestion($question);
        $poll->setAllowMultiple($allowMultiple);
        $poll->setMessage($message);
        $message->setPoll($poll);

        $this->em->persist($message);
        $this->em->persist($poll);

        foreach ($options as $index => $optionText) {
            $opt = new PollOption();
            $opt->setText(trim($optionText));
            $opt->setPosition($index + 1);
            $opt->setPoll($poll);
            $poll->addOption($opt);
            $this->em->persist($opt);
        }

        $this->em->flush();

        // Render standard feed item wrapped with OOB insertion for live feed
        $renderedHtml = '<div hx-swap-oob="beforeend:#live-feed">' . $this->messageRenderer->renderFeedItem($message, [
            'no_fade' => false,
        ]) . '</div>';

        // Broadcast via Mercure SSE to all connected channel clients
        $this->mercurePublisher->publishNewMessage(
            $channel,
            $message,
            $author,
            $rawText,
            $renderedHtml,
        );

        return sprintf("Le sondage '%s' avec %d options a été publié dans le canal #%s.", $question, count($options), $channel->getName());
    }
}
