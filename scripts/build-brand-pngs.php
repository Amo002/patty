<?php

/**
 * One-off PNG fallbacks for the favicon (D-042). Run by hand: `php scripts/build-brand-pngs.php`.
 *
 * Draws the same three layers as public/brand/favicon.svg (viewBox 32): the bun dome, the patty and the bottom bun.
 * GD has no vector paths, so each shape is built from arcs and rounded rectangles, drawn at 8x and scaled down for smooth edges.
 * Colours are the light-theme ones: a PNG cannot adapt to dark tabs the way the SVG does, which is why it is only the fallback.
 */
const SUPERSAMPLE = 8;
const VIEWBOX = 32;

/** A filled rectangle with all four corners rounded to radius $r. Coordinates are in viewBox units. */
function roundedRect(GdImage $img, float $k, float $x, float $y, float $w, float $h, float $r, int $colour): void
{
    $x1 = (int) round($x * $k);
    $y1 = (int) round($y * $k);
    $x2 = (int) round(($x + $w) * $k) - 1;
    $y2 = (int) round(($y + $h) * $k) - 1;
    $rr = (int) round($r * $k);
    $d = $rr * 2;

    imagefilledrectangle($img, $x1 + $rr, $y1, $x2 - $rr, $y2, $colour);
    imagefilledrectangle($img, $x1, $y1 + $rr, $x2, $y2 - $rr, $colour);
    imagefilledellipse($img, $x1 + $rr, $y1 + $rr, $d, $d, $colour);
    imagefilledellipse($img, $x2 - $rr, $y1 + $rr, $d, $d, $colour);
    imagefilledellipse($img, $x1 + $rr, $y2 - $rr, $d, $d, $colour);
    imagefilledellipse($img, $x2 - $rr, $y2 - $rr, $d, $d, $colour);
}

/**
 * @param  ?array{int,int,int}  $background  null for a transparent canvas
 * @param  float  $fill  how much of the canvas the artwork covers (icons need breathing room)
 */
function drawStack(int $size, ?array $background, float $fill, string $path): void
{
    $big = $size * SUPERSAMPLE;
    $img = imagecreatetruecolor($big, $big);
    imagealphablending($img, false);
    imagesavealpha($img, true);

    $clear = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $clear);

    if ($background !== null) {
        $bg = imagecolorallocate($img, ...$background);
        imagefilledrectangle($img, 0, 0, $big - 1, $big - 1, $bg);
    }

    // Shapes are drawn onto the opaque pixels, so blending can go back on.
    imagealphablending($img, true);

    $bun = imagecolorallocate($img, 0x18, 0x18, 0x1B);
    $patty = imagecolorallocate($img, 0x4F, 0x46, 0xE5);

    // $k converts viewBox units to pixels, and $offset centres the artwork on the canvas.
    $k = $big * $fill / VIEWBOX;
    $offset = ($big - VIEWBOX * $k) / 2;

    // Scale a viewBox coordinate into the offset canvas.
    $sx = fn (float $v): float => ($v * $k + $offset) / $k;

    // Bun dome: the top half of a 24 x 20 ellipse centred on (16, 16), plus the strip below it with rounded lower corners.
    imagefilledarc($img, (int) round($sx(16) * $k), (int) round($sx(16) * $k), (int) round(24 * $k), (int) round(20 * $k), 180, 360, $bun, IMG_ARC_PIE);
    imagefilledrectangle($img, (int) round($sx(4) * $k), (int) round($sx(16) * $k), (int) round($sx(28) * $k) - 1, (int) round($sx(17) * $k), $bun);
    roundedRect($img, $k, $sx(4), $sx(15.5), 24, 3, 1.5, $bun);

    roundedRect($img, $k, $sx(3), $sx(20.5), 26, 5, 2.5, $patty);
    roundedRect($img, $k, $sx(5), $sx(27.5), 22, 3.5, 1.75, $bun);

    $out = imagecreatetruecolor($size, $size);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopyresampled($out, $img, 0, 0, 0, 0, $size, $size, $big, $big);

    imagepng($out, $path, 9);
    echo "wrote {$path}\n";
}

if (! extension_loaded('gd')) {
    fwrite(STDERR, "The GD extension is required.\n");
    exit(1);
}

$dir = __DIR__.'/../public/brand';

drawStack(32, null, 1.0, "{$dir}/favicon-32.png");
drawStack(180, [255, 255, 255], 0.72, "{$dir}/apple-touch-icon.png");
drawStack(512, [255, 255, 255], 0.72, "{$dir}/icon-512.png");
