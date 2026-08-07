<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\Channel;
use App\Entity\User;
use App\Repository\MessageRepository;
use App\Repository\UserChannelReadRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds the prompts used to summarize a channel's recent discussion,
 * respecting the user's read-tracking and batching long histories.
 */
final readonly class ChannelSummaryBuilder
{
    public function __construct(
        private UserChannelReadRepository $userChannelReadRepository,
        private MessageRepository $messageRepository,
        private ChannelResolver $channelResolver,
        #[Autowire(env: 'int:LLM_MAX_SUMMARY_MESSAGES')]
        private int $maxSummaryMessages = 100,
    ) {}

    /**
     * @param list<Channel> $channels
     * @return array{0: string, 1: string, 2: array|null}
     */
    public function build(User $user, array $channels, string $targetChannelSlug): array
    {
        $targetChannel = $this->channelResolver->resolveFromList($targetChannelSlug, $channels);

        if (!$targetChannel) {
            $prompt =
                "Explique poliment en français que tu n'as pas trouvé le canal '"
                . $targetChannelSlug
                . "' ou que l'utilisateur n'y est pas inscrit.";
            $systemPrompt = "Tu es 'Assistant Roquette', un assistant virtuel d'aide pour l'application Roquette. Réponds en français.";

            return [$prompt, $systemPrompt, null];
        }

        $activeRead = $this->userChannelReadRepository->findOneBy([
            'user' => $user,
            'channel' => $targetChannel,
        ]);
        $lastReadMessageId = $activeRead?->getLastReadMessage()?->getId();
        $unreadMessages = $this->messageRepository->findUnreadInChannel($targetChannel, $user, $lastReadMessageId);
        $isFallback = false;

        if ($unreadMessages === []) {
            $isFallback = true;
            $unreadMessages = $this->messageRepository
                ->createQueryBuilder('m')
                ->where('m.channel = :channel')
                ->orderBy('m.createdAt', 'DESC')
                ->setParameter('channel', $targetChannel)
                ->setMaxResults($this->maxSummaryMessages)
                ->getQuery()
                ->getResult();
            $unreadMessages = array_reverse($unreadMessages);
            $finalMessages = $unreadMessages;
        } else {
            $readMessages = [];
            if ($lastReadMessageId !== null) {
                $readMessages = $this->messageRepository
                    ->createQueryBuilder('m')
                    ->where('m.channel = :channel')
                    ->andWhere('m.parent IS NULL')
                    ->andWhere('m.id <= :lastReadId')
                    ->orderBy('m.id', 'DESC')
                    ->setParameter('channel', $targetChannel)
                    ->setParameter('lastReadId', $lastReadMessageId)
                    ->setMaxResults(5)
                    ->getQuery()
                    ->getResult();
                $readMessages = array_reverse($readMessages);
            }
            $finalMessages = array_merge($readMessages, $unreadMessages);
        }

        $structuredMessages = [];
        foreach ($finalMessages as $msg) {
            $authorName = $msg->getAuthor() ? $msg->getAuthor()->getUsername() : 'Robot';
            $content = $msg->getContent() ?? '';
            if ($msg->isPoll()) {
                $content = '[Sondage] ' . $msg->getPoll()->getQuestion();
            }
            $structuredMessages[] = [
                'date' => $msg->getCreatedAt()->format('Y-m-d H:i'),
                'auteur' => $authorName,
                'contenu' => $content,
            ];
        }

        $systemPrompt =
            "Tu es 'Assistant Roquette', un assistant virtuel d'aide pour l'application Roquette."
            . "Ton objectif est d'être un simple observateur des discussions entre les utilisateurs et d'en extraire des synthèses claires, structurées et concises.\n\n"
            . "Tu vas recevoir l'historique des discussions sous format JSON. Chaque objet du tableau représente un message avec sa date, son auteur et son contenu.\n\n"
            . "Consignes de traitement :\n"
            . "- Analyse les données JSON fournies pour en extraire les principaux sujets abordés, les questions résolues ou en cours, ainsi que les décisions importantes.\n"
            . "- Rédige une synthèse globale et thématique de la discussion, claire et concise dans la même langue que la question.\n"
            . '- ATTENTION : Ne fais pas une retranscription brute ou une paraphrase message par message de la discussion. Ne cite pas chaque message un par un. Nous voulons une synthèse condensée des échanges.'
            . "- ATTENTION : tu n'es pas l'un des interlocuteurs et on ne te demande en aucun cas d'intervenir dans la discussion.";

        if ($structuredMessages === []) {
            $prompt =
                'Aucun message récent dans le canal #'
                . $targetChannel->getName()
                . ". Indique poliment qu'il n'y a rien à résumer.";

            return [$prompt, $systemPrompt, null];
        }

        if (!$isFallback && count($structuredMessages) > $this->maxSummaryMessages) {
            $batches = array_chunk($structuredMessages, $this->maxSummaryMessages);

            return ['', $systemPrompt, $batches];
        }

        $prompt = json_encode($structuredMessages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return [$prompt, $systemPrompt, null];
    }
}
