<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;

class GeminiLlmClient implements LlmClientInterface
{
    private string $apiKey;
    private string $base;
    private string $model;
    private string $embeddingModel;

    public function __construct(?string $model = null, ?string $embeddingModel = null)
    {
        $this->apiKey         = (string) env('GEMINI_API_KEY');
        // Default to v1beta, but we’ll try v1 on 404
        $this->base           = rtrim(env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $this->model          = $model ?: (string) config('llm.model', env('LLM_MODEL', 'gemini-2.5-flash'));
        $this->embeddingModel = $embeddingModel ?: (string) env('EMBEDDING_MODEL', 'text-embedding-004');
    }

    public function chat(array $messages, float $temperature = 0.2): array
    {
        $p = (float) env('SIMULATE_LLM_FAILURE_PROB', 0);
        if ($p > 0 && mt_rand() / mt_getrandmax() < $p) {
            throw new \RuntimeException('Simulated LLM failure');
        }

        [$system, $contents] = $this->convertMessages($messages);

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => $temperature,
                'responseMimeType' => 'application/json',
            ],
        ];
        if ($system) {
            $payload['systemInstruction'] = [
                'role'  => 'system',
                'parts' => [['text' => $system]],
            ];
        }

        $url = rtrim($this->base, '/') . "/models/{$this->model}:generateContent";
        try {
            $resp = Http::timeout(60)
                ->withHeaders([
                    'x-goog-api-key' => $this->apiKey,
                    'Content-Type'   => 'application/json',
                    'Accept'         => 'application/json',
                ])
                ->post($url, $payload)
                ->throw()
                ->json();

            $content = $resp['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
            return ['content' => $content, 'raw' => $resp];
        } catch (RequestException $e) {
            throw $e;
        }


        throw new \RuntimeException('Gemini API 404: no valid base/model combination found.');
    }

    public function embed(string $text): array
    {
        $payload = [
            'content' => ['parts' => [['text' => mb_substr($text, 0, 8000)]]],
        ];

        $bases = [$this->base, 'https://generativelanguage.googleapis.com/v1'];
        foreach ($bases as $base) {
            $url = rtrim($base, '/') . "/models/{$this->embeddingModel}:embedContent";
            try {
                $resp = Http::timeout(30)
                    ->withHeaders([
                        'x-goog-api-key' => $this->apiKey,
                        'Content-Type'   => 'application/json',
                        'Accept'         => 'application/json',
                    ])
                    ->post($url, $payload)
                    ->throw()
                    ->json();

                $values = $resp['embedding']['values'] ?? [];
                return array_map('floatval', $values);
            } catch (RequestException $e) {
                if ($e->response && $e->response->status() === 404) {
                    continue;
                }
                throw $e;
            }
        }

        throw new \RuntimeException('Gemini Embeddings 404: check EMBEDDING_MODEL and base URL.');
    }

    /** Convert OpenAI-style messages to Gemini format. */
    private function convertMessages(array $messages): array
    {
        $system = [];
        $contents = [];

        foreach ($messages as $m) {
            $role = $m['role'] ?? 'user';
            $text = is_array($m['content']) ? json_encode($m['content']) : (string) $m['content'];

            if ($role === 'system') {
                $system[] = $text;
                continue;
            }

            $geminiRole = $role === 'assistant' ? 'model' : 'user';
            $contents[] = [
                'role'  => $geminiRole,
                'parts' => [['text' => $text]],
            ];
        }

        return [trim(implode("\n", $system)), $contents];
    }
}
