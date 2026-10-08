<?php

namespace App\Support;

use Illuminate\Support\Str;

final class PurchasePrompt
{
    public static function hasContent(?string $html): bool
    {
        $safe = Str::sanitizeHtml($html ?? '');
        $text = html_entity_decode(strip_tags($safe), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_replace('/[\s\p{Z}\p{Cf}]+/u', '', $text) !== '') {
            return true;
        }

        return (bool) preg_match('/<img\b[^>]*\bsrc=["\'][^"\']+["\']/i', $safe);
    }
}
