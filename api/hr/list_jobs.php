<?php
require_once __DIR__ . '/../bootstrap.php';

$hr = require_hr($pdo);

$stmt = $pdo->prepare('
    SELECT
      j.*,
      COUNT(c.id) AS candidate_count,
      SUM(CASE WHEN c.candidate_status="not_received" THEN 1 ELSE 0 END) AS not_received_count,
      SUM(CASE WHEN c.candidate_status="pending_interview" THEN 1 ELSE 0 END) AS pending_count,
      SUM(CASE WHEN c.candidate_status="completed" THEN 1 ELSE 0 END) AS completed_count,
      SUM(CASE WHEN c.candidate_status="review_pending" THEN 1 ELSE 0 END) AS review_pending_count
    FROM hr_jobs j
    LEFT JOIN candidates c ON c.job_id = j.id AND c.hr_id = j.hr_id AND c.phone_verified_at IS NOT NULL
    WHERE j.hr_id=? AND j.status="active"
    GROUP BY j.id
    ORDER BY j.id DESC
');
$stmt->execute([(int)$hr['id']]);
$jobs = $stmt->fetchAll();

respond(true, '岗位列表', ['jobs' => $jobs]);
