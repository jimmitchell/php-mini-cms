<?php

declare(strict_types=1);

namespace CMS;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\Footnote\FootnoteExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

class Builder
{
    private Database           $db;
    private MarkdownConverter $md;
    private string             $outputDir;
    private string             $templateDir;
    private string             $mediaDir;
    private string             $fontDir;
    private array              $settings;
    private array              $navPages;

    public function __construct(array $config, Database $db)
    {
        $this->db          = $db;
        $this->outputDir   = rtrim($config['paths']['output'],   '/\\');
        $this->templateDir = rtrim($config['paths']['templates'], '/\\');
        $this->mediaDir    = rtrim($config['paths']['content'],   '/\\') . '/media';
        $this->fontDir     = $this->outputDir . '/fonts/og';

        // Allow trusted admin to embed <video>/<audio> in Markdown.
        $env = new Environment([
            'html_input'         => 'allow',
            'allow_unsafe_links' => false,
        ]);
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new GithubFlavoredMarkdownExtension());
        $env->addExtension(new FootnoteExtension());
        $env->addRenderer(FencedCode::class, new HighlightFencedCodeRenderer());
        $env->addRenderer(Image::class, new ImageRenderer($this->mediaDir));
        $this->md = new MarkdownConverter($env);

        $this->refreshContext();
    }

    // ── Public build API ──────────────────────────────────────────────────────

    /**
     * Rebuild a single published post.
     * If the post is not published, removes its output file instead.
     */
    public function buildPost(Post $post): void
    {
        $dir  = $this->outputDir . '/posts/' . Post::datePath($post->published_at ?? date('Y-m-d H:i:s'), $post->slug);
        $path = $dir . '/index.html';

        if ($post->status !== 'published') {
            $this->removeFile($path);
            // Rebuild any archives this post was in so it no longer appears there.
            foreach ($post->categories as $cat) {
                $this->buildCategoryArchive((int) $cat['id']);
            }
            foreach ($post->tags as $tag) {
                $this->buildTagArchive((int) $tag['id']);
            }
            return;
        }

        // Generate OG image first so the URL is available to the HTML template.
        $ogImageUrl = $this->buildOgImage($post);

        $html     = $this->md->convert($post->content)->getContent();
        $prevPost = Post::findPrev($this->db, $post);
        $nextPost = Post::findNext($this->db, $post);
        $rendered = $this->render('post.php', [
            'post'        => $post,
            'html'        => $html,
            'prevPost'    => $prevPost,
            'nextPost'    => $nextPost,
            'ogImageUrl'  => $ogImageUrl,
        ]);
        $hash     = hash('sha256', $rendered);

        if ($hash !== $post->content_hash) {
            $this->writeFile($path, $rendered);
            $post->markBuilt($hash);
        }

        // Rebuild taxonomy archive pages for this post's terms.
        foreach ($post->categories as $cat) {
            $this->buildCategoryArchive((int) $cat['id']);
        }
        foreach ($post->tags as $tag) {
            $this->buildTagArchive((int) $tag['id']);
        }
    }

    /**
     * Rebuild a single published page.
     * If the page is not published, removes its output file instead.
     */
    public function buildPage(Page $page): void
    {
        $dir  = $this->outputDir . '/pages/' . $page->slug;
        $path = $dir . '/index.html';

        if ($page->status !== 'published') {
            $this->removeFile($path);
            return;
        }

        $html     = $this->md->convert($page->content)->getContent();
        $rendered = $this->render('page.php', ['page' => $page, 'html' => $html]);
        $hash     = hash('sha256', $rendered);

        if ($hash !== $page->content_hash) {
            $this->writeFile($path, $rendered);
            $page->markBuilt($hash);
        }
    }

    /**
     * Rebuild all paginated index pages and remove stale ones.
     */
    public function buildIndex(): void
    {
        $perPage = max(1, (int) ($this->settings['posts_per_page'] ?? 10));

        $allPosts = Post::findAll($this->db, 'published');
        $total    = count($allPosts);
        $pages    = max(1, (int) ceil($total / $perPage));

        for ($p = 1; $p <= $pages; $p++) {
            $slice    = array_slice($allPosts, ($p - 1) * $perPage, $perPage);
            $rendered = $this->render('index.php', [
                'posts'       => $slice,
                'currentPage' => $p,
                'totalPages'  => $pages,
                'totalPosts'  => $total,
            ]);

            $path = $p === 1
                ? $this->outputDir . '/index.html'
                : $this->outputDir . '/page/' . $p . '/index.html';

            $this->writeFile($path, $rendered);
        }

        // Remove stale paginated pages beyond the new total.
        $this->removeStalePaginationPages($pages);

        // Keep the search index, search page, and 404 in sync with published posts.
        $this->buildSearchIndex();
        $this->buildSearchPage();
        $this->build404();
    }

    /**
     * Render and write feed.xml.
     */
    public function buildFeed(): void
    {
        $feed = new Feed($this->db, $this->settings);
        $xml  = $feed->render();
        $this->writeFile($this->outputDir . '/feed.xml', $xml);
    }

    /**
     * Render and write feed.json (JSON Feed 1.1).
     */
    public function buildJsonFeed(): void
    {
        $feed = new JsonFeed($this->db, $this->settings);
        $this->writeFile($this->outputDir . '/feed.json', $feed->render());
    }

    /**
     * Generate sitemap.xml listing all published posts and pages.
     * Skipped silently when site_url is not configured.
     */
    public function buildSitemap(): void
    {
        $siteUrl = rtrim($this->settings['site_url'] ?? '', '/');
        if ($siteUrl === '') {
            return;
        }

        $posts = Post::findAll($this->db, 'published');
        $pages = Page::findAll($this->db, 'published');

        $x = fn(string $v) => htmlspecialchars($v, ENT_XML1, 'UTF-8');

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        // Homepage
        $lines[] = '  <url>';
        $lines[] = '    <loc>' . $x($siteUrl . '/') . '</loc>';
        $lines[] = '  </url>';

        // Published posts
        foreach ($posts as $post) {
            if ($post->published_at === null) {
                continue;
            }
            $url     = $siteUrl . '/' . Post::datePath($post->published_at, $post->slug) . '/';
            $lastmod = substr($post->updated_at ?: $post->published_at, 0, 10);
            $lines[] = '  <url>';
            $lines[] = '    <loc>' . $x($url) . '</loc>';
            $lines[] = '    <lastmod>' . $x($lastmod) . '</lastmod>';
            $lines[] = '  </url>';
        }

        // Published pages
        foreach ($pages as $page) {
            $url     = $siteUrl . '/' . $page->slug . '/';
            $lastmod = substr($page->updated_at, 0, 10);
            $lines[] = '  <url>';
            $lines[] = '    <loc>' . $x($url) . '</loc>';
            $lines[] = '    <lastmod>' . $x($lastmod) . '</lastmod>';
            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        $this->writeFile($this->outputDir . '/sitemap.xml', implode("\n", $lines) . "\n");
    }

    /**
     * Write search.json — an array of published posts for client-side search.
     */
    public function buildSearchIndex(): void
    {
        $posts   = Post::findAll($this->db, 'published');
        $siteUrl = rtrim($this->settings['site_url'] ?? '', '/');
        $locale  = $this->settings['locale']   ?? '';
        $tz      = $this->settings['timezone'] ?? '';

        $data = [];
        foreach ($posts as $post) {
            $excerpt  = $post->effectiveExcerpt();
            $data[] = [
                'title'   => $post->title,
                'url'     => $siteUrl . '/' . Post::datePath($post->published_at ?? date('Y-m-d H:i:s'), $post->slug) . '/',
                'excerpt' => $excerpt !== null ? strip_tags($excerpt) : '',
                'date'    => $post->published_at
                    ? Helpers::formatDate($post->published_at, 'M j, Y', $locale, $tz)
                    : '',
            ];
        }

        $this->writeFile(
            $this->outputDir . '/search.json',
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Write search/index.html — the static search results page.
     */
    public function buildSearchPage(): void
    {
        $rendered = $this->render('search.php', []);
        $this->writeFile($this->outputDir . '/search/index.html', $rendered);
    }

    /**
     * Render and write 404.html — the themed not-found page.
     */
    public function build404(): void
    {
        $rendered = $this->render('404.php', []);
        $this->writeFile($this->outputDir . '/404.html', $rendered);
    }

    /**
     * Generate theme.min.css from theme.css.
     * Safe to call repeatedly — skips silently if theme.css is absent.
     */
    public function buildCss(): void
    {
        $src  = $this->outputDir . '/theme.css';
        $dest = $this->outputDir . '/theme.min.css';

        if (!file_exists($src)) {
            return;
        }

        $this->writeFile($dest, $this->minifyCss((string) file_get_contents($src)));
    }

    /**
     * Full site rebuild: all published posts, pages, index, feed, search.
     */
    public function buildAll(): void
    {
        $this->refreshContext();
        $this->buildCss();
        $this->migrateOldPagePaths();
        $this->migrateOldPostPaths();

        // Clear stored hashes so every file is force-regenerated.
        $this->db->exec("UPDATE posts SET content_hash = NULL");
        $this->db->exec("UPDATE pages SET content_hash = NULL");

        foreach (Post::findAll($this->db) as $post) {
            $this->buildPost($post);
        }
        foreach (Page::findAll($this->db) as $page) {
            $this->buildPage($page);
        }
        $this->buildIndex();
        $this->buildFeed();
        $this->buildJsonFeed();
        $this->buildSitemap();
        $this->buildAllTaxonomyArchives();
    }

    /**
     * Rebuild the static archive page for a single category.
     */
    public function buildCategoryArchive(int $categoryId): void
    {
        $cat = $this->db->selectOne("SELECT * FROM categories WHERE id = ?", [$categoryId]);
        if ($cat === null) {
            return;
        }

        $posts    = Post::findByCategory($this->db, $categoryId);
        $path     = $this->outputDir . '/category/' . $cat['slug'] . '/index.html';
        $rendered = $this->render('taxonomy.php', [
            'type'  => 'category',
            'term'  => $cat,
            'posts' => $posts,
        ]);
        $this->writeFile($path, $rendered);
    }

    /**
     * Rebuild the static archive page for a single tag.
     */
    public function buildTagArchive(int $tagId): void
    {
        $tag = $this->db->selectOne("SELECT * FROM tags WHERE id = ?", [$tagId]);
        if ($tag === null) {
            return;
        }

        $posts    = Post::findByTag($this->db, $tagId);
        $path     = $this->outputDir . '/tag/' . $tag['slug'] . '/index.html';
        $rendered = $this->render('taxonomy.php', [
            'type'  => 'tag',
            'term'  => $tag,
            'posts' => $posts,
        ]);
        $this->writeFile($path, $rendered);
    }

    /**
     * Rebuild all category and tag archive pages, removing any stale directories
     * whose terms have been deleted.
     */
    public function buildAllTaxonomyArchives(): void
    {
        $categories = $this->db->select("SELECT * FROM categories ORDER BY name");
        $validCatSlugs = [];
        foreach ($categories as $cat) {
            $this->buildCategoryArchive((int) $cat['id']);
            $validCatSlugs[] = $cat['slug'];
        }

        $tags = $this->db->select("SELECT * FROM tags ORDER BY name");
        $validTagSlugs = [];
        foreach ($tags as $tag) {
            $this->buildTagArchive((int) $tag['id']);
            $validTagSlugs[] = $tag['slug'];
        }

        // Remove stale category archive directories.
        $catDir = $this->outputDir . '/category';
        if (is_dir($catDir)) {
            foreach (scandir($catDir) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (!in_array($entry, $validCatSlugs, true)) {
                    $stale = $catDir . '/' . $entry . '/index.html';
                    $this->removeFile($stale);
                    $dir = $catDir . '/' . $entry;
                    if (is_dir($dir) && count(scandir($dir)) === 2) {
                        rmdir($dir);
                    }
                }
            }
        }

        // Remove stale tag archive directories.
        $tagDir = $this->outputDir . '/tag';
        if (is_dir($tagDir)) {
            foreach (scandir($tagDir) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (!in_array($entry, $validTagSlugs, true)) {
                    $stale = $tagDir . '/' . $entry . '/index.html';
                    $this->removeFile($stale);
                    $dir = $tagDir . '/' . $entry;
                    if (is_dir($dir) && count(scandir($dir)) === 2) {
                        rmdir($dir);
                    }
                }
            }
        }
    }

    /**
     * Generate an OG image for a published post if the title (or site title)
     * has changed since the image was last generated.
     * Silently skips if the GD extension is unavailable.
     *
     * Returns the absolute public URL of the OG image, or '' on failure/skip.
     */
    private function buildOgImage(Post $post): string
    {
        $datePath = Post::datePath($post->published_at ?? date('Y-m-d H:i:s'), $post->slug);
        $ogPath   = $this->outputDir . '/posts/' . $datePath . '/og.png';
        $siteUrl  = rtrim($this->settings['site_url'] ?? '', '/');

        if (!extension_loaded('gd')) {
            // Return existing URL if the image was previously generated.
            return file_exists($ogPath) ? $siteUrl . '/' . $datePath . '/og.png' : '';
        }

        $siteTitle  = $this->settings['site_title'] ?? '';
        $fontStamp  = (string) (@filemtime($this->fontDir . '/ProximaNova-Bold.ttf') ?: 0);
        $ogHash     = hash('sha256', $fontStamp . '|' . $siteTitle . '|' . $post->title);

        if ($ogHash !== $post->og_image_hash || !file_exists($ogPath)) {
            try {
                $og = new OgImage($this->fontDir);
                $og->generate($siteTitle, $post->title, $ogPath);
                $post->markOgBuilt($ogHash);
            } catch (\RuntimeException $e) {
                // Non-fatal: log to stderr and continue.
                error_log('[OgImage] ' . $e->getMessage());
            }
        }

        return file_exists($ogPath) ? $siteUrl . '/' . $datePath . '/og.png' : '';
    }

    /**
     * Remove post output files that were written as posts/{slug}/index.html
     * before URLs were changed to posts/{year}/{month}/{day}/{slug}/index.html.
     * Safe to call repeatedly — only removes flat (non-date) slug directories.
     */
    private function migrateOldPostPaths(): void
    {
        $postsDir = $this->outputDir . '/posts';
        if (!is_dir($postsDir)) {
            return;
        }

        $migrated = false;
        foreach (scandir($postsDir) as $entry) {
            // Skip dots and any entry that looks like a 4-digit year (new format).
            if ($entry === '.' || $entry === '..' || is_numeric($entry)) {
                continue;
            }
            $oldDir = $postsDir . '/' . $entry;
            if (is_dir($oldDir) && file_exists($oldDir . '/index.html')) {
                $this->removeFile($oldDir . '/index.html');
                $this->removeFile($oldDir . '/og.png');
                $migrated = true;
            }
        }

        if ($migrated) {
            $this->db->exec("UPDATE posts SET content_hash = NULL, built_at = NULL, og_image_hash = NULL");
        }
    }

    /**
     * Remove page output files that were written to the project root before
     * pages were moved to the pages/ subdirectory. Safe to call repeatedly —
     * only deletes {outputDir}/{slug}/index.html, never anything inside pages/.
     */
    private function migrateOldPagePaths(): void
    {
        $migrated = false;
        foreach (Page::findAll($this->db) as $page) {
            $oldPath = $this->outputDir . '/' . $page->slug . '/index.html';
            if (file_exists($oldPath)) {
                $this->removeFile($oldPath);
                $migrated = true;
            }
        }
        // If any old files were removed, clear all page hashes so buildPage()
        // is forced to write to the new pages/ location on this run.
        if ($migrated) {
            $this->db->exec("UPDATE pages SET content_hash = NULL, built_at = NULL");
        }
    }

    // ── Rendering ─────────────────────────────────────────────────────────────

    /**
     * Render a template file to a string.
     * The template has access to: all $vars keys, $settings, $navPages, $siteUrl, $builder.
     *
     * @param array<string,mixed> $vars
     */
    private function render(string $template, array $vars): string
    {
        // Make shared context available inside the template scope.
        $vars['settings'] = $this->settings;
        $vars['navPages'] = $this->navPages;
        $vars['siteUrl']  = rtrim($this->settings['site_url'] ?? '', '/');

        $templateDir = $this->templateDir;

        $render = static function (string $tpl, array $v) use ($templateDir): string {
            extract($v, EXTR_SKIP);
            ob_start();
            include $templateDir . '/' . $tpl;
            return (string) ob_get_clean();
        };

        // Give templates access to a $render closure for including base.php.
        $vars['render'] = $render;

        return $render($template, $vars);
    }

    // ── File I/O ──────────────────────────────────────────────────────────────

    private function writeFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        if (str_ends_with($path, '.html')) {
            $content = $this->minifyHtml($content);
        }
        file_put_contents($path, $content);
    }

    /**
     * Strip insignificant whitespace from HTML output.
     * Protects <pre>, <script>, and <style> blocks verbatim.
     */
    private function minifyHtml(string $html): string
    {
        $tokens = [];
        $i      = 0;

        // Preserve blocks where whitespace is significant.
        $html = preg_replace_callback(
            '/<(pre|script|style|textarea)(\s[^>]*)?>[\s\S]*?<\/\1>/i',
            static function (array $m) use (&$tokens, &$i): string {
                $key          = "\x02BLOCK{$i}\x03";
                $tokens[$key] = $m[0];
                $i++;
                return $key;
            },
            $html
        );

        // Remove HTML comments (keep IE conditionals: <!--[if ...>).
        $html = preg_replace('/<!--(?!\[if\s)[\s\S]*?-->/i', '', $html);

        // Strip leading whitespace (indentation) from every line.
        $html = preg_replace('/^\s+/m', '', $html);

        // Collapse runs of blank lines to nothing.
        $html = preg_replace('/\n{2,}/', "\n", $html);

        // Restore protected blocks.
        return strtr(trim($html), $tokens);
    }

    /**
     * Minify a CSS string: strip comments, collapse whitespace,
     * remove spaces around structural characters.
     */
    private function minifyCss(string $css): string
    {
        // Remove /* ... */ comments.
        $css = preg_replace('/\/\*[\s\S]*?\*\//', '', $css);

        // Collapse all whitespace (spaces, tabs, newlines) to a single space.
        $css = preg_replace('/\s+/', ' ', $css);

        // Remove spaces around structural characters.
        $css = preg_replace('/\s*([{}:;,>+~])\s*/', '$1', $css);

        // Drop the redundant semicolon before a closing brace.
        $css = str_replace(';}', '}', $css);

        return trim($css);
    }

    private function removeFile(string $path): void
    {
        if (file_exists($path)) {
            unlink($path);
            // Remove parent directory if now empty.
            $dir = dirname($path);
            if (is_dir($dir) && count(scandir($dir)) === 2) {
                rmdir($dir);
            }
        }
    }

    private function removeStalePaginationPages(int $validPageCount): void
    {
        $pageDir = $this->outputDir . '/page';
        if (!is_dir($pageDir)) {
            return;
        }
        foreach (scandir($pageDir) as $entry) {
            if (!is_numeric($entry)) {
                continue;
            }
            $n = (int) $entry;
            if ($n > $validPageCount) {
                $stale = $pageDir . '/' . $entry . '/index.html';
                $this->removeFile($stale);
                $dir = $pageDir . '/' . $entry;
                if (is_dir($dir) && count(scandir($dir)) === 2) {
                    rmdir($dir);
                }
            }
        }
    }

    // ── Context ───────────────────────────────────────────────────────────────

    /**
     * (Re)load site settings and published nav pages from the DB.
     * Called on construction and before buildAll().
     */
    private function refreshContext(): void
    {
        $this->settings = $this->db->getAllSettings();
        $this->navPages = array_values(array_filter(
            Page::findAll($this->db, 'published'),
            fn($p) => $p->nav_order > 0
        ));
    }
}
