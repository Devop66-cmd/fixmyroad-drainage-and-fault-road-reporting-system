# FixMyRoad — Native PHP & MySQL (XAMPP Edition)

A complete, standalone **PHP 8 + MySQL (PDO) + Simple CSS** edition of **FixMyRoad (Kansanga, Makindye Division Pilot)** packaged for local deployment on **XAMPP** for final-year project submission and defense.

---

## 1. Quick Setup on XAMPP (Windows / macOS / Linux)

1. **Copy the folder into Apache `htdocs`**:
   - Copy this entire `fixmyroad-php` directory into `C:\xampp\htdocs\fixmyroad` (Windows) or `/Applications/XAMPP/htdocs/fixmyroad` (macOS).
   - *(All links and assets are relative, so any folder name inside `htdocs` works automatically.)*

2. **Start Apache & MySQL**:
   - Open the **XAMPP Control Panel**.
   - Click **Start** next to **Apache** and **Start** next to **MySQL**.

3. **Import the MySQL Database (`fixmyroad_db`)**:
   - Open `http://localhost/phpmyadmin` in your browser.
   - Click the **Import** tab at the top.
   - Click **Choose File** and select `database/fixmyroad.sql` inside your copied folder.
   - Click **Import** (or **Go**).
   - This creates the `fixmyroad_db` database with 11 normalized InnoDB tables and realistic Kansanga seed data.

4. **Launch the Application**:
   - Open `http://localhost/fixmyroad/` in your browser.

---

## 2. How Beginners Can Edit Colors, Fonts & Layout (`css/style.css`)

All styling lives in a single, cleanly commented stylesheet: **`css/style.css`** (no build tools or external CSS frameworks required, so the UI also works **100% offline** during your school defense).

Open `css/style.css` in VS Code / Notepad++ and change the variables at the top:

```css
:root {
  --primary: #2563eb;   /* Change main button & active link color */
  --dark: #0f172a;      /* Change sidebar, hero banner & footer color */
  --bg-page: #f1f5f9;   /* Change page background color */
  --bg-card: #ffffff;   /* Change white card background */
  --radius-lg: 16px;    /* Change rounded corner size */
}
```

Common sections inside `css/style.css` are clearly labeled:
- **Top Header & Nav**: `.site-header`, `.main-nav`, `.nav-link`
- **Official Notice Top Banner**: `.notice-banner--emergency`, `.notice-banner--maintenance`, `.notice-banner--general`
- **Sidebar & Dashboard Cards**: `.sidebar`, `.card`, `.kpi-card`
- **Buttons & Badges**: `.btn-primary`, `.btn-success`, `.btn-danger`, `.badge-pending`, `.badge-resolved`
- **Tables & Forms**: `.data-table`, `.form-control`, `.filter-bar`

---

## 3. Account Setup

The sample accounts in the SQL seed data have disabled passwords; there are no shared demo credentials. On startup, the app also disables this old shared password in databases imported from earlier versions. Residents can create accounts through `register.php`.

To enable the seeded administrator for a local demonstration:

1. Generate a password hash with PHP CLI: `php -r "echo password_hash('REPLACE_WITH_A_UNIQUE_PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"`.
2. In phpMyAdmin, select `fixmyroad_db` and open the **SQL** tab.
3. Set the password hash for the seeded administrator by replacing the placeholder with the generated hash:

   ```sql
   UPDATE users
   SET password_hash = 'PASTE_THE_GENERATED_HASH_HERE'
   WHERE id = 'usr-3' AND email = 'admin@roads.gov.ug';
   ```

Use a unique password and do not reuse it on a live deployment. Create field-officer demo accounts from the admin dashboard after signing in.

---

## 4. Clean Folder Structure

```text
fixmyroad-php/
├── api/
│   └── poll_updates.php      # Lightweight AJAX JSON endpoint for live alerts/notices
├── config/
│   └── db.php                # PDO MySQL connection (localhost, fixmyroad_db, root)
├── css/
│   └── style.css             # Simple, beginner-friendly CSS with :root theme variables
├── database/
│   └── fixmyroad.sql         # 11-table MySQL schema + Kansanga pilot seed data
├── includes/
│   ├── auth.php              # Session management, role guards & bcrypt upgrade
│   ├── footer.php            # Shared footer (reads live hotline settings) + JS/Leaflet
│   ├── header.php            # Shared nav + Dynamic Official Notice top banner
│   └── helpers.php           # CSRF tokens, Kansanga geofence, SLA engine, Hero points
├── js/
│   └── app.js                # Simple JS for demo login fill & notice live preview
├── uploads/                  # Stored citizen Before & Field Officer After evidence photos
├── admin_dashboard.php       # Admin Control Center (Notices, Dispatch, SLA, Audit CSV)
├── dashboard.php             # Citizen Dashboard, Official Notices category filter, Feed
├── field_officer.php         # Field Officer Work Orders & Preventive Maintenance logging
├── index.php                 # Public landing page with live Kansanga KPIs
├── login.php                 # Sign In
├── logout.php                # Clean session teardown
├── map_alerts.php            # Interactive Leaflet map of Kansanga faults + Alert inbox
├── profile.php               # Profile editor, password change & Hero badge breakdown
├── public_data.php           # Open Transparency Ledger, Hero Leaderboard & CSV export
├── register.php              # Citizen registration (Kansanga zones)
├── report_fault.php          # Fault report submission + Kansanga geofence + photo upload
└── track_report.php          # Ticket detail, Before/After photos, 48h Confirm Fix, Q&A, Rating
```

---

## 5. Troubleshooting

- **MySQL runs on a custom port (e.g., `3307`) or has a `root` password**:
  Edit `config/db.php` (`$host`, `$port`, `$user`, `$pass`) to match your XAMPP MySQL settings.
- **Photo uploads on Linux/macOS**:
  Ensure the `uploads/` folder is writable (`chmod 775 uploads/`).
- **Offline Presentation Note**:
  All styles (`css/style.css`) and core interactions (`js/app.js`) work **100% offline**. Only the interactive Leaflet OpenStreetMap background tiles require internet; even offline, the Kansanga hotspot buttons on `report_fault.php` still populate GPS coordinates cleanly.
