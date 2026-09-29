<?php

return [
    'message_edit_window_minutes' => (int) env('MESSAGE_EDIT_WINDOW_MINUTES', 10),
    'message_delete_window_minutes' => (int) env('MESSAGE_DELETE_WINDOW_MINUTES', 5),
    'attachments' => [
        'disk' => env('CHAT_ATTACHMENT_DISK', env('FILESYSTEM_DISK', 'local')),
        'max_files' => (int) env('CHAT_MAX_ATTACHMENTS', 5),
        'max_total_mb' => (int) env('CHAT_MAX_TOTAL_UPLOAD_MB', 120),
        'max_image_mb' => (int) env('CHAT_MAX_IMAGE_MB', 10),
        'max_video_mb' => (int) env('CHAT_MAX_VIDEO_MB', 100),
        'max_document_mb' => (int) env('CHAT_MAX_DOCUMENT_MB', 25),
        'mimes' => [
            'jpg', 'jpeg', 'png', 'webp', 'gif',
            'mp4', 'webm', 'mov',
            'pdf',
            'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'txt', 'csv',
        ],
    ],
];
