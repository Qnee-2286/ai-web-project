<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();

unset($_SESSION['candidate_id'], $_SESSION['candidate_token'], $_SESSION['candidate_account_id']);

respond(true, '已退出候选人端');
