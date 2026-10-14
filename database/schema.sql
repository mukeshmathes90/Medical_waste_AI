-- ===========================================================================
-- Smart Medical-Waste Collection and Segregation System
-- SQLite schema (structure only - seed data is inserted by database/init.php)
-- ===========================================================================

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    username        TEXT    NOT NULL UNIQUE,
    password_hash   TEXT    NOT NULL,
    full_name       TEXT    NOT NULL,
    email           TEXT,
    phone           TEXT,
    role            TEXT    NOT NULL CHECK (role IN ('admin','staff','collector')),
    department      TEXT,
    is_active       INTEGER NOT NULL DEFAULT 1,
    failed_logins   INTEGER NOT NULL DEFAULT 0,
    locked_until    TEXT,
    last_login      TEXT,
    created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_users_role ON users(role);
CREATE INDEX IF NOT EXISTS idx_users_active ON users(is_active);

CREATE TABLE IF NOT EXISTS waste_categories (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    name              TEXT    NOT NULL UNIQUE,
    code              TEXT    NOT NULL UNIQUE,
    description       TEXT,
    handling_guidance TEXT,
    color             TEXT    NOT NULL DEFAULT '#6c757d',
    container_type    TEXT,                      -- expected container type, used by the rule engine
    is_hazardous      INTEGER NOT NULL DEFAULT 0,
    is_active         INTEGER NOT NULL DEFAULT 1,
    created_at        TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS containers (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    container_code TEXT    NOT NULL UNIQUE,
    label          TEXT,
    category_id    INTEGER REFERENCES waste_categories(id) ON DELETE SET NULL,
    department     TEXT,
    location       TEXT,
    capacity       REAL,
    fill_level     REAL,
    status         TEXT    NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive','full','maintenance')),
    qr_token       TEXT    NOT NULL UNIQUE,
    created_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at     TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_containers_category ON containers(category_id);
CREATE INDEX IF NOT EXISTS idx_containers_status ON containers(status);

CREATE TABLE IF NOT EXISTS devices (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    device_code    TEXT    NOT NULL UNIQUE,
    name           TEXT,
    api_token_hash TEXT    NOT NULL,
    user_id        INTEGER REFERENCES users(id) ON DELETE SET NULL, -- optional bound collector
    location       TEXT,
    last_seen      TEXT,
    is_active      INTEGER NOT NULL DEFAULT 1,
    created_at     TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS rfid_tags (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    tag_uid      TEXT    NOT NULL UNIQUE,
    container_id INTEGER NOT NULL REFERENCES containers(id) ON DELETE CASCADE,
    device_id    INTEGER REFERENCES devices(id) ON DELETE SET NULL,
    label        TEXT,
    is_active    INTEGER NOT NULL DEFAULT 1,
    notes        TEXT,
    created_at   TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_rfid_container ON rfid_tags(container_id);

CREATE TABLE IF NOT EXISTS waste_records (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    record_code  TEXT    NOT NULL UNIQUE,
    category_id  INTEGER NOT NULL REFERENCES waste_categories(id),
    container_id INTEGER REFERENCES containers(id) ON DELETE SET NULL,
    quantity     REAL    NOT NULL CHECK (quantity > 0),
    unit         TEXT    NOT NULL DEFAULT 'kg',
    department   TEXT,
    location     TEXT,
    priority     TEXT    NOT NULL DEFAULT 'normal' CHECK (priority IN ('low','normal','high','urgent')),
    status       TEXT    NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','requested','collected','disposed','cancelled')),
    notes        TEXT,
    created_by   INTEGER NOT NULL REFERENCES users(id),
    created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at   TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_waste_category ON waste_records(category_id);
CREATE INDEX IF NOT EXISTS idx_waste_container ON waste_records(container_id);
CREATE INDEX IF NOT EXISTS idx_waste_status ON waste_records(status);
CREATE INDEX IF NOT EXISTS idx_waste_created ON waste_records(created_at);

CREATE TABLE IF NOT EXISTS collection_requests (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    request_no          TEXT    NOT NULL UNIQUE,
    waste_record_id     INTEGER REFERENCES waste_records(id) ON DELETE SET NULL,
    container_id        INTEGER REFERENCES containers(id) ON DELETE SET NULL,
    requested_by        INTEGER NOT NULL REFERENCES users(id),
    assigned_to         INTEGER REFERENCES users(id) ON DELETE SET NULL,
    department          TEXT,
    location            TEXT,
    quantity            REAL,
    unit                TEXT,
    priority            TEXT    NOT NULL DEFAULT 'normal' CHECK (priority IN ('low','normal','high','urgent')),
    status              TEXT    NOT NULL DEFAULT 'pending'
                        CHECK (status IN ('pending','approved','assigned','in_progress','collected','completed','cancelled','rejected')),
    verification_required INTEGER NOT NULL DEFAULT 1,
    qr_verified         INTEGER NOT NULL DEFAULT 0,
    rfid_verified       INTEGER NOT NULL DEFAULT 0,
    created_at          TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at          TEXT    NOT NULL DEFAULT (datetime('now')),
    collected_at        TEXT,
    completed_at        TEXT,
    completion_notes    TEXT
);
CREATE INDEX IF NOT EXISTS idx_req_status ON collection_requests(status);
CREATE INDEX IF NOT EXISTS idx_req_assigned ON collection_requests(assigned_to);
CREATE INDEX IF NOT EXISTS idx_req_container ON collection_requests(container_id);
CREATE INDEX IF NOT EXISTS idx_req_created ON collection_requests(created_at);

CREATE TABLE IF NOT EXISTS collection_status_history (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id  INTEGER NOT NULL REFERENCES collection_requests(id) ON DELETE CASCADE,
    old_status  TEXT,
    new_status  TEXT NOT NULL,
    changed_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
    remarks     TEXT,
    created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_hist_request ON collection_status_history(request_id);

CREATE TABLE IF NOT EXISTS rfid_verification_logs (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    device_id    INTEGER REFERENCES devices(id) ON DELETE SET NULL,
    container_id INTEGER REFERENCES containers(id) ON DELETE SET NULL,
    tag_uid      TEXT,
    request_id   INTEGER REFERENCES collection_requests(id) ON DELETE SET NULL,
    collector_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    result       TEXT NOT NULL,          -- verified | not_registered | denied | mismatch | inactive
    message      TEXT,
    ip_address   TEXT,
    created_at   TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_rfidlog_result ON rfid_verification_logs(result);
CREATE INDEX IF NOT EXISTS idx_rfidlog_created ON rfid_verification_logs(created_at);

CREATE TABLE IF NOT EXISTS audit_logs (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
    action     TEXT NOT NULL,
    entity     TEXT,
    entity_id  INTEGER,
    details    TEXT,
    ip_address TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs(created_at);
CREATE INDEX IF NOT EXISTS idx_audit_action ON audit_logs(action);

CREATE TABLE IF NOT EXISTS notifications (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER REFERENCES users(id) ON DELETE CASCADE,
    title      TEXT NOT NULL,
    message    TEXT,
    is_read    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_notif_user ON notifications(user_id, is_read);

CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT
);

CREATE TABLE IF NOT EXISTS login_attempts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    username   TEXT,
    ip_address TEXT,
    success    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_login_attempts ON login_attempts(username, created_at);

CREATE TABLE IF NOT EXISTS api_rate (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    bucket     TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_api_rate ON api_rate(bucket, created_at);
