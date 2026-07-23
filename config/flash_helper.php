<?php
/**
 * Session-backed flash messages with two delivery channels:
 *  - AJAX form submits (X-Requested-With: FRSAjaxForm / X-FRS-Partial header):
 *    an X-FRS-Toast response header the client turns into frsShowToast().
 *  - Full page loads: a .frs-toast-preset div in #frsToastStack that
 *    frs-toast.js already promotes to a toast on DOMContentLoaded.
 *
 * Standardizes the older per-page patterns (inline $message vars, ad-hoc
 * session keys like booking_flash). Pages migrate by calling
 * frs_flash_success()/frs_flash_error() instead of their local pattern.
 */

declare(strict_types=1);

function frs_flash_set(string $message, string $type): void
{
    if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
        session_start();
    }
    $_SESSION['frs_flash'] = ['message' => $message, 'type' => $type === 'error' ? 'error' : 'success'];
}

function frs_flash_success(string $message): void
{
    frs_flash_set($message, 'success');
}

function frs_flash_error(string $message): void
{
    frs_flash_set($message, 'error');
}

/** Read and clear the slot. */
function frs_flash_take(): ?array
{
    $flash = $_SESSION['frs_flash'] ?? null;
    unset($_SESSION['frs_flash']);
    return is_array($flash) && isset($flash['message'], $flash['type']) ? $flash : null;
}

/** Pure: header value for X-FRS-Toast. */
function frs_flash_build_toast_header(array $flash): string
{
    return rawurlencode((string)json_encode([
        'message' => (string)($flash['message'] ?? ''),
        'type' => ($flash['type'] ?? 'success') === 'error' ? 'error' : 'success',
    ]));
}

/** True when the request came from the AJAX form layer (or a partial GET). */
function frs_flash_is_ajax_form_request(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'FRSAjaxForm'
        || ($_SERVER['HTTP_X_FRS_PARTIAL'] ?? '') !== '';
}

/**
 * Deliver a pending flash. Call from the layout at the toast-stack render
 * point: on AJAX requests it sends the X-FRS-Toast header (output is still
 * buffered there, so headers_sent() is normally false); on full loads it
 * echoes a preset div inside #frsToastStack.
 */
function frs_flash_emit(): void
{
    $flash = frs_flash_take();
    if ($flash === null) {
        return;
    }
    if (frs_flash_is_ajax_form_request() && !headers_sent()) {
        header('X-FRS-Toast: ' . frs_flash_build_toast_header($flash));
        return;
    }
    echo '<div class="frs-toast-preset" data-frs-toast-message="'
        . htmlspecialchars((string)$flash['message'], ENT_QUOTES, 'UTF-8')
        . '" data-frs-toast-type="'
        . htmlspecialchars((string)$flash['type'], ENT_QUOTES, 'UTF-8')
        . '" hidden></div>';
}
