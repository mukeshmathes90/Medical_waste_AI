# Testing Checklist

How to verify the Smart Medical-Waste System. Items marked **[auto]** are covered
by the bundled automated tests; the rest are manual UI checks.

## 0. Preparation

```bat
REM Start the app
start.bat

REM Environment check
scripts\setup_check.bat

REM Backend self-test (temporary DB, does not touch your data)
set MWS_DB=%TEMP%\mws_selftest.sqlite
runtime\php\php.exe -c runtime\php\php.ini scripts\selftest.php
```

Expected: self-test prints **PASSED: 32  FAILED: 0**.

---

## 1. Setup & authentication

- [ ] **[auto]** First run redirects to `setup.php`; creating the admin works.
- [ ] **[auto]** Login rejects a wrong password and shows an error.
- [ ] **[auto]** Login accepts correct credentials and redirects to the dashboard.
- [ ] **[auto]** Session holds the user's role after login.
- [ ] **[auto]** `has_role()` returns the correct results.
- [ ] Idle beyond the session timeout forces a re-login.
- [ ] Logout clears the session and returns to `login.php`.
- [ ] Repeated wrong passwords trigger login throttling after 5 attempts.
- [ ] **[auto]** Passwords are stored hashed (never plain text).

## 2. Roles & access control

- [ ] **[auto]** Staff cannot open `admin/*` pages (HTTP 403).
- [ ] **[auto]** Each admin page loads (dashboard, users, categories, containers,
      RFID tags, requests, reports, audit, hardware, settings).
- [ ] **[auto]** Each staff page loads (dashboard, waste entry, records,
      generate QR, scan QR, requests).
- [ ] **[auto]** Each collector page loads (dashboard, requests, scan QR,
      qr verify, verify RFID, history).
- [ ] A collector cannot change admin/user records (only their own statuses).

## 3. Waste classification **[auto]** *(rule engine)*

- [ ] Sharps placed in a general container is **rejected** with a reason.
- [ ] Sharps placed in a sharps container is **accepted**.
- [ ] Infectious waste in a general container is **rejected**.
- [ ] Quantity/unit validation rejects nonsensical values (0, negative, huge).
- [ ] Duplicate container code is rejected by the schema (UNIQUE).

## 4. Waste entry & records (manual UI)

- [ ] Record a waste entry end-to-end; the record code is unique (`WR-…`).
- [ ] The record appears in **Waste Records** with the correct colour badge.
- [ ] Filtering/searching records works.
- [ ] Editing/soft-deleting a record behaves as expected.

## 5. QR codes & scanning

- [ ] Generate a QR for a container; the PNG/data renders correctly.
- [ ] Scan a container QR with the camera (needs `localhost` or HTTPS) and it
      resolves to the right container.
- [ ] `verify.php` shows correct details for a valid QR token and a friendly
      error for an invalid one.

## 6. Collection workflow **[auto]**

- [ ] Creating a request on a record with an open request is blocked.
- [ ] Illegal transition `pending → completed` is rejected.
- [ ] `pending → approved → assigned → in_progress` all succeed.
- [ ] `collected` is **blocked** while verification is incomplete.
- [ ] After QR + RFID verification, `collected` succeeds.
- [ ] `completed` finalises the request; history is written.
- [ ] `rejected` / `cancelled` require a reason.

## 7. RFID & device API **[auto]**

- [ ] Unknown tag returns result `not_registered`.
- [ ] Correct tag + request returns `verified` and sets the `rfid_verified` flag.
- [ ] Tag mapped to a different container returns `mismatch`.
- [ ] A collector not assigned to the request is `denied`.
- [ ] `POST /api/rfid_verify.php` with a bad token returns HTTP 401.
- [ ] `POST /api/device_status.php` with a good token returns `ok: true`.
- [ ] Rate limiting returns HTTP 429 after the per-minute limit.

## 8. Reports & audit

- [ ] **[auto]** CSV export returns `200` with a CSV content type.
- [ ] Each report type renders and the date filter works.
- [ ] The print view is clean (no nav/sidebar).
- [ ] Audit log records logins, changes, exports and denied access.

## 9. Settings

- [ ] General settings (facility name, public URL, session timeout) save.
- [ ] Changing your own password works and the new password is required next login.
- [ ] Toggling QR/RFID verification changes whether collection is gated.

## 10. Hardware (manual / physical)

- [ ] ESP32 connects to Wi-Fi and shows its IP on the OLED.
- [ ] Scanning a registered tag on the OLED shows `VERIFIED`.
- [ ] Scanning an unknown tag shows `NOT REGISTERED`.
- [ ] The `rfid_verification_logs` table receives rows for each scan.
- [ ] See `hardware/wiring_guide.md` troubleshooting table.

## 11. Responsive / cross-browser (manual)

- [ ] Pages are usable on a phone-sized viewport (Bootstrap grid).
- [ ] Layout holds in Chrome, Edge and Firefox.
- [ ] No console errors on the main pages.

---

### Automated test result (this build)

| Suite | Command | Result |
|-------|---------|--------|
| Backend self-test | `scripts\selftest.php` | 32 / 32 pass |
| HTTP end-to-end | session-based script (login, RBAC, all pages, API, CSV) | 30 / 30 pass |
| PHP lint | `php -l` on every `.php` file | 0 errors |

> The ESP32 firmware and physical wiring are **not** automatically testable and
> must be verified on real hardware.
