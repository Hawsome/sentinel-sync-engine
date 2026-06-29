<?php
// Shared helpers included by all engine scripts.
// Defining these here prevents fatal "cannot redeclare" errors if files are
// ever included together (e.g. in a test harness).

const BATCH_SIZE       = 500;
const MAX_ATTEMPTS     = 5;
const MAX_EMAIL_LENGTH = 254;

function log_msg(string $level, string $message): void {
    echo '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' . $message . PHP_EOL;
}

function validate_row(array $row): ?string {
    if (empty($row['id']) || !is_numeric($row['id']) || (int)$row['id'] <= 0) {
        return 'Invalid or missing id';
    }
    if (!isset($row['amount_kobo']) || !is_numeric($row['amount_kobo']) || (int)$row['amount_kobo'] <= 0) {
        return 'Invalid or missing amount_kobo (must be positive integer)';
    }
    if (empty($row['donor_email']) || !filter_var($row['donor_email'], FILTER_VALIDATE_EMAIL)) {
        return 'Invalid or missing donor_email';
    }
    if (strlen($row['donor_email']) > MAX_EMAIL_LENGTH) {
        return 'donor_email exceeds maximum length';
    }
    return null;
}
