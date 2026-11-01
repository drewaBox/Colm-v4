<?php
// Tests the School Level / Course / Year Level / Section rules.   Run:  php tests/students_test.php
// It uses the starting list (default_school_structure), so it needs no database.
require_once __DIR__ . '/../backend/students.php';

$failed = 0;
function expect($name, $actual, $wanted) {
    global $failed;
    if ($actual === $wanted) { echo "  PASS  $name\n"; return; }
    $failed++; echo "  FAIL  $name (got " . var_export($actual, true) . ")\n";
}
expect('Default student password is shared', make_default_password(), 'Password123!');
$structure = default_school_structure();

// How many sections does each combination have?  [level, course, year, wanted number of sections]
$wanted = [
    ['COLLEGE', 'BSTM', 1, 3], ['COLLEGE', 'BSTM', 2, 2], ['COLLEGE', 'BSTM', 3, 4], ['COLLEGE', 'BSTM', 4, 2],
];
foreach ($wanted as [$level, $course, $year, $sections]) {
    expect("$level / $course / $year has $sections sections", count($structure[$level][$course][$year] ?? []), $sections);
    expect('... the last section is accepted', check_school_combination($level, $course, $year, "Section $sections", $structure), '');
    expect('... one more section is refused', check_school_combination($level, $course, $year, 'Section ' . ($sections + 1), $structure) !== '', true);
}
expect('Only College is in the structure', array_keys($structure), ['COLLEGE']);
expect('College has all 16 programs', array_keys($structure['COLLEGE']), [
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
]);
expect('Every College course has the same year and section setup as BSTM', array_reduce(
    $structure['COLLEGE'],
    fn($valid, $years) => $valid && array_keys($years) === [1, 2, 3, 4]
        && $years === $structure['COLLEGE']['BSTM'],
    true
), true);
expect('Senior High and Junior High are absent from the student structure', [isset($structure['SENIOR HIGH SCHOOL']), isset($structure['HIGH SCHOOL'])], [false, false]);

echo "Wrong combinations are refused\n";
expect('STEM does not belong to COLLEGE', check_school_combination('COLLEGE', 'STEM', 1, 'Section 1', $structure) !== '', true);
expect('BSTM Grade 11 does not exist', check_school_combination('COLLEGE', 'BSTM', 11, 'Section 1', $structure) !== '', true);
expect('BSTM year 5 does not exist', check_school_combination('COLLEGE', 'BSTM', 5, 'Section 1', $structure) !== '', true);
expect('Senior High enrollment is refused', check_school_combination('SENIOR HIGH SCHOOL', 'STEM', 11, 'Section 1', $structure) !== '', true);
expect('Junior High enrollment is refused', check_school_combination('HIGH SCHOOL', 'HIGH SCHOOL', 7, 'Section 1', $structure) !== '', true);
expect('Section 0 is refused', check_school_combination('COLLEGE', 'BSTM', 1, 'Section 0', $structure) !== '', true);
expect('Unknown school level is refused', check_school_combination('KINDER', 'BSTM', 1, 'Section 1', $structure) !== '', true);

echo "Names: capital letters do not matter, the list decides how it is saved\n";
[$values, $problem] = resolve_school_combination('college', 'bstm', 3, '4', $structure);
expect('"college / bstm / 3 / 4" is accepted', $problem, '');
expect('... and saved as in the list', $values, ['school_level' => 'COLLEGE', 'course' => 'BSTM', 'year' => 3, 'section' => 'Section 4']);
expect('"section4" is understood', find_section(['Section 1', 'Section 4'], 'section4'), 'Section 4');
expect('A section named A (added by the admin) works', find_section(['A', 'B'], 'b'), 'B');

echo "An admin-made list is used (new course, sections A and B)\n";
$custom = ['COLLEGE' => ['BSIT' => [1 => ['A', 'B']]]];
expect('New course BSIT / year 1 / section B is accepted', check_school_combination('COLLEGE', 'BSIT', 1, 'B', $custom), '');
expect('Section C does not exist', check_school_combination('COLLEGE', 'BSIT', 1, 'C', $custom) !== '', true);
expect('An old course that is not in the (active) list is refused for NEW students', check_school_combination('COLLEGE', 'BSTM', 1, 'Section 1', $custom) !== '', true);
expect('The level of a course is found', level_of_course('bsit', $custom), 'COLLEGE');

echo "validate_student\n";
$good = ['student_id' => '2026-10001', 'full_name' => 'Maria Santos', 'email' => 'm@example.com', 'school_level' => 'COLLEGE', 'course' => 'BSTM',
         'year' => '3', 'section' => 'Section 4', 'contact_number' => '09171234567'];
[$data, $errors] = validate_student($good, $structure);
expect('Valid student has no errors', $errors, []);
expect('Section is saved as "Section 4"', $data['section'], 'Section 4');
expect('New student defaults to currently enrolled', $data['status'], 'currently_enrolled');
expect('Student ID accepts 4 digits, a hyphen, and 5 digits', preg_match('/^\d{4}-\d{5}$/', $data['student_id']), 1);
[, $errors] = validate_student(array_merge($good, ['student_id' => '123-456789']), $structure);
expect('Student ID rejects an incorrect format', in_array('Student ID must have 4 digits, a hyphen, then 5 digits (e.g. 1234-56789).', $errors, true), true);
[$data, $errors] = validate_student(array_diff_key($good, ['section' => true]), $structure, false);
expect('New student can be created without a section', [$errors, $data['section']], [[], null]);
[, $errors] = validate_student(array_merge(array_diff_key($good, ['section' => true]), ['course' => 'UNKNOWN']), $structure, false);
expect('Section-free registration still rejects an unknown course', in_array('The course does not belong to the selected School Level.', $errors, true), true);
[, $errors] = validate_student(array_merge(array_diff_key($good, ['section' => true]), ['year' => '5']), $structure, false);
expect('Section-free registration still rejects an invalid year', in_array('The year level does not belong to the selected course.', $errors, true), true);
[, $errors] = validate_student(array_diff_key($good, ['section' => true]), $structure);
expect('Section remains required for CSV and student edits', in_array('Section is missing.', $errors, true), true);
[, $errors] = validate_student(array_merge($good, ['section' => 'Section 5']), $structure);
expect('BSTM 3rd year Section 5 is refused', count($errors) > 0, true);
[$data, $errors] = validate_student(['student_id' => '2026-10002', 'full_name' => 'Jose Reyes', 'email' => 'jose@example.com', 'course' => 'BSIT - Information Technology', 'year' => '2', 'section' => 'Section 2'], $structure);
expect('CSV row without School Level: the course decides it', [$errors, $data['school_level']], [[], 'COLLEGE']);
[, $errors] = validate_student(array_merge($good, ['email' => '']), $structure);
expect('Missing notification email is refused', count($errors) > 0, true);
[, $errors] = validate_student(array_merge($good, ['school_level' => 'HIGH SCHOOL']), $structure);
expect('BSTM inside HIGH SCHOOL is refused', count($errors) > 0, true);
[, $errors] = validate_student(array_merge($good, ['student_id' => '']), $structure);
expect('Missing Student ID is refused', count($errors) > 0, true);
expect('"Third Year" is understood', parse_year_level('Third Year'), 3);
expect('Default year labels', [default_year_label('COLLEGE', 1), default_year_label('COLLEGE', 4)], ['First Year', 'Fourth Year']);
expect('Academic year accepts consecutive years', valid_academic_year('2025-2026'), true);
expect('Academic year rejects non-consecutive years', valid_academic_year('2024-2026'), false);
expect('Academic year rejects malformed values', valid_academic_year('2025/2026'), false);

echo $failed ? "\n$failed failed\n" : "\nAll passed\n";
exit($failed ? 1 : 0);
