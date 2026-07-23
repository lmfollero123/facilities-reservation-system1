<?php
/**
 * Session-backed flash messages with two delivery channels, emitted in two
 * phases because HTTP headers must be sent before any HTML output:
 *
 *  - Header phase (frs_flash_emit_header()): called from the layout's PHP
 *    prologue, before its first byte of output. On AJAX form submits
 *    (X-Requested-With: FRSAjaxForm) it sends the X-FRS-Toast response
 *    header (consuming the flash) so the client's fetch layer can read it
 *    and call frsShowToast() on the swapped region. Calling this late (e.g.
 *    at the toast-stack render point, after ~KBs of HTML) would silently
 *    fail: headers_sent() would be true, the header would be dropped, and
 *    the DOM fallback below renders outside the AJAX-swapped region anyway
 *    — so AJAX submitters would never see the message.
 *  - DOM phase (frs_flash_emit()): called from the layout at the toast-stack
 *    render point, for full page loads. If the header phase already
 *    delivered (and consumed) the flash, this is a no-op. Otherwise it
 *    echoes a .frs-toast-preset div in #frsToastStack that frs-toast.js
 *    promotes to a toast on DOMContentLoaded.
 *
 * A flash is only ever consumed by the channel that can actually deliver it.
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

/** True only for a real AJAX form submit (the header channel's only consumer). */
function frs_flash_is_ajax_form_request(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'FRSAjaxForm';
}

/**
 * Header phase. Call from the layout's PHP prologue, before any HTML
 * output. Delivers via the X-FRS-Toast header — and consumes the flash —
 * only when the request is an AJAX form submit AND headers can still be
 * sent AND a flash exists. Any failed condition consumes nothing, leaving
 * the flash for frs_flash_emit()'s DOM-phase fallback.
 */
function frs_flash_emit_header(): void
{
    if (!frs_flash_is_ajax_form_request() || headers_sent()) {
        return;
    }
    $flash = $_SESSION['frs_flash'] ?? null;
    if (!is_array($flash) || !isset($flash['message'], $flash['type'])) {
        return;
    }
    header('X-FRS-Toast: ' . frs_flash_build_toast_header($flash));
    frs_flash_take();
}

/**
 * DOM phase. Call from the layout at the toast-stack render point. If
 * frs_flash_emit_header() already delivered (and consumed) the flash for
 * this request, there is nothing left here. Otherwise, echoes a preset div
 * inside #frsToastStack for full page loads.
 */
function frs_flash_emit(): void
{
    $flash = frs_flash_take();
    if ($flash === null) {
        return;
    }
    echo '<div class="frs-toast-preset" data-frs-toast-message="'
        . htmlspecialchars((string)$flash['message'], ENT_QUOTES, 'UTF-8')
        . '" data-frs-toast-type="'
        . htmlspecialchars((string)$flash['type'], ENT_QUOTES, 'UTF-8')
        . '" hidden></div>';
}
