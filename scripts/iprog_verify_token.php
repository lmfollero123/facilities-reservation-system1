<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/sms_helper.php';

$result = verifyIprogSmsToken();
echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
