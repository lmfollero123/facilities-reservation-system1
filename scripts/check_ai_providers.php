<?php
/**
 * Health check for the chat-completion provider chain (config/ai_providers.php).
 *
 * Shows which providers are configured, then sends each a one-word prompt and
 * reports whether it answered. Use it after adding or rotating a key.
 *
 * Usage: php scripts/check_ai_providers.php [--no-call] [--list-models]
 *   --no-call      List the configured chain without spending any quota.
 *   --list-models  Also print the model ids each provider accepts, which is
 *                  what you need when a model id is rejected as not found.
 *   --reset        Clear all cooldowns first, so every provider is retried.
 *
 * Needs a PHP binary with curl. The cPanel CLI php has no curl extension, so
 * on the live host run it through LiteSpeed's build instead:
 *   /usr/local/lsws/lsphp82/bin/lsphp -q scripts/check_ai_providers.php
 *
 * API keys are never printed — only whether one is present.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/ai_providers.php';

$options = getopt('', ['no-call', 'list-models', 'reset']);
$callProviders = !isset($options['no-call']);
$listModels = isset($options['list-models']);

if (isset($options['reset'])) {
    frs_ai_clear_cooldowns();
    echo "Cleared all provider cooldowns.

";
}

$chain = frs_ai_provider_chain();

if ($chain === []) {
    echo "No providers configured.\n";
    echo "Add at least one key (GROQ_API_KEY, CEREBRAS_API_KEY, MISTRAL_API_KEY,\n";
    echo "CLOUDFLARE_AI_ACCOUNT_ID + CLOUDFLARE_API_TOKEN, OPENROUTER_API_KEY).\n";
    exit(1);
}

echo 'Provider chain (' . count($chain) . " configured, tried in this order):\n\n";

$cooldowns = frs_ai_cooldowns();
$answered = 0;
$failures = 0;
$skipped = 0;

foreach ($chain as $index => $provider) {
    $position = $index + 1;
    echo "{$position}. {$provider['name']}\n";
    echo "     model     {$provider['model']}\n";
    echo '     key       present (' . strlen($provider['key']) . " chars)\n";

    if (isset($cooldowns[$provider['name']])) {
        $seconds = $cooldowns[$provider['name']] - time();
        echo "     status    in cooldown for {$seconds}s — requests skip it until then\n\n";
        $skipped++;
        continue;
    }

    if (!$callProviders) {
        echo "     status    not called (--no-call)\n\n";
        continue;
    }

    // Call this provider alone, so one bad key cannot hide behind a healthy
    // provider further down the chain.
    $started = microtime(true);
    $reply = frs_ai_chat_single($provider, [
        ['role' => 'user', 'content' => 'Reply with the single word: ok'],
    ], 20, 0.0);
    $ms = (int) round((microtime(true) - $started) * 1000);

    if ($reply === null) {
        $failures++;
        echo "     status    FAILED after {$ms}ms — see the PHP error log for the response\n\n";
        continue;
    }

    $answer = trim(preg_replace('/\s+/', ' ', $reply));
    if (mb_strlen($answer) > 40) {
        $answer = mb_substr($answer, 0, 40) . '…';
    }
    echo "     status    OK in {$ms}ms — replied \"{$answer}\"\n\n";
    $answered++;
}

foreach (frs_ai_provider_skip_reasons() as $name => $reason) {
    echo "-- {$name}: not configured — {$reason}\n";
}

if ($listModels) {
    echo "\nModel ids each configured provider accepts:\n\n";
    foreach ($chain as $provider) {
        $models = frs_ai_list_models($provider);
        if ($models === null) {
            echo "  {$provider['name']}: could not fetch the model list\n";
            continue;
        }
        echo "  {$provider['name']} (" . count($models) . "):\n";
        foreach ($models as $model) {
            $marker = $model === $provider['model'] ? ' <- configured' : '';
            echo "    {$model}{$marker}\n";
        }
        echo "\n";
    }
}

if (!$callProviders) {
    exit(0);
}
echo "\n";

echo "{$answered} of " . count($chain) . ' providers answered';
echo $skipped > 0
    ? ", {$skipped} skipped while already in cooldown.\n"
    : ".\n";

if ($failures > 0) {
    echo "A failing provider is skipped at runtime, so the chain still works as\n";
    echo "long as one answers — but it is not adding any headroom.\n";
}

if ($answered === 0 && $skipped > 0) {
    echo "Nothing was called this run. Re-run once the cooldowns above expire.\n";
}

exit($answered > 0 ? 0 : 1);
