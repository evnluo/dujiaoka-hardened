<?php

namespace App\Support;

use Endroid\QrCode\QrCode as NativeQrCode;
use Endroid\QrCode\Writer\PngWriter;

// Retain the storefront's QrCode::format('png')->size(...)->generate(...) contract.
final class QrCode
{
    private int $pixels = 200;

    public static function format(string $format): self
    {
        if ($format !== 'png') throw new \InvalidArgumentException('The storefront QR helper generates PNG images.');
        return new self();
    }

    public function size(int $pixels): self
    {
        if ($pixels < 32 || $pixels > 2048) throw new \InvalidArgumentException('Invalid QR image size.');
        $this->pixels = $pixels;
        return $this;
    }

    public function generate(string $text): string
    {
        return (new PngWriter())->write(new NativeQrCode(data: $text, size: $this->pixels))->getString();
    }
}
