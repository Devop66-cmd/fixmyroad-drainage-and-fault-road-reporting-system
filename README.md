# FixMyRoad — Kansanga Road and Drainage Fault Reporting System

Diploma project prototype (Kampala International University, SOMAC, October 2026).

**Team:** Nabaale Aisha (2025-01-33934), Chamjowk Obyeny George (2025-01-33757), Ipavu Roy Francis (2025-01-34217)  
**Supervisor:** Mr. Niyonkuru Steven

Plain PHP 8 and MySQL (XAMPP). The research text mentions Laravel; this pilot uses plain PHP so it runs in XAMPP without Composer.

## Roles

| Role | What they can do |
|---|---|
| Community Member | Report faults inside Kansanga, follow tickets, upvote, confirm repairs |
| Local Council | See the whole parish feed, report, and hide or restore comments. Cannot dispatch |
| Field Officer | Update assigned work orders and maintenance, hide or restore comments |
| Supervisor | Assign crews, escalate, bulk-update reports, run the SLA sweep. Cannot manage staff or delete accounts |
| Page Moderator | Publish and archive official notices, hide or restore comments. Cannot dispatch repairs |
| Administrator | Full access, including assigning and removing roles |

Only an administrator can change roles. The primary administrator (`admin@roads.gov.ug`) cannot be deleted or reassigned.

Password for every demo account: `password123`

- Citizen: `alaruroy@gmail.com`
- Local Council: `lc@kansanga.go.ug`
- Field officer: `dmukasa@roads.gov.ug`
- Supervisor: `supervisor@roads.gov.ug`
- Page Moderator: `moderator@roads.gov.ug`
- Administrator: `admin@roads.gov.ug`

Accounts whose password was stored as `!` are repaired to `password123` the next time a page loads. A password you already changed is left alone.

## Setup

See [README_XAMPP.md](README_XAMPP.md). Import `database/fixmyroad.sql`. On the next page load the app adds password-reset, outbox, and comment-moderation tables if they are missing.

Optional environment variables: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.


