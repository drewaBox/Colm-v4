<?php
// The electronic signature drawn by the student on the credential request form (TOR, Form 137, Honorable Dismissal).
// The browser sends the drawing as a PNG data URL. We never trust it: it is decoded, size-checked, checked for real ink,
// redrawn with GD (so nothing hidden inside the file survives) and saved as a normal private file. One signature per request, never updated.
require_once __DIR__ . '/files.php';

const SIGNATURE_MAX_BYTES = 262144;     // 256 KB of PNG
const SIGNATURE_MIN_INK = 40;           // sampled dark pixels needed so an empty or almost empty box is refused

// Returns the clean PNG bytes, or throws RuntimeException with a message for the student.
function signature_from_data_url($dataUrl) {
    $prefix = 'data:image/png;base64,';
    if (!is_string($dataUrl) || strncmp($dataUrl, $prefix, strlen($prefix)) !== 0) throw new RuntimeException('Draw your signature in the signature box.');
    $base64 = substr($dataUrl, strlen($prefix));
    if (strlen($base64) > (int)(SIGNATURE_MAX_BYTES * 4 / 3) + 16) throw new RuntimeException('The signature picture is too large. Clear it and sign again.');
    $png = base64_decode($base64, true);
    if ($png === false || strlen($png) > SIGNATURE_MAX_BYTES || strncmp($png, "\x89PNG\r\n\x1a\n", 8) !== 0) throw new RuntimeException('The signature is not a valid picture. Clear it and sign again.');
    $info = @getimagesizefromstring($png);
    if (!$info || $info[2] !== IMAGETYPE_PNG || $info[0] < 100 || $info[1] < 40 || $info[0] > 1200 || $info[1] > 600) {
        throw new RuntimeException('The signature picture has the wrong size. Clear it and sign again.');
    }
    if (!function_exists('imagecreatefromstring')) throw new RuntimeException('The server cannot check the signature (PHP GD is missing).');
    if (!image_fits_in_memory($info[0], $info[1])) throw new RuntimeException('The signature picture is too large. Clear it and sign again.');
    $source = @imagecreatefromstring($png);
    if (!$source) throw new RuntimeException('The signature is not a valid picture. Clear it and sign again.');

    // Redraw on a white background and count the dark pixels (the pad draws dark ink on a transparent background).
    $width = imagesx($source); $height = imagesy($source);
    $clean = imagecreatetruecolor($width, $height);
    imagefill($clean, 0, 0, imagecolorallocate($clean, 255, 255, 255));
    imagecopy($clean, $source, 0, 0, 0, 0, $width, $height);
    imagedestroy($source);
    $ink = 0;
    for ($y = 0; $y < $height; $y += 2) {
        for ($x = 0; $x < $width; $x += 2) {
            $c = imagecolorat($clean, $x, $y);
            if ((($c >> 16) & 255) + (($c >> 8) & 255) + ($c & 255) < 450) $ink++;     // clearly darker than white
        }
    }
    if ($ink < SIGNATURE_MIN_INK) { imagedestroy($clean); throw new RuntimeException('Please sign inside the box. The signature is empty or too small.'); }
    ob_start();
    imagepng($clean, null, 6);
    $output = ob_get_clean();
    imagedestroy($clean);
    if ($output === false || $output === '') throw new RuntimeException('The signature could not be saved. Please sign again.');
    return $output;
}

// Writes the PNG as a private file and returns ['stored' => name, 'sha256' => hash]. The caller inserts the request_signatures row
// inside its own transaction and calls delete_stored_file() if that transaction fails.
function store_signature_png($png) {
    $tmp = tempnam(sys_get_temp_dir(), 'sig');
    if ($tmp === false || file_put_contents($tmp, $png) === false) throw new RuntimeException('The signature could not be saved. Please sign again.');
    try {
        $stored = store_file($tmp, 'png');
    } finally {
        @unlink($tmp);
    }
    return ['stored' => $stored, 'sha256' => hash('sha256', $png)];
}
