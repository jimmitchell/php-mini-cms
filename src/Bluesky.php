<?php

declare(strict_types=1);

namespace CMS;

class Bluesky
{
    private const API_BASE = 'https://bsky.social/xrpc';

    private string $handle;
    private string $appPassword;

    public function __construct(string $handle, string $appPassword)
    {
        $this->handle      = $handle;
        $this->appPassword = $appPassword;
    }

    /**
     * Build and post to Bluesky for a newly-published post.
     * Returns true on success, false on failure.
     */
    public function postToBluesky(string $title, string $excerpt, string $url): bool
    {
        $session = $this->createSession();
        if ($session === false) {
            return false;
        }

        $text   = $this->buildText($title, $excerpt, $url);
        $facets = $this->buildFacets($text, $url);

        return $this->createPost($session['did'], $session['jwt'], $text, $facets);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Authenticate and return ['did' => ..., 'jwt' => ...], or false on failure.
     *
     * @return array{did:string,jwt:string}|false
     */
    private function createSession(): array|false
    {
        $ch = curl_init(self::API_BASE . '/com.atproto.server.createSession');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['identifier' => $this->handle, 'password' => $this->appPassword]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return false;
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data) || empty($data['did']) || empty($data['accessJwt'])) {
            return false;
        }

        return ['did' => $data['did'], 'jwt' => $data['accessJwt']];
    }

    /**
     * Compose post text within Bluesky's 300-grapheme limit.
     * Layout: title\n\nexcerpt\n\nurl
     */
    private function buildText(string $title, string $excerpt, string $url): string
    {
        // Reserve space for title, url, and four newline chars.
        $reserved = mb_strlen($title) + mb_strlen($url) + 4;
        $budget   = max(0, 300 - $reserved);

        if (mb_strlen($excerpt) > $budget) {
            $excerpt = rtrim(mb_substr($excerpt, 0, $budget - 1)) . '…';
        }

        return $excerpt !== ''
            ? $title . "\n\n" . $excerpt . "\n\n" . $url
            : $title . "\n\n" . $url;
    }

    /**
     * Build AT Protocol facets array to make the URL a clickable link.
     * Byte offsets (not character offsets) are required by the protocol.
     */
    private function buildFacets(string $text, string $url): array
    {
        $byteStart = strpos($text, $url);
        if ($byteStart === false) {
            return [];
        }
        $byteEnd = $byteStart + strlen($url);

        return [
            [
                'index' => [
                    '$type'     => 'app.bsky.richtext.facet#byteSlice',
                    'byteStart' => $byteStart,
                    'byteEnd'   => $byteEnd,
                ],
                'features' => [
                    [
                        '$type' => 'app.bsky.richtext.facet#link',
                        'uri'   => $url,
                    ],
                ],
            ],
        ];
    }

    /**
     * POST the record to the Bluesky API. Returns true on HTTP 200.
     */
    private function createPost(string $did, string $jwt, string $text, array $facets): bool
    {
        $record = [
            '$type'     => 'app.bsky.feed.post',
            'text'      => $text,
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        if (!empty($facets)) {
            $record['facets'] = $facets;
        }

        $body = json_encode([
            'repo'       => $did,
            'collection' => 'app.bsky.feed.post',
            'record'     => $record,
        ]);

        $ch = curl_init(self::API_BASE . '/com.atproto.repo.createRecord');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $jwt,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $response !== false && $httpCode === 200;
    }
}
