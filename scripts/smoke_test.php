<?php
/**
 * Post-deployment smoke test.
 *
 * Automates the checklist in docs/DEPLOYMENT_SMOKE_TESTS.md: run it on the
 * server right after every deploy (and optionally from anywhere with a base
 * URL for the HTTP checks).
 *
 * Usage:
 *   php scripts/smoke_test.php                     # local checks only
 *   php scripts/smoke_test.php https://cprf.infragovservices.com
 *
 * Exit codes: 0 = all checks passed (warnings allowed), 1 = at least one failure.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

$baseUrl = isset($argv[1]) ? rtrim((string)$argv[1], '/') : '';

$failures = 0;
$warnings = 0;

function smoke(string $label, callable $check): void
{
    global $failures, $warnings;
    try {
        $result = $check(); // true | 'warn:<message>' | throws
        if (is_string($result) && str_starts_with($result, 'warn:')) {
            $warnings++;
            echo "WARN  {$label} — " . substr($result, 5) . PHP_EOL;
            return;
        }
        echo "PASS  {$label}" . PHP_EOL;
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL  {$label} — " . $e->getMessage() . PHP_EOL;
    }
}

function smoke_env(string $key): string
{
    return trim((string)(function_exists('env_value') ? env_value($key, '') : (getenv($key) ?: '')));
}

function smoke_http(string $url, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'CPRF-SmokeTest/1.0',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        throw new RuntimeException('connection failed: ' . $err);
    }
    return ['code' => $code, 'body' => (string)$body];
}

echo '== CPRF post-deployment smoke test (' . date('Y-m-d H:i:s') . ') ==' . PHP_EOL;

// ---- Local environment checks --------------------------------------------

smoke('.env present with APP_URL', function () {
    if (!is_file(dirname(__DIR__) . '/.env')) {
        throw new RuntimeException('.env file missing');
    }
    if (smoke_env('APP_URL') === '') {
        return 'warn:APP_URL is empty';
    }
    return true;
});

smoke('vendor/ dependencies installed (PHPMailer)', function () {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('vendor/autoload.php missing — run composer install --no-dev');
    }
    require_once $autoload;
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        throw new RuntimeException('PHPMailer class not found');
    }
    return true;
});

smoke('database connection', function () {
    db()->query('SELECT 1');
    return true;
});

smoke('core tables exist (users, facilities, reservations)', function () {
    foreach (['users', 'facilities', 'reservations'] as $table) {
        db()->query("SELECT 1 FROM {$table} LIMIT 1");
    }
    return true;
});

smoke('migrations applied (energy_sync_state present)', function () {
    try {
        db()->query('SELECT 1 FROM energy_sync_state LIMIT 1');
    } catch (Throwable $e) {
        throw new RuntimeException('late-migration table missing — run php run_migrations.php');
    }
    return true;
});

smoke('upload directories writable', function () {
    $dirs = ['public/uploads', 'public/img/announcements'];
    $problems = [];
    foreach ($dirs as $dir) {
        $path = dirname(__DIR__) . '/' . $dir;
        if (!is_dir($path)) {
            $problems[] = "{$dir} missing";
        } elseif (!is_writable($path)) {
            $problems[] = "{$dir} not writable";
        }
    }
    if ($problems) {
        throw new RuntimeException(implode('; ', $problems));
    }
    return true;
});

smoke('mail configured', function () {
    if (smoke_env('MAIL_USERNAME') === '' && smoke_env('SMTP_USERNAME') === '') {
        return 'warn:no SMTP username configured — OTP emails will fail';
    }
    return true;
});

smoke('energy integration configured', function () {
    if (smoke_env('ENERGY_API_URL') === '' || smoke_env('ENERGY_API_TOKEN') === '') {
        return 'warn:ENERGY_API_URL / ENERGY_API_TOKEN not set — energy sync disabled';
    }
    return true;
});

// ---- HTTP checks (only when a base URL is given) --------------------------

if ($baseUrl !== '') {
    smoke("health endpoint ({$baseUrl}/api/health)", function () use ($baseUrl) {
        $resp = smoke_http($baseUrl . '/api/health');
        if ($resp['code'] !== 200) {
            throw new RuntimeException("HTTP {$resp['code']}: " . substr($resp['body'], 0, 150));
        }
        $json = json_decode($resp['body'], true);
        if (!is_array($json) || ($json['status'] ?? '') !== 'healthy') {
            throw new RuntimeException('health endpoint reports: ' . substr($resp['body'], 0, 150));
        }
        return true;
    });

    smoke('home page renders', function () use ($baseUrl) {
        $resp = smoke_http($baseUrl . '/');
        if ($resp['code'] !== 200) {
            throw new RuntimeException("HTTP {$resp['code']}");
        }
        return true;
    });

    smoke('login page renders', function () use ($baseUrl) {
        $resp = smoke_http($baseUrl . '/login');
        if ($resp['code'] !== 200) {
            throw new RuntimeException("HTTP {$resp['code']}");
        }
        if (stripos($resp['body'], 'password') === false) {
            return 'warn:login page returned 200 but no password field found';
        }
        return true;
    });

    smoke('energy facilities feed authenticates', function () use ($baseUrl) {
        $token = smoke_env('ENERGY_API_TOKEN');
        if ($token === '') {
            return 'warn:skipped — ENERGY_API_TOKEN not set';
        }
        $resp = smoke_http($baseUrl . '/public/api/energy-facilities-feed.php', [
            'Authorization: Bearer ' . $token,
        ]);
        if ($resp['code'] !== 200) {
            throw new RuntimeException("HTTP {$resp['code']}: " . substr($resp['body'], 0, 150));
        }
        return true;
    });
} else {
    echo 'NOTE  pass a base URL to add HTTP checks, e.g. php scripts/smoke_test.php https://cprf.infragovservices.com' . PHP_EOL;
}

echo str_repeat('-', 60) . PHP_EOL;
echo sprintf('Result: %s (%d failure(s), %d warning(s))%s', $failures === 0 ? 'PASS' : 'FAIL', $failures, $warnings, PHP_EOL);
exit($failures === 0 ? 0 : 1);
