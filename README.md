# FixMyRoad — Kansanga Road and Drainage Fault Reporting System

Diploma project prototype (Kampala International University, SOMAC, October 2026).

**Team:** Nabaale Aisha (2025-01-33934), Chamjowk Obyeny George (2025-01-33757), Ipavu Roy Francis (2025-01-34217)  
**Supervisor:** Mr. Niyonkuru Steven

Plain PHP 8 and MySQL (XAMPP). The research text mentions Laravel; this pilot uses plain PHP so it runs in XAMPP without Composer.

## Roles

| Role | What they can do |
|---|---|
| Community Member | Report faults inside Kansanga, follow tickets, upvote, confirm repairs |
| Field Officer | Update assigned work orders and maintenance, hide comments |
| Supervisor | Assign crews, escalate, bulk-update reports, run the SLA sweep. Cannot manage staff or delete accounts |
| Page Moderator | Publish and archive official notices, hide comments. Cannot dispatch repairs |
| Administrator | Full access, including assigning and removing roles |

Only an administrator can change roles. The primary administrator (`admin@roads.gov.ug`) cannot be deleted or reassigned.

Extra demo accounts (created on first page load if missing), password `password123`:

- Supervisor: `supervisor@roads.gov.ug`
- Page Moderator: `moderator@roads.gov.ug`

## Demo accounts

Password for all three: `password123`

- Citizen: `alaruroy@gmail.com`
- Field officer: `dmukasa@roads.gov.ug`
- Administrator: `admin@roads.gov.ug`

## Setup

See [README_XAMPP.md](README_XAMPP.md). Import `database/fixmyroad.sql`. On the next page load the app adds password-reset, outbox, and comment-moderation tables if they are missing.

Optional environment variables: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.


