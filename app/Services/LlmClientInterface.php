<?php

namespace App\Services;


interface LlmClientInterface
{
    /** @return array{content: string, raw: mixed} */
    public function chat(array $messages, float $temperature = 0.2): array;


    /** @return float[] */
    public function embed(string $text): array;
}
