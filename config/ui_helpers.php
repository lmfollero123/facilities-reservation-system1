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

if (!function_exists('frs_page_title')) {
    /**
     * Page &lt;h1&gt; with optional ⓘ (omit generic subtitle paragraphs).
     */
    function frs_page_title(string $title, ?string $tip = null): string
    {
        return frs_heading_with_tip($title, $tip, 'h1');
    }
}
