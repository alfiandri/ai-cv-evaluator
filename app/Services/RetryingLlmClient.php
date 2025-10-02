<?php

namespace App\Services;

use Throwable;

class RetryingLlmClient implements LlmClientInterface
{
    public function __construct(private LlmClientInterface $inner) {}


    public function chat(array $messages, float $temperature = 0.2): array
    {
        $delays = [0.5, 1.5, 3.5];
        $last = null;
        foreach ($delays as $i => $delay) {
            try {
                return $this->inner->chat($messages, $temperature);
            } catch (Throwable $e) {
                $last = $e;
                usleep((int)($delay * 1_000_000));
            }
        }
        throw $last ?? new \RuntimeException('LLM failed');
    }


    public function embed(string $text): array
    {
        $delays = [0.5, 1.5, 3.5];
        $last = null;
        foreach ($delays as $delay) {
            try {
                return $this->inner->embed($text);
            } catch (Throwable $e) {
                $last = $e;
                usleep((int)($delay * 1_000_000));
            }
        }
        throw $last ?? new \RuntimeException('Embedding failed');
    }
}
