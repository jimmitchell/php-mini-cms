<?php

declare(strict_types=1);

namespace CMS;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
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
        $dir  = $this->outputDir . '/posts/' . $post->slug;
        $path = $dir . '/index.html';

        if ($post->status !== 'published') {
            $this->removeFile($path);
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

        // Keep the search index and page in sync with published posts.
        $this->buildSearchIndex();
        $this->buildSearchPage();
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
                'url'     => $siteUrl . '/posts/' . rawurlencode($post->slug) . '/',
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
     * Full site rebuild: all published posts, pages, index, feed, search.
     */
    public function buildAll(): void
    {
        $this->refreshContext();
        $this->migrateOldPagePaths();

        foreach (Post::findAll($this->db) as $post) {
            $this->buildPost($post);
        }
        foreach (Page::findAll($this->db) as $page) {
            $this->buildPage($page);
        }
        $this->buildIndex();
        $this->buildFeed();
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
        $ogPath = $this->outputDir . '/posts/' . $post->slug . '/og.png';
        $siteUrl = rtrim($this->settings['site_url'] ?? '', '/');

        if (!extension_loaded('gd')) {
            // Return existing URL if the image was previously generated.
            return file_exists($ogPath) ? $siteUrl . '/posts/' . $post->slug . '/og.png' : '';
        }

        $siteTitle = $this->settings['site_title'] ?? '';
        $ogHash    = hash('sha256', $siteTitle . '|' . $post->title);

        if ($ogHash !== $post->og_image_hash) {
            try {
                $og = new OgImage($this->fontDir);
                $og->generate($siteTitle, $post->title, $ogPath);
                $post->markOgBuilt($ogHash);
            } catch (\RuntimeException $e) {
                // Non-fatal: log to stderr and continue.
                error_log('[OgImage] ' . $e->getMessage());
            }
        }

        return file_exists($ogPath) ? $siteUrl . '/posts/' . $post->slug . '/og.png' : '';
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
        file_put_contents($path, $content);
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
