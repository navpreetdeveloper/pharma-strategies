<?php

return [
    'subject' => env('VAPID_SUBJECT'),
    'public_key' => env('VAPID_PUBLIC_KEY'),
    'private_key' => env('VAPID_PRIVATE_KEY'),
    'enabled' => (bool) env('WEB_PUSH_ENABLED', false) || (bool) (env('VAPID_PUBLIC_KEY') && env('VAPID_PRIVATE_KEY')),
];
