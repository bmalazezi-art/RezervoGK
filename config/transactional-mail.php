<?php

return [
    'enabled' => (bool) env('TRANSACTIONAL_MAIL_ENABLED', false),
    // Only local/testing environments may use an unauthenticated loopback SMTP sink.
    'qa_local_smtp' => (bool) env('TRANSACTIONAL_MAIL_QA_LOCAL_SMTP', false),
];
