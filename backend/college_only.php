<?php
// The system is for COLLEGE students only. This hides every other school level (Senior High, Junior High ...) WITHOUT deleting anything:
//   1. Students whose level is not COLLEGE are copied once into the table students_non_college_backup (a backup for the registrar to review).
//   2. Every non-COLLEGE level, with its courses, year levels and sections, is deactivated (is_active = 0), so it can no longer be chosen anywhere.
//   3. "Form 138 / Report Card" (a high school document) is deactivated in the document catalog. Old requests that used it keep working.
// Nothing is dropped, truncated or moved. Safe to run many times. Needs the Academic Management tables (school_levels ...).
function college_only_apply(PDO $pdo) {
    $exists = fn($t) => (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t'")->fetchColumn() > 0;

    if ($exists('students')) {
        // 1. backup of the non-college students (created once, then only new rows are added; existing backup rows are never changed)
        $pdo->exec("CREATE TABLE IF NOT EXISTS students_non_college_backup LIKE students");
        $pdo->exec("INSERT INTO students_non_college_backup SELECT s.* FROM students s
                    WHERE s.school_level IS NOT NULL AND UPPER(s.school_level) <> 'COLLEGE'
                      AND NOT EXISTS (SELECT 1 FROM students_non_college_backup b WHERE b.user_id = s.user_id)");
    }
    if ($exists('school_levels') && $exists('school_courses') && $exists('school_year_levels') && $exists('school_sections')) {
        // 2. deactivate everything under a non-college level (children first, so no page can pick them while the parent is still active)
        $notCollege = "SELECT id FROM school_levels WHERE UPPER(name) <> 'COLLEGE'";
        $pdo->exec("UPDATE school_sections SET is_active = 0 WHERE year_level_id IN (SELECT id FROM school_year_levels WHERE course_id IN (SELECT id FROM school_courses WHERE level_id IN ($notCollege)))");
        $pdo->exec("UPDATE school_year_levels SET is_active = 0 WHERE course_id IN (SELECT id FROM school_courses WHERE level_id IN ($notCollege))");
        $pdo->exec("UPDATE school_courses SET is_active = 0 WHERE level_id IN ($notCollege)");
        $pdo->exec("UPDATE school_levels SET is_active = 0 WHERE UPPER(name) <> 'COLLEGE'");
    }
    if ($exists('documents')) {
        $pdo->exec("UPDATE documents SET is_active = 0 WHERE name LIKE 'Form 138%'");   // 3. high school document
    }
}
