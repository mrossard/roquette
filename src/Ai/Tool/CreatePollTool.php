<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use App\Ai\ChannelResolver;
use App\Entity\Message;
use App\Repository\UserRepository;
use App\Service\ChannelAccessService;
use App\Service\MercurePublisher;
use App\Service\MessageFormatter;
use App\Service\MessageRenderer;
use App\Service\PollFactory;
use App\Service\RobotUserProvider;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

final readonly class CreatePollTool extends AbstractAiTool
{
    public const string NAME = 'create_poll';

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private RobotUserProvider $robotUserProvider,
        private MercurePublisher $mercurePublisher,
        private MessageFormatter $messageFormatter,
        private Environment $twig,
        private MessageRenderer $messageRenderer,
        private ChannelResolver $channelResolver,
        private ChannelAccessService $channelAccessService,
        private PollFactory $pollFactory,
    ) {}

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return "Crée un sondage interactif dans un canal spécifié de l'application Roquette.";
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'channelSlug' => ['type' => 'string', 'description' => "Le slug du canal où publier le sondage (ex: 'general')."],
                'question' => ['type' => 'string', 'description' => 'La question du sondage, sans les choix de réponse.'],
                'options' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'La liste des choix possibles (au moins 2).'],
                'allowMultiple' => ['type' => 'boolean', 'description' => "Indique si l'utilisateur peut sélectionner plusieurs choix."],
            ],
            'required' => ['channelSlug', 'question', 'options'],
        ];
    }

    /**
     * @param string $channelSlug Le slug du canal où publier le sondage (ex: "general").
     * @param string $question La question du sondage.
     * @param array<string> $options La liste des choix possibles (ex: ["Choix A", "Choix B"]).
     * @param bool $allowMultiple Indique si l'utilisateur peut sélectionner plusieurs choix.
     * @param int|null $authorUserId ID de l'auteur du sondage (optionnel).
     * @param int|null $workspaceId ID du workspace courant (optionnel).
     */
    public function __invoke(
        string $channelSlug,
        string $question,
        array $options,
        bool $allowMultiple = false,
        ?int $authorUserId = null,
        ?int $workspaceId = null,
    ): string {
        $author = $this->resolveUser($this->userRepository, $authorUserId)
            ?? $this->robotUserProvider->getRobotUser()
            ?? $this->userRepository->findOneBy([]);

        $resolved = $this->resolveChannelAndCheckAccess(
            $this->channelResolver,
            $this->channelAccessService,
            $channelSlug,
            $author,
            $workspaceId,
        );
        if ($resolved['error'] !== null) {
            return sprintf('Impossible de créer le sondage : %s', $resolved['error']);
        }

        $channel = $resolved['channel'];
        if ($channel === null) {
            return sprintf("Impossible de créer le sondage : le canal '%s' n'existe pas.", $channelSlug);
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

        if (!$this->pollFactory->hasValidOptions($options)) {
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

        $poll = $this->pollFactory->createPoll($message, $question, $options, $allowMultiple);

        $this->em->persist($message);
        $this->em->persist($poll);

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
