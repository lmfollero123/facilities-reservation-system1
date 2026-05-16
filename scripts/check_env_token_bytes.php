<?php
declare(strict_types=1);

$path = dirname(__DIR__) . '/.env';
if (!is_file($path)) {
    echo "No .env file\n";
    exit(1);
}

$raw = file_get_contents($path);
echo 'File BOM: ' . (str_starts_with($raw, "\xEF\xBB\xBF") ? 'yes' : 'no') . PHP_EOL;

foreach (explode("\n", $raw) as $i => $line) {
    if (!str_starts_with(trim($line), 'PHILSMS_API_TOKEN=')) {
        continue;
    }
    $val = substr($line, strpos($line, '=') + 1);
    $val = rtrim($val, "\r\n");
    echo 'Line: ' . ($i + 1) . PHP_EOL;
    echo 'Value length: ' . strlen($val) . PHP_EOL;
    echo 'Has pipe: ' . (str_contains($val, '|') ? 'yes' : 'no') . PHP_EOL;
    echo 'Leading/trailing whitespace: '
        . (preg_match('/^\s|\s$/', $val) ? 'yes' : 'no') . PHP_EOL;
    echo 'Non-printable chars: ' . (preg_match('/[^\x20-\x7E]/', $val) ? 'yes' : 'no') . PHP_EOL;
    if (preg_match('/^(\d+)\|/', $val, $m)) {
        echo 'Token id prefix: ' . $m[1] . PHP_EOL;
    }
    exit(0);
}

echo "PHILSMS_API_TOKEN line not found\n";
exit(1);
