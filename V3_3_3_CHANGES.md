# Pharma Strategies v3.3.3

## Profile and navigation
- Added a dedicated authenticated profile page.
- The signed-in user's header identity now links directly to the profile.
- Replaced the admin dashboard's Privacy & Security shortcut with My Profile.
- Added compact back buttons to admin/profile pages with same-origin browser-history behaviour and safe fallbacks.
- Added mobile-friendly profile identity avatar.

## Push notification fix
- Fixed device push subscription persistence by storing the required endpoint together with its hash and encrypted subscription payload.
- Improved API error details for subscription failures without exposing secrets.
- Added clearer handling when browser notification permission is blocked.
- Removed disruptive browser alert behaviour from the push UI.

## Chat UX
- Chronological insertion remains based on server timestamp and message ID.
- Late-arriving older messages no longer force the user to the bottom unless they were already near the bottom.
- Message-send errors are rendered inside the chat UI instead of browser alerts.

## Validation
- PHP syntax checked.
- JavaScript syntax checked.
- Added a feature test covering push-subscription endpoint persistence.
