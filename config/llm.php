<?php
return [
    'provider'    => env('LLM_PROVIDER', 'gemini'),
    'model'       => env('LLM_MODEL', 'gemini-1.5-flash'),
    'temperature' => (float) env('LLM_TEMPERATURE', 0.2),
];
