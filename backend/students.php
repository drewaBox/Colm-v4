<?php
// Student accounts: creating one student, checking and importing a CSV file, default passwords.
require_once __DIR__ . '/database.php';

const CSV_COLUMNS = ['student_id', 'full_name', 'email', 'course', 'year', 'section', 'contact_number'];
const CSV_MAX_ROWS = 1000;
const STUDENT_STATUSES = ['currently_enrolled', 'alumni', 'undergrad'];

// Every newly created, imported, or reset student account starts with this password.
function make_default_password() {
    return 'Password123!';
}

// ---------------------------------------------------------------------------------------------
// The school's levels, courses, year levels and sections.
// The admin manages them on the Academic Management page (tables school_levels, school_courses, school_year_levels,
// school_sections). The Add Student form (JavaScript) and the PHP check use the SAME list, so a wrong combination can never
// be saved. Only ACTIVE items can be chosen for a new student; students who already have an item that was deactivated keep it.
// ---------------------------------------------------------------------------------------------

// The starting list (installed the first time, and used when the tables do not exist yet).
//   level => [ course => [ year level => [ section names ] ] ]
function default_school_structure() {
    $sections = fn($count) => array_map(fn($n) => 'Section ' . $n, range(1, $count));
    $collegeYears = [1 => $sections(3), 2 => $sections(2), 3 => $sections(4), 4 => $sections(2)];
    $collegeCourses = [
        'BSTM',
        'BSA - Accountancy',
        'BS AIS - Accounting Information System',
        'BSBA FM - Financial Management',
        'BSBA MM - Marketing Management',
        'BSEntrep - Entrepreneurship',
        'BSIT - Information Technology',
        'BSCS - Computer Science',
        'BSCRIM - Criminology',
        'BSHM - Hospitality Management',
        'BTVTE - Food and Service Management',
        'BTVTE - Home Economics',
        'BTVTE - Animal and Crop Science',
        'BTLE - Home Economics',
        'BTLE - Agri-Fishery',
        'BSRT - Radiologic Technology',
    ];
    return [
        'COLLEGE' => array_fill_keys($collegeCourses, $collegeYears),
    ];
}

// "First Year", "Second Year" ... (this system is for college only).
function default_year_label($level, $year) {
    return [1 => 'First Year', 2 => 'Second Year', 3 => 'Third Year', 4 => 'Fourth Year', 5 => 'Fifth Year', 6 => 'Sixth Year'][(int)$year] ?? ('Year ' . (int)$year);
}


function credential_request_document($name) {
    $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$name));
    return strpos($normalized, 'transcriptofrecords') === 0 || $normalized === 'honorabledismissal'
        || strpos($normalized, 'form137') === 0;
}

function credential_form_required_document($name) {
    return credential_request_document($name);
}

// The Student Verification form and the Student ID upload were removed from the request process (the first form
// already verifies the student). Always false for new requests.
function student_verification_form_required_document($name, $category) {
    return false;
}

// Old requests that were submitted with a Student Verification form keep being read the way they were saved.
function legacy_student_verification_document($name, $category) {
    return !credential_request_document($name) && strcasecmp(trim((string)$category), 'Certification') === 0;
}

function effective_document_requirements($documentId, $name, $category, $includeCredentialUploadRequirements = false) {
    if ((credential_form_required_document($name) || student_verification_form_required_document($name, $category))
        && !$includeCredentialUploadRequirements) return [];
    $requirements = fetch_all('SELECT id, document_id, name, description, ai_hint, keywords, is_required, requires_name, allowed_types, max_size_mb
                               FROM document_requirements WHERE document_id = ? ORDER BY sort_order, id', [$documentId]);
    // Certification documents have no requirements step: the student information in the first form is the verification.
    if (strcasecmp((string)$category, 'Certification') === 0) return [];
    return $requirements;
}

function ensure_college_course_structure(PDO $pdo, int $courseId) {
    $fetchAll = function ($sql, $params = []) use ($pdo) {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    };
    $fetchOne = function ($sql, $params = []) use ($pdo) {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    $course = $fetchOne('SELECT c.name, l.name AS level FROM school_courses c JOIN school_levels l ON l.id = c.level_id WHERE c.id = ?', [$courseId]);
    if (!$course || $course['level'] !== 'COLLEGE') return 0;

    $templateCourse = $fetchOne("SELECT c.id FROM school_courses c
                                 JOIN school_levels l ON l.id = c.level_id
                                 WHERE l.name = 'COLLEGE' AND c.name = 'BSTM'");
    $templateYears = [];
    $templateSections = [];
    if ($course['name'] === 'BSTM') {
        foreach (default_school_structure()['COLLEGE']['BSTM'] as $number => $sections) {
            $templateYears[] = ['year_number' => $number, 'label' => default_year_label('COLLEGE', $number), 'is_active' => 1];
            $templateSections[$number] = array_map(fn($name) => ['name' => $name, 'is_active' => 1], $sections);
        }
    } elseif ($templateCourse) {
        $templateYears = $fetchAll('SELECT id, year_number, label, is_active FROM school_year_levels WHERE course_id = ? AND is_active = 1 ORDER BY year_number', [$templateCourse['id']]);
        foreach ($templateYears as $year) {
            $templateSections[(int)$year['year_number']] = $fetchAll(
                'SELECT name, is_active FROM school_sections WHERE year_level_id = ? AND is_active = 1 ORDER BY id',
                [$year['id']]
            );
        }
    }
    if (!$templateYears) return 0;

    $existingYears = $fetchAll('SELECT id, year_number FROM school_year_levels WHERE course_id = ?', [$courseId]);
    $yearsByNumber = [];
    foreach ($existingYears as $year) $yearsByNumber[(int)$year['year_number']] = $year;
    $yearInsert = $pdo->prepare('INSERT INTO school_year_levels (course_id, year_number, label, is_active) VALUES (?, ?, ?, ?)');
    $yearUpdate = $pdo->prepare('UPDATE school_year_levels SET label = ?, is_active = ? WHERE id = ?');
    $sectionInsert = $pdo->prepare('INSERT INTO school_sections (year_level_id, name, is_active) VALUES (?, ?, ?)');
    $sectionUpdate = $pdo->prepare('UPDATE school_sections SET is_active = ? WHERE id = ?');
    $sectionDelete = $pdo->prepare('DELETE FROM school_sections WHERE id = ?');
    $yearDelete = $pdo->prepare('DELETE FROM school_year_levels WHERE id = ?');
    $studentCount = $pdo->prepare('SELECT COUNT(*) FROM students WHERE school_level = ? AND course = ? AND year_level = ? AND section = ?');
    $yearStudentCount = $pdo->prepare('SELECT COUNT(*) FROM students WHERE school_level = ? AND course = ? AND year_level = ?');
    $changed = 0;
    $templateNumbers = [];

    foreach ($templateYears as $templateYear) {
        $number = (int)$templateYear['year_number'];
        $templateNumbers[] = $number;
        if (!isset($yearsByNumber[$number])) {
            $yearInsert->execute([$courseId, $number, $templateYear['label'], $templateYear['is_active']]);
            $yearId = (int)$pdo->lastInsertId();
            $changed++;
        } else {
            $yearId = (int)$yearsByNumber[$number]['id'];
            $yearUpdate->execute([$templateYear['label'], $templateYear['is_active'], $yearId]);
            $changed += $yearUpdate->rowCount();
        }

        $existingSections = $fetchAll('SELECT id, name, is_active FROM school_sections WHERE year_level_id = ?', [$yearId]);
        $templateNames = [];
        foreach ($templateSections[$number] ?? [] as $templateSection) {
            $name = $templateSection['name'];
            $templateNames[] = $name;
            $existing = null;
            foreach ($existingSections as $section) if ($section['name'] === $name) { $existing = $section; break; }
            if ($existing) {
                $sectionUpdate->execute([$templateSection['is_active'], $existing['id']]);
                $changed += $sectionUpdate->rowCount();
            } else {
                $sectionInsert->execute([$yearId, $name, $templateSection['is_active']]);
                $changed++;
            }
        }

        foreach ($existingSections as $section) {
            if (in_array($section['name'], $templateNames, true)) continue;
            $studentCount->execute(['COLLEGE', $course['name'], $number, $section['name']]);
            if ((int)$studentCount->fetchColumn() > 0) {
                $sectionUpdate->execute([0, $section['id']]);
                $changed += $sectionUpdate->rowCount();
            } else {
                $sectionDelete->execute([$section['id']]);
                $changed++;
            }
        }
    }

    foreach ($existingYears as $year) {
        if (in_array((int)$year['year_number'], $templateNumbers, true)) continue;
        $yearStudentCount->execute(['COLLEGE', $course['name'], (int)$year['year_number']]);
        if ((int)$yearStudentCount->fetchColumn() > 0) {
            $deactivateYear = $pdo->prepare('UPDATE school_year_levels SET is_active = 0 WHERE id = ?');
            $deactivateYear->execute([$year['id']]);
            $changed += $deactivateYear->rowCount();
            $deactivateSections = $pdo->prepare('UPDATE school_sections SET is_active = 0 WHERE year_level_id = ?');
            $deactivateSections->execute([$year['id']]);
            $changed += $deactivateSections->rowCount();
        } else {
            $pdo->prepare('DELETE FROM school_sections WHERE year_level_id = ?')->execute([$year['id']]);
            $yearDelete->execute([$year['id']]);
            $changed++;
        }
    }
    return $changed;
}

// All levels with their courses, year levels and sections, as a nested list (used by the Academic Management page and the forms).
//   [ ['id', 'level', 'is_active', 'courses' => [ ['id', 'name', 'is_active', 'years' => [ ['id', 'year', 'label', 'is_active', 'sections' => [ ['id', 'name', 'is_active'] ]] ]] ]] ]
// $includeInactive = false: only items that are active AND whose parents are active (what a NEW student may choose).
function load_school_tree($includeInactive = false) {
    $where = $includeInactive ? '' : ' WHERE is_active = 1';
    $sections = [];
    foreach (fetch_all('SELECT id, year_level_id, name, is_active FROM school_sections' . $where . ' ORDER BY id') as $row) {
        $sections[$row['year_level_id']][] = ['id' => (int)$row['id'], 'name' => $row['name'], 'is_active' => (bool)$row['is_active']];
    }
    $years = [];
    foreach (fetch_all('SELECT id, course_id, year_number, label, is_active FROM school_year_levels' . $where . ' ORDER BY year_number') as $row) {
        $years[$row['course_id']][] = ['id' => (int)$row['id'], 'year' => (int)$row['year_number'], 'label' => $row['label'],
                                       'is_active' => (bool)$row['is_active'], 'sections' => $sections[$row['id']] ?? []];
    }
    $courses = [];
    foreach (fetch_all('SELECT id, level_id, name, is_active FROM school_courses' . $where . ' ORDER BY sort_order, id') as $row) {
        $courses[$row['level_id']][] = ['id' => (int)$row['id'], 'name' => $row['name'], 'is_active' => (bool)$row['is_active'], 'years' => $years[$row['id']] ?? []];
    }
    $tree = [];
    foreach (fetch_all('SELECT id, name, is_active FROM school_levels' . $where . ' ORDER BY sort_order, id') as $row) {
        $tree[] = ['id' => (int)$row['id'], 'level' => $row['name'], 'is_active' => (bool)$row['is_active'], 'courses' => $courses[$row['id']] ?? []];
    }
    return $tree;
}

// True when the Academic Management tables exist and have been filled.
function school_tables_ready() {
    static $ready = null;
    if ($ready === null) {
        try { $ready = (int)fetch_one('SELECT COUNT(*) AS c FROM school_levels')['c'] > 0; }
        catch (PDOException $e) { $ready = false; }       // the tables do not exist yet (database/install.php was not run)
    }
    return $ready;
}

// The list used to CHECK a student:  level => [ course => [ year => [ section names ] ] ]   (active items only)
function school_structure() {
    if (!school_tables_ready()) return default_school_structure();
    $map = [];
    foreach (load_school_tree(false) as $level) {
        $map[$level['level']] = [];
        foreach ($level['courses'] as $course) {
            $map[$level['level']][$course['name']] = [];
            foreach ($course['years'] as $year) $map[$level['level']][$course['name']][$year['year']] = array_column($year['sections'], 'name');
        }
    }
    return $map;
}

function school_structure_for_client($includeInactive = false) {
    $levels = [];
    $tree = school_tables_ready() ? load_school_tree($includeInactive) : [];
    if (!$tree) {
        foreach (default_school_structure() as $levelName => $courses) {
            $courseList = [];
            foreach ($courses as $courseName => $years) {
                $yearList = [];
                foreach ($years as $yearNumber => $sections) {
                    $yearList[] = ['year' => $yearNumber, 'label' => default_year_label($levelName, $yearNumber), 'sections' => $sections];
                }
                $courseList[] = ['name' => $courseName, 'years' => $yearList];
            }
            $levels[] = ['level' => $levelName, 'courses' => $courseList];
        }
        return $levels;
    }
    foreach ($tree as $level) {
        $courseList = [];
        foreach ($level['courses'] as $course) {
            $yearList = [];
            foreach ($course['years'] as $year) {
                $yearList[] = ['year' => $year['year'], 'label' => $year['label'], 'sections' => array_column($year['sections'], 'name')];
            }
            $courseList[] = ['name' => $course['name'], 'years' => $yearList];
        }
        $levels[] = ['level' => $level['level'], 'courses' => $courseList];
    }
    return $levels;
}

// The name of a year level: "First Year", "Second Year" ... (the admin can change it). $course is optional.
function year_label($level, $year, $course = null) {
    static $labels = null;
    if ($labels === null) {
        $labels = [];
        if (school_tables_ready()) {
            foreach (load_school_tree(true) as $l) foreach ($l['courses'] as $c) foreach ($c['years'] as $y) {
                $labels[$l['level']][$c['name']][$y['year']] = $y['label'];
                $labels[$l['level']]['*'][$y['year']] = $labels[$l['level']]['*'][$y['year']] ?? $y['label'];
            }
        }
    }
    $found = $labels[$level][$course ?? '*'][(int)$year] ?? ($labels[$level]['*'][(int)$year] ?? null);
    return $found ?? default_year_label($level, $year);
}

// Finds $value in a list of names ignoring capital letters ("bstm" = "BSTM"). Returns the name as it is in the list, or null.
function find_name($names, $value) {
    foreach ($names as $name) if (strcasecmp((string)$name, trim((string)$value)) === 0) return $name;
    return null;
}

// "3", "3rd Year", "Third Year" -> the number. Returns null if there is no number in it.
function parse_year_level($value) {
    $value = mb_strtolower(trim((string)$value));
    $words = ['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4];
    foreach ($words as $word => $number) if (strpos($value, $word) === 0) return $number;
    return preg_match('/\d+/', $value, $m) ? (int)$m[0] : null;
}

// "Section 2", "section2" or just "2" -> the section name as it is in the list ("Section 2"). Returns null if there is none.
function find_section($names, $value) {
    $simple = fn($text) => strtolower(preg_replace('/\s+/', '', (string)$text));
    $wanted = $simple($value);
    if ($wanted === '') return null;
    if (ctype_digit($wanted)) $wanted = 'section' . $wanted;
    foreach ($names as $name) if ($simple($name) === $wanted) return $name;
    return null;
}

// The school level a course belongs to (the CSV file has no School Level column).
function level_of_course($course, $structure = null) {
    foreach ($structure ?? school_structure() as $level => $courses) {
        if (find_name(array_keys($courses), $course) !== null) return $level;
    }
    return null;
}

// Is this combination real (and active)? Returns [the values with the capital letters of the list, error text or ''].
function resolve_school_combination($level, $course, $year, $section, $structure = null) {
    $structure = $structure ?? school_structure();
    $levelName = find_name(array_keys($structure), $level);
    if ($levelName === null) return [null, 'Please choose a valid School Level.'];
    $courseName = find_name(array_keys($structure[$levelName]), $course);
    if ($courseName === null) return [null, 'The course does not belong to the selected School Level.'];
    if (!isset($structure[$levelName][$courseName][(int)$year])) return [null, 'The year level does not belong to the selected course.'];
    $sectionName = find_section($structure[$levelName][$courseName][(int)$year], $section);
    if ($sectionName === null) return [null, 'The section does not exist for the selected year level.'];
    return [['school_level' => $levelName, 'course' => $courseName, 'year' => (int)$year, 'section' => $sectionName], ''];
}

// Same as above, but only the error text ('' = fine).
function check_school_combination($level, $course, $year, $section, $structure = null) {
    return resolve_school_combination($level, $course, $year, $section, $structure)[1];
}

// Checks one student's data. Returns [cleaned data, list of error messages].
function validate_student($row, $structure = null, $requireSection = true) {
    $structure = $structure ?? school_structure();
    $errors = [];
    $studentId = trim((string)($row['student_id'] ?? ''));
    $fullName = preg_replace('/\s+/', ' ', trim((string)($row['full_name'] ?? '')));
    $email = trim((string)($row['email'] ?? ''));
    $contact = trim((string)($row['contact_number'] ?? ''));
    $course = trim((string)($row['course'] ?? ''));
    $level = trim((string)($row['school_level'] ?? ''));
    if ($level === '' && $course !== '') $level = level_of_course($course, $structure) ?? '';       // CSV: the course tells the level
    $year = parse_year_level($row['year'] ?? '');
    $sectionInput = trim((string)($row['section'] ?? ''));
    $status = strtolower(trim((string)($row['status'] ?? 'currently_enrolled')));
    $academicYear = trim((string)($row['academic_year'] ?? ''));
    if (!in_array($status, STUDENT_STATUSES, true)) $errors[] = 'Choose Currently Enrolled, Alumni, or Undergrad as the student status.';
    if ($academicYear !== '' && !valid_academic_year($academicYear)) $errors[] = 'Academic year must use consecutive years in YYYY-YYYY format (for example, 2025-2026).';

    if ($studentId === '') $errors[] = 'Student ID is missing.';
    elseif (!preg_match('/^\d{4}-\d{5}$/', $studentId)) $errors[] = 'Student ID must have 4 digits, a hyphen, then 5 digits (e.g. 1234-56789).';
    if ($fullName === '') $errors[] = 'Full name is missing.';
    elseif (mb_strlen($fullName) < 3 || mb_strlen($fullName) > 120 || !preg_match('/^[\p{L}\p{M}\s.\'-]+(,[\p{L}\p{M}\s.\'-]+)?$/u', $fullName)) $errors[] = 'Full name is not valid.';
    if ($email === '') $errors[] = 'Email address is required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120) $errors[] = 'Email address is not valid.';
    if ($contact !== '' && !preg_match('/^[0-9+() -]{7,20}$/', $contact)) $errors[] = 'Contact number is not valid.';

    // The add-student and self-registration forms may leave section unset, but the academic combination must still be valid.
    $resolved = null;
    if ($course === '') $errors[] = 'Course is missing.';
    elseif ($year === null) $errors[] = 'Year level is missing or not a number.';
    elseif ($sectionInput === '' && $requireSection) $errors[] = 'Section is missing.';
    elseif ($sectionInput !== '') {
        [$resolved, $problem] = resolve_school_combination($level, $course, $year, $sectionInput, $structure);
        if ($problem !== '') $errors[] = $problem;
    } elseif (!$requireSection) {
        $levelName = find_name(array_keys($structure), $level);
        if ($levelName === null) {
            $errors[] = 'Please choose a valid School Level.';
        } else {
            $courseName = find_name(array_keys($structure[$levelName]), $course);
            if ($courseName === null) {
                $errors[] = 'The course does not belong to the selected School Level.';
            } elseif (!isset($structure[$levelName][$courseName][$year])) {
                $errors[] = 'The year level does not belong to the selected course.';
            } else {
                $resolved = ['school_level' => $levelName, 'course' => $courseName, 'year' => $year, 'section' => null];
            }
        }
    }

    return [[
        'student_id' => $studentId, 'full_name' => $fullName, 'email' => $email,
        'school_level' => $resolved['school_level'] ?? $level, 'course' => $resolved['course'] ?? $course,
        'year' => $resolved['year'] ?? $year, 'section' => $resolved['section'] ?? ($sectionInput !== '' ? $sectionInput : null), 'contact_number' => $contact,
        'status' => $status, 'academic_year' => $academicYear,
    ], $errors];
}

function valid_academic_year($value) {
    if (!preg_match('/^(19\d{2}|20\d{2})-(19\d{2}|20\d{2})$/', (string)$value, $matches)) return false;
    [$start, $end] = array_map('intval', explode('-', $value));
    return $start >= 1950 && $end === $start + 1 && $start <= (int)date('Y') + 1;
}

// Does this student ID (or username) already exist?
function student_id_taken($studentId) {
    return fetch_one('SELECT id FROM users WHERE username = ? OR student_no = ?', [$studentId, $studentId]) !== null;
}

function student_email_taken($email, $exceptUserId = 0) {
    $sql = 'SELECT id FROM users WHERE email = ?';
    $params = [$email];
    if ($exceptUserId > 0) { $sql .= ' AND id <> ?'; $params[] = $exceptUserId; }
    return fetch_one($sql, $params) !== null;
}

// Creates the login account and the student profile. Returns the new user id.
function create_student($data, $password, $mustChangePassword = true, $isActive = true, $isApproved = true) {
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        query('INSERT INTO users (username, password_hash, password_changed_at, full_name, email, role, student_no, must_change_password, is_active, is_approved) VALUES (?, ?, CURRENT_TIMESTAMP, ?, ?, ?, ?, ?, ?, ?)',
            [$data['student_id'], password_hash($password, PASSWORD_DEFAULT), $data['full_name'],
             $data['email'] !== '' ? $data['email'] : null, 'student', $data['student_id'], $mustChangePassword ? 1 : 0, $isActive ? 1 : 0, $isApproved ? 1 : 0]);
        $userId = (int)$pdo->lastInsertId();
        query('INSERT INTO students (user_id, school_level, course, year_level, section, status, academic_year, contact_no) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, $data['school_level'], $data['course'], $data['year'], $data['section'], $data['status'], $data['academic_year'] !== '' ? $data['academic_year'] : null, $data['contact_number'] !== '' ? $data['contact_number'] : null]);
        if ($own) $pdo->commit();
        return $userId;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// Reads the uploaded CSV file into rows. Throws RuntimeException with a clear message if the file is not usable.
// Returns a list of ['line' => number in the file, 'data' => [column => value]].
function read_students_csv($path, $requiredColumns = CSV_COLUMNS) {
    $text = file_get_contents($path);
    if ($text === false || trim($text) === '') throw new RuntimeException('The CSV file is empty.');
    if (substr($text, 0, 3) === "\xEF\xBB\xBF") $text = substr($text, 3);                 // Excel adds a BOM
    if (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    if (strpos($text, "\0") !== false) throw new RuntimeException('This does not look like a CSV text file.');

    $lines = preg_split('/\r\n|\r|\n/', $text);
    $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $text);
    rewind($stream);

    $header = fgetcsv($stream, 0, $delimiter);
    $header = array_map(fn($h) => strtolower(trim(str_replace([' ', '-'], '_', (string)$h))), $header ?: []);
    // Accept a few common alternative column names.
    $aliases = ['school_level' => 'school_level', 'level' => 'school_level', 'id_number' => 'student_id', 'student_no' => 'student_id', 'id' => 'student_id', 'name' => 'full_name',
                'fullname' => 'full_name', 'year_level' => 'year', 'contact' => 'contact_number', 'contact_no' => 'contact_number',
                'mobile' => 'contact_number', 'phone' => 'contact_number', 'program' => 'course'];
    $header = array_map(fn($h) => $aliases[$h] ?? $h, $header);
    $missing = array_values(array_diff($requiredColumns, $header));
    if ($missing) throw new RuntimeException('The CSV is missing these columns: ' . implode(', ', $missing) . '. Download the sample file to see the correct format.');

    $rows = [];
    $line = 1;
    while (($fields = fgetcsv($stream, 0, $delimiter)) !== false) {
        $line++;
        if ($fields === [null] || trim(implode('', $fields)) === '') continue; // empty line
        if (count($rows) >= CSV_MAX_ROWS) throw new RuntimeException('The CSV has more than ' . CSV_MAX_ROWS . ' students. Split it into smaller files.');
        $data = [];
        foreach ($header as $i => $name) $data[$name] = $fields[$i] ?? '';
        $rows[] = ['line' => $line, 'data' => $data];
    }
    fclose($stream);
    if (!$rows) throw new RuntimeException('The CSV has no student rows.');
    return $rows;
}

// Checks every row. Nothing is saved. Returns ['valid' => [...], 'failed' => [...]].
function check_students_csv($rows) {
    $valid = []; $failed = []; $seen = []; $seenEmails = [];
    $structure = school_structure();                  // read the lists once for the whole file
    foreach ($rows as $row) {
        [$data, $errors] = validate_student($row['data'], $structure);
        if (!$errors) {
            $key = mb_strtolower($data['student_id']);
            if (isset($seen[$key])) $errors[] = "Student ID appears twice in this file (first on line {$seen[$key]}).";
            elseif (student_id_taken($data['student_id'])) $errors[] = 'A student or account with this ID already exists.';
            $emailKey = mb_strtolower($data['email']);
            if (!$errors && isset($seenEmails[$emailKey])) $errors[] = "Email address appears twice in this file (first on line {$seenEmails[$emailKey]}).";
            elseif (!$errors && student_email_taken($data['email'])) $errors[] = 'Email address is already used by another account.';
            if (!$errors) { $seen[$key] = $row['line']; $seenEmails[$emailKey] = $row['line']; }
        }
        if ($errors) $failed[] = ['line' => $row['line'], 'student_id' => $data['student_id'], 'full_name' => $data['full_name'], 'errors' => $errors];
        else $valid[] = ['line' => $row['line'], 'data' => $data];
    }
    return ['valid' => $valid, 'failed' => $failed];
}

// The sample file the admin downloads on the Students page. The course decides the school level.
function sample_students_csv() {
    return "student_id,full_name,email,course,year,section,contact_number,status\r\n"
         . "2026-10001,Maria Santos,maria.santos@example.com,BSTM,1,Section 1,09171234567,currently_enrolled\r\n"
         . "2026-10002,Jose Reyes,jose.reyes@example.com,BSIT - Information Technology,2,Section 2,09181234567,undergrad\r\n"
         . "2026-10003,Ana Garcia,ana.garcia@example.com,BSA - Accountancy,4,Section 1,09191234567,alumni\r\n";
}