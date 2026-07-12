CREATE TABLE IF NOT EXISTS reminders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    user_id INTEGER NOT NULL,
    chat_id INTEGER NOT NULL,

    reminder_text TEXT NOT NULL,
    timezone TEXT NOT NULL,

    scheduled_at INTEGER NOT NULL,
    next_attempt_at INTEGER NOT NULL,

    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (
            status IN (
                'pending',
                'processing',
                'sent',
                'failed',
                'cancelled'
            )
        ),

    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT,

    locked_at INTEGER,
    sent_at TEXT,
    cancelled_at TEXT,

    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,

    FOREIGN KEY (user_id)
        REFERENCES users (telegram_id)
        ON DELETE CASCADE,

    FOREIGN KEY (chat_id)
        REFERENCES chats (telegram_id)
        ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_reminders_due
    ON reminders (
        status,
        scheduled_at,
        next_attempt_at
    );

CREATE INDEX IF NOT EXISTS idx_reminders_user_status
    ON reminders (
        user_id,
        status,
        scheduled_at
    );

CREATE INDEX IF NOT EXISTS idx_reminders_updated_at
    ON reminders (
        status,
        updated_at
    );

CREATE TABLE IF NOT EXISTS reminder_worker_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    status TEXT NOT NULL
        CHECK (
            status IN (
                'running',
                'completed',
                'failed'
            )
        ),

    claimed_count INTEGER NOT NULL DEFAULT 0,
    sent_count INTEGER NOT NULL DEFAULT 0,
    failed_count INTEGER NOT NULL DEFAULT 0,
    retried_count INTEGER NOT NULL DEFAULT 0,
    pruned_count INTEGER NOT NULL DEFAULT 0,

    started_at TEXT NOT NULL,
    completed_at TEXT,
    error_message TEXT
);

CREATE INDEX IF NOT EXISTS idx_reminder_worker_runs_started_at
    ON reminder_worker_runs (started_at);
