<?php

namespace App\Services;

use App\Models\Document;

class VectorStore
{
    public function __construct(private LlmClientInterface $llm) {}


    public function upsert(string $type, string $content, array $meta = []): void
    {
        $embedding = $this->normalize($this->llm->embed($content));
        Document::updateOrCreate([
            'type' => $type,
        ], [
            'content' => $content,
            'embedding' => $embedding,
            'meta' => $meta,
        ]);
    }


    public function ensureRubricSeeded(): void
    {
        if (!Document::where('type', 'scoring_rubric')->exists()) {
            $rubric = json_encode($this->defaultRubric(), JSON_PRETTY_PRINT);
            $this->upsert('scoring_rubric', $rubric, ['version' => 1]);
        }
    }


    /** @return array<int, array{type:string,content:string,score:float}> */
    public function search(string $query, ?string $type = null, int $k = 2): array
    {
        $q = $this->normalize($this->llm->embed($query));
        $docs = Document::when($type, fn($qq) => $qq->where('type', $type))->get();
        $scored = [];
        foreach ($docs as $d) {
            $score = $this->dot($q, $d->embedding ?? []);
            $scored[] = ['type' => $d->type, 'content' => $d->content, 'score' => $score];
        }
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, $k);
    }


    private function dot(array $a, array $b): float
    {
        $s = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) $s += $a[$i] * $b[$i];
        return $s;
    }


    private function normalize(array $v): array
    {
        $n = sqrt(array_reduce($v, fn($c, $x) => $c + $x * $x, 0.0));
        if ($n == 0) return $v;
        return array_map(fn($x) => $x / $n, $v);
    }


    private function defaultRubric(): array
    {
        return [
            'cv' => [
                'technical_skills_match' => '1-5: backend, DBs, APIs, cloud, AI/LLM exposure',
                'experience_level' => '1-5: years + project complexity',
                'relevant_achievements' => '1-5: impact, scale',
                'cultural_fit' => '1-5: communication, learning attitude'
            ],
            'project' => [
                'correctness' => '1-5: meets prompt design, chaining, RAG, error handling',
                'code_quality' => '1-5: clean, modular, testable',
                'resilience' => '1-5: failures, retries, backoff',
                'documentation' => '1-5: README, trade-offs',
                'creativity' => '1-5: extras (auth, deployment, dashboards)'
            ]
        ];
    }
}
