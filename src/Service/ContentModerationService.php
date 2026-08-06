<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;

readonly class ContentModerationService

{
    /** @var array<string, string> Regex pattern => Description */
    private const SECRET_PATTERNS = [
        '/sk-[a-zA-Z0-9_.\-]{20,}/' => "Clé d'API OpenAI / Service AI",

        '/AKIA[0-9A-Z]{16}/' => "Clé AWS Access Key",
        '/ghp_[a-zA-Z0-9]{36}/' => "Token GitHub Personal Access",
        '/xox[baprs]-[a-zA-Z0-9-]+/' => "Token Slack",

        '/eyJ[a-zA-Z0-9_-]{10,}\.eyJ[a-zA-Z0-9_-]{10,}\.[a-zA-Z0-9_-]{10,}/' => "Token JWT décelé",
        '/(?:postgres|mysql|mongodb|redis):\/\/[^:]+:([^@]+)@/i' => "Mot de passe dans chaîne de connexion DB",
        '/-----BEGIN (?:RSA )?PRIVATE KEY-----/' => "Clé privée RSA/SSH",
    ];

    public function __construct(
        private ?LlmService $llmService = null,
        private ?LoggerInterface $logger = null,
    ) {}

    public function moderate(string $content): ModerationResult
    {
        $trimContent = trim($content);
        if ($trimContent === '') {
            return ModerationResult::clean();
        }

        // 1. Détection rapide de secrets / tokens via regex
        $maskedContent = $trimContent;
        $detectedSecrets = [];

        foreach (self::SECRET_PATTERNS as $pattern => $description) {
            if (preg_match($pattern, $maskedContent, $matches)) {
                $detectedSecrets[] = $description;
                $maskedContent = (string) preg_replace($pattern, '[SECRET MASQUÉ]', $maskedContent);
            }
        }

        if ($detectedSecrets !== []) {
            $reason = "Secret ou donnée sensible détecté(e) : " . implode(', ', array_unique($detectedSecrets));
            $this->logger?->warning('Sensitive secret detected in message content', ['secrets' => $detectedSecrets]);

            return ModerationResult::masked(
                maskedContent: $maskedContent,
                originalContent: $trimContent,
                reason: $reason,
            );
        }

        // 2. Détection de toxicité via LLM (si le service LLM est disponible)
        if ($this->llmService !== null) {
            try {
                $systemPrompt = "Tu es un système d'analyse de modération de contenu. "
                    . "Examine le texte fourni et détermine s'il contient des injures graves, du harcèlement, de la haine ou du contenu hautement inapproprié. "
                    . "Réponds STRICTEMENT et UNIQUEMENT par l'un de ces deux mots : TOXIC ou CLEAN.";

                $response = strtoupper(trim($this->llmService->generateText($trimContent, $systemPrompt)));

                if (str_contains($response, 'TOXIC')) {
                    $this->logger?->warning('Toxic content detected by LLM moderation', ['content' => $trimContent]);

                    return ModerationResult::flagged("Contenu inapproprié ou toxique détecté par la modération IA");
                }
            } catch (\Throwable $e) {
                $this->logger?->error('LLM moderation call failed', ['error' => $e->getMessage()]);
            }
        }

        return ModerationResult::clean();
    }
}
