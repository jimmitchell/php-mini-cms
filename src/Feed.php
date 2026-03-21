<?php

declare(strict_types=1);

namespace CMS;

use League\CommonMark\GithubFlavoredMarkdownConverter;

class Feed
{
    private Database                            $db;
    private array                               $settings;
    private GithubFlavoredMarkdownConverter     $converter;

    public function __construct(Database $db, array $settings)
    {
        $this->db        = $db;
        $this->settings  = $settings;
        $this->converter = new GithubFlavoredMarkdownConverter(['html_input' => 'allow']);
    }

    /**
     * Render Atom 1.0 XML for the N most-recently published posts.
     * Returns the raw XML string (no BOM, UTF-8).
     */
    public function render(): string
    {
        $count    = (int) ($this->settings['feed_post_count'] ?? 20);
        $siteUrl  = rtrim($this->settings['site_url'] ?? '', '/');
        $title    = $this->settings['site_title']       ?? 'My CMS';
        $desc     = $this->settings['site_description'] ?? '';

        $posts = $this->db->select(
            "SELECT id, title, slug, content, excerpt, published_at, updated_at
               FROM posts
              WHERE status = 'published'
              ORDER BY published_at DESC
              LIMIT :limit",
            ['limit' => $count]
        );

        $feedUpdated = !empty($posts)
            ? $this->atom($posts[0]['updated_at'] ?? $posts[0]['published_at'])
            : $this->atom(date('Y-m-d H:i:s'));

        $feedId = $siteUrl ?: 'urn:uuid:' . sha1($title);

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<feed xmlns="http://www.w3.org/2005/Atom">' . "\n";
        $xml .= '  <title>' . $this->x($title) . '</title>' . "\n";
        if ($desc !== '') {
            $xml .= '  <subtitle>' . $this->x($desc) . '</subtitle>' . "\n";
        }
        $xml .= '  <link href="' . $this->x($siteUrl . '/') . '" rel="alternate" type="text/html"/>' . "\n";
        $xml .= '  <link href="' . $this->x($siteUrl . '/feed.xml') . '" rel="self" type="application/atom+xml"/>' . "\n";
        $xml .= '  <id>' . $this->x($feedId . '/feed.xml') . '</id>' . "\n";
        $xml .= '  <updated>' . $feedUpdated . '</updated>' . "\n";
        $xml .= '  <generator uri="https://github.com/php-mini-cms">php-mini-cms</generator>' . "\n";

        foreach ($posts as $post) {
            $postUrl = $siteUrl . '/' . Post::datePath($post['published_at'], $post['slug']) . '/';
            $html    = $this->converter->convert($post['content'])->getContent();

            $tinylyticsCode = $this->settings['tinylytics_code'] ?? '';
            if ($tinylyticsCode !== '') {
                $pixelUrl = 'https://tinylytics.app/pixel/' . rawurlencode($tinylyticsCode)
                    . '.gif?path=' . rawurlencode('/' . Post::datePath($post['published_at'], $post['slug']) . '/');
                $html .= '<img src="' . htmlspecialchars($pixelUrl, ENT_QUOTES | ENT_XML1, 'UTF-8') . '"'
                    . ' alt="" style="width:1px;height:1px;border:0;" />';
            }

            $xml .= '  <entry>' . "\n";
            $xml .= '    <title>' . $this->x($post['title']) . '</title>' . "\n";
            $xml .= '    <link href="' . $this->x($postUrl) . '" rel="alternate" type="text/html"/>' . "\n";
            $xml .= '    <id>' . $this->x($postUrl) . '</id>' . "\n";
            $xml .= '    <published>' . $this->atom($post['published_at']) . '</published>' . "\n";
            $xml .= '    <updated>' . $this->atom($post['updated_at'] ?? $post['published_at']) . '</updated>' . "\n";
            $xml .= '    <content type="html"><![CDATA[' . $html . ']]></content>' . "\n";
            $xml .= '  </entry>' . "\n";
        }

        $xml .= '</feed>' . "\n";

        return $xml;
    }

    /**
     * Render Atom 1.0 XML for a taxonomy term (category or tag).
     *
     * @param string  $type  'category' or 'tag'
     * @param array   $term  Assoc array with keys: id, name, slug
     * @param Post[]  $posts Published Post objects for this term
     */
    public function renderForTerm(string $type, array $term, array $posts): string
    {
        $count    = (int) ($this->settings['feed_post_count'] ?? 20);
        $siteUrl  = rtrim($this->settings['site_url'] ?? '', '/');
        $siteTitle = $this->settings['site_title'] ?? 'My CMS';
        $label    = $type === 'category' ? 'Category' : 'Tag';
        $title    = $siteTitle . ' — ' . $label . ': ' . $term['name'];
        $termUrl  = $siteUrl . '/' . $type . '/' . rawurlencode($term['slug']) . '/';
        $feedUrl  = $siteUrl . '/' . $type . '/' . rawurlencode($term['slug']) . '/feed.xml';

        $posts = array_slice($posts, 0, $count);

        $feedUpdated = !empty($posts)
            ? $this->atom($posts[0]->updated_at ?? $posts[0]->published_at)
            : $this->atom(date('Y-m-d H:i:s'));

        $feedId = $siteUrl ?: 'urn:uuid:' . sha1($siteTitle);

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<feed xmlns="http://www.w3.org/2005/Atom">' . "\n";
        $xml .= '  <title>' . $this->x($title) . '</title>' . "\n";
        $xml .= '  <link href="' . $this->x($termUrl) . '" rel="alternate" type="text/html"/>' . "\n";
        $xml .= '  <link href="' . $this->x($feedUrl) . '" rel="self" type="application/atom+xml"/>' . "\n";
        $xml .= '  <id>' . $this->x($feedId . '/' . $type . '/' . $term['slug'] . '/feed.xml') . '</id>' . "\n";
        $xml .= '  <updated>' . $feedUpdated . '</updated>' . "\n";
        $xml .= '  <generator uri="https://github.com/php-mini-cms">php-mini-cms</generator>' . "\n";

        foreach ($posts as $post) {
            $postUrl = $siteUrl . '/' . Post::datePath($post->published_at, $post->slug) . '/';
            $html    = $this->converter->convert($post->content)->getContent();

            $xml .= '  <entry>' . "\n";
            $xml .= '    <title>' . $this->x($post->title) . '</title>' . "\n";
            $xml .= '    <link href="' . $this->x($postUrl) . '" rel="alternate" type="text/html"/>' . "\n";
            $xml .= '    <id>' . $this->x($postUrl) . '</id>' . "\n";
            $xml .= '    <published>' . $this->atom($post->published_at) . '</published>' . "\n";
            $xml .= '    <updated>' . $this->atom($post->updated_at ?? $post->published_at) . '</updated>' . "\n";
            $xml .= '    <content type="html"><![CDATA[' . $html . ']]></content>' . "\n";
            $xml .= '  </entry>' . "\n";
        }

        $xml .= '</feed>' . "\n";

        return $xml;
    }

    /** XML-encode a plain string. */
    private function x(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Convert a SQLite datetime string to an Atom date (RFC 3339). */
    private function atom(string $dt): string
    {
        $ts = strtotime($dt);
        if ($ts === false) {
            error_log('[Feed] Invalid datetime value: ' . $dt);
            return date('Y-m-d\TH:i:s\Z');
        }
        return date('Y-m-d\TH:i:s\Z', $ts);
    }
}
