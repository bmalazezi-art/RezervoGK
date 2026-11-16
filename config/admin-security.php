<?php

return [
    // Admins must enter a 6-digit code emailed to them after their password.
    // Emergency only: set ADMIN_EMAIL_CODE=false in .env if email delivery is down.
    'email_code' => (bool) env('ADMIN_EMAIL_CODE', true),
    'code_minutes' => 10,
    'max_attempts' => 5,
    'resend_after_seconds' => 60,
    // Written by the nightly server backup (scripts/server/setup-maintenance.sh); read by Admin > System health.
    'backup_status_file' => env('BACKUP_STATUS_FILE', '/var/lib/gjej-backup-status.json'),
];
