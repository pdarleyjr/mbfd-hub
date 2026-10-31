<?php

declare(strict_types=1);

namespace App\Services\Mail;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/** Text helpers for the Email Center: snippets, subjects, and an accurate plain-text view of sanitized HTML. */
final class MailText
{
    private const BLOCKS = ['p', 'div', 'ul', 'ol', 'li', 'blockquote', 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'table', 'tr', 'hr'];

    /** A one-line preview: the newest words of the message, quoted history and signatures' dividers cut off. */
    public function snippet(?string $text, ?string $html = null, int $limit = 140): string
    {
        $source = $text !== null && trim($text) !== '' ? $text : ($html !== null ? $this->fromHtml($html) : '');
        $kept = [];
        foreach (preg_split('/\R/u', $source) ?: [] as $line) {
            $trimmed = trim($line);
            if (preg_match('/^(On .{5,200} wrote:|-{2,}\s*(Original|Forwarded) message\s*-{2,}|_{5,}|From:\s.+)$/iu', $trimmed) === 1) {
                break;
            }
            if (str_starts_with($trimmed, '>') || $trimmed === '') {
                continue;
            }
            $kept[] = $trimmed;
        }
        $flat = trim((string) preg_replace('/\s+/u', ' ', implode(' ', $kept)));

        return mb_strlen($flat) > $limit ? rtrim(mb_substr($flat, 0, $limit - 1)).'…' : $flat;
    }

    /** The subject without leading Re:/Fwd: markers, so a thread is named for what it is about. */
    public function baseSubject(?string $subject): string
    {
        $subject = trim((string) $subject);
        while (preg_match('/^(re|fwd?|aw|sv)\s*(\[\d+\])?\s*:\s*/iu', $subject, $match) === 1) {
            $subject = substr($subject, strlen($match[0]));
        }

        return trim((string) preg_replace('/\s+/u', ' ', $subject));
    }

    public function replySubject(?string $subject): string
    {
        $subject = trim((string) $subject);

        return preg_match('/^re\s*:/iu', $subject) === 1 ? $subject : 'Re: '.$subject;
    }

    public function forwardSubject(?string $subject): string
    {
        $subject = trim((string) $subject);

        return preg_match('/^(fwd?|fw)\s*:/iu', $subject) === 1 ? $subject : 'Fwd: '.$subject;
    }

    /** Plain text for a message that has only HTML (or for the text alternative of composer output). */
    public function fromHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="utf-8" ?><body>'.$html.'</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $document->getElementsByTagName('body')->item(0);
        $text = $body instanceof DOMNode ? $this->render($body, 0, null) : '';
        $text = (string) preg_replace('/[ \t]+\n/u', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    private function render(DOMNode $node, int $quoteDepth, ?string $listType, int $listIndex = 0): string
    {
        $out = '';
        $index = 0;
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $out .= (string) preg_replace('/\s+/u', ' ', $child->wholeText);

                continue;
            }
            if (! $child instanceof DOMElement) {
                continue;
            }
            $name = strtolower($child->nodeName);
            if (in_array($name, ['script', 'style', 'head', 'title'], true)) {
                continue;
            }
            if ($name === 'br') {
                $out .= "\n";

                continue;
            }
            if ($name === 'a') {
                $label = trim($this->render($child, $quoteDepth, null));
                $href = trim($child->getAttribute('href'));
                $out .= $href !== '' && $label !== $href && $label !== preg_replace('/^mailto:/i', '', $href)
                    ? ($label === '' ? $href : $label.' ('.$href.')')
                    : ($label === '' ? $href : $label);

                continue;
            }
            if ($name === 'li') {
                $index++;
                $bullet = $listType === 'ol' ? $index.'. ' : '- ';
                $out .= "\n".$bullet.trim($this->render($child, $quoteDepth, null));

                continue;
            }
            $inner = $this->render($child, $quoteDepth + ($name === 'blockquote' ? 1 : 0), in_array($name, ['ul', 'ol'], true) ? $name : $listType);
            if ($name === 'blockquote') {
                $lines = array_map(static fn (string $line): string => '> '.$line, explode("\n", trim($inner)));
                $out .= "\n\n".implode("\n", $lines)."\n\n";
            } elseif (in_array($name, ['ul', 'ol'], true)) {
                $out .= "\n".trim($inner, " \t\r\n")."\n";
            } elseif (in_array($name, self::BLOCKS, true)) {
                $out .= "\n\n".trim($inner, " \t\r\n")."\n\n";
            } else {
                $out .= $inner;
            }
        }

        return $out;
    }
}
