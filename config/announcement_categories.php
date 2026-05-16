<?php
/**
 * Shared announcement category styling for public pages.
 */
declare(strict_types=1);

if (!function_exists('getAnnouncementCategory')) {
    /**
     * @return array{type: string, color: string, bgColor: string}
     */
    function getAnnouncementCategory(string $title, string $message, ?string $type = null): array
    {
        $titleLower = strtolower($title);
        $messageLower = strtolower($message);
        $combined = $titleLower . ' ' . $messageLower;

        if ($type !== null && $type !== '') {
            $normalized = strtolower(trim($type));
            $byType = [
                'urgent' => ['type' => 'urgent', 'color' => '#dc2626', 'bgColor' => '#fee2e2'],
                'emergency' => ['type' => 'emergency', 'color' => '#dc2626', 'bgColor' => '#fee2e2'],
                'event' => ['type' => 'event', 'color' => '#2563eb', 'bgColor' => '#eff6ff'],
                'health' => ['type' => 'health', 'color' => '#059669', 'bgColor' => '#ecfdf5'],
                'deadline' => ['type' => 'deadline', 'color' => '#d97706', 'bgColor' => '#fef3c7'],
                'advisory' => ['type' => 'advisory', 'color' => '#0891b2', 'bgColor' => '#ecf0f1'],
                'general' => ['type' => 'general', 'color' => '#6b7280', 'bgColor' => '#f3f4f6'],
            ];
            if (isset($byType[$normalized])) {
                return $byType[$normalized];
            }
        }

        $categoryConfig = [
            'emergency' => ['color' => '#dc2626', 'bgColor' => '#fee2e2'],
            'urgent' => ['color' => '#dc2626', 'bgColor' => '#fee2e2'],
            'event' => ['color' => '#2563eb', 'bgColor' => '#eff6ff'],
            'health' => ['color' => '#059669', 'bgColor' => '#ecfdf5'],
            'deadline' => ['color' => '#d97706', 'bgColor' => '#fef3c7'],
            'advisory' => ['color' => '#0891b2', 'bgColor' => '#ecf0f1'],
            'general' => ['color' => '#6b7280', 'bgColor' => '#f3f4f6'],
        ];

        $patterns = [
            'emergency' => '/emergency|urgent|alert|critical|disaster|calamity|closure|interruption/i',
            'event' => '/event|activity|program|ceremony|celebration|gathering|schedule|drive/i',
            'health' => '/health|medical|covid|vaccine|sanitation|disease|clinic|doctor/i',
            'deadline' => '/deadline|due date|submit by|application closes|final date/i',
            'advisory' => '/advisory|notice|attention|remember|reminder|please note|maintenance/i',
        ];

        foreach ($patterns as $category => $pattern) {
            if (preg_match($pattern, $combined)) {
                return array_merge(['type' => $category], $categoryConfig[$category]);
            }
        }

        return array_merge(['type' => 'general'], $categoryConfig['general']);
    }
}
