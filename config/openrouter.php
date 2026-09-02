<?php

declare(strict_types=1);

return [
    'api_key' => env('OPENROUTER_API_KEY'),
    'base_url' => rtrim((string) env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'), '/'),
    'model' => env('OPENROUTER_MODEL', 'anthropic/claude-sonnet-5'),
    'timeout' => (int) env('OPENROUTER_TIMEOUT', 90),
];
