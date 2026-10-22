<?php

// Laravel merges these mailers with its framework SMTP/array defaults.
return [
    'default' => env('MAIL_MAILER', 'private-preview'),
    'mailers' => ['private-preview' => ['transport' => 'private-preview']],
];
