# Pharma Strategies v3.6

Laravel 12 + Livewire/Blade + Tailwind + MySQL + Laravel Reverb workplace communication platform.

## v3.6 fixes and approved additions

- Message replies with quoted previews and jump-to-original behaviour.
- Message forwarding to one or more authorised company conversations.
- Attachment forwarding copies files into the target conversation's private storage path.
- Server-side forwarding authorization and audit logging.
- Real-time online/offline presence using a company-scoped Reverb presence channel.
- Last-seen timestamps updated by a 30-second authenticated heartbeat.
- Unsend/delete-for-everyone within a server-enforced five-minute window.
- One unique direct conversation per employee pair, including migration/merge handling for older duplicate direct threads.
- Authenticated sessions return to the chat workspace instead of the public landing page.
- Presence indicators for direct conversations.

## Existing v3.4 features retained

- Ten-minute server-enforced message editing.
- Private image, video, PDF and professional document attachments.
- Attachment previews/downloads with company/conversation authorization.
- Responsive message composer and mobile camera/media/document options.

## Important test note

The package includes feature tests and static checks. Full Composer/PHPUnit, MySQL, Reverb and browser/device testing still needs to be run in the user's Windows development environment because the build environment used to prepare this ZIP does not contain the project's Composer vendor directory or the user's MySQL/Reverb/browser environment.

No sample/demo data is included.


## Reverb local development

After changing `.env`, always run `php artisan optimize:clear`, stop any existing `php artisan reverb:start` process, and start Reverb again. Reverb is a long-running process and its application credentials must match the frontend build. For local development the project defaults to app ID `pharma-strategies-local`, key `pharma-strategies-local-key`, secret `pharma-strategies-local-secret`, server `127.0.0.1:8081`. Run `npm run build` or `npm run dev` after changing the `VITE_REVERB_*` values.

Laravel Reverb uses the Pusher protocol for Laravel Echo connections; the browser-side Pusher error about a missing application ID indicates the Reverb server and the frontend are not using the same Reverb application credentials.

## Registration redirect

Company registration now redirects the newly authenticated Company Super Admin directly to the `/admin` dashboard (`admin.dashboard`) after the company, user, role and company session are created.


## v3.6.3 fixes
- Fixed database notification loading type mismatch.
- Fixed static message three-dot action menu click handling.
- Added explicit Close chat control that returns to the conversation list without navigating to the public landing page.
- Added image attachment failure fallback. Private attachments remain protected by authenticated authorization.
- If upgrading from an older extracted project, copy the previous `storage/app/private` directory into the new project so existing private attachment files remain available; database records alone do not contain the uploaded file bytes.

## Device / browser push notifications

Pharma Strategies uses standard Web Push for browser and supported installed-PWA mobile notifications. Generate a stable VAPID key pair once per environment:

```powershell
php artisan pharma:push-keys
php artisan optimize:clear
```

Then sign in and choose **Enable device notifications** from the notification panel. Browser permission must be granted by the user. On supported mobile browsers, install the PWA to receive notifications in the device notification centre while the app is not open. Native iOS/Android push would require a separate native application layer; this project provides web/PWA push.

Keep `VAPID_PRIVATE_KEY` secret and do not rotate the VAPID key pair casually because existing subscriptions are tied to the application server key.
