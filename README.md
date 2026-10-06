# Hotel-Booking-System
# 🏨 Aurelia Grand HMS — PHP Backend (XAMPP)

Pure PHP + MySQL backend. Same approach as the reference project.
No Node.js needed. Runs entirely inside XAMPP.
Requires PHP 7.4+ with MySQLi/mysqlnd enabled.

---

## Setup (5 steps)

### 1. Start XAMPP
Open XAMPP Control Panel → Start **Apache** + **MySQL**

### 2. Put the folder in htdocs
Copy this entire folder to:
```
C:\xampp\htdocs\aurelia-grand\
```

### 3. Import the database
- Go to **http://localhost/phpmyadmin**
- Click **New** → name: `aurelia_grand` → collation: `utf8mb4_unicode_ci` → Create
- Click `aurelia_grand` in the sidebar → click **Import** tab
- Choose `schema.sql` → click **Import**

### 4. Configure credentials and create the first admin
For local XAMPP only, the database defaults to `root` with no password. For deployment, configure `AURELIA_DB_HOST`, `AURELIA_DB_PORT`, `AURELIA_DB_NAME`, `AURELIA_DB_USER`, `AURELIA_DB_PASSWORD`, and `APP_ENV=production` in the Apache/PHP environment. Production refuses the root/blank-password defaults.

From a terminal in the project folder, run `php generate_hash.php` and enter the admin details when prompted. This utility is CLI-only and returns 404 through Apache; remove it from the web root after setup.

Password reset email delivery requires `MAIL_FROM` and `PASSWORD_RESET_BASE_URL` (an HTTPS URL ending in `index.html`) plus a configured PHP mail transport. Without those settings, the UI reports that reset email is unavailable.

### 5. Open the site
**http://localhost/aurelia-grand/index.html**

---

## File Map

```
aurelia-grand/
├── index.html              ← Frontend (your original, now wired to PHP)
├── schema.sql              ← Run once in phpMyAdmin
├── generate_hash.php       ← CLI-only first-admin setup utility
│
├── includes/
│   └── database.php        ← MySQL connection (edit password here if needed)
│
├── login.php               ← POST: email, password → JSON
├── register.php            ← POST: first_name, last_name, email, password, role → JSON
├── logout.php              ← POST + CSRF → destroys session
├── get_session.php         ← GET → returns current logged-in user
├── request_password_reset.php ← POST → requests a one-time reset email
├── reset_password.php      ← POST → consumes a one-time reset token
│
├── get_rooms.php           ← GET ?status=available → room list JSON
├── process_booking.php     ← POST: room_id, check_in, check_out → JSON
├── get_bookings.php        ← GET → bookings for current user (or all if admin/staff)
├── update_booking_status.php ← POST: booking_id, status → JSON
│
├── room_service.php        ← POST action=place/get/update_status → JSON
├── cleaning.php            ← POST action=request/get/update → JSON
├── reviews.php             ← POST/GET action=get/submit → JSON
├── invoices.php            ← GET action=get; POST action=mark_paid → JSON
│
├── chat_api.php            ← Guest chat (send_message / fetch_messages)
├── admin_chat_handler.php  ← Admin chat (get_inbox / fetch_conversation / send_reply)
│
└── admin/
    ├── dashboard.php       ← GET → stats JSON (admin only)
    ├── staff.php           ← GET/POST action=get/add/toggle_duty/deactivate
    └── tasks.php           ← GET/POST action=get/add/update_status/delete
```

---

There are no seeded or shared default credentials. Create the initial admin using the CLI setup above. Guest accounts are created through registration; staff accounts are created by an administrator.

---

## How the Frontend Connects

The `index.html` uses `fetch()` to call PHP files. Same-origin POST requests include a session CSRF token automatically:

```javascript
// Login example
const fd = new FormData();
fd.append('email', email);
fd.append('password', password);
const res  = await fetch('login.php', { method: 'POST', body: fd });
const data = await res.json();
// data.success, data.user.name, data.user.role

// Get rooms example
const res  = await fetch('get_rooms.php?status=available');
const data = await res.json();
// data.data → array of room objects

// Book a room
const fd = new FormData();
fd.append('room_id', 3);
fd.append('check_in', '2026-07-10');
fd.append('check_out', '2026-07-14');
const res  = await fetch('process_booking.php', { method: 'POST', body: fd });
const data = await res.json();
// data.booking_ref, data.total, data.nights
```

---

## Troubleshooting

| Problem | Fix |
|---|---|
| Blank page / no JSON | Check Apache & MySQL are running in XAMPP |
| `Connection failed` | Check the `AURELIA_DB_*` settings or use the local XAMPP defaults |
| Login says invalid | Verify the account exists and use the password set at account creation |
| Booking fails | Make sure you're logged in first |
| Password reset unavailable | Configure `MAIL_FROM`, `PASSWORD_RESET_BASE_URL`, and the PHP mail transport |
