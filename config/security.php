<?php

return [
    'require_email_verification' => (bool) env('REQUIRE_EMAIL_VERIFICATION', false),
    'require_privileged_mfa' => env('REQUIRE_PRIVILEGED_MFA', env('APP_ENV') === 'production'),
];
