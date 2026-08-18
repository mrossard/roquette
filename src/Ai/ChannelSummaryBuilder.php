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
    private MessagePromptFormatter $messagePromptFormatter;

    public function __construct(
        private UserChannelReadRepository $userChannelReadRepository,
        private MessageRepository $messageRepository,
        private ChannelResolver $channelResolver,
        #[Autowire(env: 'int:LLM_MAX_SUMMARY_MESSAGES')]
        private int $maxSummaryMessages = 100,
        #[Autowire(env: 'int:LLM_MAX_SUMMARY_BATCHES')]
        private int $maxSummaryBatches = 5,
        ?MessagePromptFormatter $messagePromptFormatter = null,
    ) {
        $this->messagePromptFormatter = $messagePromptFormatter ?? new MessagePromptFormatter();
    }

    /**
     * @param list<Channel> $channels
     */
    public function build(User $user, array $channels, string $targetChannelSlug): SummaryPromptResult
    {
        $targetChannel = $this->channelResolver->resolveFromList($targetChannelSlug, $channels);

        if (!$targetChannel) {
            $prompt =
                "Explique poliment en français que tu n'as pas trouvé le canal '"
                . $targetChannelSlug
                . "' ou que l'utilisateur n'y est pas inscrit.";
            $systemPrompt = "Tu es 'Assistant Roquette', un assistant virtuel d'aide pour l'application Roquette. Réponds en français.";

            return new SummaryPromptResult($prompt, $systemPrompt, null);
        }

        $activeRead = $this->userChannelReadRepository->findOneBy([
            'user' => $user,
            'channel' => $targetChannel,
        ]);
        $lastReadMessageId = $activeRead?->getLastReadMessage()?->getId();
        $unreadMessages = $this->messageRepository->findUnreadInChannel($targetChannel, $user, $lastReadMessageId);
        $isFallback = false;

        $readMessages = ($unreadMessages !== [] && $lastReadMessageId !== null)
            ? $this->messageRepository->findRecentReadBefore($targetChannel, $lastReadMessageId, 5)
            : [];
        $finalMessages = $unreadMessages === []
            ? $this->messageRepository->findRecentInChannel($targetChannel, $this->maxSummaryMessages)
            : array_merge($readMessages, $unreadMessages);

        $structuredMessages = $this->structureMessages($finalMessages);

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

            return new SummaryPromptResult($prompt, $systemPrompt, null);
        }

        if (!$isFallback && count($structuredMessages) > $this->maxSummaryMessages) {
            // Cap the number of batches to bound the number of LLM calls per summary.
            $cap = max(1, $this->maxSummaryBatches) * $this->maxSummaryMessages;
            if (count($structuredMessages) > $cap) {
                $structuredMessages = array_slice($structuredMessages, -$cap);
            }

            $batches = array_chunk($structuredMessages, $this->maxSummaryMessages);
            if (count($batches) > 1) {
                return new SummaryPromptResult('', $systemPrompt, $batches);
            }
        }

        $prompt = json_encode($structuredMessages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return new SummaryPromptResult((string) $prompt, $systemPrompt, null);
    }

    /**
     * @param list<\App\Entity\Message> $messages
     * @return list<array{date: string, auteur: string, contenu: string}>
     */
    private function structureMessages(array $messages): array
    {
        $structured = [];
        foreach ($messages as $msg) {
            $structured[] = $this->messagePromptFormatter->formatStructured($msg);
        }

        return $structured;
    }
}
