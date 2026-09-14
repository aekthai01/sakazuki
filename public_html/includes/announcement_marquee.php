<?php
/**
 * Shared announcement presentation helpers.
 *
 * The announcement text itself remains in announcement_texts. Presentation
 * options live in the existing settings table so both sites can deploy this
 * without a schema migration.
 */

if (!function_exists('normalizeAnnouncementTextColor')) {
    function normalizeAnnouncementTextColor($value, string $default = '#93C5FD'): string
    {
        if (!is_scalar($value)) {
            return $default;
        }

        $value = strtoupper(trim((string) $value));
        if (preg_match('/^#[0-9A-F]{6}$/', $value)) {
            return $value;
        }
        if (preg_match('/^#[0-9A-F]{3}$/', $value)) {
            return '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3];
        }
        return $default;
    }
}

if (!function_exists('getAnnouncementTextColor')) {
    function getAnnouncementTextColor(): string
    {
        $saved = function_exists('getSetting')
            ? getSetting('announcement_text_color', '#93C5FD')
            : '#93C5FD';
        return normalizeAnnouncementTextColor($saved, '#93C5FD');
    }
}

if (!function_exists('saveAnnouncementTextColor')) {
    function saveAnnouncementTextColor($value): bool
    {
        if (!is_scalar($value)) {
            return false;
        }
        $raw = strtoupper(trim((string) $value));
        if (!preg_match('/^#[0-9A-F]{6}$/', $raw)) {
            return false;
        }
        return function_exists('updateSetting')
            ? (bool) updateSetting('announcement_text_color', $raw)
            : false;
    }
}
