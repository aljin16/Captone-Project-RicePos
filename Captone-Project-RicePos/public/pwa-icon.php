<?php
// Dynamic PNG icon generator for PWA using assets/img/THRIVE.png as the source
// Usage: pwa-icon.php?size=192 (allowed: 128,144,152,180,192,256,384,512)

$size = isset($_GET['size']) ? (int)$_GET['size'] : 192;
$allowed = [128,144,152,180,192,256,384,512];
if (!in_array($size, $allowed, true)) { $size = 192; }

// Attempt to load the user's provided icon image
$sourcePath = __DIR__ . '/assets/img/1logo.png';
$srcImg = null;
// If GD is not available, serve the source file directly if possible
if (!function_exists('imagecreatetruecolor')) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=31536000, immutable');
    if (is_file($sourcePath)) {
        readfile($sourcePath);
    } else {
        // tiny 1x1 transparent PNG fallback
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=');
    }
    exit;
}
if (is_file($sourcePath)) {
    // Try a more flexible loader that supports multiple formats
    $data = @file_get_contents($sourcePath);
    if ($data !== false) {
        $srcImg = @imagecreatefromstring($data);
    }
}

// Fallback: if the file is missing or unreadable, render the previous simple vector icon
if (!$srcImg) {
    // If decoding failed but the file exists, serve the original file bytes
    if (is_file($sourcePath)) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($sourcePath);
        exit;
    }
    // If file does not exist, fallback to a simple vector icon
    $img = imagecreatetruecolor($size, $size);
    imagesavealpha($img, true);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $transparent);
    $bg = imagecolorallocate($img, 45, 108, 223);
    $margin = max(8, (int)round($size * 0.08));
    imagefilledellipse($img, (int)($size/2), (int)($size/2), $size - 2*$margin, $size - 2*$margin, $bg);
    $white = imagecolorallocate($img, 255, 255, 255);
    $cx = (int)($size * 0.5);
    $cy = (int)($size * 0.5);
    $w = (int)($size * 0.46);
    $h = (int)($size * 0.28);
    $x0 = $cx - (int)($w/2);
    $y0 = $cy - (int)($h/2);
    imagefilledrectangle($img, $x0, $y0, $x0 + $w, $y0 + $h, $white);
    $hx0 = $x0 - (int)($w*0.15);
    $hy0 = $y0 - (int)($h*0.65);
    $hx1 = $x0 + (int)($w*0.15);
    $hy1 = $y0 - (int)($h*0.25);
    imagefilledellipse($img, $hx0, $hy0, max(2, (int)($size*0.06)), max(2, (int)($size*0.06)), $white);
    imagefilledrectangle($img, $hx0, $hy0, $hx1, $hy1, $white);
    $r = max(3, (int)($size*0.06));
    imagefilledellipse($img, $x0 + (int)($w*0.2), $y0 + $h + $r + 2, 2*$r, 2*$r, $white);
    imagefilledellipse($img, $x0 + (int)($w*0.8), $y0 + $h + $r + 2, 2*$r, 2*$r, $white);
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=31536000, immutable');
    imagepng($img);
    imagedestroy($img);
    exit;
}

// Prepare destination canvas with transparent background
$dst = imagecreatetruecolor($size, $size);
imagealphablending($dst, false);
imagesavealpha($dst, true);
$transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
imagefill($dst, 0, 0, $transparent);

// Compute centered square crop of the source image (cover)
$srcW = imagesx($srcImg);
$srcH = imagesy($srcImg);
$cropSide = min($srcW, $srcH);
$srcX = (int)(($srcW - $cropSide) / 2);
$srcY = (int)(($srcH - $cropSide) / 2);

// Resample into destination at requested size
imagecopyresampled($dst, $srcImg, 0, 0, $srcX, $srcY, $size, $size, $cropSide, $cropSide);
imagedestroy($srcImg);

header('Content-Type: image/png');
header('Cache-Control: public, max-age=31536000, immutable');
imagepng($dst);
imagedestroy($dst);
?>
