<?php
// Extra safety checks for uploaded payment receipts (the printed Official Receipt).
// Nothing can PROVE a picture is real, so these checks do two things:
//   1. BLOCK the obvious cheats: the same file or the same Official Receipt No. used again, wrong amount, impossible date.
//   2. FLAG anything suspicious (AI / editing software in the file, the typed numbers not found in the picture) so the
//      registrar looks closer. The registrar then compares the Official Receipt No. with the school's own receipt records.
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/ai.php';

// Adds the new columns to payment_receipts when they are missing (safe to run many times).
function ensure_receipt_security_columns() {
    static $done = false;
    if ($done) return;
    $pdo = db();
    $columns = [
        'official_receipt_no' => 'VARCHAR(40) NULL',
        'amount_paid'         => 'DECIMAL(10,2) NULL',
        'date_paid'           => 'DATE NULL',
        'file_hash'           => 'CHAR(64) NULL',
        'security_flags'      => 'TEXT NULL',
    ];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_receipts' AND COLUMN_NAME = ?");
    foreach ($columns as $name => $definition) {
        $stmt->execute([$name]);
        if ((int)$stmt->fetchColumn() === 0) $pdo->exec("ALTER TABLE payment_receipts ADD COLUMN `$name` $definition");
    }
    $done = true;
}

// The part of the file where programs write their name. For PNG and JPEG pictures this leaves out the picture data itself:
// compressed picture bytes are random, so a short name (like "WPS") can appear there by pure chance and wrongly flag a good receipt.
function receipt_metadata_area($bytes) {
    $length = strlen($bytes);
    if (strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0) {            // PNG: every chunk except the picture data (IDAT)
        $area = '';
        $pos = 8;
        while ($pos + 8 <= $length) {
            $size = unpack('N', substr($bytes, $pos, 4))[1];
            if ($size > $length) break;
            if (substr($bytes, $pos + 4, 4) !== 'IDAT') $area .= substr($bytes, $pos + 8, $size);
            $pos += 12 + $size;
        }
        return $area;
    }
    if (strncmp($bytes, "\xFF\xD8", 2) === 0) {                         // JPEG: the header part before the picture data
        $start = strpos($bytes, "\xFF\xDA");
        return $start === false ? $bytes : substr($bytes, 0, $start);
    }
    return $bytes;                                                      // PDF and anything else: look at the whole file
}

// Looks inside the file for names of AI tools and editing programs that leave their name in the file.
function receipt_metadata_flags($path) {
    $bytes = @file_get_contents($path, false, null, 0, 6 * 1024 * 1024);
    if ($bytes === false) return [];
    $bytes = receipt_metadata_area($bytes);
    $flags = [];
    $ai = [
        'c2pa' => 'Content Credentials (C2PA)', 'trainedAlgorithmicMedia' => 'AI-generated media label', 'DALL-E' => 'DALL-E', 'DALL·E' => 'DALL-E',
        'OpenAI' => 'OpenAI', 'ChatGPT' => 'ChatGPT', 'Midjourney' => 'Midjourney', 'Stable Diffusion' => 'Stable Diffusion',
        'Firefly' => 'Adobe Firefly', 'DreamStudio' => 'DreamStudio', 'Leonardo' => 'Leonardo AI', 'Gemini' => 'Google Gemini', 'Imagen' => 'Google Imagen',
    ];
    foreach ($ai as $needle => $label) {
        if (stripos($bytes, $needle) !== false) { $flags[] = "The file contains an AI marker ($label). It may be AI-generated."; break; }
    }
    if (preg_match('/Steps: \d+, Sampler:/', $bytes)) $flags[] = 'The file contains AI image-generator settings.';
    $editors = ['Photoshop', 'GIMP', 'Canva', 'Pixlr', 'Photopea', 'Paint.NET', 'Snapseed', 'PicsArt', 'Microsoft Word', 'WPS Office'];
    foreach ($editors as $editor) {
        if (stripos($bytes, $editor) !== false) { $flags[] = "The file was saved by editing software ($editor). Check that it was not altered."; break; }
    }
    return $flags;
}

// Reads the text of the picture/PDF and checks that the typed Official Receipt No. and amount really appear in it.
function receipt_ocr_flags($path, $mime, $orNo, $amount) {
    try {
        $read = $mime === 'application/pdf' ? ocr_pdf($path) : ocr_image($path);
    } catch (Throwable $error) {
        return ['Automatic text reading was not available. Compare the receipt with the typed details by eye.'];
    }
    $text = (string)($read['text'] ?? '');
    if (($read['words'] ?? 0) < 6) {
        return ['The text on the receipt could not be read automatically. Check it by eye.'];
    }
    $flags = [];
    $normalize = fn($value) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value));
    if (strpos($normalize($text), $normalize($orNo)) === false) {
        $flags[] = "The Official Receipt No. you typed ($orNo) was not found on the picture.";
    }
    $plain = str_replace(',', '', $text);
    $amountText = number_format((float)$amount, 2, '.', '');
    $short = rtrim(rtrim($amountText, '0'), '.');
    if (!preg_match('/(?<![\d.])' . preg_quote($amountText, '/') . '(?!\d)/', $plain)
        && !preg_match('/(?<![\d.])' . preg_quote($short, '/') . '(?![\d]|\.\d)/', $plain)) {
        $flags[] = "The amount (PHP $amountText) was not found on the picture.";
    }
    return $flags;
}

// The uploaded receipt of a request, if it can be trusted without more work:
//   Verified by staff, OR waiting for review but with NO security warnings (it passed the automatic checks).
function receipt_passed_checks($requestId) {
    ensure_receipt_security_columns();
    $receipt = fetch_one('SELECT id, status, security_flags FROM payment_receipts WHERE request_id = ?', [(int)$requestId]);
    if (!$receipt) return null;
    if ($receipt['status'] === 'Verified') return $receipt;
    if ($receipt['status'] === 'Pending' && trim((string)$receipt['security_flags']) === '') return $receipt;
    return null;
}

// Called when staff go on with a request (mark ready, soft copy): if the receipt passed the checks the payment is
// confirmed now (payment record + "Paid"). Returns true when the request is paid, false when the receipt still needs a staff review.
function auto_confirm_receipt_payment($request, $staffId) {
    if ((float)$request['total_amount'] <= 0 || $request['payment_status'] === 'Paid') return true;
    $receipt = receipt_passed_checks($request['id']);
    if (!$receipt) return false;
    $requestId = (int)$request['id'];
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $locked = fetch_one('SELECT payment_status FROM requests WHERE id = ? FOR UPDATE', [$requestId]);
        if ($locked && $locked['payment_status'] !== 'Paid') {
            $receiptNo = null;
            for ($i = 0; $i < 10 && !$receiptNo; $i++) {
                $try = 'OR-' . date('Ymd') . '-' . random_int(1000, 9999);
                if (!fetch_one('SELECT id FROM payments WHERE receipt_no = ?', [$try])) $receiptNo = $try;
            }
            if (!$receiptNo) throw new RuntimeException('Could not create a receipt number.');
            query('INSERT INTO payments (request_id, receipt_no, amount, method, received_by) VALUES (?, ?, ?, ?, ?)',
                [$requestId, $receiptNo, $request['total_amount'], $request['payment_method'] ?? 'Cash', $staffId]);
            query("UPDATE requests SET payment_status = 'Paid' WHERE id = ?", [$requestId]);
            $note = "Payment receipt passed the automatic security checks ($receiptNo).";
            if ($receipt['status'] === 'Pending') {
                query("UPDATE payment_receipts SET status = 'Verified', remarks = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP WHERE id = ?", [$note, $staffId, $receipt['id']]);
            }
            add_history($requestId, $request['status'], $request['status'], $staffId, $note);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    add_notification((int)$request['student_id'], $requestId, 'Payment confirmed',
        "Payment for request {$request['tracking_no']} was confirmed. Keep your hard-copy receipt for document release.", 'success');
    return true;
}