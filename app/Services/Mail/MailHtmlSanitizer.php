<?php

declare(strict_types=1);

namespace App\Services\Mail;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Two policies, both server-side, both from the Symfony HtmlSanitizer already in the stack.
 *
 * composer(): what a member types in the editor. Safe formatting and http/https/mailto links only; no
 *   images, styles, classes or relative links.
 * display(): received or already-sent HTML shown in the sandboxed body route. A wider element set but still
 *   no images (remote content never loads), scripts, forms, styles or non-web link schemes. Every link gets
 *   rel="noopener noreferrer" and opens in a new context.
 */
final class MailHtmlSanitizer
{
    private const SCHEMES = ['http', 'https', 'mailto'];

    private const MAX_INPUT = 400000;

    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select',
        'head', 'title', 'meta', 'link', 'base', 'svg', 'math', 'template', 'noscript', 'img', 'picture', 'audio', 'video', 'frame', 'frameset'];

    public function composer(string $html): string
    {
        $config = (new HtmlSanitizerConfig)
            ->withMaxInputLength(self::MAX_INPUT)
            ->allowLinkSchemes(self::SCHEMES)
            ->allowRelativeLinks(false)
            ->allowMediaSchemes([]);
        foreach (['p', 'div', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'h1', 'h2', 'h3'] as $element) {
            $config = $config->allowElement($element);
        }
        $config = $config->allowElement('a', ['href']);
        $config = $this->hardenLinks($config);
        foreach (self::DROP as $element) {
            $config = $config->dropElement($element);
        }

        return trim((new HtmlSanitizer($config))->sanitize($html));
    }

    public function display(string $html): string
    {
        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->withMaxInputLength(self::MAX_INPUT)
            ->allowLinkSchemes(self::SCHEMES)
            ->allowRelativeLinks(false)
            ->allowMediaSchemes([]);
        $config = $this->hardenLinks($config);
        foreach (self::DROP as $element) {
            $config = $config->dropElement($element);
        }
        foreach (['style', 'class', 'id', 'srcset', 'src', 'background'] as $attribute) {
            $config = $config->dropAttribute($attribute, '*');
        }

        return trim((new HtmlSanitizer($config))->sanitize($html));
    }

    private function hardenLinks(HtmlSanitizerConfig $config): HtmlSanitizerConfig
    {
        return $config
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->forceAttribute('a', 'target', '_blank');
    }
}
