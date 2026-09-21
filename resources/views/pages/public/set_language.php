<?php
/**
 * GET /set-language?lang=tl&return=/some/path
 * Stores the chosen locale in session and redirects back. No CSRF check
 * needed -- this only changes display language for the current session,
 * not any account/data state, so a forged cross-site GET has no real
 * consequence beyond momentarily flipping the visitor's own UI language.
 */
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/i18n.php';

$lang = (string)($_GET['lang'] ?? '');
frs_set_locale($lang);

$returnTo = frs_safe_redirect_path((string)($_GET['return'] ?? ''));
$destination = $returnTo ?? (base_path() . '/');

header('Location: ' . $destination);
exit;
