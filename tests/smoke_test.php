<?php
// End-to-end check of the whole system through the real HTTP API.
//   1) php database/install.php
//   2) Start Apache + MySQL. Install Tesseract (free OCR) so documents can be verified - see README.md.
//   3) php tests/smoke_test.php http://localhost/new-ui-main/api/
// Needs the PHP GD extension (it draws the test pictures) and the demo accounts (password Password123!).
require_once __DIR__ . '/../backend/workflow.php';
$base = rtrim($argv[1] ?? 'http://localhost/new-ui-main/api/', '/') . '/';
$passed = 0; $failed = 0;

function check($name, $condition, $detail = '') {
    global $passed, $failed;
    if ($condition) { $passed++; echo "  PASS  $name\n"; }
    else { $failed++; echo "  FAIL  $name $detail\n"; }
}

// One "browser": keeps its own cookies and CSRF token.
class Client {
    public $jar; public $csrf = '';
    function __construct() { $this->jar = tempnam(sys_get_temp_dir(), 'jar'); }
    function call($file, $params = [], $post = null, $json = true, $extraHeaders = []) {
        global $base;
        $url = $base . $file . ($params ? '?' . http_build_query($params) : '');
        $ch = curl_init($url);
        $headers = $extraHeaders;
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($json) { $headers[] = 'Content-Type: application/json'; $post = json_encode($post); }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
                                CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 120]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($body, true);
        if (isset($data['csrf'])) $this->csrf = $data['csrf'];
        return [$status, $data, $body];
    }
    function login($user, $pass = 'Password123!') { return $this->call('login.php', [], ['username' => $user, 'password' => $pass]); }
}

if (!function_exists('imagecreatetruecolor')) { echo "The PHP GD extension is needed to draw the test pictures.\n"; exit(1); }
$tmp = sys_get_temp_dir();

// A readable "document" picture: the text is drawn small and then enlarged so Tesseract can read it.
// It contains the student's name and many words that the catalog requirements ask for.
function make_document($path, $variant = 'good', $seed = 0, $name = 'JUAN DELA CRUZ') {
    $small = imagecreatetruecolor(400, 300);
    imagefill($small, 0, 0, imagecolorallocate($small, 250, 250, 246));
    $ink = imagecolorallocate($small, 15, 15, 15);
    $lines = ['COLLEGE OF OUR LADY OF MERCY', 'STUDENT REQUEST FORM', "NAME: $name", 'STUDENT ID SCHOOL RECORD', 'CLEARANCE APPLICATION GRADES',
              'SCHOOL YEAR 2026 2027', "REF $seed"];
    foreach ($lines as $i => $text) imagestring($small, 4, 10, 15 + $i * 38, $text, $ink);
    $img = imagecreatetruecolor(1600, 1200);
    imagecopyresampled($img, $small, 0, 0, 0, 0, 1600, 1200, 400, 300);
    if ($variant === 'blur') for ($i = 0; $i < 40; $i++) imagefilter($img, IMG_FILTER_GAUSSIAN_BLUR);
    if ($variant === 'dark') imagefilter($img, IMG_FILTER_BRIGHTNESS, -245);
    imagepng($img, $path);
}

// A tiny but valid PDF for the soft copy tests.
function make_pdf($path, $note = 'Official document') {
    file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n% $note\ntrailer<</Root 1 0 R>>\n%%EOF\n");
}

echo "Login, roles and security\n";
$student = new Client(); $registrar = new Client(); $cashier = new Client(); $admin = new Client(); $anon = new Client();
[$s] = $anon->call('requests.php', ['action' => 'list']);            check('API blocks users who are not logged in (401)', $s === 401);
[$s] = $anon->login('registrar', 'wrong-password');                  check('Wrong password is refused (401)', $s === 401);
[$s, $d] = $student->login('2026-00001');                            check('Student login, opens student.html', $s === 200 && $d['user']['role'] === 'student' && $d['user']['home'] === 'student.html');
[$s, $d] = $registrar->login('registrar');                           check('Registrar login has the role "registrar"', $s === 200 && $d['user']['role'] === 'registrar' && $d['user']['home'] === 'manage.html');
[$s, $d] = $admin->login('admin');                                   check('Admin login has the role "admin"', $s === 200 && $d['user']['role'] === 'admin');
[$s, $d] = $cashier->login('cashier');                               check('Cashier login has the role "cashier"', $s === 200 && $d['user']['role'] === 'cashier');
[$s] = $student->call('users.php');                                  check('Student cannot open the accounts list (403)', $s === 403);
[$s] = $registrar->call('users.php');                                check('Registrar cannot manage accounts (403)', $s === 403);
[$s] = $admin->call('users.php');                                    check('Admin can open the accounts list', $s === 200);
[$s] = $student->call('students.php', ['action' => 'list']);         check('Student cannot open the students list (403)', $s === 403);
[$s] = $registrar->call('students.php', ['action' => 'list']);       check('Registrar can open the students list', $s === 200);
[$s] = $admin->call('students.php', ['action' => 'list']);           check('Admin can open the students list', $s === 200);
echo "  (cashier: payment only)\n";
foreach (['students.php' => ['action' => 'list'], 'requests.php' => ['action' => 'list'], 'documents.php' => [], 'users.php' => [], 'verify.php' => [], 'photo.php' => ['id' => 1]] as $file => $params) {
    [$s] = $cashier->call($file, $params);
    check("Cashier cannot use $file (403)", $s === 403 || $s === 405, "status $s");
}
[$s] = $cashier->call('payments.php', ['action' => 'summary']);       check('Cashier can use the payments API', $s === 200);
[$s] = $student->call('payments.php', ['action' => 'summary']);       check('Student cannot use the payments API (403)', $s === 403);
[$s] = $registrar->call('payments.php', ['action' => 'summary']);     check('Registrar cannot use the payments API (403)', $s === 403);
// Typing a restricted address in the browser (Accept: text/html) sends the cashier back to the payment page.
$ch = curl_init($base . 'students.php?action=list');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEFILE => $cashier->jar, CURLOPT_HTTPHEADER => ['Accept: text/html']]);
$raw = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
check('Cashier typing a restricted address is redirected to the payment page', $status === 302 && stripos($raw, 'Location: ../frontend/manage.html') !== false, "status $status");
$saved = $student->csrf; $student->csrf = '';
[$s] = $student->call('notifications.php', ['action' => 'read_all'], []); check('POST without CSRF token is refused (403)', $s === 403);
$student->csrf = 'wrong-token';
[$s] = $student->call('notifications.php', ['action' => 'read_all'], []); check('POST with a wrong CSRF token is refused (403)', $s === 403);
$student->csrf = $saved;
[$s] = $student->call('documents.php', ['action' => 'delete'], ['id' => 1]);  check('Student cannot delete a document (403)', $s === 403);

echo "Catalog management\n";
$docName = 'Test Document ' . random_int(1000, 9999);
$newDoc = ['id' => 0, 'name' => $docName, 'description' => 'Created by the smoke test', 'category' => 'Certification', 'price' => 50,
           'processing_min_days' => 1, 'processing_max_days' => 2, 'release_methods' => ['Hardcopy'], 'is_active' => true,
           'requirements' => [['name' => 'Test requirement', 'description' => 'Any picture', 'keywords' => 'student,id', 'allowed_types' => ['png', 'jpg'], 'max_size_mb' => 3, 'is_required' => true, 'requires_name' => true]]];
[$s, $d] = $admin->call('documents.php', ['action' => 'save'], $newDoc);        check('Admin adds a document', $s === 200 && $d['id'] > 0, $d['error'] ?? '');
$docId = $d['id'] ?? 0;
[$s] = $admin->call('documents.php', ['action' => 'save'], $newDoc);            check('Same document name twice is refused (422)', $s === 422);
$bad = $newDoc; $bad['name'] = 'Bad'; $bad['price'] = -5;
[$s] = $admin->call('documents.php', ['action' => 'save'], $bad);               check('Negative price is refused (422)', $s === 422);
[$s, $d] = $student->call('documents.php');
check('New document appears in the student catalog', $s === 200 && in_array($docName, array_column($d['documents'], 'name')));
[$s] = $admin->call('documents.php', ['action' => 'toggle'], ['id' => $docId]); check('Admin deactivates the document', $s === 200);
[$s, $d] = $student->call('documents.php');
check('Inactive document is hidden from students', !in_array($docName, array_column($d['documents'], 'name')));
[$s, $d] = $admin->call('documents.php');
$mine = array_values(array_filter($d['documents'], fn($x) => $x['id'] === $docId))[0] ?? null;
$edit = $newDoc; $edit['id'] = $docId; $edit['price'] = 75; $edit['is_active'] = true; $edit['requirements'][0]['id'] = $mine['requirements'][0]['id'];
[$s] = $admin->call('documents.php', ['action' => 'save'], $edit);              check('Admin edits the document', $s === 200);
[$s, $d] = $admin->call('documents.php');
$mine = array_values(array_filter($d['documents'], fn($x) => $x['id'] === $docId))[0] ?? null;
check('Edit is saved in the database (price 75, active)', $mine && $mine['price'] == 75 && $mine['is_active'] === true);
[$s] = $admin->call('documents.php', ['action' => 'delete'], ['id' => $docId]); check('Admin deletes the unused document', $s === 200);
[$s, $d] = $admin->call('documents.php');
check('Deleted document is gone', !in_array($docName, array_column($d['documents'], 'name')));

echo "Students and CSV import\n";
$id1 = '2026-9' . random_int(1000, 9999); $id2 = '2026-9' . random_int(1000, 9999);
$csv = "student_id,full_name,email,course,year,section,contact_number\n"
     . "$id1,Maria Santos,maria@example.com,BSTM,3,Section 4,09171234567\n"
     . "$id2,Jose Reyes,not-an-email,BSIT - Information Technology,2,Section 1,09181234567\n"
     . "$id1,Duplicate Person,dup@example.com,BSTM,1,Section 1,09171234567\n"
     . ",No Id,,BSTM,1,Section 1,\n"
     . "2026-00001,Existing Student,a@example.com,BSTM,1,Section 1,\n"
     . "2026-9999,Wrong Section,a@example.com,BSTM,3,Section 5,\n";
file_put_contents("$tmp/students.csv", $csv);
[$s, $d] = $registrar->call('students.php', ['action' => 'import'], ['csv' => new CURLFile("$tmp/students.csv", 'text/csv', 'students.csv'), 'dry_run' => '1'], false);
check('CSV check finds the valid and the invalid rows', $s === 200 && $d['valid_count'] === 1 && count($d['failed']) === 5, json_encode($d['failed'] ?? $d));
[$s, $d] = $registrar->call('students.php', ['action' => 'import'], ['csv' => new CURLFile("$tmp/students.csv", 'text/csv', 'students.csv')], false);
check('CSV import creates only the valid student', $s === 200 && count($d['imported']) === 1 && $d['imported'][0]['username'] === $id1, $d['error'] ?? '');
$defaultPassword = $d['imported'][0]['password'] ?? '';
check('Imported students use the shared default password', $defaultPassword === 'Password123!');
[$s, $d] = $registrar->call('students.php', ['action' => 'import'], ['csv' => new CURLFile("$tmp/students.csv", 'text/csv', 'students.csv')], false);
check('Importing the same file again does not duplicate students', $s === 200 && count($d['imported']) === 0);
file_put_contents("$tmp/wrong.csv", "name,age\nA,1\n");
[$s, $d] = $registrar->call('students.php', ['action' => 'import'], ['csv' => new CURLFile("$tmp/wrong.csv", 'text/csv', 'wrong.csv')], false);
check('CSV with wrong columns is refused (422)', $s === 422 && strpos($d['error'], 'missing these columns') !== false);

$newStudent = new Client();
[$s, $d] = $newStudent->login($id1, $defaultPassword);                           check('Imported student logs in with Student ID + default password', $s === 200 && $d['user']['must_change_password'] === true);
[$s, $d] = $newStudent->call('documents.php');                                    check('Default password must be changed first (403)', $s === 403 && ($d['code'] ?? '') === 'PASSWORD_CHANGE_REQUIRED');
[$s] = $newStudent->call('password.php', [], ['current_password' => $defaultPassword, 'new_password' => 'MyOwnPass#2026']);  check('Student changes the default password', $s === 200);
[$s] = $newStudent->call('documents.php');                                        check('Portal is unlocked after the change', $s === 200);
[$s, $d] = $newStudent->call('students.php', ['action' => 'profile']);
check('Student profile shows level, course, year and section', $s === 200 && $d['student']['school_level'] === 'COLLEGE' && $d['student']['course'] === 'BSTM' && (int)$d['student']['year_level'] === 3 && $d['student']['section'] === 'Section 4', $d['error'] ?? '');
$studentUserId = $d['student']['id'] ?? 0;
[$s, $d] = $registrar->call('students.php', ['action' => 'profile', 'id' => $studentUserId]);   check('Registrar views a student profile', $s === 200 && $d['student']['full_name'] === 'Maria Santos');
[$s] = $newStudent->call('students.php', ['action' => 'profile', 'id' => 1]);     check('Student only ever gets their own profile', $s === 200);
file_put_contents("$tmp/notimage.png", 'not a picture');
[$s, $d] = $newStudent->call('photo.php', [], ['photo' => new CURLFile("$tmp/notimage.png", 'image/png', 'x.png')], false);   check('A fake photo is refused (422)', $s === 422);
$tiny = imagecreatetruecolor(100, 100); imagepng($tiny, "$tmp/tiny.png");
[$s, $d] = $newStudent->call('photo.php', [], ['photo' => new CURLFile("$tmp/tiny.png", 'image/png', 'tiny.png')], false);   check('A photo smaller than 200x200 is refused (422)', $s === 422, $d['error'] ?? '');
$big = imagecreatetruecolor(400, 500); imagefill($big, 0, 0, imagecolorallocate($big, 90, 120, 200)); imagejpeg($big, "$tmp/photo.jpg");
[$s] = $newStudent->call('photo.php', [], ['photo' => new CURLFile("$tmp/photo.jpg", 'image/jpeg', 'photo.jpg')], false);    check('Student saves a profile photo', $s === 200);
[$s, $d] = $newStudent->call('students.php', ['action' => 'profile']);                                                         check('The photo is kept (profile says has_photo)', $d['student']['has_photo'] === true);
[$s] = $newStudent->call('photo.php', [], ['photo' => new CURLFile("$tmp/photo.jpg", 'image/jpeg', 'photo.jpg'), 'user_id' => 1], false);  check('A student who sends another user_id still only changes their own photo', $s === 200);
[$s] = $newStudent->call('photo.php', ['id' => $studentUserId]);                  check('Photo can be opened', $s === 200);
[$s] = $anon->call('photo.php', ['id' => $studentUserId]);                        check('Photo is private (401 when not logged in)', $s === 401);
[$s, $d] = $registrar->call('students.php', ['action' => 'create'], ['student_id' => $id2, 'full_name' => 'Jose Reyes', 'email' => 'jose@example.com', 'school_level' => 'COLLEGE', 'course' => 'BSIT - Information Technology', 'year' => 2, 'section' => 'Section 2', 'contact_number' => '09181234567']);
check('Registrar adds one student by hand', $s === 201 && !empty($d['credentials']['password']), $d['error'] ?? '');
[$s] = $registrar->call('students.php', ['action' => 'create'], ['student_id' => $id2 . 'h', 'full_name' => 'Junior High Student', 'school_level' => 'HIGH SCHOOL', 'course' => 'HIGH SCHOOL', 'year' => 7, 'section' => 'Section 1']);
check('Junior High enrollment is refused (422)', $s === 422);
[$s, $d] = $registrar->call('students.php', ['action' => 'create'], ['student_id' => $id2 . 'x', 'full_name' => 'Wrong Combo', 'school_level' => 'COLLEGE', 'course' => 'STEM', 'year' => 11, 'section' => 'Section 1']);
check('A wrong School Level / Course combination is refused (422)', $s === 422, $d['error'] ?? '');
[$s, $d] = $registrar->call('students.php', ['action' => 'create'], ['student_id' => $id2 . 'y', 'full_name' => 'Senior High Student', 'school_level' => 'SENIOR HIGH SCHOOL', 'course' => 'STEM', 'year' => 12, 'section' => 'Section 1']);
check('Senior High enrollment is refused (422): the system is college only', $s === 422, $d['error'] ?? '');
[$s, $d] = $registrar->call('students.php', ['action' => 'structure']);
$counts = [];
foreach ($d['levels'] ?? [] as $level) foreach ($level['courses'] as $course) foreach ($course['years'] as $year) $counts[$level['level'] . '/' . $course['name'] . '/' . $year['year']] = $year['sections'];
check('The structure endpoint gives the correct college section counts and no Senior/Junior High', count($counts['COLLEGE/BSTM/3'] ?? []) === 4 && !isset($counts['SENIOR HIGH SCHOOL/HUMSS/11']) && !isset($counts['SENIOR HIGH SCHOOL/STEM/12']) && !isset($counts['HIGH SCHOOL/HIGH SCHOOL/9']), json_encode($counts));
[$s, $d] = $registrar->call('students.php', ['action' => 'list', 'q' => $id2]); $joseId = $d['students'][0]['id'] ?? 0;
[$s] = $registrar->call('students.php', ['action' => 'update'], ['id' => $joseId, 'full_name' => 'Jose P. Reyes', 'email' => 'jose@example.com', 'school_level' => 'SENIOR HIGH SCHOOL', 'course' => 'STEM', 'year' => 11, 'section' => 'Section 2']);
check('Registrar edits a student', $s === 200);
[$s] = $registrar->call('students.php', ['action' => 'delete'], ['id' => $joseId]);   check('Registrar deletes a student without requests', $s === 200);

echo "Stage 3: document verification before submitting\n";
[$s, $d] = $student->call('documents.php');
$doc = array_values(array_filter($d['documents'], fn($x) => $x['name'] === 'Certification of Grades'))[0];
$reqs = $doc['requirements'];
$first = $reqs[0]['id'];

// Helper: upload one file for one requirement, like the browser does.
$verify = function ($client, $requirementId, $path, $name, $mime = 'image/png', $replace = 0) {
    return $client->call('verify.php', [], ['file' => new CURLFile($path, $mime, $name), 'requirement_id' => $requirementId, 'replace_check_id' => $replace], false);
};
$good = [];
foreach ($reqs as $i => $req) { $good[$req['id']] = "$tmp/good_$i.png"; make_document($good[$req['id']], 'good', $i + 1); }
make_document("$tmp/blurry.png", 'blur', 99);
make_document("$tmp/dark.png", 'dark', 98);
make_document("$tmp/other.png", 'good', 97, 'MARIA SANTOS');
file_put_contents("$tmp/fake.png", 'this is not an image');
file_put_contents("$tmp/virus.exe", 'MZ');

[$s] = $anon->call('verify.php', [], ['requirement_id' => $first], false);                check('Verification needs a login (401)', $s === 401);
[$s] = $registrar->call('verify.php', [], ['requirement_id' => $first], false);           check('Only students can use verification (403)', $s === 403);
[$s, $d] = $verify($student, $first, "$tmp/virus.exe", 'virus.exe', 'application/octet-stream');
check('Unsupported file type: "Invalid File" (422)', $s === 422 && ($d['title'] ?? '') === 'Invalid File', $d['error'] ?? '');
[$s, $d] = $verify($student, $first, "$tmp/fake.png", 'fake.png');
check('A text file renamed to .png is refused (422)', $s === 422, $d['error'] ?? '');
[$s, $d] = $verify($student, $first, "$tmp/blurry.png", 'blurry.png');
check('Blurry document: status DECLINED', $s === 200 && ($d['check']['status'] ?? '') === 'DECLINED', json_encode($d));
check('... with the reason', ($d['check']['message'] ?? '') === 'Verification declined. The document is too blurry to read.', $d['check']['message'] ?? '');
[$s, $d] = $verify($student, $first, "$tmp/dark.png", 'dark.png');
check('Very dark document is declined', ($d['check']['status'] ?? '') === 'DECLINED' && strpos($d['check']['message'], 'too dark') !== false, json_encode($d));

[$s, $d] = $verify($student, $first, $good[$first], 'doc.png');
$status = $d['check']['status'] ?? '';
if ($status === 'PENDING') {
    echo "        Tesseract is not installed (or TESSERACT_PATH in .env is wrong), so a good document cannot be VERIFIED.\n";
    echo "        The rest of this test needs it. Install Tesseract (see README.md), then run the test again.\n";
    check('Without Tesseract the check stays PENDING and does not let the student skip it', true);
    $failed++; echo "  FAIL  Tesseract missing - stage 3 and the steps after it were not tested\n";
    echo "\n$passed passed, $failed failed\n"; exit(1);
}
check('Good document: VERIFIED', $s === 200 && $status === 'VERIFIED', json_encode($d));
check('... message', ($d['check']['message'] ?? '') === 'Document verified successfully. You may continue.');
[$s, $d] = $verify($student, $first, "$tmp/other.png", 'other.png');
check("Another person's document is declined (name mismatch)", ($d['check']['status'] ?? '') === 'DECLINED' && strpos($d['check']['message'], 'does not match') !== false, json_encode($d));

$checkIds = [];
foreach ($reqs as $req) {
    [$s, $d] = $verify($student, $req['id'], $good[$req['id']], 'doc.png');
    check("Good document VERIFIED for \"{$req['name']}\"", $s === 200 && ($d['check']['status'] ?? '') === 'VERIFIED', json_encode($d));
    $checkIds[$req['id']] = $d['check']['id'] ?? 0;
}

$payloadFor = function ($ids) use ($doc) {
    $checks = [];
    foreach ($ids as $reqId => $checkId) if ($checkId) $checks["r1:$reqId"] = $checkId;
    return json_encode(['purpose' => 'Scholarship application', 'release_method' => 'Hardcopy', 'payment_method' => 'Cash',
        'items' => [['row' => 'r1', 'document_id' => $doc['id'], 'quantity' => 1]], 'checks' => $checks]);
};

echo "Stage 3 cannot be skipped (the server checks again)\n";
[$s, $r] = $student->call('requests.php', ['action' => 'create'], ['payload' => $payloadFor([])], false);
check('No uploaded documents: refused (422)', $s === 422 && !empty($r['missing']), $r['error'] ?? '');
$ids = $checkIds; $ids[$first] = 99999999;
[$s, $r] = $student->call('requests.php', ['action' => 'create'], ['payload' => $payloadFor($ids)], false);
check('A made-up verification number is refused (422)', $s === 422, $r['error'] ?? '');
[$s, $d] = $verify($student, $first, "$tmp/blurry.png", 'blurry.png');
$ids = $checkIds; $ids[$first] = $d['check']['id'] ?? 0;
[$s, $r] = $student->call('requests.php', ['action' => 'create'], ['payload' => $payloadFor($ids)], false);
check('A declined document cannot be submitted (422)', $s === 422, $r['error'] ?? '');
[$s, $r] = $registrar->call('requests.php', ['action' => 'create'], ['payload' => $payloadFor($checkIds)], false);
check('A staff account cannot create requests (403)', $s === 403);
[$s, $r] = $newStudent->call('requests.php', ['action' => 'create'], ['payload' => $payloadFor($checkIds)], false);
check("Another student cannot use someone else's verified files (422)", $s === 422);

echo "Stage 5: request created, QR code\n";
[$s, $r] = $student->call('requests.php', ['action' => 'create'], ['payload' => $payloadFor($checkIds)], false);
check('Request is created', $s === 201 && !empty($r['request']['id']), $r['error'] ?? '');
$requestId = $r['request']['id'] ?? 0;
check('Request goes to registrar review', ($r['request']['status'] ?? '') === 'FOR_REVIEW');
[$s, $dateDetail] = $student->call('requests.php', ['action' => 'detail', 'id' => $requestId]);
$submittedOn = substr($dateDetail['request']['created_at'] ?? '', 0, 10);
check('Release date range uses working days from submission and stores its latest date',
    $s === 200 && $dateDetail['request']['target_start'] === estimate_processing_date($submittedOn, $doc['processing_min_days'])
    && $dateDetail['request']['target_end'] === estimate_processing_date($submittedOn, $doc['processing_max_days']), json_encode($dateDetail['request'] ?? []));
[$s, $catalogWithPendingRequest] = $student->call('documents.php');
$pendingDoc = array_values(array_filter($catalogWithPendingRequest['documents'] ?? [], fn($item) => $item['id'] === $doc['id']))[0] ?? [];
check('Catalog marks requested document unavailable until request is resolved',
    $s === 200 && !empty($pendingDoc['pending_request']) && $pendingDoc['pending_tracking_no'] === $r['request']['tracking_no']);
[$s, $r2] = $student->call('requests.php', ['action' => 'create'], ['payload' => $payloadFor($checkIds)], false);
check('The same document cannot be requested again while its request is active (422)',
    $s === 422 && strpos($r2['error'] ?? '', 'already have a request for') !== false, $r2['error'] ?? '');
[$s, $q1] = $student->call('qr.php', ['request_id' => $requestId]);
check('QR code is created and saved', $s === 200 && strpos($q1['qr_text'] ?? '', 'api/slip.php?t=') !== false && $q1['created'] === true, json_encode($q1));
[$s, $q2] = $student->call('qr.php', ['request_id' => $requestId]);
check('Asking again (like a page refresh) returns the same QR text while nothing changed', $s === 200 && $q2['qr_text'] === $q1['qr_text'] && $q2['created'] === false);
check('The QR link has a token', strpos($q1['qr_text'], 't=') !== false);
[$s] = $newStudent->call('qr.php', ['request_id' => $requestId]);          check("Another student cannot get this request's QR (404)", $s === 404);
[$s] = $cashier->call('qr.php', ['request_id' => $requestId]);             check('Cashier cannot get QR codes (403)', $s === 403);

echo "Student and registrar views\n";
[$s, $d] = $student->call('requests.php', ['action' => 'detail', 'id' => $requestId]);
$studentFile = $d['items'][0]['requirements'][0]['file'];
check('Student sees the file and a Pending status', $s === 200 && $studentFile['review_status'] === 'Pending');
check('Student does not receive the technical check details', $studentFile['ai'] === null && !preg_match('/extracted_text|confidence|name_match/', json_encode($d)));
[$s, $d] = $registrar->call('requests.php', ['action' => 'detail', 'id' => $requestId]);
$adminFile = $d['items'][0]['requirements'][0]['file'];
check('Registrar sees the automatic check details', $adminFile['ai'] !== null && array_key_exists('name_on_document', $adminFile['ai']));
[$s] = $registrar->call('file.php', ['id' => $adminFile['id'], 'inline' => 1]);   check('Registrar can open the file', $s === 200);
[$s] = $cashier->call('file.php', ['id' => $adminFile['id']]);                    check('Cashier cannot open student files (403)', $s === 403);
[$s] = $newStudent->call('file.php', ['id' => $adminFile['id']]);                 check('Another student cannot open the file (404)', $s === 404);
[$s] = $student->call('requests.php', ['action' => 'set_status'], ['id' => $requestId, 'status' => 'PROCESSING']);   check('Student cannot change request status (403)', $s === 403);
[$s, $d] = $registrar->call('requests.php', ['action' => 'set_status'], ['id' => $requestId, 'status' => 'PROCESSING']);
check('Cannot process before every file is approved (422)', $s === 422 && strpos($d['error'] ?? '', 'verify every uploaded document') !== false, $d['error'] ?? '');

echo "Correction request\n";
[$s] = $registrar->call('requests.php', ['action' => 'review_file'], ['file_id' => $adminFile['id'], 'decision' => 'Rejected', 'remarks' => 'The picture is cut off']);
check('Registrar declines a file with a reason', $s === 200);
[$s] = $registrar->call('requests.php', ['action' => 'set_status'], ['id' => $requestId, 'status' => 'NEEDS_CORRECTION', 'remarks' => 'Please upload a clearer file']);
check('Registrar requests correction', $s === 200);
[$s, $d] = $student->call('requests.php', ['action' => 'detail', 'id' => $requestId]);
$itemId = $d['items'][0]['id'];
check('Student sees "Declined" with the reason', $d['items'][0]['requirements'][0]['file']['review_remarks'] === 'The picture is cut off');
make_document("$tmp/fixed.png", 'good', 123);
[$s, $v] = $verify($student, $first, "$tmp/fixed.png", 'fixed.png');
check('Corrected file is verified before it is sent', ($v['check']['status'] ?? '') === 'VERIFIED', json_encode($v));
[$s, $r] = $student->call('requests.php', ['action' => 'resubmit', 'id' => $requestId], ['payload' => json_encode(['checks' => []])], false);
check('Correction without a new verified file is refused (422)', $s === 422);
[$s, $r] = $student->call('requests.php', ['action' => 'resubmit', 'id' => $requestId], ['payload' => json_encode(['checks' => ["$itemId:$first" => $v['check']['id'] ?? 0]])], false);
check('Student sends the corrected document', $s === 200, $r['error'] ?? '');
[$s, $d] = $registrar->call('requests.php', ['action' => 'detail', 'id' => $requestId]);
check('Request is back in review', $d['request']['status'] === 'FOR_REVIEW', $d['request']['status']);
foreach ($d['items'][0]['requirements'] as $req) {
    [$s] = $registrar->call('requests.php', ['action' => 'review_file'], ['file_id' => $req['file']['id'], 'decision' => 'Verified']);
    check("Approve \"{$req['name']}\"", $s === 200);
}
[$s, $paymentGate] = $registrar->call('requests.php', ['action' => 'set_status'], ['id' => $requestId, 'status' => 'PROCESSING']);
check('Fully reviewed request still waits for cashier payment (422)', $s === 422 && strpos($paymentGate['error'] ?? '', 'cashier') !== false, $paymentGate['error'] ?? '');
[$s] = $registrar->call('requests.php', ['action' => 'review_file'], ['file_id' => $d['items'][0]['requirements'][0]['file']['id'], 'decision' => 'Rejected']);
check('Declining without a reason is refused (422)', $s === 422);

echo "Payment (cashier) and completion\n";
[$s, $d] = $registrar->call('requests.php', ['action' => 'set_status'], ['id' => $requestId, 'status' => 'PROCESSING']);
check('Registrar cannot start processing before cashier confirms payment (422)', $s === 422 && strpos($d['error'] ?? '', 'cashier') !== false, $d['error'] ?? '');
[$s, $d] = $cashier->call('payments.php', ['action' => 'pending']);
$pending = array_values(array_filter($d['payments'] ?? [], fn($p) => $p['id'] === $requestId))[0] ?? null;
check('Cashier sees the request in Pending Payments with the student information', $s === 200 && $pending && $pending['student_no'] === '2026-00001' && $pending['course'] === 'BSTM', json_encode($d));
$total = $pending['total_amount'] ?? 0;
[$s, $d] = $cashier->call('payments.php', ['action' => 'record'], ['request_id' => $requestId, 'amount' => $total + 1, 'method' => 'Cash']);
check('A wrong amount is refused (422)', $s === 422, $d['error'] ?? '');
[$s, $d] = $cashier->call('payments.php', ['action' => 'record'], ['request_id' => $requestId, 'amount' => $total, 'method' => 'Cash']);
check('Cashier records the payment and gets a receipt number', $s === 201 && strpos($d['receipt_no'] ?? '', 'OR-') === 0, $d['error'] ?? '');
$paymentId = $d['payment_id'] ?? 0;
[$s] = $registrar->call('requests.php', ['action' => 'set_status'], ['id' => $requestId, 'status' => 'PROCESSING']);
check('Registrar can start processing after cashier confirms payment', $s === 200);
[$s] = $cashier->call('payments.php', ['action' => 'record'], ['request_id' => $requestId, 'amount' => $total, 'method' => 'Cash']);
check('Paying twice is refused (422)', $s === 422);
[$s, $d] = $cashier->call('payments.php', ['action' => 'history']);
check('Payment History shows the payment', $s === 200 && in_array($paymentId, array_column($d['payments'], 'id')));
[$s, $d] = $cashier->call('payments.php', ['action' => 'receipt', 'id' => $paymentId]);
check('Receipt can be opened', $s === 200 && $d['receipt']['student_no'] === '2026-00001' && count($d['receipt']['items']) >= 1);
[$s, $d] = $cashier->call('payments.php', ['action' => 'summary']);
check('Payment Dashboard numbers', $s === 200 && $d['summary']['all_count'] >= 1);
[$s] = $registrar->call('requests.php', ['action' => 'set_status'], ['id' => $requestId, 'status' => 'READY']);        check('Request is ready after payment', $s === 200);
[$s] = $registrar->call('requests.php', ['action' => 'set_status'], ['id' => $requestId, 'status' => 'COMPLETED']);    check('Request is completed', $s === 200);
[$s, $completedRequestList] = $student->call('requests.php', ['action' => 'list']);
$completedRequest = array_values(array_filter($completedRequestList['requests'] ?? [], fn($item) => $item['id'] === $requestId))[0] ?? [];
check('Completed requests expose the actual date they became ready for release', $s === 200 && !empty($completedRequest['released_at']));
[$s] = $registrar->call('requests.php', ['action' => 'set_status'], ['id' => $requestId, 'status' => 'PROCESSING']);   check('A completed request cannot go back (422)', $s === 422);
[$s, $catalogAfterCompletion] = $student->call('documents.php');
$availableDoc = array_values(array_filter($catalogAfterCompletion['documents'] ?? [], fn($item) => $item['id'] === $doc['id']))[0] ?? [];
check('Catalog allows the same document again after completion', $s === 200 && empty($availableDoc['pending_request']));
[$s, $d] = $student->call('requests.php', ['action' => 'detail', 'id' => $requestId]);
check('Student sees COMPLETED and the history', $d['request']['status'] === 'COMPLETED' && count($d['history']) >= 6);
[$s, $d] = $student->call('notifications.php');
check('Student got the payment notification', in_array('Payment confirmed', array_column($d['notifications'], 'title')));
$latestNotificationId = max(array_column($d['notifications'], 'id'));
[$s, $delta] = $student->call('notifications.php', ['after_id' => $latestNotificationId - 1]);
check('Notification updates can be fetched incrementally in order', $s === 200 && count($delta['notifications'] ?? []) === 1 && $delta['notifications'][0]['id'] === $latestNotificationId);
[$s, $d] = $student->call('students.php', ['action' => 'profile']);
check('Profile shows the verified documents', $d['summary']['verified'] >= 1);

echo "Soft copy (PDF): format, upload, secure one-time download\n";
// 1. The student asks for a SOFT COPY. (The first request is completed, so a new one for the same document is allowed.)
$softIds = [];
foreach ($reqs as $i => $req) {
    make_document("$tmp/soft_$i.png", 'good', 500 + $i);
    [$s, $d] = $verify($student, $req['id'], "$tmp/soft_$i.png", 'doc.png');
    $softIds[$req['id']] = $d['check']['id'] ?? 0;
}
$softChecks = [];
foreach ($softIds as $reqId => $checkId) $softChecks["r1:$reqId"] = $checkId;
$softPayload = fn($format, $checks) => json_encode(['purpose' => 'Scholarship application', 'release_method' => $format, 'payment_method' => 'Cash',
    'items' => [['row' => 'r1', 'document_id' => $doc['id'], 'quantity' => 1]], 'checks' => $checks]);
[$s, $r] = $student->call('requests.php', ['action' => 'create'], ['payload' => $softPayload('Papyrus', $softChecks)], false);
check('A made-up document format is refused (422)', $s === 422, $r['error'] ?? '');
[$s, $r] = $student->call('requests.php', ['action' => 'create'], ['payload' => $softPayload('Softcopy', $softChecks)], false);
check('Student creates a request with the format "Soft Copy / PDF"', $s === 201 && !empty($r['request']['id']), $r['error'] ?? '');
$softRequest = $r['request']['id'] ?? 0;
[$s, $d] = $registrar->call('requests.php', ['action' => 'detail', 'id' => $softRequest]);
$softItem = $d['items'][0]['id'] ?? 0;
check('The registrar can see that a SOFT COPY was requested', $d['request']['release_method'] === 'Softcopy' && $d['items'][0]['softcopy']['wanted'] === true, json_encode($d['request']['release_method'] ?? ''));
[$s, $d] = $registrar->call('requests.php', ['action' => 'list', 'status' => 'FOR_REVIEW']);
$row = array_values(array_filter($d['requests'] ?? [], fn($x) => $x['id'] === $softRequest))[0] ?? [];
check('The request list shows "Waiting for Registrar" for the soft copy', ($row['softcopy_label'] ?? '') === 'Waiting for Registrar', json_encode($row['softcopy_label'] ?? ''));

// 2. Payment and processing are NOT bypassed.
[$s] = $registrar->call('requests.php', ['action' => 'attach_release', 'id' => $softRequest], ['item_id' => $softItem, 'file' => new CURLFile("$tmp/good_0.png", 'image/png', 'x.png')], false);
check('No PDF can be uploaded before the payment and the processing (422)', $s === 422);
[$s, $d] = $registrar->call('requests.php', ['action' => 'detail', 'id' => $softRequest]);
foreach ($d['items'][0]['requirements'] as $req) $registrar->call('requests.php', ['action' => 'review_file'], ['file_id' => $req['file']['id'], 'decision' => 'Verified']);
[$s] = $registrar->call('requests.php', ['action' => 'set_status'], ['id' => $softRequest, 'status' => 'PROCESSING']);
check('Processing is still blocked until the cashier confirms the payment (422)', $s === 422);
[$s, $d] = $cashier->call('payments.php', ['action' => 'pending']);
$softPending = array_values(array_filter($d['payments'] ?? [], fn($p) => $p['id'] === $softRequest))[0] ?? null;
$cashier->call('payments.php', ['action' => 'record'], ['request_id' => $softRequest, 'amount' => $softPending['total_amount'] ?? 0, 'method' => 'Cash']);
[$s] = $registrar->call('requests.php', ['action' => 'set_status'], ['id' => $softRequest, 'status' => 'PROCESSING']);
check('After payment the registrar processes the request', $s === 200);

// 3. The student cannot download before the PDF exists.
[$s, $d] = $student->call('softcopy.php', ['action' => 'mine']);
$mine = array_values(array_filter($d['items'] ?? [], fn($x) => $x['item_id'] === $softItem))[0] ?? [];
check('Student dashboard: Soft Copy = "Waiting for Registrar", no download button', ($mine['softcopy']['label'] ?? '') === 'Waiting for Registrar' && $mine['softcopy']['can_download'] === false, json_encode($mine['softcopy'] ?? ''));
[$s] = $student->call('softcopy.php', ['action' => 'download'], ['item_id' => $softItem]);
check('Download before the PDF is ready is refused (403)', $s === 403);

// 4. The registrar uploads the PDF (PDF only).
[$s, $d] = $registrar->call('requests.php', ['action' => 'attach_release', 'id' => $softRequest], ['item_id' => $softItem, 'file' => new CURLFile("$tmp/good_0.png", 'image/png', 'picture.png')], false);
check('A picture is refused as a soft copy (422)', $s === 422, $d['error'] ?? '');
file_put_contents("$tmp/evil.pdf", '<?php echo "hacked"; ?>');
[$s, $d] = $registrar->call('requests.php', ['action' => 'attach_release', 'id' => $softRequest], ['item_id' => $softItem, 'file' => new CURLFile("$tmp/evil.pdf", 'application/pdf', 'evil.pdf')], false);
check('A PHP script named .pdf is refused (422)', $s === 422, $d['error'] ?? '');
make_pdf("$tmp/doc.pdf", 'first');
[$s, $d] = $registrar->call('requests.php', ['action' => 'attach_release', 'id' => $softRequest], ['item_id' => 99999999, 'file' => new CURLFile("$tmp/doc.pdf", 'application/pdf', 'doc.pdf')], false);
check('A document number that is not part of the request is refused (404)', $s === 404);
[$s, $d] = $cashier->call('requests.php', ['action' => 'attach_release', 'id' => $softRequest], ['item_id' => $softItem, 'file' => new CURLFile("$tmp/doc.pdf", 'application/pdf', 'doc.pdf')], false);
check('The cashier cannot upload (403)', $s === 403);
[$s, $d] = $registrar->call('requests.php', ['action' => 'attach_release', 'id' => $softRequest], ['item_id' => $softItem, 'file' => new CURLFile("$tmp/doc.pdf", 'application/pdf', '../../evil<name>.pdf')], false);
check('Registrar uploads the PDF (any strange file name is harmless)', $s === 200, $d['error'] ?? '');
[$s] = $student->call('softcopy.php', ['action' => 'download'], ['item_id' => $softItem]);
check('Uploaded but not marked ready: the student still cannot download (403)', $s === 403);
[$s] = $registrar->call('requests.php', ['action' => 'set_status'], ['id' => $softRequest, 'status' => 'READY']);
check('The request cannot be "Ready" before the soft copy is marked ready (422)', $s === 422);
[$s] = $student->call('requests.php', ['action' => 'softcopy_ready'], ['id' => $softRequest, 'item_id' => $softItem]);
check('A student cannot mark a soft copy ready (403)', $s === 403);
[$s] = $registrar->call('requests.php', ['action' => 'softcopy_ready'], ['id' => $softRequest, 'item_id' => $softItem]);
check('Registrar marks the soft copy ready', $s === 200);
[$s] = $registrar->call('requests.php', ['action' => 'set_status'], ['id' => $softRequest, 'status' => 'READY']);
check('Request becomes Ready for Release', $s === 200);
[$s, $d] = $student->call('requests.php', ['action' => 'detail', 'id' => $softRequest]);
check('The student is never given the file id or file name', !preg_match('/stored_name|release_file":\{/', json_encode($d)) && $d['items'][0]['release_file'] === null);
[$s, $d] = $student->call('softcopy.php', ['action' => 'mine']);
$mine = array_values(array_filter($d['items'] ?? [], fn($x) => $x['item_id'] === $softItem))[0] ?? [];
check('Student dashboard: "Soft Copy Available" with a Download PDF button', ($mine['softcopy']['label'] ?? '') === 'Soft Copy Available' && $mine['softcopy']['can_download'] === true && $mine['softcopy']['can_request_more'] === false);

// 5. Nobody else can get the PDF.
[$s] = $anon->call('softcopy.php', ['action' => 'download'], ['item_id' => $softItem]);                 check('Not logged in: no download (401)', $s === 401);
[$s] = $newStudent->call('softcopy.php', ['action' => 'download'], ['item_id' => $softItem]);           check("Another student changing the id gets nothing (404)", $s === 404);
[$s] = $cashier->call('softcopy.php', ['action' => 'download'], ['item_id' => $softItem]);              check('The cashier cannot download (403)', $s === 403);
[$s] = $registrar->call('softcopy.php', ['action' => 'download'], ['item_id' => $softItem]);            check('The student download endpoint is only for students (403)', $s === 403);
$saved = $student->csrf; $student->csrf = 'bad';
[$s] = $student->call('softcopy.php', ['action' => 'download'], ['item_id' => $softItem]);               check('A download without the security token is refused (403)', $s === 403);
$student->csrf = $saved;
[$s, $d2] = $registrar->call('requests.php', ['action' => 'detail', 'id' => $softRequest]);
$releaseFileId = $d2['items'][0]['release_file']['id'] ?? 0;
[$s] = $student->call('file.php', ['id' => $releaseFileId]);                                             check('file.php never sends a soft copy to a student (403/404)', in_array($s, [403, 404], true));
[$s] = $registrar->call('file.php', ['id' => $releaseFileId]);                                           check('file.php never sends a soft copy to staff either (403)', $s === 403);

// 6. The one-time download.
[$s, $d, $raw] = $student->call('softcopy.php', ['action' => 'download'], ['item_id' => $softItem]);
check('Student downloads the PDF', $s === 200 && strpos($raw, '%PDF-') === 0, "status $s");
[$s, $d] = $student->call('softcopy.php', ['action' => 'download'], ['item_id' => $softItem]);
check('A second download is refused: the one-time download is used (403)', $s === 403 && ($d['code'] ?? '') === 'USED', json_encode($d));
[$s, $d] = $student->call('softcopy.php', ['action' => 'mine']);
$mine = array_values(array_filter($d['items'] ?? [], fn($x) => $x['item_id'] === $softItem))[0] ?? [];
check('Dashboard: "Download Used", button to request an additional download', ($mine['softcopy']['label'] ?? '') === 'Download Used' && $mine['softcopy']['can_download'] === false && $mine['softcopy']['can_request_more'] === true);
[$s, $d, $raw] = $registrar->call('softcopy.php', ['action' => 'view', 'item_id' => $softItem]);
check('Registrar can view the PDF (not counted as a student download)', $s === 200 && strpos($raw, '%PDF-') === 0);
[$s] = $student->call('softcopy.php', ['action' => 'view', 'item_id' => $softItem]);                    check('A student cannot use the staff view (403)', $s === 403);

// 7. Additional download: request, reject, request again, approve.
[$s] = $student->call('softcopy.php', ['action' => 'request_more'], ['item_id' => $softItem, 'reason_type' => 'PDF file was corrupted', 'reason' => '']);
check('An additional download needs a reason (422)', $s === 422);
[$s] = $student->call('softcopy.php', ['action' => 'request_more'], ['item_id' => $softItem, 'reason_type' => 'Lost the downloaded copy', 'reason' => 'Long enough reason']);
check('A removed reason type is refused (422)', $s === 422);
[$s] = $newStudent->call('softcopy.php', ['action' => 'request_more'], ['item_id' => $softItem, 'reason_type' => 'Wrong name or data', 'reason' => 'Not my document']);
check("A student cannot ask for somebody else's document (404)", $s === 404);
[$s, $d] = $student->call('softcopy.php', ['action' => 'request_more'], ['item_id' => $softItem, 'reason_type' => 'System error - unable to download', 'reason' => 'The download failed because of a system error']);
check('Student requests an additional download with a reason', $s === 201, $d['error'] ?? '');
[$s] = $student->call('softcopy.php', ['action' => 'request_more'], ['item_id' => $softItem, 'reason_type' => 'PDF file was corrupted', 'reason' => 'Asking twice']);
check('A second request while one is waiting is refused (422)', $s === 422);
[$s] = $student->call('softcopy.php', ['action' => 'download'], ['item_id' => $softItem]);
check('No download while the request is waiting (403)', $s === 403);
[$s, $d] = $registrar->call('softcopy.php', ['action' => 'requests', 'status' => 'PENDING']);
$queue = array_values(array_filter($d['requests'] ?? [], fn($x) => $x['item_id'] === $softItem))[0] ?? null;
check('Registrar sees the request: student, document, request no., first download, previous downloads, reason', $queue && $queue['student_no'] === '2026-00001' && $queue['download_count'] === 1 && !empty($queue['first_download_at']) && $queue['reason_type'] === 'System error - unable to download' && !empty($queue['tracking_no']), json_encode($queue));
[$s] = $student->call('softcopy.php', ['action' => 'decide'], ['id' => $queue['id'] ?? 0, 'decision' => 'approve']);
check('A student cannot approve their own request (403)', $s === 403);
[$s] = $cashier->call('softcopy.php', ['action' => 'requests'], []);
check('The cashier cannot see the requests (403)', $s === 403);
[$s] = $registrar->call('softcopy.php', ['action' => 'decide'], ['id' => $queue['id'] ?? 0, 'decision' => 'reject', 'rejection_reason' => 'Please use the copy you already downloaded.']);
check('Registrar rejects the request with a reason', $s === 200);
[$s] = $registrar->call('softcopy.php', ['action' => 'decide'], ['id' => $queue['id'] ?? 0, 'decision' => 'approve']);
check('A request that was decided cannot be decided again (422)', $s === 422);
[$s] = $student->call('softcopy.php', ['action' => 'download'], ['item_id' => $softItem]);
check('After the rejection the student still cannot download (403)', $s === 403);
[$s, $d] = $student->call('softcopy.php', ['action' => 'mine']);
$mine = array_values(array_filter($d['items'] ?? [], fn($x) => $x['item_id'] === $softItem))[0] ?? [];
check('The student sees that it was rejected and why', ($mine['softcopy']['code'] ?? '') === 'REJECTED' && strpos($mine['softcopy']['rejection_reason'] ?? '', 'already downloaded') !== false);
[$s, $d] = $student->call('softcopy.php', ['action' => 'request_more'], ['item_id' => $softItem, 'reason_type' => 'File was corrupted', 'reason' => 'The downloaded file does not open']);
check('After a rejection the student may ask again', $s === 201);
[$s, $d] = $registrar->call('softcopy.php', ['action' => 'requests', 'status' => 'PENDING']);
$queue2 = array_values(array_filter($d['requests'] ?? [], fn($x) => $x['item_id'] === $softItem))[0] ?? null;
[$s] = $admin->call('softcopy.php', ['action' => 'decide'], ['id' => $queue2['id'] ?? 0, 'decision' => 'approve']);
check('Admin approves the additional download', $s === 200);
[$s, $d, $raw] = $student->call('softcopy.php', ['action' => 'download'], ['item_id' => $softItem]);
check('The student can download again after the approval', $s === 200 && strpos($raw, '%PDF-') === 0);
[$s] = $student->call('softcopy.php', ['action' => 'download'], ['item_id' => $softItem]);
check('The approval was for ONE download: the next one is refused (403)', $s === 403);
[$s, $d] = $student->call('notifications.php');
check('The student was told about the approval', in_array('Additional download approved', array_column($d['notifications'], 'title')) && in_array('Additional download rejected', array_column($d['notifications'], 'title')));

// 8. History / audit log
[$s, $d] = $registrar->call('softcopy.php', ['action' => 'history', 'item_id' => $softItem]);
$types = array_column($d['downloads'] ?? [], 'download_type');
check('Download history: original, staff view and additional download are all recorded', $s === 200 && in_array('ORIGINAL', $types) && in_array('ADDITIONAL', $types) && in_array('STAFF', $types), json_encode($types));
$additional = array_values(array_filter($d['downloads'] ?? [], fn($x) => $x['download_type'] === 'ADDITIONAL'))[0] ?? [];
check('The additional download shows the reason, approver and date', ($additional['reason_type'] ?? '') === 'File was corrupted' && !empty($additional['approved_by_name']) && !empty($additional['approved_at']) && (int)$additional['download_number'] === 2, json_encode($additional));
check('Every student download has the user, date and download number', $d['item']['download_count'] === 2 && count(array_filter($d['downloads'], fn($x) => $x['download_type'] !== 'STAFF' && !empty($x['by_name']) && !empty($x['downloaded_at']))) === 2);
check('The history lists both additional requests (rejected and approved)', count($d['additional_requests'] ?? []) === 2);
[$s] = $student->call('softcopy.php', ['action' => 'history', 'item_id' => $softItem]);                  check('A student cannot open the download history (403)', $s === 403);
[$s] = $cashier->call('softcopy.php', ['action' => 'history', 'item_id' => $softItem]);                  check('The cashier cannot open the download history (403)', $s === 403);

// 9. Replacing the PDF starts again.
make_pdf("$tmp/doc2.pdf", 'second');
[$s] = $registrar->call('requests.php', ['action' => 'attach_release', 'id' => $softRequest], ['item_id' => $softItem, 'file' => new CURLFile("$tmp/doc2.pdf", 'application/pdf', 'corrected.pdf')], false);
check('Registrar replaces the PDF', $s === 200);
[$s] = $student->call('softcopy.php', ['action' => 'download'], ['item_id' => $softItem]);
check('A replaced PDF must be marked ready again before it can be downloaded (403)', $s === 403);
$registrar->call('requests.php', ['action' => 'softcopy_ready'], ['id' => $softRequest, 'item_id' => $softItem]);
[$s, $d, $raw] = $student->call('softcopy.php', ['action' => 'download'], ['item_id' => $softItem]);
check('After marking the new PDF ready the student can download it (one download again)', $s === 200 && strpos($raw, 'second') !== false);
[$s, $d] = $registrar->call('softcopy.php', ['action' => 'history', 'item_id' => $softItem]);
check('The history keeps the earlier downloads', count(array_filter($d['downloads'], fn($x) => $x['download_type'] !== 'STAFF')) === 3);
// Format "Hard Copy" requests have no soft copy part
[$s, $d] = $student->call('softcopy.php', ['action' => 'mine']);
$hard = array_values(array_filter($d['items'] ?? [], fn($x) => $x['release_method'] === 'Hardcopy'))[0] ?? null;
check('A hard copy request shows no soft copy and no buttons', $hard && $hard['softcopy']['wanted'] === false && !$hard['softcopy']['can_download'] && !$hard['softcopy']['can_request_more']);

echo "Academic Management (levels, courses, year levels, sections)\n";
[$s] = $student->call('academic.php');                                                                     check('A student cannot open Academic Management (403)', $s === 403);
[$s] = $registrar->call('academic.php');                                                                   check('A registrar cannot open Academic Management (403)', $s === 403);
[$s] = $cashier->call('academic.php');                                                                     check('The cashier cannot open Academic Management (403)', $s === 403);
[$s, $d] = $admin->call('academic.php');
check('Admin sees the lists (levels, courses, year levels, sections)', $s === 200 && count($d['levels'] ?? []) >= 3 && count($d['courses'] ?? []) >= 4 && count($d['year_levels'] ?? []) >= 10 && count($d['sections'] ?? []) >= 20, json_encode($d));
$bstm = array_values(array_filter($d['courses'], fn($c) => $c['name'] === 'BSTM'))[0] ?? [];
check('The list says how many students use BSTM', ($bstm['students'] ?? 0) >= 1);
$tag = random_int(100, 999);
[$s, $d] = $admin->call('academic.php', ['action' => 'save'], ['type' => 'level', 'name' => "TEST LEVEL $tag"]);          check('Admin adds a level', $s === 201, $d['error'] ?? '');
$levelId = $d['id'] ?? 0;
[$s] = $admin->call('academic.php', ['action' => 'save'], ['type' => 'level', 'name' => "TEST LEVEL $tag"]);             check('The same level twice is refused (422)', $s === 422);
[$s, $d] = $admin->call('academic.php', ['action' => 'save'], ['type' => 'course', 'name' => "BS Test $tag", 'parent_id' => $levelId]);   check('Admin adds a course', $s === 201, $d['error'] ?? '');
$courseId = $d['id'] ?? 0;
[$s] = $admin->call('academic.php', ['action' => 'save'], ['type' => 'course', 'name' => "BS Test $tag", 'parent_id' => $levelId]);        check('The same course twice is refused (422)', $s === 422);
[$s] = $admin->call('academic.php', ['action' => 'save'], ['type' => 'course', 'name' => 'No parent', 'parent_id' => 0]);                 check('A course needs a level (422)', $s === 422);
[$s, $d] = $admin->call('academic.php', ['action' => 'save'], ['type' => 'year_level', 'name' => 'First Year', 'year_number' => 1, 'parent_id' => $courseId]);  check('Admin adds a year level', $s === 201, $d['error'] ?? '');
$yearId = $d['id'] ?? 0;
[$s] = $admin->call('academic.php', ['action' => 'save'], ['type' => 'year_level', 'name' => 'Again', 'year_number' => 1, 'parent_id' => $courseId]);  check('The same year number twice is refused (422)', $s === 422);
[$s] = $admin->call('academic.php', ['action' => 'save'], ['type' => 'year_level', 'name' => 'Bad', 'year_number' => 99, 'parent_id' => $courseId]);   check('A year number of 99 is refused (422)', $s === 422);
[$s, $d] = $admin->call('academic.php', ['action' => 'save'], ['type' => 'section', 'name' => 'A', 'parent_id' => $yearId]);                  check('Admin adds a section "A"', $s === 201, $d['error'] ?? '');
$sectionA = $d['id'] ?? 0;
[$s, $d] = $admin->call('academic.php', ['action' => 'save'], ['type' => 'section', 'name' => 'B', 'parent_id' => $yearId]);                  $sectionB = $d['id'] ?? 0;
[$s, $d] = $registrar->call('students.php', ['action' => 'structure']);
$found = array_values(array_filter($d['levels'] ?? [], fn($l) => $l['level'] === "TEST LEVEL $tag"))[0] ?? null;
check('The new items appear in the Add Student lists', $found && $found['courses'][0]['years'][0]['sections'] === ['A', 'B'], json_encode($found));
$newId = '2026-8' . random_int(1000, 9999);
[$s, $d] = $registrar->call('students.php', ['action' => 'create'], ['student_id' => $newId, 'full_name' => 'Academic Test', 'school_level' => "TEST LEVEL $tag", 'course' => "BS Test $tag", 'year' => 1, 'section' => 'B']);
check('A student can be added to the new combination', $s === 201, $d['error'] ?? '');
[$s, $d] = $registrar->call('students.php', ['action' => 'create'], ['student_id' => $newId . 'z', 'full_name' => 'Wrong Section', 'school_level' => "TEST LEVEL $tag", 'course' => "BS Test $tag", 'year' => 1, 'section' => 'C']);
check('A section that does not exist is refused (422)', $s === 422);
// rename a used section: the student's record follows
[$s] = $admin->call('academic.php', ['action' => 'save'], ['type' => 'section', 'id' => $sectionB, 'name' => 'B2']);
check('Admin renames a section', $s === 200);
[$s, $d] = $registrar->call('students.php', ['action' => 'list', 'q' => $newId]);
check("The student's record was renamed too (section B2)", ($d['students'][0]['section'] ?? '') === 'B2', json_encode($d['students'][0] ?? ''));
// remove: unused -> deleted, used -> only deactivated
[$s, $d] = $admin->call('academic.php', ['action' => 'remove'], ['type' => 'section', 'id' => $sectionA]);
check('Removing a section nobody uses deletes it', $s === 200 && $d['result'] === 'deleted', json_encode($d));
[$s, $d] = $admin->call('academic.php', ['action' => 'remove'], ['type' => 'section', 'id' => $sectionB]);
check('Removing a section that a student uses only deactivates it', $s === 200 && $d['result'] === 'deactivated' && strpos($d['message'], 'currently being used') !== false, json_encode($d));
[$s, $d] = $admin->call('academic.php', ['action' => 'remove'], ['type' => 'level', 'id' => $levelId]);
check('Removing a level that still has courses only deactivates it', $s === 200 && $d['result'] === 'deactivated');
[$s, $d] = $registrar->call('students.php', ['action' => 'structure']);
check('A deactivated level disappears from the Add Student list', !in_array("TEST LEVEL $tag", array_column($d['levels'], 'level')));
[$s, $d] = $registrar->call('students.php', ['action' => 'structure', 'all' => 1]);
check('...but the filters can still see it (old students stay findable)', in_array("TEST LEVEL $tag", array_column($d['levels'], 'level')));
[$s, $d] = $registrar->call('students.php', ['action' => 'create'], ['student_id' => $newId . 'q', 'full_name' => 'Inactive Test', 'school_level' => "TEST LEVEL $tag", 'course' => "BS Test $tag", 'year' => 1, 'section' => 'B2']);
check('A NEW student cannot use a deactivated level (422)', $s === 422);
[$s, $d] = $registrar->call('students.php', ['action' => 'list', 'q' => $newId]);
check('The old student still has the deactivated values and is still listed', $s === 200 && ($d['students'][0]['course'] ?? '') === "BS Test $tag");
$sid = $d['students'][0]['id'] ?? 0;
[$s] = $registrar->call('students.php', ['action' => 'update'], ['id' => $sid, 'full_name' => 'Academic Test Renamed', 'email' => '', 'school_level' => "TEST LEVEL $tag", 'course' => "BS Test $tag", 'year' => 1, 'section' => 'B2']);
check('Editing that student still works while the values stay the same', $s === 200);
[$s] = $admin->call('academic.php', ['action' => 'toggle'], ['type' => 'level', 'id' => $levelId]);        check('Admin activates the level again', $s === 200);
[$s, $d] = $admin->call('academic.php', ['action' => 'remove'], ['type' => 'course', 'id' => $bstm['id'] ?? 0]);
check('Removing BSTM (used by students) only deactivates it', $s === 200 && $d['result'] === 'deactivated');
[$s, $d] = $registrar->call('students.php', ['action' => 'list', 'course' => 'BSTM']);
check('The BSTM students are still there', $s === 200 && count($d['students']) >= 1);
[$s] = $admin->call('academic.php', ['action' => 'toggle'], ['type' => 'course', 'id' => $bstm['id'] ?? 0]);   check('Admin activates BSTM again (the test cleans up)', $s === 200);
[$s] = $registrar->call('academic.php', ['action' => 'save'], ['type' => 'level', 'name' => 'X']);        check('A registrar cannot add a level (403)', $s === 403);

echo "AI tools check (admin)\n";
[$s] = $student->call('ai_test.php', [], []);                                           check('Students cannot run the tools check (403)', $s === 403);
[$s] = $registrar->call('ai_test.php', [], []);                                         check('Registrar cannot run the tools check (403)', $s === 403);
[$s, $d] = $admin->call('ai_test.php', [], []);                                         check('Admin can run the tools check', $s === 200 && isset($d['tools']) && count($d['tools']) >= 4, json_encode($d));

echo "Create Admin / Registrar / Cashier accounts\n";
$name = 'tester' . random_int(1000, 9999);
$account = fn($role, $extra = []) => array_merge(['username' => $name . $role, 'full_name' => 'Test ' . $role, 'email' => $name . $role . '@example.com', 'role' => $role, 'password' => 'Password123!', 'confirm_password' => 'Password123!'], $extra);
foreach (['admin', 'registrar', 'cashier'] as $role) {
    [$s, $d] = $admin->call('users.php', ['action' => 'create'], $account($role));
    check("Admin creates a $role account", $s === 201 && ($d['message'] ?? '') === 'Account created successfully.', $d['error'] ?? '');
    $c = new Client(); [$s, $d] = $c->login($name . $role);
    check("... the new $role can log in and has the role \"$role\"", $s === 200 && $d['user']['role'] === $role, json_encode($d));
}
[$s, $d] = $admin->call('users.php', ['action' => 'create'], $account('admin'));                                  check('Duplicate username: "Username already exists."', $s === 422 && $d['error'] === 'Username already exists.', $d['error'] ?? '');
[$s, $d] = $admin->call('users.php', ['action' => 'create'], $account('cashier', ['username' => $name . 'b', 'email' => '', 'confirm_password' => 'Different123!']));
check('Different passwords: "Passwords do not match."', $s === 422 && $d['error'] === 'Passwords do not match.', $d['error'] ?? '');
[$s, $d] = $admin->call('users.php', ['action' => 'create'], $account('cashier', ['username' => $name . 'c', 'role' => '']));
check('No role: "Please complete all required fields."', $s === 422 && $d['error'] === 'Please complete all required fields.', $d['error'] ?? '');
[$s] = $admin->call('users.php', ['action' => 'create'], $account('student', ['username' => $name . 's']));       check('A student account cannot be made here (422)', $s === 422);
[$s] = $registrar->call('users.php', ['action' => 'create'], $account('admin', ['username' => $name . 'z']));     check('A registrar cannot create accounts (403)', $s === 403);
[$s, $d] = $admin->call('users.php', ['q' => $name . 'cashier']); $newId = $d['users'][0]['id'] ?? 0;
[$s] = $admin->call('users.php', ['action' => 'toggle'], ['id' => $newId]);            check('Admin disables the account', $s === 200);
$test = new Client(); [$s] = $test->login($name . 'cashier');                           check('Disabled account cannot log in (403)', $s === 403);

echo "Filters (all filters work together)\n";
$ids = fn($d, $key = 'students', $field = 'student_no') => array_column($d[$key] ?? [], $field);
[$s, $d] = $registrar->call('students.php', ['action' => 'list', 'level' => 'COLLEGE', 'course' => 'BSTM', 'year' => 3, 'section' => 'Section 4', 'account' => 'active']);
check('Students: level + course + year + section + account finds the imported student', $s === 200 && in_array($id1, $ids($d)), json_encode($ids($d)));
[$s, $d] = $registrar->call('students.php', ['action' => 'list', 'level' => 'COLLEGE', 'course' => 'BSTM', 'year' => 2, 'section' => 'Section 4']);
check('Students: a different year leaves that student out', !in_array($id1, $ids($d)));
[$s, $d] = $registrar->call('students.php', ['action' => 'list', 'level' => 'SENIOR HIGH SCHOOL', 'course' => 'BSTM']);
check('Students: a course of another level returns nothing', $s === 200 && count($d['students']) === 0);
[$s, $d] = $registrar->call('students.php', ['action' => 'list', 'q' => $id1, 'section' => 'Section 3']);
check('Students: search + section together', !in_array($id1, $ids($d)));
[$s, $d] = $registrar->call('students.php', ['action' => 'list', 'account' => 'disabled']);
check('Students: account filter "disabled" shows no active student', $s === 200 && !in_array('2026-00001', $ids($d)));
[$s, $d] = $registrar->call('requests.php', ['action' => 'list', 'status' => 'COMPLETED', 'payment' => 'Paid', 'document' => $doc['id'], 'from' => date('Y-m-d', strtotime('-1 day')), 'to' => date('Y-m-d')]);
check('Requests: status + payment + document + dates finds the completed request', $s === 200 && in_array($requestId, $ids($d, 'requests', 'id')), json_encode($ids($d, 'requests', 'id')));
[$s, $d] = $registrar->call('requests.php', ['action' => 'list', 'status' => 'COMPLETED', 'payment' => 'Unpaid']);
check('Requests: Completed + Unpaid does not include it', !in_array($requestId, $ids($d, 'requests', 'id')));
[$s, $d] = $registrar->call('requests.php', ['action' => 'list', 'from' => date('Y-m-d', strtotime('+1 day'))]);
check('Requests: a "from" date in the future returns nothing', $s === 200 && count($d['requests']) === 0);
[$s, $d] = $registrar->call('requests.php', ['action' => 'list', 'document' => 999999]);
check('Requests: an unknown document returns nothing', $s === 200 && count($d['requests']) === 0);
[$s, $d] = $student->call('requests.php', ['action' => 'list', 'status' => 'COMPLETED']);
check('Student sees only own requests with the status filter', $s === 200 && in_array($requestId, $ids($d, 'requests', 'id')));
[$s, $d] = $newStudent->call('requests.php', ['action' => 'list', 'status' => 'COMPLETED']);
check("Another student's filter never shows this request", $s === 200 && !in_array($requestId, $ids($d, 'requests', 'id')));
[$s, $d] = $cashier->call('payments.php', ['action' => 'history', 'method' => 'Cash', 'from' => date('Y-m-d'), 'to' => date('Y-m-d')]);
check('Payment history: Cash + today finds the payment', $s === 200 && in_array($paymentId, $ids($d, 'payments', 'id')));
[$s, $d] = $cashier->call('payments.php', ['action' => 'history', 'method' => 'GCash']);
check('Payment history: GCash does not include the cash payment', !in_array($paymentId, $ids($d, 'payments', 'id')));
[$s, $d] = $admin->call('users.php', ['role' => 'cashier', 'status' => 'active']);
check('Accounts: role + status filter', $s === 200 && count(array_filter($d['users'], fn($u) => $u['role'] !== 'cashier' || !$u['is_active'])) === 0);

echo "Catalog protection\n";
[$s] = $admin->call('documents.php', ['action' => 'delete'], ['id' => $doc['id']]);    check('A document used by requests cannot be deleted (422)', $s === 422);

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);