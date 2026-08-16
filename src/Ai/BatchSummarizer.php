<?php

declare(strict_types=1);

namespace App\Ai;

use App\Service\LlmService;

final readonly class BatchSummarizer
{
    public function __construct(
        private LlmService $llmService,
    ) {}

    /**
     * Summarizes discussion batches sequentially using the LLM and combines them into final prompts.
     *
     * @param list<list<array<string, string>>> $batches
     * @param (callable(int, int): void)|null $onBatchProgress Called before processing each batch (batchNumber, totalBatches)
     * @param (callable(): void)|null $onFinalProgress Called before building final combined prompts
     * @return array{0: string, 1: string} [$prompt, $systemPrompt]
     */
    public function summarize(
        array $batches,
        ?callable $onBatchProgress = null,
        ?callable $onFinalProgress = null,
    ): array {
        $intermediateSummaries = [];
        $totalBatches = count($batches);

        $intermediateSystemPrompt =
            "Tu es 'Assistant Roquette', un assistant virtuel d'aide pour l'application Roquette.\n"
            . "Rédige une synthèse claire, structurée et concise du lot de messages fourni.\n"
            . "Consignes de traitement :\n"
            . "- Analyse les données JSON fournies pour en extraire les principaux sujets abordés, les questions résolues ou en cours, ainsi que les décisions importantes.\n"
            . "- Rédige une synthèse du lot de discussion, claire et concise.\n"
            . '- ATTENTION : Ne fais pas une retranscription brute ou une paraphrase message par message de la discussion. Ne cite pas chaque message un par un.';

        foreach ($batches as $index => $batch) {
            $batchNum = $index + 1;
            if ($onBatchProgress !== null) {
                $onBatchProgress($batchNum, $totalBatches);
            }

            $batchPrompt = json_encode($batch, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $intermediateSummaries[] = $this->llmService->generateText($batchPrompt, $intermediateSystemPrompt);
        }

        if ($onFinalProgress !== null) {
            $onFinalProgress();
        }

        $prompt = "Voici les synthèses des différents lots de la discussion à combiner :\n\n";
        foreach ($intermediateSummaries as $index => $subSummary) {
            $batchNum = $index + 1;
            $prompt .= "--- Résumé du Lot {$batchNum} ---\n{$subSummary}\n\n";
        }

        $systemPrompt =
            "Tu es 'Assistant Roquette', un assistant virtuel d'aide pour l'application Roquette.\n"
            . "Rédige une synthèse globale unique, claire, structurée et cohérente combinant les résumés des différents lots de discussion fournis ci-dessous.\n"
            . "Consignes de traitement :\n"
            . "- Fusionne les sujets redondants ou continus pour en faire une synthèse thématique unifiée.\n"
            . "- Rédige une synthèse claire et concise dans la même langue que les résumés fournis.\n"
            . '- Ne fais pas une simple juxtaposition des résumés. Fais-en une synthèse globale.';

        return [$prompt, $systemPrompt];
    }
}
