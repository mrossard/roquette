<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\User;
use App\Service\LlmRateLimiter;
use App\Service\LlmService;
use App\Service\MessageFormatter;
use App\Service\RobotDmMessageService;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Twig\Environment;

/**
 * Manages pending AI tool confirmation tokens and handles execution
 * when confirmed either via button click or text response (e.g., "ok", "oui", "vas-y").
 */
class PendingConfirmationService
{
    public const TTL = 900; // 15 minutes matching ToolActionSigner

    public function __construct(
        private readonly ToolActionSigner $toolActionSigner,
        private readonly ToolRegistry $toolRegistry,
        private readonly HubInterface $hub,
        private readonly Environment $twig,
        private readonly MessageFormatter $messageFormatter,
        private readonly CacheInterface $cache,
        private readonly LlmRateLimiter $llmRateLimiter,
        private readonly LlmService $llmService,
        private readonly RobotDmMessageService $robotDmMessageService,
        private readonly \App\Service\MercurePublisher $mercurePublisher,
    ) {}

    public function savePendingConfirmation(
        User|int $user,
        #[\SensitiveParameter]
        string $token,
        ?string $channelSlug = null,
    ): void {
        $userId = $user instanceof User ? $user->getId() : $user;
        if ($userId === null) {
            return;
        }

        $keyUser = 'pending_tool_confirm_u_' . $userId;
        $this->cache->delete($keyUser);
        $this->cache->get($keyUser, static function (ItemInterface $item) use ($token): string {
            $item->expiresAfter(self::TTL);

            return $token;
        });

        if ($channelSlug !== null && $channelSlug !== '') {
            $keyChannel = 'pending_tool_confirm_uc_' . $userId . '_' . md5($channelSlug);
            $this->cache->delete($keyChannel);
            $this->cache->get($keyChannel, static function (ItemInterface $item) use ($token): string {
                $item->expiresAfter(self::TTL);

                return $token;
            });
        }
    }

    public function getPendingConfirmation(User|int $user, ?string $channelSlug = null): ?string
    {
        $userId = $user instanceof User ? $user->getId() : $user;
        if ($userId === null) {
            return null;
        }

        if ($channelSlug !== null && $channelSlug !== '') {
            $keyChannel = 'pending_tool_confirm_uc_' . $userId . '_' . md5($channelSlug);
            $token = $this->cache->get($keyChannel, static fn(): ?string => null);
            if (\is_string($token) && $token !== '') {
                return $token;
            }
        }

        $keyUser = 'pending_tool_confirm_u_' . $userId;
        $token = $this->cache->get($keyUser, static fn(): ?string => null);
        if (\is_string($token) && $token !== '') {
            return $token;
        }

        return null;
    }

    public function clearPendingConfirmation(User|int $user, ?string $channelSlug = null): void
    {
        $userId = $user instanceof User ? $user->getId() : $user;
        if ($userId === null) {
            return;
        }

        $keyUser = 'pending_tool_confirm_u_' . $userId;
        $this->cache->delete($keyUser);

        if ($channelSlug !== null && $channelSlug !== '') {
            $keyChannel = 'pending_tool_confirm_uc_' . $userId . '_' . md5($channelSlug);
            $this->cache->delete($keyChannel);
        }
    }

    /**
     * Hybrid confirmation check: combines a fast-path keyword/phrase check
     * for instant execution with an LLM classification fallback for complex natural language expressions.
     */
    public function isConfirmation(string $text, #[\SensitiveParameter] ?string $token = null, ?User $user = null): bool
    {
        if ($this->isConfirmationText($text)) {
            return true;
        }

        if ($token !== null && $user !== null && mb_strlen(trim($text)) < 200) {
            $payload = $this->toolActionSigner->verify($token, $user->getId());
            if ($payload !== null) {
                return $this->classifyWithLlm($text, $payload);
            }
        }

        return false;
    }

    /**
     * Fast-path check for common unambiguous affirmative responses.
     */
    public function isConfirmationText(string $text): bool
    {
        $trimmed = trim(mb_strtolower($text));
        $cleaned = trim($trimmed, " \t\n\r\0\x0B!.?,\"':;");

        if ($cleaned === '') {
            return false;
        }

        $exactPhrases = [
            'ok',
            'okay',
            'k',
            'oui',
            'yes',
            'yep',
            'yo',
            'd\'accord',
            'daccord',
            'dac',
            'd\'ac',
            'confirm',
            'confirmer',
            'confirme',
            'confirmation',
            'je confirme',
            'valider',
            'valide',
            'je valide',
            'go',
            'ok go',
            'on fait comme ça',
            'on fait comme ca',
            'c\'est bon',
            'cest bon',
            'ca marche',
            'ça marche',
            'super',
            'parfait',
        ];

        if (\in_array($cleaned, $exactPhrases, true)) {
            return true;
        }

        return (bool) preg_match(
            '/^(?:ok|okay|k|oui|yep|yes|d\'?accord|d\'?ac|dac|confirm(?:er|e|ation)?|je\s+confirme|valid(?:er|e)|je\s+valide|go|c\'?est\s+bon|ca\s+marche|ça\s+marche)(?:\s+(?:stp|s\'il\s+te\s+pla[îi]t|merci|assistant|robot|go|super|parfait))?$/iu',
            $cleaned,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function classifyWithLlm(string $text, array $payload): bool
    {
        $toolName = (string) ($payload['tool'] ?? '');
        $args = json_encode($payload['args'] ?? [], JSON_UNESCAPED_UNICODE);

        $prompt = sprintf(
            "L'utilisateur a reçu une demande de confirmation pour l'action : Outil '%s' avec les arguments %s.\nL'utilisateur vient de répondre : \"%s\".\nCette réponse est-elle une confirmation affirmative de l'action ? Réponds STRICTEMENT par YES ou NO.",
            $toolName,
            $args,
            $text,
        );

        $systemPrompt = "Tu es un classifieur strict d'intention de confirmation pour l'application Roquette. Tu réponds STRICTEMENT par le mot 'YES' si le message exprime un accord ou me validation de l'action demandée, ou 'NO' dans le cas contraire. Ne génère aucun autre texte.";

        try {
            $response = trim($this->llmService->generateText($prompt, $systemPrompt));

            return str_contains(strtoupper($response), 'YES');
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Verifies the token, executes the tool action, broadcasts Mercure update, and clears pending state.
     */
    public function executeConfirmation(#[\SensitiveParameter] string $token, User $user): bool
    {
        $payload = $this->toolActionSigner->verify($token, $user->getId());
        if ($payload === null) {
            return false;
        }

        $limiter = $this->llmRateLimiter->consumeConfirmation($user);
        if (!$limiter) {
            return false;
        }

        $toolName = $payload['tool'] ?? null;
        $arguments = $payload['args'] ?? [];
        if (!\is_string($toolName) || !\is_array($arguments)) {
            return false;
        }

        $result = $this->toolRegistry->execute(
            new ToolCall('confirm-' . uniqid(), $toolName, $arguments),
            $payload['uid'] ?? null,
            $payload['ws'] ?? null,
        );

        $channelSlug = (string) ($payload['channelSlug'] ?? '');

        // Update or replace robot DM message in database so the obsolete "Veuillez confirmer..." is replaced by the actual result
        $this->robotDmMessageService->updateOrPersistRobotDmMessage($channelSlug, $result);

        $renderedHtml = $this->twig->render('dashboard/_help_message_update.html.twig', [
            'helpMessageId' => (string) ($payload['helpMessageId'] ?? ''),
            'html' => $this->messageFormatter->format($result),
            'timestamp' => new \DateTime(),
            'channelSlug' => $channelSlug,
        ]);

        $this->mercurePublisher->publishToUser($user, $renderedHtml, 'help_stream_update');

        $this->clearPendingConfirmation($user, $channelSlug);

        return true;
    }
}
