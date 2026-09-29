# Pharma Strategies v3.4 — Message Composer & Attachments

## Implemented
- Server-enforced 10-minute message editing window.
- Real-time `message.edited` broadcast event.
- Edit controls automatically disappear when the edit window expires in the open chat.
- Attachment menu with Camera, Photos & videos, and PDF & documents.
- Multiple attachments per message (configurable, default 5).
- Images, video, PDF and common Office/text documents.
- Mobile camera capture input.
- Desktop drag-and-drop attachment support.
- Attachment previews for images, videos and PDFs.
- Private authenticated download/preview routes.
- Per-type and combined upload size validation.
- Randomised private storage paths scoped by company/conversation/message.
- Attachment metadata stored in MySQL; binary files stay on the filesystem disk and can later be moved to object storage.
- No message classification UI was added.
- Composer and attachment controls are responsive for mobile screens.

## Configuration
- `MESSAGE_EDIT_WINDOW_MINUTES=10`
- `CHAT_MAX_ATTACHMENTS=5`
- `CHAT_MAX_TOTAL_UPLOAD_MB=120`
- `CHAT_MAX_IMAGE_MB=10`
- `CHAT_MAX_VIDEO_MB=100`
- `CHAT_MAX_DOCUMENT_MB=25`

## Important production note
The local disk is appropriate for development. For production, configure the private filesystem layer to use managed object storage and add malware scanning before accepting untrusted workplace files at scale.
