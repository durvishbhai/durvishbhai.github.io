<?php

declare(strict_types=1);

use Vertex\Core\Env;

/** "Continue with Google" for client accounts. Off until the client ID and secret from the Google Cloud Console are saved and it is switched on in Integrations & settings. */
return [
    'enabled' => (bool) Env::get('GOOGLE_LOGIN_ENABLED', false),
    'client_id' => (string) Env::get('GOOGLE_CLIENT_ID', ''),
    'client_secret' => (string) Env::get('GOOGLE_CLIENT_SECRET', ''),
    'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
    'token_url' => 'https://oauth2.googleapis.com/token',
    'state_ttl_seconds' => 600,
];
