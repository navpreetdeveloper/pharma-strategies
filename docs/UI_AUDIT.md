# Interface audit

Reviewed on 12 October 2026.

The shared layout supplies a favicon, route-specific descriptions, indexing restrictions for private/account pages, and a footer with the current year. Security screens have distinct titles. Error screens for 403, 404, 419 and 500 provide a return-home action and do not query account navigation while rendering an error.

The existing account menu supports touch, Escape, outside clicks and breakpoint changes. Redundant public sign-in navigation is hidden on the sign-in page. The logo and footer brand link return home. Public support links render only when `SUPPORT_EMAIL` or `SUPPORT_PHONE` is configured; employee directory and privacy-contact email addresses use `mailto:` links. No public contact details have been invented.

Directory tables adapt to cards at tablet widths rather than requiring horizontal scrolling. Long names, email addresses, recovery codes and message text can wrap. Notification panels stay inside the mobile viewport. Form labels remain after input placeholders are removed. Existing inline errors, action success feedback and destructive confirmations remain in use; success feedback also has a no-JavaScript fallback.

Both public PNG icons were compressed losslessly: 1,580 to 1,558 bytes and 4,425 to 4,344 bytes. Their pixel data and dimensions are unchanged. Existing private chat attachments were not altered.

## Verification

`SiteUiAuditTest` renders public, account security, employee, company administration and platform administration screens. It checks metadata, labels, fragment targets, named link destinations, form methods/actions, contact links, copyright year, custom 404 HTML and JSON error behavior. Fixtures contain synthetic accounts and messages, using an isolated test database.

To export synthetic HTML for browser verification:

```powershell
$env:UI_AUDIT_EXPORT='1'
php vendor/phpunit/phpunit/phpunit --filter SiteUiAuditTest
Remove-Item Env:UI_AUDIT_EXPORT
```

Exports and screenshots stay under the ignored `storage/app/private/ui-audit/` directory. Headless Chromium checked 19 page layouts at 320, 375, 768, 1024 and 1440 pixels, plus mobile-menu expansion: 134 checks, no document horizontal overflow. This verifies rendered browser layouts; it does not replace physical iOS/Android keyboard and notification testing.

Local mobile testing uses compiled frontend assets. A generated `public/hot` file pointing at an IPv6-only development server was removed because phones could not reach that asset host. If restarting Vite, use a dev server reachable from the testing device or return to `npm run build` and remove `public/hot`. Production releases must exclude `public/hot`.
