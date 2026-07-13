CREATE TABLE IF NOT EXISTS mini_app_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_hash TEXT NOT NULL UNIQUE,
    user_id INTEGER NOT NULL,
    csrf_hash TEXT NOT NULL,
    init_data_hash TEXT NOT NULL,
    ip_hash TEXT NOT NULL,
    user_agent_hash TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    last_seen_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    absolute_expires_at INTEGER NOT NULL,
    revoked_at INTEGER,
    revocation_reason TEXT,

    FOREIGN KEY (user_id)
        REFERENCES users (telegram_id)
        ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_mini_app_sessions_user
    ON mini_app_sessions (
        user_id,
        revoked_at,
        expires_at DESC
    );

CREATE INDEX IF NOT EXISTS idx_mini_app_sessions_expiry
    ON mini_app_sessions (
        revoked_at,
        expires_at
    );

CREATE TABLE IF NOT EXISTS mini_app_rate_limits (
    key_hash TEXT PRIMARY KEY,
    window_started_at INTEGER NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    expires_at INTEGER NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_mini_app_rate_limits_expiry
    ON mini_app_rate_limits (expires_at);

CREATE TABLE IF NOT EXISTS mini_app_audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    session_id INTEGER,
    action TEXT NOT NULL,
    resource_type TEXT,
    resource_id TEXT,
    success INTEGER NOT NULL DEFAULT 1,
    error_code TEXT,
    details_json TEXT NOT NULL DEFAULT '{}',
    ip_hash TEXT NOT NULL,
    user_agent_hash TEXT NOT NULL,
    occurred_at INTEGER NOT NULL,
    created_at TEXT NOT NULL,

    FOREIGN KEY (user_id)
        REFERENCES users (telegram_id)
        ON DELETE SET NULL,

    FOREIGN KEY (session_id)
        REFERENCES mini_app_sessions (id)
        ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_mini_app_audit_user
    ON mini_app_audit_logs (
        user_id,
        occurred_at DESC
    );

CREATE INDEX IF NOT EXISTS idx_mini_app_audit_action
    ON mini_app_audit_logs (
        action,
        success,
        occurred_at DESC
    );

CREATE INDEX IF NOT EXISTS idx_mini_app_audit_session
    ON mini_app_audit_logs (
        session_id,
        occurred_at DESC
    );

INSERT INTO feature_flags (
    flag_key,
    enabled,
    rollout_percentage,
    description,
    updated_at,
    updated_by
) VALUES (
    'mini_app',
    1,
    100,
    'Authenticated Telegram Mini App dashboard and user self-service API.',
    datetime('now'),
    'migration:014'
)
ON CONFLICT(flag_key) DO UPDATE SET
    enabled = 1,
    rollout_percentage = 100,
    description = excluded.description,
    updated_at = excluded.updated_at,
    updated_by = excluded.updated_by;
