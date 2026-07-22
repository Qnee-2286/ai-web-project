<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/payment_membership.php';

$hr = require_hr($pdo);
hi_cleanup_expired_unpaid_orders($pdo);
$snapshot = hi_membership_snapshot($pdo, (int)$hr['id']);

respond(true, 'ok', $snapshot);
