CREATE TABLE IF NOT EXISTS file_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    user_id INTEGER NOT NULL,
    chat_id INTEGER NOT NULL,
    request_message_id INTEGER,

    operation TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'queued'
        CHECK (
            status IN (
                'queued',
                'processing',
                'completed',
                'failed',
                'cancelled'
            )
        ),

    source_kind TEXT NOT NULL
        CHECK (
            source_kind IN (
                'telegram_file',
                'text'
            )
        ),

    file_id TEXT,
    file_unique_id TEXT,
    file_name TEXT,
    mime_type TEXT,
    file_size INTEGER,
    width INTEGER,
    height INTEGER,

    input_text TEXT,
    parameters_json TEXT NOT NULL DEFAULT '{}',

    output_name TEXT,
    output_mime_type TEXT,
    output_size INTEGER,

    progress INTEGER NOT NULL DEFAULT 0,
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 3,

    started_at TEXT,
    completed_at TEXT,
    expires_at INTEGER,

    error_code TEXT,
    error_message TEXT,

    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,

    FOREIGN KEY (user_id)
        REFERENCES users (telegram_id)
        ON DELETE CASCADE,

    FOREIGN KEY (chat_id)
        REFERENCES chats (telegram_id)
        ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_file_jobs_one_active_per_user
    ON file_jobs (user_id)
    WHERE status IN ('queued', 'processing');

CREATE INDEX IF NOT EXISTS idx_file_jobs_status
    ON file_jobs (
        status,
        created_at,
        id
    );

CREATE INDEX IF NOT EXISTS idx_file_jobs_user_history
    ON file_jobs (
        user_id,
        id DESC
    );

CREATE TABLE IF NOT EXISTS file_capability_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    capability TEXT NOT NULL,
    available INTEGER NOT NULL,
    version TEXT,
    details TEXT,
    checked_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_file_capability_snapshots_latest
    ON file_capability_snapshots (
        capability,
        id DESC
    );

INSERT INTO feature_flags (
    flag_key,
    enabled,
    rollout_percentage,
    description,
    updated_at,
    updated_by
) VALUES (
    'file_tools',
    1,
    100,
    'Queued file, image, PDF and text conversion tools.',
    datetime('now'),
    'migration:012'
)
ON CONFLICT(flag_key) DO UPDATE SET
    enabled = 1,
    rollout_percentage = 100,
    description = excluded.description,
    updated_at = excluded.updated_at,
    updated_by = excluded.updated_by;
