<?php

declare(strict_types=1);

namespace CMS;

use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/**
 * Custom CommonMark renderer for inline images.
 *
 * Adds:
 *   - loading="lazy" and decoding="async" on every image
 *   - width/height attributes (prevents CLS) for local /media/ files
 *   - <picture> element with a WebP <source> when a .webp sibling exists
 *
 * External image URLs receive lazy/async only; no dimensions or WebP fallback.
 */
class ImageRenderer implements NodeRendererInterface
{
    public function __construct(private readonly string $mediaDir) {}

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable|string|null
    {
        Image::assertInstanceOf($node);

        $url   = $node->getUrl();
        $title = $node->getTitle() ?? '';
        $alt   = strip_tags((string) $childRenderer->renderNodes($node->children()));

        $attrs = [
            'src'      => $url,
            'alt'      => $alt,
            'loading'  => 'lazy',
            'decoding' => 'async',
        ];

        if ($title !== '') {
            $attrs['title'] = $title;
        }

        // Enrich local /media/ images with dimensions and an optional WebP source.
        if (str_starts_with($url, '/media/')) {
            $filename     = basename(rawurldecode($url));
            $physicalPath = $this->mediaDir . '/' . $filename;

            if (is_file($physicalPath)) {
                // Inject width/height to prevent layout shift.
                $size = @getimagesize($physicalPath);
                if ($size !== false) {
                    $attrs['width']  = $size[0];
                    $attrs['height'] = $size[1];
                }

                // Wrap in <picture> when a WebP sibling exists (JPEG/PNG only).
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                    $webpPath = preg_replace('/\.[^.]+$/', '.webp', $physicalPath);
                    if ($webpPath !== null && is_file($webpPath)) {
                        $webpUrl = preg_replace('/\.[^.]+$/', '.webp', $url);
                        return $this->picture((string) $webpUrl, $attrs);
                    }
                }
            }
        }

        return $this->img($attrs);
    }

    /** Render a <picture> element with a WebP <source> and a fallback <img>. */
    private function picture(string $webpUrl, array $attrs): string
    {
        $source = '<source srcset="' . $this->esc($webpUrl) . '" type="image/webp">';
        return '<picture>' . $source . $this->img($attrs) . '</picture>';
    }

    /** Render a self-closing <img> element. */
    private function img(array $attrs): string
    {
        $html = '<img';
        foreach ($attrs as $name => $value) {
            $html .= ' ' . $name . '="' . $this->esc((string) $value) . '"';
        }
        return $html . '>';
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
