<?php

namespace App\Service;

use App\Mcp\OperationException;
use App\Support\ShopRichContent;
use DOMDocument;
use Illuminate\Support\Facades\Storage;

/** Local, content-addressed image uploads. Never accepts or fetches a remote URL. */
final class AdminMediaService
{
    public const MAX_BYTES = 4194304;
    public const MAX_BASE64 = 5592408;

    private static function decode(array $p): array
    {
        if (! preg_match('/\A[A-Za-z0-9_-]{1,120}\.(jpe?g|png|webp)\z/iD', $p['filename'] ?? '', $match)) {
            throw new OperationException('invalid_image_filename');
        }
        $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        $extension = strtolower($match[1]);
        $encoded = $p['contentBase64'] ?? '';
        if (strlen($encoded) > self::MAX_BASE64 || $encoded === '' || preg_match('/[^A-Za-z0-9+\/=]/', $encoded)) {
            throw new OperationException('invalid_image_base64');
        }
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || strlen($bytes) > self::MAX_BYTES || base64_encode($bytes) !== $encoded) {
            throw new OperationException('invalid_image_size_or_encoding');
        }
        $size = @getimagesizefromstring($bytes);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! $size || $mime !== $types[$extension] || ($p['mimeType'] ?? '') !== $mime || ($size['mime'] ?? '') !== $mime) {
            throw new OperationException('image_type_mismatch');
        }
        if ($size[0] < 1 || $size[1] < 1 || $size[0] > 8192 || $size[1] > 8192 || $size[0] * $size[1] > 16000000) {
            throw new OperationException('image_dimensions_exceeded');
        }
        // GD's native allocation is not consistently accounted by PHP. Bound both pixels
        // and a conservative decode/reencode budget before allocating any image resource.
        $limit = ini_parse_quantity((string) ini_get('memory_limit'));
        $budget = memory_get_usage(true) + $size[0] * $size[1] * 8 + strlen($encoded) * 2 + 16777216;
        if ($limit > 0 && $budget > $limit) { throw new OperationException('image_resource_budget_exceeded'); }
        if (! function_exists('imagecreatefromstring')) { throw new OperationException('image_decoder_unavailable'); }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) { throw new OperationException('invalid_image_content'); }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        ob_start();
        try {
            $ok = match ($mime) {
                'image/jpeg' => imagejpeg($image, null, 90),
                'image/png' => imagepng($image, null, 6),
                'image/webp' => function_exists('imagewebp') && imagewebp($image, null, 90),
            };
            $safe = ob_get_contents();
        } finally {
            ob_end_clean();
            // PHP 8.5 frees GdImage automatically; imagedestroy() is deprecated.
            unset($image);
        }
        if (! $ok || ! is_string($safe) || $safe === '' || strlen($safe) > self::MAX_BYTES) {
            throw new OperationException('image_reencoding_failed_or_too_large');
        }
        $extension = $mime === 'image/jpeg' ? 'jpg' : ($mime === 'image/png' ? 'png' : 'webp');
        $directory = ($p['purpose'] ?? 'product') === 'rich_content' ? 'images/rich-content/mcp/' : 'images/mcp/';
        $path = $directory.hash('sha256', $safe).'.'.$extension;

        return ['bytes' => $safe, 'path' => $path, 'mimeType' => $mime, 'size' => strlen($safe), 'width' => $size[0], 'height' => $size[1]];
    }

    public static function upload(array $p, bool $write): array
    {
        $image = self::decode($p);
        $bytes = $image['bytes'];
        unset($image['bytes']);
        $exists = Storage::disk('admin')->exists($image['path']);
        if ($exists && ! hash_equals(hash('sha256', $bytes), hash('sha256', Storage::disk('admin')->get($image['path'])))) {
            throw new OperationException('media_storage_integrity_conflict');
        }
        if ($write && ! $exists) {
            try {
                // Only reencoded pixels are written, never the submitted filename or bytes.
                if (! Storage::disk('admin')->put($image['path'], $bytes, ['visibility' => 'public'])) {
                    throw new OperationException('media_storage_failed');
                }
            } catch (\Throwable) {
                throw new OperationException('media_storage_failed');
            }
        }
        $image['url'] = Storage::disk('admin')->url($image['path']);
        return $image + ['id' => $image['path'], 'before' => $exists ? $image : null, 'after' => $image,
            'changed' => $exists ? 0 : 1, 'unchanged' => $exists ? 1 : 0, 'skipped' => 0];
    }

    public static function assertImagePath(string $path): void
    {
        if (! preg_match('/\Aimages\/(?:[A-Za-z0-9_-]+\/){0,3}[A-Za-z0-9_-]+\.(?:jpe?g|png|webp)\z/iD', $path)
            || strlen($path) > 255 || ! Storage::disk('admin')->exists($path)) {
            throw new OperationException('invalid_image_path');
        }
    }

    /** Keep Filament's data-id and proportional width schema without trusting attachment paths. */
    public static function sanitizeHtml(string $html): string
    {
        if ($html === '') { return ''; }
        if (strlen($html) > 60000 || ! mb_check_encoding($html, 'UTF-8') || str_contains($html, "\0")) {
            throw new OperationException('invalid_rich_content');
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET);
        } finally {
            libxml_clear_errors(); libxml_use_internal_errors($previous);
        }
        foreach ($document->getElementsByTagName('img') as $image) {
            if ($image->hasAttribute('data-id')) {
                $path = $image->getAttribute('data-id');
                self::assertImagePath($path);
                $image->setAttribute('src', Storage::disk('admin')->url($path));
            }
        }
        $body = '';
        foreach ($document->getElementsByTagName('body')->item(0)->childNodes as $node) { $body .= $document->saveHTML($node); }
        $safe = ShopRichContent::render($body, true);
        if (strlen($safe) > 65535) { throw new OperationException('rich_content_too_large'); }
        return $safe;
    }
}
