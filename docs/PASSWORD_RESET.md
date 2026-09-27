# Password recovery

Open `auth/forgot-password.php` from the login page. Active accounts receive a single-use link valid for 30 minutes. Responses do not disclose whether an email is registered. Requesting a new link invalidates the previous one. Successful reset clears login lockout and revokes prior sessions; users sign in again.

## Deployment

- Apply `database/migration_password_reset_requests.sql` (already applied on the current server). Existing users require `password_reset_token`, `password_reset_expires` and `password_changed_at` columns.
- Configure and enable SMTP under System Settings, Notifications. Existing encrypted SMTP credentials are reused. TLS is mandatory (implicit on port 465, STARTTLS on other ports).
- Set `PASSWORD_RESET_BASE_URL` in `config/.env` when deploying to another domain; it must be the trusted HTTPS application root. Default: `https://sam2026.upnm.edu.my/e-sports`. Request Host headers are never used in email links.
- Three requests per email and ten per source IP per hour. Only hashes are retained for rate limiting; entries older than one day are pruned on requests.
- Reset tokens are stored as SHA-256 hashes. CSRF checks protect both forms. Passwords follow the system password policy and are hashed using PHP's password hashing API.
- Delivery errors produce a generic browser response and a `[password-reset]` log entry without credentials or reset tokens. Users should contact the secretariat if no email arrives.

## Verification

Run `php tests/password_reset_test.php`. It uses connection-local temporary tables and a fake sender, so no production accounts are modified and no email is sent. Covers unknown/inactive accounts, hashing, replacement, expiry, replay, password policy, mail failure and email/IP throttling.

SMTP connection/TLS and live public page responses have been checked. Actual mailbox delivery still needs a reset request using an account whose mailbox you control.
