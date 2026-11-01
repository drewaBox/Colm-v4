<?php
// Semester master lists and their validation records.
//
// A "master list import" is one CSV of the students enrolled in ONE semester of ONE academic year. Every row becomes a validation record:
//   VALIDATED      the student has a portal account and name, course, year level and section agree with the list
//   MISMATCH       the account exists but something differs (the note says what)
//   NOT_REGISTERED no portal account with this student ID yet
//   INVALID        the row itself is unusable (for example the ID has the wrong format)
// When a NEWER semester is imported, the older ACTIVE imports are archived (status ARCHIVED). Records are never copied, changed or deleted:
// "archived" is the status of the import they belong to, so history can not be overwritten by a later import.
require_once __DIR__ . '/students.php';
require_once __DIR__ . '/names.php';
require_once __DIR__ . '/audit.php';

const SEMESTER_LABELS = [1 => '1st Semester', 2 => '2nd Semester', 3 => 'Summer'];

// 2025-2026 + 2nd Semester => 20252. Bigger = later. Returns null when the academic year or the semester is not valid.
function master_period_key($academicYear, $semester) {
    $semester = (int)$semester;
    if (!isset(SEMESTER_LABELS[$semester]) || !valid_academic_year($academicYear)) return null;
    return (int)substr($academicYear, 0, 4) * 10 + $semester;
}

function master_period_label($academicYear, $semester) {
    return (SEMESTER_LABELS[(int)$semester] ?? 'Semester ' . (int)$semester) . ', A.Y. ' . $academicYear;
}

// Compares one CSV row with the portal account. Returns [status, note].
function master_row_status($data) {
    $studentNo = trim((string)($data['student_id'] ?? ''));
    $name = trim((string)($data['full_name'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{3,29}$/', $studentNo)) return ['INVALID', 'The student ID has the wrong format.'];
    if ($name === '') return ['INVALID', 'The name is empty.'];

    $account = fetch_one("SELECT u.full_name, s.course, s.year_level, s.section, s.school_level FROM users u
                          LEFT JOIN students s ON s.user_id = u.id
                          WHERE u.role = 'student' AND (u.student_no = ? OR u.username = ?) LIMIT 1", [$studentNo, $studentNo]);
    if (!$account) return ['NOT_REGISTERED', 'No portal account has this student ID.'];

    $problems = [];
    // names_match() returns ['match' => bool, 'score' => n]; both directions must agree (it accepts any word order and one wrong letter in a long word).
    if (!names_match($account['full_name'], $name)['match'] || !names_match($name, $account['full_name'])['match']) $problems[] = 'name';
    $course = trim((string)($data['course'] ?? ''));
    if ($course !== '' && mb_strtolower($course) !== mb_strtolower((string)$account['course'])) $problems[] = 'course';
    $year = trim((string)($data['year'] ?? ''));
    if ($year !== '') {
        $parsed = parse_year_level($year);
        if ($parsed === null || (int)$parsed !== (int)$account['year_level']) $problems[] = 'year level';
    }
    $section = trim((string)($data['section'] ?? ''));
    if ($section !== '' && mb_strtolower($section) !== mb_strtolower((string)$account['section'])) $problems[] = 'section';
    if ($problems) return ['MISMATCH', 'The list and the portal account differ: ' . implode(', ', $problems) . '.'];
    return ['VALIDATED', null];
}

// Reads and checks the file, then (unless $dryRun) saves the import and archives the older active imports in ONE database transaction.
// Throws RuntimeException with a clear message when the import must not happen. Returns the plan / result for the browser.
function import_master_list($path, $academicYear, $semester, $user, $dryRun, $confirmArchive) {
    $academicYear = trim((string)$academicYear);
    $key = master_period_key($academicYear, $semester);
    if ($key === null) throw new RuntimeException('Choose a valid academic year (for example 2025-2026) and semester.');
    $semester = (int)$semester;

    $rows = read_students_csv($path, ['student_id', 'full_name']);       // only the ID and the name are required here; course/year/section are compared when present
    $hash = hash_file('sha256', $path);

    // Build the validation records first (nothing is saved yet).
    $records = []; $skipped = []; $seen = []; $counts = ['VALIDATED' => 0, 'MISMATCH' => 0, 'NOT_REGISTERED' => 0, 'INVALID' => 0];
    foreach ($rows as $row) {
        $data = $row['data'];
        $studentNo = trim((string)($data['student_id'] ?? ''));
        if ($studentNo === '') { $skipped[] = ['line' => $row['line'], 'reason' => 'No student ID.']; continue; }
        $dupKey = mb_strtolower($studentNo);
        if (isset($seen[$dupKey])) { $skipped[] = ['line' => $row['line'], 'reason' => "Student ID $studentNo appears twice (first on line {$seen[$dupKey]}). The first one is used."]; continue; }
        $seen[$dupKey] = $row['line'];
        [$status, $note] = master_row_status($data);
        $counts[$status]++;
        $records[] = [
            'student_no' => mb_substr($studentNo, 0, 30), 'name' => mb_substr(trim((string)($data['full_name'] ?? '')) ?: '(no name)', 0, 150),
            'course' => mb_substr(trim((string)($data['course'] ?? '')), 0, 100) ?: null,
            'year' => mb_substr(trim((string)($data['year'] ?? '')), 0, 30) ?: null,
            'section' => mb_substr(trim((string)($data['section'] ?? '')), 0, 30) ?: null,
            'status' => $status, 'note' => $note,
        ];
    }
    if (!$records) throw new RuntimeException('The file has no usable student rows.');

    $pdo = db();
    try {
        $pdo->beginTransaction();
        // The table is small; locking every row makes two people importing at the same time wait for each other.
        $imports = fetch_all('SELECT id, academic_year, semester, period_key, file_sha256, status FROM master_list_imports ORDER BY period_key FOR UPDATE');

        foreach ($imports as $existing) {
            if ($existing['file_sha256'] === $hash) {
                throw new RuntimeException('This exact file was already imported as ' . master_period_label($existing['academic_year'], $existing['semester']) . '. Nothing was changed.');
            }
            if ((int)$existing['period_key'] === $key) {
                throw new RuntimeException(master_period_label($academicYear, $semester) . ' was already imported (' . strtolower($existing['status']) . '). Imported lists are never overwritten.');
            }
        }
        $latest = $imports ? end($imports) : null;
        if ($latest && (int)$latest['period_key'] > $key) {
            throw new RuntimeException('A newer list already exists (' . master_period_label($latest['academic_year'], $latest['semester']) . '). Importing an older semester is not supported, so no wrong records are archived.');
        }
        // Everything still ACTIVE is older than the new list (checked above), so those are the ones to archive.
        $toArchive = array_values(array_filter($imports, fn($i) => $i['status'] === 'ACTIVE'));
        $plan = [
            'period' => master_period_label($academicYear, $semester), 'rows' => count($records), 'counts' => $counts, 'skipped' => $skipped,
            'will_archive' => array_map(fn($i) => ['id' => (int)$i['id'], 'label' => master_period_label($i['academic_year'], $i['semester'])], $toArchive),
        ];
        if ($dryRun) { $pdo->commit(); return $plan + ['saved' => false]; }
        if ($toArchive && !$confirmArchive) {
            throw new RuntimeException('Confirm the import: ' . implode(', ', array_column($plan['will_archive'], 'label')) . ' will be archived.');
        }

        $now = date('Y-m-d H:i:s');
        query('INSERT INTO master_list_imports (academic_year, semester, period_key, file_sha256, row_count, status, imported_by, imported_at) VALUES (?, ?, ?, ?, ?, \'ACTIVE\', ?, ?)',
            [$academicYear, $semester, $key, $hash, count($records), $user['id'], $now]);
        $importId = (int)$pdo->lastInsertId();
        foreach (array_chunk($records, 200) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?)'));
            $params = [];
            foreach ($chunk as $r) array_push($params, $importId, $r['student_no'], $r['name'], $r['course'], $r['year'], $r['section'], $r['status'], $r['note'], $now);
            $sql = 'INSERT INTO validation_records (import_id, student_no, student_name, course, year_level, section, validation_status, validation_note, validated_at) VALUES ' . $marks;
            query($sql, $params);
        }
        audit_log('masterlist_import', 'master_list', $importId, null, $user['id'], null, master_period_label($academicYear, $semester), count($records) . ' records.');

        foreach ($toArchive as $old) {
            $changed = query("UPDATE master_list_imports SET status = 'ARCHIVED', archived_at = ?, archived_by = ?, archive_note = ? WHERE id = ? AND status = 'ACTIVE'",
                [$now, $user['id'], 'Archived when ' . master_period_label($academicYear, $semester) . ' was imported.', $old['id']])->rowCount();
            if ($changed) audit_log('masterlist_archive', 'master_list', (int)$old['id'], null, $user['id'], 'ACTIVE', 'ARCHIVED', 'Archived by the import of ' . master_period_label($academicYear, $semester) . '.');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;      // nothing was saved: not the import, not the archiving
    }
    return $plan + ['saved' => true, 'import_id' => $importId];
}

// Search the records (active and archived). Filters: academic_year, semester, student_no, name, status, scope (active|archived|all), archived_from, archived_to.
function search_validation_records($filters, $page, $perPage = 25) {
    $where = []; $params = [];
    $scope = $filters['scope'] ?? 'all';
    if ($scope === 'active') $where[] = "m.status = 'ACTIVE'";
    elseif ($scope === 'archived') $where[] = "m.status = 'ARCHIVED'";
    if (($filters['academic_year'] ?? '') !== '') { $where[] = 'm.academic_year = ?'; $params[] = $filters['academic_year']; }
    if ((int)($filters['semester'] ?? 0) > 0) { $where[] = 'm.semester = ?'; $params[] = (int)$filters['semester']; }
    if (($filters['student_no'] ?? '') !== '') { $where[] = 'v.student_no LIKE ?'; $params[] = '%' . $filters['student_no'] . '%'; }
    if (($filters['name'] ?? '') !== '') { $where[] = 'v.student_name LIKE ?'; $params[] = '%' . $filters['name'] . '%'; }
    if (in_array($filters['status'] ?? '', ['VALIDATED', 'MISMATCH', 'NOT_REGISTERED', 'INVALID'], true)) { $where[] = 'v.validation_status = ?'; $params[] = $filters['status']; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['archived_from'] ?? '')) { $where[] = 'm.archived_at >= ?'; $params[] = $filters['archived_from'] . ' 00:00:00'; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['archived_to'] ?? '')) { $where[] = 'm.archived_at <= ?'; $params[] = $filters['archived_to'] . ' 23:59:59'; }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $from = 'FROM validation_records v JOIN master_list_imports m ON m.id = v.import_id';
    $total = (int)fetch_one("SELECT COUNT(*) AS c $from $whereSql", $params)['c'];
    $page = max(1, (int)$page);
    $offset = ($page - 1) * $perPage;
    $rows = fetch_all("SELECT v.id, v.student_no, v.student_name, v.course, v.year_level, v.section, v.validation_status, v.validation_note, v.validated_at,
                              m.academic_year, m.semester, m.status AS list_status, m.archived_at
                       $from $whereSql ORDER BY m.period_key DESC, v.student_name ASC, v.id ASC LIMIT $perPage OFFSET $offset", $params);
    foreach ($rows as &$row) $row['semester_label'] = SEMESTER_LABELS[(int)$row['semester']] ?? '';
    return ['records' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
}
