<?php
/**
 * Multi-provider chat completion transport.
 *
 * Every provider here speaks the OpenAI /chat/completions shape, so switching
 * between them is just a different base URL, key and model name. Requests walk
 * the chain in order and stop at the first provider that answers; a provider
 * that rate-limits (429) or errors is put in a short cooldown so the next
 * request skips it instead of paying its timeout again.
 *
 * The point is headroom: the free tiers stack, and no single provider going
 * down takes the AI features with it.
 */

require_once __DIR__ . '/app.php';

/** Cooldown after a provider returns 429, in seconds. */
const FRS_AI_COOLDOWN_RATE_LIMITED = 300;

/** Cooldown after a provider errors or times out, in seconds. */
const FRS_AI_COOLDOWN_ERROR = 60;

function frs_ai_env(string $key, string $default = ''): string
{
    $value = function_exists('env_value')
        ? trim((string) env_value($key, $default))
        : trim((string) (getenv($key) ?: $default));

    // Treat the .env.example placeholders as "not configured".
    return str_starts_with($value, 'YOUR_') ? '' : $value;
}

/**
 * Ordered list of usable providers. Only providers with a key configured are
 * returned, so the chain shrinks to whatever the deployment actually has.
 *
 * Order is env-tunable via AI_PROVIDER_ORDER (comma-separated) so a provider
 * can be promoted or dropped without a deploy.
 *
 * @return list<array{name:string,url:string,key:string,model:string,token_param:string,extra:array<string,mixed>,headers:list<string>}>
 */
function frs_ai_provider_chain(): array
{
    $cloudflareAccount = frs_ai_env('CLOUDFLARE_AI_ACCOUNT_ID');

    $catalog = [
        'groq' => [
            'url' => 'https://api.groq.com/openai/v1/chat/completions',
            'key' => frs_ai_env('GROQ_API_KEY'),
            'model' => frs_ai_env('GROQ_MODEL', 'openai/gpt-oss-20b'),
            'token_param' => 'max_completion_tokens',
            // gpt-oss is a reasoning model and its thinking tokens count against
            // the completion budget, so keep the effort low or a hard prompt can
            // burn the whole budget and come back empty.
            'extra' => ['reasoning_effort' => 'low'],
            'headers' => [],
        ],
        'cerebras' => [
            'url' => 'https://api.cerebras.ai/v1/chat/completions',
            'key' => frs_ai_env('CEREBRAS_API_KEY'),
            'model' => frs_ai_env('CEREBRAS_MODEL', 'llama-3.3-70b'),
            'token_param' => 'max_tokens',
            'extra' => [],
            'headers' => [],
        ],
        'mistral' => [
            'url' => 'https://api.mistral.ai/v1/chat/completions',
            'key' => frs_ai_env('MISTRAL_API_KEY'),
            'model' => frs_ai_env('MISTRAL_MODEL', 'mistral-small-latest'),
            'token_param' => 'max_tokens',
            'extra' => [],
            'headers' => [],
        ],
        'cloudflare' => [
            'url' => $cloudflareAccount === ''
                ? ''
                : 'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode($cloudflareAccount) . '/ai/v1/chat/completions',
            'key' => frs_ai_env('CLOUDFLARE_AI_API_TOKEN'),
            'model' => frs_ai_env('CLOUDFLARE_AI_MODEL', '@cf/meta/llama-3.3-70b-instruct-fp8-fast'),
            'token_param' => 'max_tokens',
            'extra' => [],
            'headers' => [],
        ],
        'openrouter' => [
            'url' => 'https://openrouter.ai/api/v1/chat/completions',
            'key' => frs_ai_env('OPENROUTER_API_KEY'),
            'model' => frs_ai_env('OPENROUTER_MODEL', 'meta-llama/llama-3.3-70b-instruct:free'),
            'token_param' => 'max_tokens',
            'extra' => [],
            'headers' => ['HTTP-Referer: https://cprf.infragovservices.com', 'X-Title: Barangay Culiat PFRS'],
        ],
    ];

    $order = frs_ai_env('AI_PROVIDER_ORDER', 'groq,cerebras,mistral,cloudflare,openrouter');
    $names = array_filter(array_map('trim', explode(',', $order)));

    $chain = [];
    foreach ($names as $name) {
        $provider = $catalog[$name] ?? null;
        if ($provider === null || $provider['key'] === '' || $provider['url'] === '' || $provider['model'] === '') {
            continue;
        }
        $chain[] = ['name' => $name] + $provider;
    }

    return $chain;
}

/** Path of the small JSON file tracking per-provider cooldowns. */
function frs_ai_cooldown_file(): string
{
    return sys_get_temp_dir() . '/frs_ai_cooldowns.json';
}

/** @return array<string,int> provider name => unix timestamp the cooldown ends */
function frs_ai_cooldowns(): array
{
    $file = frs_ai_cooldown_file();
    if (!is_readable($file)) {
        return [];
    }
    $decoded = json_decode((string) @file_get_contents($file), true);
    if (!is_array($decoded)) {
        return [];
    }

    $now = time();
    return array_filter(
        array_map('intval', $decoded),
        static fn ($until) => $until > $now
    );
}

function frs_ai_start_cooldown(string $provider, int $seconds): void
{
    $cooldowns = frs_ai_cooldowns();
    $cooldowns[$provider] = time() + $seconds;
    @file_put_contents(frs_ai_cooldown_file(), json_encode($cooldowns), LOCK_EX);
}

/**
 * Send a chat completion through the provider chain.
 *
 * @param list<array{role:string,content:string}> $messages
 * @param string|null $usedProvider Set to the provider name that answered.
 * @return string|null Reply text, or null when every provider failed.
 */
function frs_ai_chat_raw(
    array $messages,
    int $maxTokens = 400,
    float $temperature = 0.1,
    ?string &$usedProvider = null
): ?string {
    $chain = frs_ai_provider_chain();
    if ($chain === []) {
        error_log('AI chat call skipped: no provider configured.');
        return null;
    }

    $cooldowns = frs_ai_cooldowns();
    $attempted = false;

    foreach ($chain as $provider) {
        if (isset($cooldowns[$provider['name']])) {
            continue;
        }
        $attempted = true;

        $payload = [
            'model' => $provider['model'],
            'messages' => $messages,
            'temperature' => $temperature,
            $provider['token_param'] => $maxTokens,
        ] + $provider['extra'];

        $ch = curl_init($provider['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => array_merge([
                'Content-Type: application/json',
                'Authorization: Bearer ' . $provider['key'],
            ], $provider['headers']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 429) {
            frs_ai_start_cooldown($provider['name'], FRS_AI_COOLDOWN_RATE_LIMITED);
            error_log("AI provider {$provider['name']} rate limited; trying next.");
            continue;
        }

        if ($raw === false || $httpCode !== 200) {
            frs_ai_start_cooldown($provider['name'], FRS_AI_COOLDOWN_ERROR);
            error_log("AI provider {$provider['name']} failed: HTTP {$httpCode}, " . ($curlErr ?: substr((string) $raw, 0, 200)));
            continue;
        }

        $data = json_decode((string) $raw, true);
        $text = $data['choices'][0]['message']['content'] ?? null;
        if (is_string($text) && $text !== '') {
            $usedProvider = $provider['name'];
            return $text;
        }

        // A 200 with no content usually means the completion budget was spent
        // before any visible tokens; another provider may still answer.
        error_log("AI provider {$provider['name']} returned an empty completion; trying next.");
    }

    error_log($attempted
        ? 'AI chat call failed: every provider in the chain errored.'
        : 'AI chat call skipped: every provider is in cooldown.');

    return null;
}

/**
 * Same as frs_ai_chat_raw() but decodes the reply as JSON, tolerating the
 * markdown fences models add despite being told not to.
 *
 * @param list<array{role:string,content:string}> $messages
 */
function frs_ai_chat_json(array $messages, int $maxTokens = 400, float $temperature = 0.1): ?array
{
    $text = frs_ai_chat_raw($messages, $maxTokens, $temperature);
    if ($text === null) {
        return null;
    }

    $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text));
    $json = json_decode((string) $text, true);
    return is_array($json) ? $json : null;
}
