# Security Review Findings

Review date: 2026-11-23

Status: All three findings below have been fixed in the current worktree.

## 1. Hardcoded production administrator credentials

- Severity: HIGH
- Confidence: 9/10
- Location: `database/fixmyroad.sql`, lines 191-193

The seed import creates three publicly documented accounts using `SEED_PLAIN:password123`, including `admin@roads.gov.ug`. If imported into a deployed environment without credential rotation, an unauthenticated visitor can sign in as administrator.

**Resolution:** Seeded accounts now have disabled passwords. The XAMPP setup guide explains how to set a unique administrator password hash before first sign-in.

## 2. Broken access control for resolved tickets

- Severity: MEDIUM
- Confidence: 10/10
- Location: `track_report.php`, lines 22-84

Ticket confirmation, reopening, and feedback actions do not verify ticket ownership or the active 48-hour confirmation window. Any authenticated user can supply a public ticket ID and close, reopen, or rate another user's resolved ticket.

**Resolution:** Mutating repair actions require the signed-in account's email to match the report owner. Confirmation and reopening additionally require an active 48-hour window, checked again in the update query.

## 3. Failed-CSRF redirect uses an untrusted Referer

- Severity: MEDIUM
- Confidence: 8/10
- Location: `includes/helpers.php`, lines 137-141

`verify_csrf()` redirects failed POST submissions to `$_SERVER['HTTP_REFERER']` without validating same-origin or local destination. An attacker-controlled Referer can cause an open redirect after a failed CSRF validation.

**Resolution:** Failed CSRF validation now redirects to the fixed same-site `index.php` route.
