<?php

declare(strict_types=1);

/*
 * Writes one synthetic test photo for the Narcotics check browser suite.
 *   php narcotics-photo.php <jpeg|png> <width> <height> <exif-orientation> <out-path> <marker>
 *
 * The left half is red and the right half is blue, with a light gradient and drawn text on top, so a wrong
 * rotation is visible in the pixels. A JPEG gets an EXIF segment carrying the orientation and GPS-looking
 * text (the marker) right after the start marker, which the browser must not send.
 */
[, $type, $width, $height, $orientation, $out, $marker] = $argv + array_fill(0, 7, '');
$width = (int) $width;
$height = (int) $height;
$orientation = (int) $orientation;

$image = imagecreatetruecolor($width, $height);
imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, 0xE02020);
imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, 0x2020E0);
for ($y = 0; $y < $height; $y += 40) {
    imageline($image, 0, $y, $width - 1, $y, 0xFFFFFF - (intdiv($y * 255, max(1, $height)) * 0x010101));
}
imagestring($image, 5, 20, 20, 'NARCOTICS CHECK TEST PHOTO 1234567890', 0xFFFFFF);

ob_start();
if ($type === 'png') {
    imagepng($image);
    $bytes = (string) ob_get_clean();
} else {
    imagejpeg($image, null, 90);
    $bytes = (string) ob_get_clean();
    if ($orientation > 1 || $marker !== '') {
        $tiff = 'II'.pack('v', 42).pack('V', 8).pack('v', 1).pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', max(1, $orientation)).pack('v', 0).pack('V', 0);
        $payload = "Exif\0\0".$tiff.'GPSLatitudeRef=N;'.$marker;
        $bytes = substr($bytes, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($bytes, 2);
    }
}
file_put_contents($out, $bytes);
echo strlen($bytes), PHP_EOL;
