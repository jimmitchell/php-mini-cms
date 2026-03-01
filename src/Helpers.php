<?php

declare(strict_types=1);

namespace CMS;

class Helpers
{
    /**
     * Convert a string into a URL-safe slug.
     * e.g. "Hello, World! It's Alive" → "hello-world-its-alive"
     */
    public static function slugify(string $text): string
    {
        // Transliterate non-ASCII characters to ASCII equivalents (requires intl).
        if (function_exists('transliterator_transliterate')) {
            $text = transliterator_transliterate('Any-Latin; Latin-ASCII', $text) ?? $text;
        }

        $text = strtolower($text);
        $text = preg_replace('/[\'\"]+/', '', $text);         // strip quotes
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);    // non-alnum → hyphen
        $text = trim($text, '-');

        return $text !== '' ? $text : 'untitled';
    }

    /**
     * Format a datetime string for display.
     *
     * When $locale is provided and the intl extension is available, uses
     * IntlDateFormatter::FULL for a fully localised date (e.g. "vendredi
     * 28 février 2026" for fr_FR).  Falls back to PHP date() otherwise.
     */
    public static function formatDate(string $datetime, string $format = 'l, F j, Y', string $locale = '', string $timezone = ''): string
    {
        $ts = strtotime($datetime);
        if ($ts === false) {
            return $datetime;
        }

        $tz = $timezone !== '' ? @timezone_open($timezone) : false;

        if ($locale !== '' && class_exists('IntlDateFormatter')) {
            $fmt = new \IntlDateFormatter(
                $locale,
                \IntlDateFormatter::FULL,
                \IntlDateFormatter::NONE,
                $tz ?: null
            );
            $result = $fmt->format($ts);
            if ($result !== false) {
                return $result;
            }
        }

        if ($tz) {
            $dt = new \DateTime('@' . $ts);
            $dt->setTimezone($tz);
            return $dt->format($format);
        }

        return date($format, $ts);
    }

    /**
     * Strip HTML tags and truncate plain text to $length characters,
     * appending an ellipsis if truncated.
     */
    public static function truncate(string $html, int $length = 200): string
    {
        $text = strip_tags($html);
        $text = preg_replace('/\s+/', ' ', trim($text));

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        // Break at the last word boundary within the limit.
        $truncated = mb_substr($text, 0, $length);
        $lastSpace = mb_strrpos($truncated, ' ');
        if ($lastSpace !== false) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return rtrim($truncated) . '…';
    }

    /**
     * Escape a string for safe HTML output.
     */
    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
