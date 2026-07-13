CREATE TABLE IF NOT EXISTS smart_alerts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    chat_id INTEGER NOT NULL,
    alert_type TEXT NOT NULL
        CHECK (
            alert_type IN (
                'weather_condition',
                'temperature',
                'wind',
                'currency'
            )
        ),
    subject TEXT NOT NULL,
    secondary_subject TEXT,
    operator TEXT NOT NULL
        CHECK (
            operator IN (
                'above',
                'below',
                'equals',
                'changes',
                'contains',
                'starts',
                'stops'
            )
        ),
    comparison_value TEXT,
    threshold_value REAL,
    cooldown_seconds INTEGER NOT NULL DEFAULT 3600,
    hysteresis REAL NOT NULL DEFAULT 0,
    max_notifications_per_day INTEGER NOT NULL DEFAULT 3,
    check_interval_seconds INTEGER NOT NULL DEFAULT 300,
    next_check_at INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'active'
        CHECK (status IN ('active', 'paused', 'cancelled')),
    last_observed_value TEXT,
    last_condition_state INTEGER,
    last_triggered_value TEXT,
    last_triggered_at INTEGER,
    last_checked_at INTEGER,
    failure_count INTEGER NOT NULL DEFAULT 0,
    last_error TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id)
        REFERENCES users (telegram_id)
        ON DELETE CASCADE,
    FOREIGN KEY (chat_id)
        REFERENCES chats (telegram_id)
        ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_smart_alerts_due
    ON smart_alerts (status, next_check_at, id);

CREATE INDEX IF NOT EXISTS idx_smart_alerts_user
    ON smart_alerts (user_id, status, id DESC);

CREATE TABLE IF NOT EXISTS alert_notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    alert_id INTEGER NOT NULL,
    dedup_key TEXT NOT NULL UNIQUE,
    observed_value TEXT,
    date_key TEXT NOT NULL,
    sent_at INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (alert_id)
        REFERENCES smart_alerts (id)
        ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_alert_notifications_daily
    ON alert_notifications (alert_id, date_key, sent_at);

CREATE TABLE IF NOT EXISTS smart_subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    chat_id INTEGER NOT NULL,
    subscription_type TEXT NOT NULL
        CHECK (subscription_type IN ('weather', 'country')),
    subject TEXT NOT NULL,
    frequency TEXT NOT NULL
        CHECK (frequency IN ('daily', 'weekly', 'monthly')),
    schedule_time TEXT NOT NULL,
    weekday INTEGER,
    month_day INTEGER,
    timezone TEXT NOT NULL,
    next_run_at INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'active'
        CHECK (status IN ('active', 'paused', 'cancelled')),
    last_run_at INTEGER,
    failure_count INTEGER NOT NULL DEFAULT 0,
    last_error TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id)
        REFERENCES users (telegram_id)
        ON DELETE CASCADE,
    FOREIGN KEY (chat_id)
        REFERENCES chats (telegram_id)
        ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_smart_subscriptions_due
    ON smart_subscriptions (status, next_run_at, id);

CREATE INDEX IF NOT EXISTS idx_smart_subscriptions_user
    ON smart_subscriptions (user_id, status, id DESC);

CREATE TABLE IF NOT EXISTS site_monitors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    chat_id INTEGER NOT NULL,
    url TEXT NOT NULL,
    normalized_url TEXT NOT NULL,
    interval_seconds INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'active'
        CHECK (status IN ('active', 'paused', 'cancelled')),
    next_check_at INTEGER NOT NULL,
    last_state TEXT NOT NULL DEFAULT 'unknown'
        CHECK (last_state IN ('unknown', 'up', 'down')),
    last_status_code INTEGER,
    last_response_ms REAL,
    consecutive_failures INTEGER NOT NULL DEFAULT 0,
    consecutive_successes INTEGER NOT NULL DEFAULT 0,
    last_error TEXT,
    last_checked_at INTEGER,
    last_changed_at INTEGER,
    last_notified_at INTEGER,
    daily_report_enabled INTEGER NOT NULL DEFAULT 0,
    daily_report_time TEXT NOT NULL DEFAULT '09:00',
    timezone TEXT NOT NULL DEFAULT 'Asia/Tehran',
    next_report_at INTEGER,
    last_report_at INTEGER,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id)
        REFERENCES users (telegram_id)
        ON DELETE CASCADE,
    FOREIGN KEY (chat_id)
        REFERENCES chats (telegram_id)
        ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_site_monitors_unique
    ON site_monitors (user_id, normalized_url);

CREATE INDEX IF NOT EXISTS idx_site_monitors_due
    ON site_monitors (status, next_check_at, id);

CREATE INDEX IF NOT EXISTS idx_site_monitors_reports
    ON site_monitors (
        status,
        daily_report_enabled,
        next_report_at,
        id
    );

CREATE TABLE IF NOT EXISTS monitor_checks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    monitor_id INTEGER NOT NULL,
    checked_at INTEGER NOT NULL,
    state TEXT NOT NULL
        CHECK (state IN ('up', 'down')),
    status_code INTEGER,
    response_ms REAL,
    final_url TEXT,
    primary_ip TEXT,
    error_code TEXT,
    error_message TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (monitor_id)
        REFERENCES site_monitors (id)
        ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_monitor_checks_monitor_time
    ON monitor_checks (monitor_id, checked_at DESC);

CREATE INDEX IF NOT EXISTS idx_monitor_checks_state
    ON monitor_checks (state, checked_at DESC);

CREATE TABLE IF NOT EXISTS monitor_notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    monitor_id INTEGER NOT NULL,
    state TEXT NOT NULL
        CHECK (state IN ('up', 'down')),
    dedup_key TEXT NOT NULL UNIQUE,
    sent_at INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (monitor_id)
        REFERENCES site_monitors (id)
        ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_monitor_notifications_monitor
    ON monitor_notifications (monitor_id, sent_at DESC);

INSERT INTO feature_flags (
    flag_key,
    enabled,
    rollout_percentage,
    description,
    updated_at,
    updated_by
) VALUES
    (
        'smart_alerts',
        1,
        100,
        'Weather, temperature, wind and currency smart alerts.',
        datetime('now'),
        'migration:010'
    ),
    (
        'scheduled_subscriptions',
        1,
        100,
        'Daily, weekly and monthly reports.',
        datetime('now'),
        'migration:010'
    ),
    (
        'site_monitoring',
        1,
        100,
        'SSRF-protected HTTP, SSL and DNS monitoring.',
        datetime('now'),
        'migration:010'
    )
ON CONFLICT(flag_key) DO UPDATE SET
    enabled = excluded.enabled,
    rollout_percentage = excluded.rollout_percentage,
    description = excluded.description,
    updated_at = excluded.updated_at,
    updated_by = excluded.updated_by;
