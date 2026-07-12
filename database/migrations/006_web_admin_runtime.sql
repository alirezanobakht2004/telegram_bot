ALTER TABLE chats
ADD COLUMN admin_blocked INTEGER NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS idx_chats_admin_blocked
    ON chats (admin_blocked);

CREATE TABLE IF NOT EXISTS runtime_settings (
    setting_key TEXT PRIMARY KEY,
    value_json TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    updated_by TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_runtime_settings_updated_at
    ON runtime_settings (updated_at);

CREATE TABLE IF NOT EXISTS admin_login_attempts (
    identifier TEXT PRIMARY KEY,
    attempts INTEGER NOT NULL DEFAULT 0,
    window_started_at INTEGER NOT NULL,
    blocked_until INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_admin_login_attempts_blocked_until
    ON admin_login_attempts (blocked_until);

CREATE TABLE IF NOT EXISTS admin_audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_identity TEXT NOT NULL,
    action TEXT NOT NULL,
    target TEXT NOT NULL,
    details_json TEXT NOT NULL DEFAULT '{}',
    ip_address TEXT NOT NULL,
    user_agent TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_admin_audit_logs_created_at
    ON admin_audit_logs (created_at);

CREATE INDEX IF NOT EXISTS idx_admin_audit_logs_action
    ON admin_audit_logs (action);
