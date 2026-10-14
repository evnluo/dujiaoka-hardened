<?php

namespace App\Support;

use DOMDocument;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class ShopRichContent
{
    public static function render(?string $html, bool $keepAttachmentIds = false): string
    {
        if (blank($html)) {
            return '';
        }

        // Do not rewrite stored legacy HTML. Sanitize only the public rendering boundary.
        $config = (new HtmlSanitizerConfig)->allowSafeElements()->allowRelativeLinks()->allowRelativeMedias()
            ->allowAttribute('style', ['img'])->allowAttribute('width', ['img'])->withMaxInputLength(500000);
        if ($keepAttachmentIds) {
            $config = $config->allowAttribute('data-id', ['img']);
        }
        $safe = (new HtmlSanitizer($config))->sanitize($html);
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $safe . '</body></html>', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        foreach ($document->getElementsByTagName('img') as $image) {
            preg_match('/(?:^|;)\s*width\s*:\s*([^;]+)/i', $image->getAttribute('style'), $match);
            $width = trim($match[1] ?? $image->getAttribute('width'));
            $image->removeAttribute('style');
            $image->removeAttribute('height');
            $image->removeAttribute('width');
            if (preg_match('/^[1-9]\d{0,4}(?:\.\d+)?(?:px|%)?$/', $width)) {
                $image->setAttribute('style', 'width: ' . (is_numeric($width) ? $width . 'px' : $width));
            }
        }
        $result = '';
        foreach ($document->getElementsByTagName('body')->item(0)->childNodes as $node) {
            $result .= $document->saveHTML($node);
        }

        return $result;
    }
}
