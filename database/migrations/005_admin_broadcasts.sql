CREATE TABLE IF NOT EXISTS admin_broadcasts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_user_id INTEGER NOT NULL,
    message_text TEXT NOT NULL,

    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (
            status IN (
                'pending',
                'running',
                'completed',
                'cancelled'
            )
        ),

    total_recipients INTEGER NOT NULL DEFAULT 0,
    sent_count INTEGER NOT NULL DEFAULT 0,
    failed_count INTEGER NOT NULL DEFAULT 0,

    created_at TEXT NOT NULL,
    started_at TEXT,
    completed_at TEXT,
    cancelled_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_admin_broadcasts_status
    ON admin_broadcasts (status);

CREATE INDEX IF NOT EXISTS idx_admin_broadcasts_created_at
    ON admin_broadcasts (created_at);

CREATE TABLE IF NOT EXISTS admin_broadcast_recipients (
    broadcast_id INTEGER NOT NULL,
    chat_id INTEGER NOT NULL,

    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (
            status IN (
                'pending',
                'processing',
                'sent',
                'failed'
            )
        ),

    attempts INTEGER NOT NULL DEFAULT 0,
    error_message TEXT,
    attempted_at TEXT,

    PRIMARY KEY (broadcast_id, chat_id),

    FOREIGN KEY (broadcast_id)
        REFERENCES admin_broadcasts (id)
        ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_admin_broadcast_recipients_status
    ON admin_broadcast_recipients (
        broadcast_id,
        status
    );
