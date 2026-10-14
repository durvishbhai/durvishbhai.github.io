<?php

declare(strict_types=1);

use Vertex\Core\Env;

/** AI assistance. Off until an administrator turns it on and saves an API key (Administration -> Integrations & settings -> AI). */
return [
    'enabled' => (bool) Env::get('AI_ENABLED', false),
    'provider' => (string) Env::get('AI_PROVIDER', 'anthropic'), // anthropic | openai (any OpenAI-compatible service)
    'base_url' => (string) Env::get('AI_BASE_URL', ''),
    'model' => (string) Env::get('AI_MODEL', 'claude-haiku-5-5'),
    'api_key' => (string) Env::get('AI_API_KEY', ''),
    'monthly_cap' => (int) Env::get('AI_MONTHLY_CAP', 500),
    'user_hourly_cap' => 30,
    'max_tokens' => 1200,
    'timeout' => 45,
];
