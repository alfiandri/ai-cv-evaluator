<?php

namespace App\Services;

use Illuminate\Support\Arr;

class EvaluationPipeline
{
    public function __construct(private LlmClientInterface $llm, private VectorStore $vs) {}

    /** @return array{cv_match_rate: float, cv_feedback: string, project_score: float, project_feedback: string, overall_summary: string} */
    public function run(string $cvText, string $projectText, string $jobContextType, string $studyContextType): array
    {
        $llm = new RetryingLlmClient($this->llm);

        // Retrieve RAG context (may be empty; we handle that gracefully)
        $jd     = $this->vs->search('criteria for cv scoring', $jobContextType, 1)[0]['content'] ?? '';
        $rubric = $this->vs->search('standardized scoring rubric', 'scoring_rubric', 1)[0]['content'] ?? '';
        $study  = $this->vs->search('project requirements', $studyContextType, 1)[0]['content'] ?? '';

        // ---- Step 1: Extract structured CV info ----
        $extractSchema = [
            'type' => 'object',
            'properties' => [
                'skills' => ['type' => 'array', 'items' => ['type' => 'string']],
                'experience_years' => ['type' => 'number'],
                'projects' => ['type' => 'array', 'items' => ['type' => 'string']],
                'achievements' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['skills', 'experience_years']
        ];

        $cvExtract = $this->jsonCall($llm, [
            ['role' => 'system', 'content' => 'Extract structured info from the CV text. Reply JSON only.'],
            ['role' => 'user', 'content' => json_encode(['cv_text' => $cvText, 'schema' => $extractSchema], JSON_UNESCAPED_UNICODE)],
        ]);

        // Prepare rubric safely
        $rubricArr  = json_decode($rubric, true) ?: [];
        $cvRubric   = $rubricArr['cv'] ?? [];
        $projRubric = $rubricArr['project'] ?? [];

        // ---- Step 2-3: CV match rate & feedback ----
        $cvEval = $this->jsonCall($llm, [
            ['role' => 'system', 'content' => 'You are a precise evaluator. Use the rubric (1-5). Respond in JSON only with {scores:{...}, feedback}'],
            ['role' => 'user',   'content' => json_encode([
                'rubric'         => $cvRubric,
                'job_description' => $jd,
                'cv_structured'  => $cvExtract,
            ])],
        ]);

        $cvScores    = $this->clampScores($cvEval['scores'] ?? []);
        $cvMatchRate = $this->normalizeRubric($cvScores); // 0..1

        // ---- Step 4: Project evaluation (two-pass: initial → refine) ----
        $projEval1 = $this->jsonCall($llm, [
            ['role' => 'system', 'content' => 'Score the project using rubric (1-5). JSON only: {scores:{...}, feedback}'],
            ['role' => 'user',   'content' => json_encode([
                'rubric'           => $projRubric,
                'study_case_brief' => $study,
                'project_report'   => $projectText,
            ])],
        ]);

        // Refinement pass
        $projEval2 = $this->jsonCall($llm, [
            ['role' => 'system', 'content' => 'Refine previous scoring. Penalize missing error handling, retries & tests. JSON only: {scores:{...}, feedback}'],
            ['role' => 'user',   'content' => json_encode([
                'previous_scoring' => $projEval1,
                'project_report'   => $projectText,
            ])],
        ]);

        $projScores   = $this->clampScores($projEval2['scores'] ?? []);
        $projectScore = $this->normalizeRubric($projScores, 5, 10.0); // 0..10

        // ---- Summary synthesis ----
        $summary = $this->jsonCall($llm, [
            ['role' => 'system', 'content' => 'Write a concise JSON summary with key "overall_summary".'],
            ['role' => 'user',   'content' => json_encode([
                'cv_match_rate'    => $cvMatchRate,
                'cv_feedback'      => $cvEval['feedback'] ?? '',
                'project_score'    => $projectScore,
                'project_feedback' => $projEval2['feedback'] ?? '',
                'notes'            => [
                    'cv_scores_present'    => !empty($cvScores),
                    'project_scores_present' => !empty($projScores),
                ]
            ])],
        ]);

        return [
            'cv_match_rate'    => round($cvMatchRate, 2),
            'cv_feedback'      => $cvEval['feedback'] ?? 'No CV feedback generated.',
            'project_score'    => round($projectScore, 1),
            'project_feedback' => $projEval2['feedback'] ?? 'No project feedback generated.',
            'overall_summary'  => $summary['overall_summary'] ?? 'Summary unavailable.',
        ];
    }

    /** Normalize rubric scores safely (no division-by-zero). */
    private function normalizeRubric(array $scores, int $maxPerCriterion = 5, float $scale = 1.0): float
    {
        $n = count($scores);
        if ($n === 0) {
            return 0.0;
        }
        $sum = array_sum($scores);
        return ($sum / ($maxPerCriterion * $n)) * $scale;
    }

    private function jsonCall(LlmClientInterface $llm, array $messages): array
    {
        $temperature = (float) config('llm.temperature', 0.2);
        $resp = $llm->chat($messages, $temperature);
        $parsed = json_decode($resp['content'] ?? '{}', true);
        if (!is_array($parsed)) {
            // Attempt correction
            $fix = $llm->chat([
                ['role' => 'system', 'content' => 'Fix the following into valid JSON only, no explanation.'],
                ['role' => 'user',   'content' => $resp['content'] ?? '']
            ], 0.0);
            $parsed = json_decode($fix['content'] ?? '{}', true) ?: [];
        }
        return $parsed;
    }

    /** @param array<string,int|float> $scores */
    private function clampScores(array $scores): array
    {
        $out = [];
        foreach (array_keys($scores) as $k) {
            $v = (int) round((float) $scores[$k]);
            $out[$k] = max(1, min(5, $v));
        }
        return $out;
    }
}
