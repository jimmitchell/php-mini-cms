<?php

declare(strict_types=1);

namespace CMS;

use League\CommonMark\GithubFlavoredMarkdownConverter;

class JsonFeed
{
    private Database                        $db;
    private array                           $settings;
    private GithubFlavoredMarkdownConverter $converter;

    public function __construct(Database $db, array $settings)
    {
        $this->db        = $db;
        $this->settings  = $settings;
        $this->converter = new GithubFlavoredMarkdownConverter(['html_input' => 'allow']);
    }

    /**
     * Render JSON Feed 1.1 for the N most-recently published posts.
     * Returns the raw JSON string (UTF-8, trailing newline).
     */
    public function render(): string
    {
        $count   = (int) ($this->settings['feed_post_count'] ?? 20);
        $siteUrl = rtrim($this->settings['site_url'] ?? '', '/');
        $title   = $this->settings['site_title']       ?? 'My CMS';
        $desc    = $this->settings['site_description'] ?? '';

        $posts = $this->db->select(
            "SELECT id, title, slug, content, excerpt, published_at, updated_at
               FROM posts
              WHERE status = 'published'
              ORDER BY published_at DESC
              LIMIT :limit",
            ['limit' => $count]
        );

        $feed = [
            'version'       => 'https://jsonfeed.org/version/1.1',
            'title'         => $title,
            'home_page_url' => $siteUrl . '/',
            'feed_url'      => $siteUrl . '/feed.json',
        ];

        if ($desc !== '') {
            $feed['description'] = $desc;
        }

        $items = [];
        foreach ($posts as $post) {
            $postUrl = $siteUrl . '/' . Post::datePath($post['published_at'], $post['slug']) . '/';
            $html    = $this->converter->convert($post['content'])->getContent();

            $item = [
                'id'             => $postUrl,
                'url'            => $postUrl,
                'title'          => $post['title'],
                'content_html'   => $html,
                'date_published' => $this->rfc3339($post['published_at']),
                'date_modified'  => $this->rfc3339($post['updated_at'] ?? $post['published_at']),
            ];

            if (!empty($post['excerpt'])) {
                $item['summary'] = $post['excerpt'];
            }

            $items[] = $item;
        }

        $feed['items'] = $items;

        return json_encode($feed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /** Convert a SQLite datetime string to RFC 3339. */
    private function rfc3339(string $dt): string
    {
        $ts = strtotime($dt);
        return $ts !== false ? date('Y-m-d\TH:i:s\Z', $ts) : date('Y-m-d\TH:i:s\Z');
    }
}
