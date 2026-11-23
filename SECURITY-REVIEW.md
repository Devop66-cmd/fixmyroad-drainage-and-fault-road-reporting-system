# Security Review Findings

Review date: 2026-11-23

## 1. Hardcoded production administrator credentials

- Severity: HIGH
- Confidence: 9/10
- Location: `database/fixmyroad.sql`, lines 186-192

The seed import creates three publicly documented accounts using `SEED_PLAIN:password123`, including `admin@roads.gov.ug`. If imported into a deployed environment without credential rotation, an unauthenticated visitor can sign in as administrator.

**Remediation:** Remove seed accounts and hardcoded demo passwords from production imports. Provision administrator accounts interactively, require unique passwords, and rotate credentials if this schema has already been deployed.

## 2. Broken access control for resolved tickets

- Severity: MEDIUM
- Confidence: 10/10
- Location: `track_report.php`, lines 21-70

Ticket confirmation, reopening, and feedback actions do not verify ticket ownership or the active 48-hour confirmation window. Any authenticated user can supply a public ticket ID and close, reopen, or rate another user's resolved ticket.

**Remediation:** Require the authenticated citizen to match `reported_by_email` or `reported_by`, verify the ticket is resolved and awaiting confirmation, and verify `confirmation_expires_at` is in the future. Enforce the checks in the POST handler and database transaction.

## 3. Failed-CSRF redirect uses an untrusted Referer

- Severity: MEDIUM
- Confidence: 8/10
- Location: `includes/helpers.php`, lines 139-145

`verify_csrf()` redirects failed POST submissions to `$_SERVER['HTTP_REFERER']` without validating same-origin or local destination. An attacker-controlled Referer can cause an open redirect after a failed CSRF validation.

**Remediation:** Redirect failed CSRF submissions to a fixed same-site route such as `index.php` or `dashboard.php`, or return HTTP 403.
