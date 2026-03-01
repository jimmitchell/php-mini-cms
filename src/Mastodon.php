<?php

declare(strict_types=1);

namespace CMS;

class Mastodon
{
    private string $instanceUrl;
    private string $token;

    public function __construct(string $instanceUrl, string $token)
    {
        $this->instanceUrl = rtrim($instanceUrl, '/');
        $this->token       = $token;
    }

    /**
     * Build and post a toot for a newly-published post.
     * Returns true on success, false on failure.
     */
    public function tootPost(string $title, string $excerpt, string $postUrl): bool
    {
        $text = $this->buildText($title, $excerpt, $postUrl);
        return $this->post($text);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Compose toot text within Mastodon's 500-character limit.
     */
    private function buildText(string $title, string $excerpt, string $url): string
    {
        // Layout: title\n\nexcerpt\n\nurl
        // Reserve space for title, url, and four newline chars.
        $reserved = mb_strlen($title) + mb_strlen($url) + 4;
        $budget   = max(0, 500 - $reserved);

        if (mb_strlen($excerpt) > $budget) {
            $excerpt = rtrim(mb_substr($excerpt, 0, $budget - 1)) . '…';
        }

        return $excerpt !== ''
            ? $title . "\n\n" . $excerpt . "\n\n" . $url
            : $title . "\n\n" . $url;
    }

    /**
     * POST the status to the Mastodon API.
     */
    private function post(string $text): bool
    {
        $ch = curl_init($this->instanceUrl . '/api/v1/statuses');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['status' => $text, 'visibility' => 'public']),
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $response !== false && in_array($httpCode, [200, 201], true);
    }
}
