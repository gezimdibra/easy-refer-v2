<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * On-the-fly QR images (spec Section 3 Steps 5 and 11a, audit M6):
 * rendered per request as an SVG image response, never written to a
 * web-accessible file.
 */
final class QrImage
{
    public static function svg(string $contents, int $size = 320): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size),
            new SvgImageBackEnd(),
        );

        return (new Writer($renderer))->writeString($contents);
    }
}
