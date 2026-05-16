<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/sms_helper.php';

$pdo = db();

if (!($_SESSION['user_authenticated'] ?? false)) {
    header('Location: ' . base_path() . '/login');
    exit;
}

$role = (string)($_SESSION['role'] ?? '');
if ($role !== 'Admin') {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

$pageTitle = 'SMS Test (IPROG) | LGU Facilities Reservation';
$smsStatus = getSmsConfigurationStatus();
$resultMessage = '';
$resultType = '';
$lastDebug = null;
$iprogVerify = verifyIprogSmsToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_token'])) {
    if (!frs_csrf_ok()) {
        $resultMessage = 'Invalid security token. Refresh the page and try again.';
        $resultType = 'error';
    } else {
        $pasteToken = trim((string)($_POST['paste_token'] ?? ''));
        $iprogVerify = $pasteToken !== '' ? verifyIprogSmsToken($pasteToken) : verifyIprogSmsToken();
        if ($iprogVerify['ok']) {
            $resultMessage = $pasteToken !== ''
                ? 'Pasted token is valid. Update IPROG_API_TOKEN in .env and restart Apache.'
                : 'IPROG API token is valid.' . ($iprogVerify['credits'] !== null ? ' Balance: ' . $iprogVerify['credits'] . ' credit(s).' : '');
            $resultType = 'success';
        } else {
            $resultMessage = 'IPROG rejected the token: ' . $iprogVerify['message'];
            $resultType = 'error';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test_sms'])) {
    if (!frs_csrf_ok()) {
        $resultMessage = 'Invalid security token. Refresh the page and try again.';
        $resultType = 'error';
    } else {
        $to = trim($_POST['recipient'] ?? '');
        $body = trim($_POST['message'] ?? '');
        if ($body === '') {
            $body = 'LGU Culiat: IPROG SMS test from Facilities Reservation System.';
        }
        $sent = sendSmsNotification($to !== '' ? $to : null, $body);
        $lastDebug = frs_sms_last_debug();
        if ($sent) {
            $normalized = normalizePhilippineMobileNumber($to !== '' ? $to : $smsStatus['default_recipient']);
            $drv = $smsStatus['driver'] ?? '';
            if ($drv === 'log') {
                $resultMessage = 'SMS logged for demo. Open: ' . ($smsStatus['log_path'] ?? 'storage/logs/sms.log');
            } else {
                $resultMessage = 'IPROG SMS queued for ' . ($normalized ?? 'recipient')
                    . '. Check your phone in a few seconds (sender: iprogSMS on Globe/TM/DITO).';
            }
            $resultType = 'success';
        } else {
            $resultMessage = frs_sms_last_error() ?? 'SMS failed. See details below.';
            $resultType = 'error';
        }
    }
}

$config = frs_sms_config();
$tokenRaw = trim((string)($config['iprogsms']['api_token'] ?? ''));
$tokenPreview = $tokenRaw !== '' ? substr($tokenRaw, 0, 6) . '…' . substr($tokenRaw, -4) : '';
$envFilePath = realpath(__DIR__ . '/../../../../.env') ?: '(not found)';

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Admin</span><span class="sep">/</span><span>SMS Test</span>
    </div>
    <h1>IPROG SMS Test</h1>
    <small>Verify your IPROG API key and send a test message.</small>
</div>

<?php if ($resultMessage !== ''): ?>
    <div role="alert" style="margin-bottom: 1rem; padding: 1rem 1.25rem; border-radius: 8px; border: 2px solid <?= $resultType === 'success' ? '#0d7a43' : '#b23030'; ?>; background: <?= $resultType === 'success' ? '#e3f8ef' : '#fdecee'; ?>; color: <?= $resultType === 'success' ? '#0d5c32' : '#8b1e1e'; ?>;">
        <strong><?= $resultType === 'success' ? 'Success' : 'Could not send'; ?>:</strong>
        <?= htmlspecialchars($resultMessage, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="booking-wrapper">
    <section class="booking-card">
        <h2>Configuration status</h2>
        <ul class="audit-list" style="margin-bottom: 1.5rem;">
            <li><strong>SMS enabled:</strong> <?= $smsStatus['enabled'] ? 'Yes' : 'No'; ?></li>
            <li><strong>Driver:</strong> <code><?= htmlspecialchars((string)($smsStatus['driver'] ?? 'iprogsms'), ENT_QUOTES, 'UTF-8'); ?></code></li>
            <li><strong>API token:</strong> <?= $smsStatus['token_set'] ? ('Set (' . htmlspecialchars($tokenPreview, ENT_QUOTES, 'UTF-8') . ')') : 'Missing — set IPROG_API_TOKEN in .env'; ?></li>
            <li><strong>Sender ID:</strong> <?= htmlspecialchars($smsStatus['sender_id'] !== '' ? $smsStatus['sender_id'] : 'iprogSMS', ENT_QUOTES, 'UTF-8'); ?></li>
            <li><strong>Default recipient:</strong> <?= htmlspecialchars($smsStatus['default_recipient'] !== '' ? $smsStatus['default_recipient'] : '(none)', ENT_QUOTES, 'UTF-8'); ?>
                <?php if ($smsStatus['default_recipient'] !== ''): ?>
                    → <code><?= htmlspecialchars((string)normalizePhilippineMobileNumber($smsStatus['default_recipient']), ENT_QUOTES, 'UTF-8'); ?></code>
                <?php endif; ?>
            </li>
            <li><strong>Ready to send:</strong> <?= $smsStatus['ready'] ? 'Yes' : 'No'; ?></li>
            <li><strong>Token check:</strong>
                <?php if ($iprogVerify['ok']): ?>
                    <span style="color:#0d7a43;font-weight:600;">Valid</span>
                    <?php if ($iprogVerify['credits'] !== null): ?>
                        — <?= htmlspecialchars((string)$iprogVerify['credits'], ENT_QUOTES, 'UTF-8'); ?> credit(s)
                    <?php endif; ?>
                <?php else: ?>
                    <span style="color:#b23030;font-weight:600;"><?= htmlspecialchars($iprogVerify['message'], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
            </li>
            <li><strong>.env:</strong> <code style="font-size:0.85rem;"><?= htmlspecialchars($envFilePath, ENT_QUOTES, 'UTF-8'); ?></code></li>
        </ul>

        <?php if (!empty($smsStatus['issues'])): ?>
            <div style="padding: 1rem; background: #fff4e5; border: 1px solid #ffc107; border-radius: 8px; margin-bottom: 1.5rem;">
                <strong style="color: #856404;">Setup checklist</strong>
                <ol style="margin: 0.5rem 0 0 1.25rem; color: #856404;">
                    <?php foreach ($smsStatus['issues'] as $issue): ?>
                        <li><?= htmlspecialchars($issue, ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ol>
            </div>
        <?php endif; ?>

        <?php if ($lastDebug): ?>
            <details style="margin-bottom: 1.5rem; padding: 0.75rem 1rem; background: #f8f9fc; border: 1px solid #dfe3ef; border-radius: 8px;">
                <summary style="cursor: pointer; font-weight: 600;">Last API response (debug)</summary>
                <pre style="margin: 0.75rem 0 0; font-size: 0.8rem; white-space: pre-wrap; word-break: break-word;">HTTP <?= (int)($lastDebug['http_code'] ?? 0); ?>
Recipient: <?= htmlspecialchars((string)($lastDebug['recipient'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
Sender: <?= htmlspecialchars((string)($lastDebug['sender_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>

<?= htmlspecialchars((string)($lastDebug['response'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></pre>
            </details>
        <?php endif; ?>

        <form method="POST" style="margin-bottom: 1.5rem; max-width: 640px;">
            <?= csrf_field(); ?>
            <label>
                Paste API token (optional)
                <input type="password" name="paste_token" autocomplete="off" placeholder="your IPROG API token" style="width:100%;margin-top:0.35rem;padding:0.5rem;">
            </label>
            <button type="submit" name="verify_token" value="1" class="btn-outline" style="margin-top:0.75rem;">Verify API token</button>
        </form>

        <h2>Send test SMS</h2>
        <form method="POST" class="booking-form" style="max-width: 520px;">
            <?= csrf_field(); ?>
            <input type="hidden" name="send_test_sms" value="1">
            <label>
                Recipient mobile
                <div class="input-wrapper">
                    <i class="bi bi-phone input-icon"></i>
                    <input type="tel" name="recipient" placeholder="09171234567 or 639171234567" value="<?= htmlspecialchars($_POST['recipient'] ?? $smsStatus['default_recipient'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <small style="color:#8b95b5;">Leave blank to use SMS_DEFAULT_RECIPIENT from .env</small>
            </label>
            <label>
                Message
                <textarea name="message" rows="3" placeholder="Test message"><?= htmlspecialchars($_POST['message'] ?? 'LGU Culiat: IPROG SMS test from Facilities Reservation System.', ENT_QUOTES, 'UTF-8'); ?></textarea>
            </label>
            <button type="submit" class="btn-primary" <?= $smsStatus['ready'] ? '' : 'disabled'; ?>>Send test SMS</button>
        </form>
    </section>

    <aside class="booking-card">
        <h2>IPROG notes</h2>
        <ul class="audit-list">
            <li>Get your API token from <a href="https://www.iprogsms.com" target="_blank" rel="noopener">iprogsms.com</a> dashboard.</li>
            <li>Set <code>SMS_DRIVER=iprogsms</code> and <code>IPROG_API_TOKEN=...</code> in <code>.env</code>, then restart Apache.</li>
            <li>Shared sender <strong>iprogSMS</strong> works on Globe, TM, and DITO.</li>
            <li>Smart/TNT may need an approved custom sender name on IPROG.</li>
            <li>Each SMS uses 1 credit (₱1). Check balance on this page after verify.</li>
            <li><a href="https://www.iprogsms.com/api/v1/documentation" target="_blank" rel="noopener">API documentation</a></li>
        </ul>
    </aside>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
