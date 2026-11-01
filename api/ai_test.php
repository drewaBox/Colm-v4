<?php
// Admin only. POST -> checks that the tools for the document pre-check are installed and working.
//   OCR_PROVIDER=tesseract : Tesseract + Poppler (programs on the server) and PHP GD.
//   OCR_PROVIDER=ocrspace  : the free online OCR (internet access, curl, API key) and PHP GD. It sends ONE small test picture.
// Used by the "Check AI tools" button on the Settings page.
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/ai.php';
register_error_handlers();
require_method('POST');
require_login(['admin']);
require_csrf();

$tools = [];
$gd = function_exists('imagecreatefromstring');

if (ocr_provider() === 'ocrspace') {
    $tools[] = ['name' => 'PHP curl (talks to the online OCR)', 'ok' => function_exists('curl_init'),
                'message' => function_exists('curl_init') ? 'Enabled.' : 'Turn on the curl extension in the PHP settings.'];
    $hasKey = trim((string)env('OCRSPACE_API_KEY', '')) !== '';
    $tools[] = ['name' => 'OCRSPACE_API_KEY in .env', 'ok' => $hasKey, 'message' => $hasKey ? 'Set.' : 'Empty. Get a free key at ocr.space/ocrapi/freekey and put it in .env.'];
    if (function_exists('curl_init') && $hasKey) {
        // One real request: a tiny picture with text. It proves that the server can reach the service and that the key is accepted.
        $temp = null;
        try {
            if (!$gd) throw new RuntimeException('PHP GD is needed to make the test picture.');
            $small = imagecreatetruecolor(150, 22);
            imagefill($small, 0, 0, imagecolorallocate($small, 255, 255, 255));
            imagestring($small, 5, 6, 3, 'COLM TEST 2026', imagecolorallocate($small, 0, 0, 0));
            $big = imagescale($small, 1200, 176);
            $temp = tempnam(sys_get_temp_dir(), 'colm_test');
            imagepng($big, $temp);
            $text = ocr_space_post($temp, 'test.png', 'image/png');
            $tools[] = ['name' => 'Online OCR (reads documents)', 'ok' => true,
                        'message' => 'Connected. The service answered' . ($text !== '' ? ' and read: ' . mb_substr(preg_replace('/\s+/', ' ', $text), 0, 60) : ' (the tiny test picture itself was not read, which is fine).')];
        } catch (RuntimeException $e) {
            $tools[] = ['name' => 'Online OCR (reads documents)', 'ok' => false, 'message' => $e->getMessage()];
        } finally {
            if ($temp) @unlink($temp);
        }
    }
} else {
    try {
        $out = run_tool(tesseract_command() . ' --version', 'Tesseract OCR');
        $tools[] = ['name' => 'Tesseract OCR (reads documents)', 'ok' => true, 'message' => trim(strtok($out, "\n"))];
    } catch (RuntimeException $e) {
        $tools[] = ['name' => 'Tesseract OCR (reads documents)', 'ok' => false, 'message' => $e->getMessage()];
    }
    // Poppler: text extraction and page rendering (rendering is needed for scanned PDFs).
    try {
        run_tool(poppler_command('pdftotext') . ' -v', 'pdftotext (poppler)');
        $tools[] = ['name' => 'pdftotext (reads PDF files)', 'ok' => true, 'message' => 'Installed.'];
    } catch (RuntimeException $e) {
        $tools[] = ['name' => 'pdftotext (reads PDF files)', 'ok' => false, 'message' => $e->getMessage() . ' Pictures (JPG/PNG) still work.'];
    }
    try {
        run_tool(poppler_command('pdftoppm') . ' -v', 'pdftoppm (poppler)');
        $tools[] = ['name' => 'pdftoppm (scanned PDF support)', 'ok' => true, 'message' => 'Installed.'];
    } catch (RuntimeException $e) {
        $tools[] = ['name' => 'pdftoppm (scanned PDF support)', 'ok' => false, 'message' => $e->getMessage() . ' Text-based PDFs may still work.'];
    }
}
// GD
$tools[] = ['name' => 'PHP GD (blur / dark check)', 'ok' => $gd, 'message' => $gd ? 'Enabled.' : 'Turn on extension=gd in php.ini.'];

$all = count(array_filter($tools, fn($t) => !$t['ok'])) === 0;
json_response(['ok' => true, 'all_ready' => $all, 'tools' => $tools]);
