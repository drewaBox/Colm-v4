<?php
// Simple fixed-window rate limit kept in the database (works on shared hosting, no extra software).
require_once __DIR__ . '/database.php';

// Counts one hit for $bucket (for example "track-ip:1.2.3.4"). Returns true when the call is allowed,
// false when more than $max hits happened in the last $seconds. One atomic statement, so two parallel requests cannot both slip through.
function rate_limit_allow($bucket, $max, $seconds) {
    $bucket = mb_substr($bucket, 0, 120);
    $now = date('Y-m-d H:i:s');
    $oldest = date('Y-m-d H:i:s', time() - $seconds);
    query('INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1)
           ON DUPLICATE KEY UPDATE hits = IF(window_start < ?, 1, hits + 1), window_start = IF(window_start < ?, VALUES(window_start), window_start)',
        [$bucket, $now, $oldest, $oldest]);
    $row = fetch_one('SELECT hits FROM rate_limits WHERE bucket = ?', [$bucket]);
    return $row && (int)$row['hits'] <= $max;
}
