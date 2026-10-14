# Medical_waste_AI

# Smart Medical-Waste Collection and Segregation System

A web application for hospitals/clinics to record medical waste, classify it by
category, manage colour-coded containers, generate and scan QR codes, raise and
track collection requests, and verify waste pickups with RFID (ESP32) hardware.

Built with **HTML5, CSS3, JavaScript, Bootstrap 5, PHP 8 and SQLite** — no MySQL
and no XAMPP required. The whole thing runs from a single folder with the PHP
built-in web server.

---

## 1. Features

### Roles
- **Admin** – users, waste categories, containers, RFID tags, collection requests,
  reports (CSV/print), audit log, hardware devices, and system settings.
- **Staff** (ward/nurse) – record waste, view records, generate QR codes, scan
  container QR codes, raise collection requests.
- **Collector** – see assigned work, scan QR, verify RFID, update status,
  view collection history.

### Core capabilities
- Rule-based waste **classification** (colour coding + container compatibility +
  quantity/unit validation). Turned into a clean, auditable rule engine — not a
  black box.
- **Category & container management** with QR tokens.
- **QR generation** (qrcodejs) and **QR scanning** (html5-qrcode, camera based).
- **Collection workflow** with a validated status machine:
  `pending → approved → assigned → in_progress → collected → completed`
  (plus `rejected` / `cancelled`, which require a reason).
- **QR / RFID verification gating** – collection cannot be marked *collected*
  until the required verification passes.
- **RFID REST API** for an ESP32 reader with device authentication, rate limiting
  and an OLED status string.
- **Reports** by type and date range, exportable to CSV and printable.
- **Audit log**, **notifications**, **login throttling**, **CSRF protection** and
  **prepared statements** throughout.

---

## 2. Requirements

| Requirement | Notes |
|-------------|-------|
| Windows 10/11 | `start.bat` and setup scripts are Windows batch files |
| PHP 8.0+ | Either bundled at `runtime\php\php.exe` **or** on your `PATH` |
| PHP extensions | `pdo_sqlite`, `sqlite3` (plus `mbstring`, `openssl`, `curl` recommended) |
| A browser | Chrome/Edge/Firefox for the QR camera scanner |

> A portable PHP runtime is expected at `runtime\php\`. If it is present, you do
> **not** need PHP installed system-wide. If it is missing, run
> `scripts\setup_check.bat` and see section 8 for how to add PHP.

---

## 3. Quick start

1. Double-click **`start.bat`** (or run `start.bat 9000` to force a specific port).
   The default port is **8090**; if it is already busy the launcher automatically
   tries the next free port and tells you which one it used.
2. The launcher:
   - finds PHP (bundled runtime first, then `PATH`),
   - verifies PHP 8 + SQLite,
   - creates the SQLite database on first run,
   - starts the PHP web server (bound to `0.0.0.0` so phones/ESP32 can reach it),
   - opens the app in your browser.
3. On the very first run, the app redirects to **Setup** — create the **admin**
   account.
4. Sign in and start using the system.

To stop the app, close the **"MWS PHP Server"** window.

### Manual start (without the launcher)

```bat
runtime\php\php.exe -c runtime\php\php.ini -S 0.0.0.0:8090 -t .
```

Then open <http://localhost:8090/>.

---

## 4. Sign-in / demo accounts

Demo data seeds two non-admin accounts:

| Role | Username | Password |
|------|----------|----------|
| Staff | `staff` | `Staff@123` |
| Collector | `collector` | `Collector@123` |

The **admin** account is created by you during first-run setup. For automated
setups you can also create it from the CLI:

```bat
runtime\php\php.exe -c runtime\php\php.ini database\init.php --force --admin-user=admin --admin-pass=Admin@123
```

> Change all demo passwords before any real use.

---

## 5. Project structure

```
medical_waste_system/
├── index.php                 # Role-aware landing / redirect
├── login.php  logout.php     # Authentication
├── setup.php                 # First-run admin creation
├── verify.php                # Public QR verification landing
├── start.bat                 # Launcher (find PHP, init DB, run server)
│
├── admin/                    # Admin pages
├── staff/                    # Staff pages
├── collector/                # Collector pages
├── api/                      # JSON endpoints for the ESP32 device
│   ├── rfid_verify.php
│   ├── device_status.php
│   └── classify.php
│
├── config/
│   ├── config.php            # Constants, paths, session hardening
│   └── database.php          # PDO connection + helpers
├── includes/
│   ├── auth.php              # Sessions, RBAC, CSRF, login throttling
│   ├── functions.php         # Framework helpers (e(), redirect(), settings…)
│   ├── classification.php    # Rule-based waste classification
│   ├── collection.php        # Collection request workflow
│   ├── rfid.php              # Device auth + RFID verification
│   ├── qr_scanner.php        # QR payload helpers
│   ├── header.php sidebar.php footer.php error_403.php
├── database/
│   ├── schema.sql            # Full SQLite schema
│   ├── init.php              # Create tables + seed demo data
│   └── medical_waste.sqlite  # The database (created at runtime)
├── assets/                   # Bootstrap, icons, qrcode + html5-qrcode (local)
├── hardware/
│   ├── esp32_rfid_oled.ino   # ESP32 firmware
│   └── wiring_guide.md       # Hardware wiring + configuration
├── runtime/php/              # Bundled portable PHP (optional)
└── scripts/
    ├── setup_check.bat       # Environment diagnostics
    └── selftest.php          # Automated backend self-test
```

---

## 6. How it works

### Waste classification rules
Each category defines a colour, a compatibility list of container types, and a
default unit. When a record is entered the rule engine checks:

1. category ↔ container type compatibility,
2. required colour coding,
3. quantity/unit sanity (positive, sensible range),
4. hazardous flags (e.g. sharps must go to a puncture-proof sharps container).

The result is explained in plain language on screen so it is clear *why* a
record is accepted or rejected.

### Collection status machine
Transitions are validated server-side; illegal jumps are rejected. When
verification is enabled (`require_qr_verification` / `require_rfid_verification`
in Settings), the `collected` transition is blocked until QR and/or RFID checks
succeed.

### RFID device API
`POST /api/rfid_verify.php` with headers `X-Device-Code` and `X-Device-Token`
and body `tag_uid=<UID>&request_no=<REQ>` returns JSON such as:

```json
{ "ok": true, "result": "verified", "oled": "VERIFIED",
  "message": "Tag verified.", "container_code": "MWC-0001" }
```

The `oled` value is what the device shows. Possible results include `VERIFIED`,
`NOT REGISTERED`, `COLLECTION DENIED`, `WRONG CONTAINER`, `AUTH FAILED`.

---

## 7. Hardware (optional)

The web app is fully usable **without** hardware — use the on-screen simulation
in the collector pages. To add the physical reader, see
[`hardware/wiring_guide.md`](hardware/wiring_guide.md) and flash
`hardware/esp32_rfid_oled.ino`.

Steps:
1. In **Admin → Hardware & Devices**, register a device and copy its API token.
2. Find your PC's LAN IP (`ipconfig`) — the app also shows the detected URL.
3. Put the SSID/password, IP, port, device code and token into the sketch.
4. Wire the RC522 (SPI) and SSD1306 OLED (I²C) as per the guide and flash.

> An RFID UID is not cryptographic proof of identity (UIDs can be cloned); the
> server always re-validates the tag, the container and the request state.

---

## 8. Installing PHP (only if `start.bat` says PHP was not found)

1. Download PHP 8 (x64, Non-Thread-Safe is fine) from
   <https://windows.php.net/download>.
2. Extract so that `runtime\php\php.exe` exists **inside this project**.
3. Copy `php.ini-development` to `runtime\php\php.ini` and enable at least:
   ```
   extension=pdo_sqlite
   extension=sqlite3
   extension=mbstring
   ```
   (A ready-made `runtime\php\php.ini` may already be included.)
4. Run `scripts\setup_check.bat` to confirm, then `start.bat`.

Alternatively, add the folder containing `php.exe` to your `PATH` and the
launcher will use it.

---

## 9. Testing

See [`TESTING.md`](TESTING.md) for the full manual test checklist.

Automated checks that ship with the project:

```bat
REM backend self-test (uses a temporary database)
set MWS_DB=%TEMP%\mws_selftest.sqlite
runtime\php\php.exe -c runtime\php\php.ini scripts\selftest.php

REM environment diagnostics
scripts\setup_check.bat
```

The self-test covers classification rules, the collection workflow, RFID
verification, authentication, device auth and password hashing.

---

## 10. Security notes

- Passwords are hashed with PHP `password_hash()` (bcrypt).
- Sessions regenerate on login and expire after an idle timeout.
- All state-changing forms are CSRF-protected.
- All database access uses PDO prepared statements.
- Device tokens are stored **hashed** and can be rotated/revoked in admin.
- Login attempts are throttled; the device API is rate-limited.

---

## 11. Known limitations

- Classification is an **explicit rule engine**, not machine learning — this is
  intentional and auditable.
- The **PHP built-in server** is for development/demonstration. For production,
  use Apache/Nginx with HTTPS (`start.bat` is a convenience, not a web server).
- RFID UID tags are not tamper-proof (see the hardware note).
- The QR camera scanner requires HTTPS or `localhost` for camera access in most
  browsers.
- No email/SMS integration; notifications are in-app.

---

*Smart Medical-Waste System v1.0.0 — PHP 8 + SQLite.*
