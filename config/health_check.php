<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

/**
 * @return array<string, mixed>
 */
function frs_run_health_checks(): array
{
    $checks = [];
    $healthy = true;

    try {
        $pdo = db();
        $pdo->query('SELECT 1');
        $checks['database'] = ['ok' => true, 'message' => 'Connected'];
    } catch (Throwable $e) {
        $healthy = false;
        $checks['database'] = ['ok' => false, 'message' => $e->getMessage()];
    }

    $mailEnabled = function_exists('env_value') && env_value('MAIL_SMTP_ENABLED', 'true') === 'true';
    $mailHost = function_exists('env_value') ? trim((string)env_value('MAIL_HOST', '')) : '';
    $checks['mail'] = [
        'ok' => !$mailEnabled || $mailHost !== '',
        'message' => $mailEnabled
            ? ($mailHost !== '' ? 'SMTP configured (' . $mailHost . ')' : 'SMTP enabled but MAIL_HOST empty')
            : 'SMTP disabled',
    ];
    if (!$checks['mail']['ok']) {
        $healthy = false;
    }

    $smsKey = function_exists('env_value') ? trim((string)env_value('SMS_API_TOKEN', '')) : '';
    $checks['sms'] = [
        'ok' => true,
        'message' => $smsKey !== '' ? 'SMS token configured' : 'SMS optional (not configured)',
    ];

    $gemini = function_exists('env_value') ? trim((string)env_value('GEMINI_API_KEY', '')) : '';
    $checks['gemini'] = [
        'ok' => true,
        'message' => $gemini !== '' ? 'Gemini API key set' : 'Gemini optional (not configured)',
    ];

    $cimm = function_exists('env_value') ? trim((string)env_value('CIMM_API_KEY', '')) : '';
    $checks['cimm'] = [
        'ok' => true,
        'message' => $cimm !== '' ? 'CIMM API key set' : 'CIMM optional (not configured)',
    ];

    $pythonUrl = function_exists('env_value') ? trim((string)env_value('PYTHON_ML_API_URL', 'http://127.0.0.1:5001')) : 'http://127.0.0.1:5001';
    $mlOk = false;
    $mlMsg = 'Python ML service not reachable';
    if ($pythonUrl !== '') {
        $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
        $body = @file_get_contents(rtrim($pythonUrl, '/') . '/health', false, $ctx);
        if ($body !== false) {
            $mlOk = true;
            $mlMsg = 'Python ML reachable';
        }
    }
    $checks['python_ml'] = ['ok' => $mlOk, 'message' => $mlMsg];

    $storageWritable = is_writable(__DIR__ . '/../storage');
    $checks['storage'] = [
        'ok' => $storageWritable,
        'message' => $storageWritable ? 'storage/ writable' : 'storage/ not writable',
    ];
    if (!$storageWritable) {
        $healthy = false;
    }

    return [
        'status' => $healthy ? 'healthy' : 'degraded',
        'timestamp' => date('c'),
        'checks' => $checks,
    ];
}
