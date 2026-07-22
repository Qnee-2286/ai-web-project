<?php
require_once __DIR__ . '/../bootstrap.php';
require_post();
$hr = require_hr($pdo);
$input = json_input();
$company = trim($input['company_name'] ?? '');
$credit = trim($input['credit_code'] ?? '');
$intro = trim($input['company_intro'] ?? '');
$contact = trim($input['contact_name'] ?? '');
if ($company === '') {
    respond(false, 'Company name is required', [], 422);
}
$stmt = $pdo->prepare('INSERT INTO hr_company_profiles(hr_id, company_name, credit_code, company_intro, contact_name, created_at, updated_at) VALUES(?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE company_name=VALUES(company_name), credit_code=VALUES(credit_code), company_intro=VALUES(company_intro), contact_name=VALUES(contact_name), updated_at=NOW()');
$stmt->execute([$hr['id'], $company, $credit, $intro, $contact]);
respond(true, 'Company profile saved');
