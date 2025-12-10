<?php
/**
 * Image optimization helper
 *
 * Saves an uploaded image with resizing and compression to reduce payloads.
 * Falls back to failure (caller can decide to reject or move original).
 *
 * @param string $tmpPath   Temporary upload path
 * @param string $destPath  Destination path (including filename)
 * @param int    $maxWidth  Max width to resize to (height auto-scales)
 * @param int    $quality   Quality for JPEG/WebP (0-100) or PNG compression level (0-9)
 * @return array [bool success, string|null error]
 */
function saveOptimizedImage(string $tmpPath, string $destPath, int $maxWidth = 1600, int $quality = 82): array
{
    $info = @getimagesize($tmpPath);
    if (!$info || empty($info['mime'])) {
        return [false, 'Invalid image file.'];
    }

    [$width, $height] = $info;
    $mime = $info['mime'];

    // Choose create/save handlers
    $createFn = null;
    $saveFn = null;
    $pngCompression = 6; // 0 (no compression) to 9

    switch ($mime) {
        case 'image/jpeg':
        case 'image/jpg':
            $createFn = 'imagecreatefromjpeg';
            $saveFn = 'imagejpeg';
            break;
        case 'image/png':
            $createFn = 'imagecreatefrompng';
            $saveFn = 'imagepng';
            break;
        case 'image/webp':
            if (function_exists('imagecreatefromwebp')) {
                $createFn = 'imagecreatefromwebp';
                $saveFn = 'imagewebp';
            }
            break;
        case 'image/gif':
            // Avoid recompressing GIFs (could be animated); caller can move original
            return [false, 'Gif bypass: use original upload handling.'];
        default:
            return [false, 'Unsupported image type.'];
    }

    if (!$createFn || !$saveFn || !function_exists($createFn) || !function_exists($saveFn)) {
        return [false, 'Missing image handlers for this file type.'];
    }

    $src = @$createFn($tmpPath);
    if (!$src) {
        return [false, 'Unable to read image.'];
    }

    // Calculate target size
    $newWidth = ($width > $maxWidth) ? $maxWidth : $width;
    $scale = $newWidth / $width;
    $newHeight = (int)round($height * $scale);

    if ($newWidth !== $width) {
        $dst = imagecreatetruecolor($newWidth, $newHeight);
        if ($mime === 'image/png' || $mime === 'image/webp') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    } else {
        $dst = $src;
    }

    // Save optimized image
    $result = false;
    if ($mime === 'image/png') {
        $result = @$saveFn($dst, $destPath, $pngCompression);
    } else {
        $result = @$saveFn($dst, $destPath, $quality);
    }

    if ($dst !== $src) {
        imagedestroy($dst);
    }
    imagedestroy($src);

    if (!$result) {
        return [false, 'Failed to save optimized image.'];
    }

    @chmod($destPath, 0644);
    return [true, null];
}




