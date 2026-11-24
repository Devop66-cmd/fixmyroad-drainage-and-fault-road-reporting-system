# FixMyRoad — Kansanga Road and Drainage Fault Reporting System

Diploma project prototype (Kampala International University, SOMAC, October 2026).

**Team:** Nabaale Aisha (2025-01-33934), Chamjowk Obyeny George (2025-01-33757), Ipavu Roy Francis (2025-01-34217)  
**Supervisor:** Mr. Niyonkuru Steven

Plain PHP 8 and MySQL (XAMPP). The research text mentions Laravel; this pilot uses plain PHP so it runs in XAMPP without Composer.

## Roles

| Privilege | Community Member | Field Officer | Administrator |
|---|---|---|---|
| Report faults, own tickets, upvotes, 48-hour confirmation | Yes | Report and view | Yes |
| Update work orders and maintenance | No | Yes | Yes |
| Hide comments | No | Yes | Yes |
| Dispatch, notices, SLA, audit, bulk update | No | No | Yes |
| Assign or remove roles | No | No | Yes |

The primary administrator (`admin@roads.gov.ug`) cannot be deleted or reassigned.

## Demo accounts

Password for all three: `password123`

- Citizen: `alaruroy@gmail.com`
- Field officer: `dmukasa@roads.gov.ug`
- Administrator: `admin@roads.gov.ug`

## Setup

See [README_XAMPP.md](README_XAMPP.md). Import `database/fixmyroad.sql`. On the next page load the app adds password-reset, outbox, and comment-moderation tables if they are missing.

Optional environment variables: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.

## Extra features (beyond the research minimum)

- Password reset (`forgot.php`). On XAMPP the link is shown on screen and queued as email. There is no live SMTP server.
- Email and SMS outbox. Turn them on under Admin, Support. Messages are stored in `outbound_messages`. They are not sent to a gateway.
- Login lock after 5 failures (10 minutes) and a registration cap per browser session.
- Photo resize to 1600px JPEG when PHP GD is available.
- `uploads/.htaccess` blocks PHP execution.
- Comment hide for officers and administrators.
- Bulk status or officer update on the reports tab.
- SLA sweep every 6 hours on page load, manual button, or `php scripts/sla_sweep.php`.
- English / Luganda toggle for the main navigation.
- Role checks: `php tests/privileges_test.php`.

## Limitations

No live email or SMS provider, no native app, Kansanga geofence only, English plus a small Luganda navigation set, and no automated usability study inside the software.
