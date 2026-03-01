<?php

declare(strict_types=1);

namespace CMS;

use Highlight\Highlighter;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\Xml;

class HighlightFencedCodeRenderer implements NodeRendererInterface
{
    private Highlighter $hl;

    public function __construct()
    {
        $this->hl = new Highlighter();
        $this->hl->setAutodetectLanguages([
            'php', 'javascript', 'typescript', 'python', 'bash', 'shell',
            'html', 'css', 'json', 'yaml', 'sql', 'go', 'rust',
        ]);
    }

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable|string|null
    {
        FencedCode::assertInstanceOf($node);

        $code  = $node->getLiteral();
        $words = $node->getInfoWords();
        $lang  = $words[0] ?? '';

        try {
            if ($lang !== '') {
                $result  = $this->hl->highlight($lang, $code);
                $langClass = 'language-' . htmlspecialchars($result->language, ENT_QUOTES, 'UTF-8');
                $inner   = $result->value;
            } else {
                $result  = $this->hl->highlightAuto($code);
                $langClass = 'language-' . htmlspecialchars($result->language, ENT_QUOTES, 'UTF-8');
                $inner   = $result->value;
            }
        } catch (\Exception) {
            // Unknown language — fall back to plain escaped output.
            return '<pre><code class="language-' . htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') . '">'
                . Xml::escape($code)
                . "</code></pre>\n";
        }

        return '<pre class="syntax-hl"><code class="hljs ' . $langClass . '">'
            . $inner
            . "</code></pre>\n";
    }
}
