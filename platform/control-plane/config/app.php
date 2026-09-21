<?php

return [
    'name' => getenv('APP_NAME') ?: 'dilibori-control-plane',
    'debug' => filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'secret_key' => getenv('APP_SECRET_KEY') ?: '',
    'profiles_path' => getenv('PROFILES_PATH') ?: '/opt/platform/profiles',
    'secrets_path' => getenv('SECRETS_PATH') ?: '/var/lib/dilibori/secrets',
    'runtime_agent_url' => rtrim(getenv('RUNTIME_AGENT_URL') ?: 'http://127.0.0.1:8100', '/'),
    'runtime_agent_token' => getenv('RUNTIME_AGENT_TOKEN') ?: '',
    'default_actor' => getenv('DEFAULT_ACTOR') ?: 'control-plane',
];
