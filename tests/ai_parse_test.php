<?php
// Tests the free document check WITHOUT starting Apache.   Run:  php tests/ai_parse_test.php
// Needs the PHP GD extension for the picture tests. If Tesseract is installed the real reading test runs too.
require_once __DIR__ . '/../backend/ai.php';

$failed = 0;
function expect($name, $actual, $wanted) {
    global $failed;
    if ($actual === $wanted) { echo "  PASS  $name\n"; return; }
    $failed++; echo "  FAIL  $name (got " . var_export($actual, true) . ")\n";
}
$req = ['name' => 'Valid ID', 'keywords' => 'student,id,school,college', 'requires_name' => 1];
$student = 'Juan Dela Cruz';
$torRequest = ['name' => 'Accomplished request', 'keywords' => 'request,form,purpose,transcript', 'requires_name' => 1];
$torClearance = ['name' => 'Clearance/completion of requirements', 'keywords' => 'clearance,cleared,signature,requirements', 'requires_name' => 1];
// A pretend answer of Tesseract: text, average confidence (0-100), number of words.
function read($text, $confidence = 92) { return ['text' => $text, 'confidence' => $confidence, 'words' => count(preg_split('/\s+/', trim($text)))]; }
$ownId = 'COLLEGE OF OUR LADY OF MERCY OF PULILAN FOUNDATION STUDENT IDENTIFICATION CARD Name: JUAN DELA CRUZ Student No: 2026-00001 Course: BSTM Year: 3 School Year 2026-2027';

echo "Judging the text that was read\n";
expect('Own ID -> PASS (VERIFIED)', judge_document_text(read($ownId), $req, $student)['status'], 'PASS');
expect('Name written as "CRUZ, JUAN D." -> PASS', judge_document_text(read(str_replace('JUAN DELA CRUZ', 'CRUZ, JUAN D.', $ownId)), $req, $student)['status'], 'PASS');
expect('Matching transcript request -> PASS', judge_document_text(read('TRANSCRIPT OF RECORDS REQUEST FORM PURPOSE: EMPLOYMENT STUDENT NAME JUAN DELA CRUZ'), $torRequest, $student)['status'], 'PASS');
expect('Generic request form is rejected for a transcript request', judge_document_text(read('REQUEST FORM PURPOSE: EMPLOYMENT STUDENT NAME JUAN DELA CRUZ'), $torRequest, $student)['status'], 'FAIL');
expect('Clearance requirement needs clearance-specific text', judge_document_text(read('TRANSCRIPT OF RECORDS REQUEST FORM SIGNATURE REQUIREMENTS STUDENT NAME JUAN DELA CRUZ'), $torClearance, $student)['status'], 'FAIL');
expect('Matching clearance passes its requirement', judge_document_text(read('SCHOOL CLEARANCE ALL REQUIREMENTS COMPLETED SIGNATURE STUDENT NAME JUAN DELA CRUZ'), $torClearance, $student)['status'], 'PASS');
$genericRequest = ['name' => 'Request and record verification', 'keywords' => 'request,form,student,record', 'requires_name' => 1];
expect('Generic requirement uses the selected document type as its anchor',
    judge_document_text(read('REQUEST FORM STUDENT NAME JUAN DELA CRUZ UNITS EARNED'), $genericRequest, $student, 'Certificate of Units Earned')['status'], 'PASS');
expect('Generic words alone do not pass a selected document type',
    judge_document_text(read('REQUEST FORM STUDENT NAME JUAN DELA CRUZ'), $genericRequest, $student, 'Certificate of Units Earned')['status'], 'FAIL');

$r = judge_document_text(read(str_replace('JUAN DELA CRUZ', 'MARIA SANTOS', $ownId . ' Valid until March 2027 Issued by the Registrar Office')), $req, $student);
expect("Someone else's ID -> FAIL", $r['status'], 'FAIL');
expect('... name mismatch reason', $r['reason'], "The name on the document does not match the student's registered name.");
expect('... counts as an automatic decline', $r['auto_declined'], true);

$r = judge_document_text(read('REPUBLIC OF THE PHILIPPINES Certificate of Live Birth Name JUAN DELA CRUZ Date of Birth January 5 2008'), $req, $student);
expect('Wrong kind of document -> FAIL', $r['status'], 'FAIL');
expect('... wrong document reason', $r['reason'], 'The uploaded document does not appear to be the required document.');

expect('Almost no text -> FAIL (could not be read)', judge_document_text(read('ID JUAN'), $req, $student)['reason'], 'The uploaded document could not be read.');
expect('Low OCR confidence -> FAIL (blurry)', judge_document_text(read($ownId, 30), $req, $student)['reason'], 'The document is too blurry to read.');
expect('Little text and no name -> REVIEW (needs correction)', judge_document_text(read('STUDENT ID COLLEGE SCHOOL Name: unreadable smudge', 80), $req, $student)['status'], 'REVIEW');
expect('Only the first name found -> REVIEW', judge_document_text(read(str_replace('JUAN DELA CRUZ', 'JUAN SANTOS', $ownId)), $req, $student)['status'], 'REVIEW');
expect('Medium confidence -> REVIEW', judge_document_text(read($ownId, 65), $req, $student)['status'], 'REVIEW');
$noName = ['name' => 'Request form', 'keywords' => 'request,form', 'requires_name' => 0];
expect('Requirement without name check -> PASS', judge_document_text(read('REQUEST FORM Please issue the document requested for my scholarship application thank you'), $noName, $student)['status'], 'PASS');
$noKeywords = ['name' => 'Any', 'keywords' => '', 'requires_name' => 0];
expect('Requirement without keywords skips the type check', judge_document_text(read('hello this is some readable text of a document with enough words in it'), $noKeywords, $student)['status'], 'PASS');

echo "What the student sees\n";
[$status, $title, $message] = student_view_of_result(judge_document_text(read($ownId), $req, $student));
expect('Verified: status', $status, 'VERIFIED');
expect('Verified: message', $message, 'Document verified successfully. You may continue.');
[$status, $title, $message] = student_view_of_result(ai_declined(REASON_BLURRY));
expect('Declined: status', $status, 'DECLINED');
expect('Declined: message', $message, 'Verification declined. The document is too blurry to read.');
[$status, $title, $message] = student_view_of_result(ai_result('REVIEW', REASON_UNCLEAR));
expect('Needs correction: status', $status, 'NEEDS_CORRECTION');
expect('Needs correction: message', $message, 'Verification needs correction. The text is not clear enough. Please upload a clearer or complete document.');
[$status, $title, $message] = student_view_of_result(ai_unavailable('no tesseract'));
expect('Tools missing: PENDING, never VERIFIED (cannot be skipped)', $status, 'PENDING');
expect('Tesseract problem is explained to the student', $message, 'The document pre-check is temporarily unavailable. Please try again in a few minutes. If it keeps happening, please tell the Registrar Office.');
[$status, $title, $message] = student_view_of_result(ai_unavailable('pdftotext (poppler) failed'));
expect('PDF reader problem is explained to the student', $message, 'The document pre-check is temporarily unavailable. Please try again in a few minutes. If it keeps happening, please tell the Registrar Office.');

echo "Name search in the text\n";
$words = text_words('Name: CRUZ, JUAN D. Student No 2026-00001');
$found = name_in_text('Juan Dela Cruz', $words);
expect('First and last name found in any order', $found['first'] && $found['last'], true);
$found = name_in_text('Juan Dela Cruz', text_words('Name: MARIA SANTOS'));
expect('Different person not found', $found['first'] || $found['last'], false);

echo "Picture quality checks (pictures made on the fly)\n";
$dir = sys_get_temp_dir();
if (function_exists('imagecreatetruecolor')) {
    $sharp = imagecreatetruecolor(1000, 1400);
    imagefill($sharp, 0, 0, imagecolorallocate($sharp, 245, 245, 240));
    $ink = imagecolorallocate($sharp, 20, 20, 20);
    for ($y = 80; $y < 1300; $y += 40) imagestring($sharp, 5, 80, $y, 'CERTIFICATE OF ENROLLMENT JUAN DELA CRUZ 2026-00001 ' . $y, $ink);
    imagepng($sharp, "$dir/q_sharp.png");
    expect('Sharp document passes', check_image_quality("$dir/q_sharp.png"), null);
    $blurred = imagecreatefrompng("$dir/q_sharp.png");
    for ($i = 0; $i < 25; $i++) imagefilter($blurred, IMG_FILTER_GAUSSIAN_BLUR);
    imagepng($blurred, "$dir/q_blur.png");
    expect('Blurry document is caught', check_image_quality("$dir/q_blur.png")['message'] ?? null, REASON_BLURRY);
    $dark = imagecreatefrompng("$dir/q_sharp.png");
    imagefilter($dark, IMG_FILTER_BRIGHTNESS, -240);
    imagepng($dark, "$dir/q_dark.png");
    expect('Very dark document is caught', check_image_quality("$dir/q_dark.png")['message'] ?? null, REASON_DARK);
    $small = imagecreatetruecolor(200, 200); imagepng($small, "$dir/q_small.png");
    expect('Tiny image is caught', check_image_quality("$dir/q_small.png")['message'] ?? null, REASON_LOW_RESOLUTION);
    $blank = imagecreatetruecolor(800, 800); imagefill($blank, 0, 0, imagecolorallocate($blank, 255, 255, 255)); imagepng($blank, "$dir/q_blank.png");
    expect('Blank page is caught', check_image_quality("$dir/q_blank.png")['message'] ?? null, REASON_BLANK);
    file_put_contents("$dir/q_broken.png", substr(file_get_contents("$dir/q_sharp.png"), 0, 400) . str_repeat("\0", 200));
    expect('Damaged picture is caught', is_array(check_image_quality("$dir/q_broken.png")), true);

    $result = verify_document_upload("$dir/q_blur.png", 'image/png', $req, 'Certification of Grades', $student);
    expect('A blurry file is declined before any tool is needed', $result['status'], 'FAIL');

    // The real reading with Tesseract (only when it is installed)
    try {
        run_tool(tesseract_command() . ' --version', 'Tesseract OCR');
        $read = ocr_image("$dir/q_sharp.png");
        expect('Tesseract really reads the picture', $read['words'] > 20 && stripos($read['text'], 'JUAN') !== false, true);
    } catch (RuntimeException $e) {
        echo "  SKIP  Tesseract is not installed: " . $e->getMessage() . "\n";
        $r = verify_document_upload("$dir/q_sharp.png", 'image/png', $req, 'X', $student);
        expect('Without Tesseract the result is PENDING, never VERIFIED', student_view_of_result($r)[0], 'PENDING');
    }
} else {
    echo "  SKIP  GD extension is not installed, picture tests skipped\n";
}
file_put_contents("$dir/q_ok.pdf", "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
expect('Normal PDF passes the basic check', check_pdf_basic("$dir/q_ok.pdf"), null);
file_put_contents("$dir/q_cut.pdf", "%PDF-1.4\n1 0 obj<<>>endobj\n");
expect('Cut-off PDF is caught', check_pdf_basic("$dir/q_cut.pdf")['message'] ?? null, REASON_CORRUPT);
file_put_contents("$dir/q_locked.pdf", "%PDF-1.4\n1 0 obj<</Encrypt 2 0 R>>endobj\n%%EOF\n");
expect('Password-protected PDF is caught', check_pdf_basic("$dir/q_locked.pdf")['message'] ?? null, REASON_PROTECTED);

echo "Online OCR helpers (no internet needed)\n";
$cleanOnline = ocr_result_from_text('COLLEGE OF OUR LADY OF MERCY STUDENT IDENTIFICATION CARD Name: JUAN DELA CRUZ Student No: 2026-00001');
expect('Clean online text has a high estimated confidence', $cleanOnline['confidence'] >= 80, true);
expect('Empty online text means 0 words', ocr_result_from_text('')['words'], 0);
expect('Garbage online text has a low estimated confidence', ocr_result_from_text('§§ ¤¤ ||| ~~ ^^ ##')['confidence'] < 55, true);
expect('The default reader is Tesseract', ocr_provider(), 'tesseract');

echo $failed ? "\n$failed failed\n" : "\nAll passed\n";
exit($failed ? 1 : 0);
