# Workspace API v1

Base path: `/api/v1`. This is a **same-origin, session-authenticated browser API**, not a public integration or bearer-token API. It uses the existing login and MFA flow, active-account checks, temporary-password policy and company middleware. It accepts/returns JSON and authenticated responses remain private/non-cacheable. `config/cors.php` does not grant access to external browser origins. Existing web action URLs continue to work.

For mutations, send the session cookie and `X-CSRF-TOKEN` from the page's CSRF meta element. File uploads use multipart form data with the browser-generated boundary. Never disable CSRF to support a client. A future native app or third-party integration should receive a separately designed token authentication/scopes contract rather than reusing administrator passwords or loosening these routes.

## Routes

| Method | Path | Input and behavior |
| --- | --- | --- |
| GET | `/me` | Safe current-account fields under `data`; no hashes or authenticator secrets |
| GET | `/conversations` | Optional positive `page`; up to 50 conversations with existing messages, authorized visibility, unread count, `links` and `meta` |
| GET | `/conversations/{id}/messages` | Required integer `after >= 0`; up to 100 newer messages, ascending ID, under `messages`, plus `cursor` and `has_more`; optional bounded `visible[]` (100 IDs) returns `reconciled` records and `removed` IDs |
| GET | `/conversations/{id}/history` | Required positive message cursor `before`; up to 50 older messages and `has_more` |
| POST | `/conversations/{id}/messages` | Optional `body` up to 10,000 characters, `reply_to_message_id`, `attachments[]`, `client_message_id` (UUID); body or file required; returns `ok` and shared `message` payload |
| POST | `/conversations/{id}/read` | Required positive `last_message_id` belonging to this conversation; participant-only, monotonic acknowledgment; returns `ok` |
| GET | `/presence` | Required `ids[]` of up to 100 positive user IDs; only active coworkers returned |

`/api/me` remains available as a legacy flat account response. New consumers should use `/api/v1/me`.

Example account response:

```json
{"data":{"id":42,"name":"Employee","email":"employee@example.com","status":"active","must_change_password":false}}
```

A conversation record exposes `id`, `type`, `title`, `subtitle`, `unread_count` and `latest_message_at`. List records do not expose message bodies. Message responses use `MessagePayload`, including author, timestamps, reply/forward references and authorized attachment URLs. Deleted account authors are labelled `Deleted account`; unsent messages have no body or attachments.

Listing/catching up messages does not acknowledge them. A client must submit the highest message ID actually opened/seen, rather than marking unseen messages read. Empty drafts stay out of conversation lists. Administrators reviewing conversations still follow the company's privacy policy and review auditing; review permission does not grant participant write/read-acknowledgment permission.

Recovery must advance from the server `cursor`, not the maximum ID delivered over a socket. Drain `has_more` pages before treating catch-up as complete. Reconcile `visible[]` in bounded batches to repair missed edits, unsends and pruned rows; revisions let clients keep unchanged DOM/media elements. Authorized attachment URLs remain protected routes.

## Errors and limits

| Status | Meaning |
| --- | --- |
| 401 | No valid login or additional MFA challenge required |
| 403 | Access revoked, account policy blocked, or conversation/company permission missing |
| 404 | Resource or requested cursor is absent/unavailable |
| 409 | Submission UUID was reused with different text, reply or files |
| 419 | Missing/expired CSRF token; renew the page/session |
| 422 | Invalid input; `errors` maps fields to validation messages |
| 429 | Request limit reached; respect the retry headers |
| 500+ | Generic server failure; report internally and allow an explicit retry |

API requests are limited to 120 per minute per authenticated actor/IP; message sends also have a 30-per-minute limit. Platform account recovery/deletion uses separate platform-only web routes with administrator verification and a six-per-minute limit. Do not automatically retry arbitrary mutations: a lost response can occur after a successful commit. For message sends, retain the same `client_message_id` and identical content/files when explicitly retrying an uncertain result. The server returns the existing saved message; a new intentional message needs a new UUID. UUID omission remains compatible with older clients but does not protect their sends from duplication.

Validation response:

```json
{"message":"The after field must be an integer.","errors":{"after":["The after field must be an integer."]}}
```

Unexpected error response:

```json
{"message":"Something went wrong. Please try again.","code":"server_error"}
```

## Extension rules

Keep additions under `Api/V1`, version route names as `api.v1.*`, and use explicit resources for new response contracts. Reuse feature services and tenant-scoped queries rather than duplicating HTML controller business rules. Existing chat actions are shared adapters so permissions and delivery behavior cannot diverge between web and JSON paths. Breaking contracts belong in a later version; keep endpoint page sizes bounded.

Laravel references: [routing and middleware](https://laravel.com/docs/12.x/routing), [exception handling](https://laravel.com/docs/12.x/errors).
