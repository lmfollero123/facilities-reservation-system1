<?php
/**
 * Small UI helpers (field tips, etc.)
 */

if (!function_exists('frs_field_tip')) {
    /**
     * Inline “i” icon; shows $text in a tooltip on hover/focus/tap.
     *
     * @param string      $text     Tooltip body (plain text)
     * @param string|null $popupId  Deprecated; ignored (kept for call-site compatibility)
     */
    function frs_field_tip(string $text, ?string $popupId = null): string
    {
        $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        return '<span class="frs-tip">'
            . '<button type="button" class="frs-tip-btn" data-frs-tip="' . $safe . '" aria-describedby="frs-tip-float" aria-label="More information">i</button>'
            . '</span>';
    }
}

if (!function_exists('frs_heading_with_tip')) {
    /**
     * Section heading with optional ⓘ tooltip (keeps layout compact).
     */
    function frs_heading_with_tip(string $text, ?string $tip = null, string $tag = 'h2'): string
    {
        $allowed = ['h1', 'h2', 'h3', 'h4'];
        $tag = in_array($tag, $allowed, true) ? $tag : 'h2';
        $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $tipHtml = $tip !== null && $tip !== '' ? ' ' . frs_field_tip($tip) : '';

        return '<' . $tag . ' class="frs-heading-with-tip">' . $safe . $tipHtml . '</' . $tag . '>';
    }
}

if (!function_exists('frs_logout_form')) {
    /**
     * POST logout control with CSRF (replaces GET /logout links).
     */
    function frs_logout_form(string $buttonClass = 'btn btn-primary', string $label = 'Logout', string $confirmMessage = 'Are you sure you want to log out?'): string
    {
        $action = htmlspecialchars((string)base_path() . '/logout', ENT_QUOTES, 'UTF-8');
        $btnClass = htmlspecialchars($buttonClass, ENT_QUOTES, 'UTF-8');
        $btnLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $confirm = htmlspecialchars($confirmMessage, ENT_QUOTES, 'UTF-8');
        $csrf = csrf_field();

        return '<form method="POST" action="' . $action . '" style="display:inline;margin:0;" onsubmit="try{Object.keys(sessionStorage).forEach(function(k){if(k.indexOf(\'bcf_booking_purpose_\')===0)sessionStorage.removeItem(k);});}catch(e){}">'
            . $csrf
            . '<button type="submit" class="' . $btnClass . ' confirm-action" data-message="' . $confirm . '">'
            . $btnLabel
            . '</button></form>';
    }
}

if (!function_exists('frs_session_display_name')) {
    function frs_session_display_name(string $fallback = 'User'): string
    {
        $name = trim((string)($_SESSION['user_name'] ?? $_SESSION['name'] ?? ''));
        return $name !== '' ? $name : $fallback;
    }
}

if (!function_exists('frs_page_title')) {
    /**
     * Page &lt;h1&gt; with optional ⓘ (omit generic subtitle paragraphs).
     */
    function frs_page_title(string $title, ?string $tip = null): string
    {
        return frs_heading_with_tip($title, $tip, 'h1');
    }
}
