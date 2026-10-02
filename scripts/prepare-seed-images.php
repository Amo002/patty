<?php

/*
 * PTY-22 / D-036: turns the original photos into the small square WebP files the demo serves.
 *
 *   php scripts/prepare-seed-images.php
 *
 * Reads  storage/app/seed-originals/{ingredients,menu}/<slug>.(jpg|jpeg|png|webp)   (gitignored, never committed)
 * Writes public/images/seed/{ingredients,menu}/<slug>.webp                          (480x480, quality 80, committed)
 *
 * Idempotent: the output is rewritten from the original each time, so running it twice changes nothing.
 * Needs PHP GD with WebP support. Each photo's source, photographer and licence go in public/images/seed/CREDITS.md (S17).
 */

const SIZE = 480;
const QUALITY = 80;

if (! function_exists('imagewebp')) {
    fwrite(STDERR, "PHP GD with WebP support is required.\n");
    exit(1);
}

$root = dirname(__DIR__);
$written = 0;

foreach (['ingredients', 'menu'] as $folder) {
    $files = glob("{$root}/storage/app/seed-originals/{$folder}/*.{jpg,jpeg,png,webp}", GLOB_BRACE) ?: [];
    sort($files);

    foreach ($files as $file) {
        $slug = pathinfo($file, PATHINFO_FILENAME);
        $source = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'png' => imagecreatefrompng($file),
            'webp' => imagecreatefromwebp($file),
            default => imagecreatefromjpeg($file),
        };

        if ($source === false) {
            fwrite(STDERR, "Skipped {$file}: not a readable image.\n");

            continue;
        }

        // Center-crop to a square first, so the resize never stretches the photo.
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $square = imagecrop($source, [
            'x' => intdiv($width - $side, 2),
            'y' => intdiv($height - $side, 2),
            'width' => $side,
            'height' => $side,
        ]);

        $out = imagecreatetruecolor(SIZE, SIZE);
        imagecopyresampled($out, $square, 0, 0, 0, 0, SIZE, SIZE, $side, $side);

        $dir = "{$root}/public/images/seed/{$folder}";
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        imagewebp($out, "{$dir}/{$slug}.webp", QUALITY);
        $written++;
        echo "{$folder}/{$slug}.webp\n";
    }
}

echo "{$written} image(s) written.\n";
