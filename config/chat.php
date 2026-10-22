<?php

return [
    'retention_scheduled' => (bool) env('CHAT_RETENTION_SCHEDULED', false),
    'provinces' => ['Alberta', 'British Columbia', 'Manitoba', 'New Brunswick', 'Newfoundland and Labrador', 'Northwest Territories', 'Nova Scotia', 'Nunavut', 'Ontario', 'Prince Edward Island', 'Quebec', 'Saskatchewan', 'Yukon'],
    'message_edit_window_minutes' => (int) env('MESSAGE_EDIT_WINDOW_MINUTES', 10),
    'message_delete_window_minutes' => (int) env('MESSAGE_DELETE_WINDOW_MINUTES', 5),
    'attachments' => [
        'max_files' => (int) env('CHAT_MAX_ATTACHMENTS', 5),
        'max_total_mb' => (int) env('CHAT_MAX_TOTAL_UPLOAD_MB', 120),
        'max_image_mb' => (int) env('CHAT_MAX_IMAGE_MB', 10),
        'max_video_mb' => (int) env('CHAT_MAX_VIDEO_MB', 100),
        'max_document_mb' => (int) env('CHAT_MAX_DOCUMENT_MB', 25),
        'preview_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/webm', 'video/quicktime', 'application/pdf'],
        'mimes' => [
            'jpg', 'jpeg', 'png', 'webp', 'gif',
            'mp4', 'webm', 'mov',
            'pdf',
            'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'txt', 'csv',
        ],
    ],
];
