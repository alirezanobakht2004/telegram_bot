CREATE TABLE IF NOT EXISTS usage_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_uuid TEXT NOT NULL UNIQUE,
    correlation_id TEXT,
    update_id INTEGER,
    update_type TEXT,
    user_id INTEGER,
    chat_id INTEGER,
    chat_type TEXT,
    module TEXT NOT NULL,
    action TEXT NOT NULL,
    input_kind TEXT NOT NULL,
    duration_ms REAL NOT NULL DEFAULT 0,
    success INTEGER NOT NULL DEFAULT 1,
    cache_hit INTEGER,
    error_code TEXT,
    error_message TEXT,
    metadata_json TEXT NOT NULL DEFAULT '{}',
    occurred_at INTEGER NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_usage_events_occurred_at
    ON usage_events (occurred_at);

CREATE INDEX IF NOT EXISTS idx_usage_events_module_action
    ON usage_events (module, action, occurred_at);

CREATE INDEX IF NOT EXISTS idx_usage_events_user
    ON usage_events (user_id, occurred_at);

CREATE INDEX IF NOT EXISTS idx_usage_events_success
    ON usage_events (success, occurred_at);

CREATE INDEX IF NOT EXISTS idx_usage_events_update
    ON usage_events (update_id, update_type);

CREATE TABLE IF NOT EXISTS command_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    correlation_id TEXT,
    update_id INTEGER,
    user_id INTEGER,
    chat_id INTEGER,
    chat_type TEXT,
    module TEXT NOT NULL,
    command TEXT NOT NULL,
    source TEXT NOT NULL,
    arguments_preview TEXT,
    success INTEGER NOT NULL DEFAULT 1,
    duration_ms REAL NOT NULL DEFAULT 0,
    occurred_at INTEGER NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_command_history_user
    ON command_history (user_id, occurred_at DESC);

CREATE INDEX IF NOT EXISTS idx_command_history_command
    ON command_history (command, occurred_at DESC);

CREATE INDEX IF NOT EXISTS idx_command_history_module
    ON command_history (module, occurred_at DESC);

CREATE TABLE IF NOT EXISTS api_metrics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    correlation_id TEXT,
    module TEXT,
    action TEXT,
    provider TEXT NOT NULL,
    http_method TEXT NOT NULL,
    host TEXT NOT NULL,
    path TEXT NOT NULL,
    status_code INTEGER,
    duration_ms REAL NOT NULL DEFAULT 0,
    response_bytes INTEGER NOT NULL DEFAULT 0,
    success INTEGER NOT NULL DEFAULT 1,
    error_code TEXT,
    occurred_at INTEGER NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_api_metrics_occurred_at
    ON api_metrics (occurred_at);

CREATE INDEX IF NOT EXISTS idx_api_metrics_provider
    ON api_metrics (provider, occurred_at);

CREATE INDEX IF NOT EXISTS idx_api_metrics_module
    ON api_metrics (module, occurred_at);

CREATE TABLE IF NOT EXISTS cache_metrics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    correlation_id TEXT,
    module TEXT,
    action TEXT,
    namespace TEXT NOT NULL,
    operation TEXT NOT NULL,
    key_hash TEXT NOT NULL,
    hit INTEGER,
    duration_ms REAL NOT NULL DEFAULT 0,
    value_bytes INTEGER NOT NULL DEFAULT 0,
    occurred_at INTEGER NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_cache_metrics_occurred_at
    ON cache_metrics (occurred_at);

CREATE INDEX IF NOT EXISTS idx_cache_metrics_namespace
    ON cache_metrics (namespace, operation, occurred_at);

CREATE TABLE IF NOT EXISTS feature_flags (
    flag_key TEXT PRIMARY KEY,
    enabled INTEGER NOT NULL DEFAULT 0,
    rollout_percentage INTEGER NOT NULL DEFAULT 100
        CHECK (
            rollout_percentage >= 0
            AND rollout_percentage <= 100
        ),
    description TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL,
    updated_by TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_feature_flags_enabled
    ON feature_flags (enabled, flag_key);

CREATE TABLE IF NOT EXISTS job_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_type TEXT NOT NULL,
    unique_key TEXT,
    payload_json TEXT NOT NULL DEFAULT '{}',
    status TEXT NOT NULL DEFAULT 'queued'
        CHECK (
            status IN (
                'queued',
                'processing',
                'completed',
                'failed',
                'dead',
                'cancelled'
            )
        ),
    priority INTEGER NOT NULL DEFAULT 0,
    available_at INTEGER NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 3,
    locked_by TEXT,
    locked_at INTEGER,
    last_error TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    completed_at TEXT
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_job_queue_unique_key
    ON job_queue (unique_key)
    WHERE unique_key IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_job_queue_due
    ON job_queue (
        status,
        available_at,
        priority DESC,
        id ASC
    );

CREATE INDEX IF NOT EXISTS idx_job_queue_locked
    ON job_queue (status, locked_at);

CREATE INDEX IF NOT EXISTS idx_job_queue_type
    ON job_queue (job_type, status, available_at);

CREATE TABLE IF NOT EXISTS job_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    worker_id TEXT NOT NULL,
    status TEXT NOT NULL
        CHECK (
            status IN (
                'running',
                'completed',
                'failed',
                'skipped'
            )
        ),
    claimed_count INTEGER NOT NULL DEFAULT 0,
    succeeded_count INTEGER NOT NULL DEFAULT 0,
    failed_count INTEGER NOT NULL DEFAULT 0,
    retried_count INTEGER NOT NULL DEFAULT 0,
    dead_lettered_count INTEGER NOT NULL DEFAULT 0,
    started_at TEXT NOT NULL,
    completed_at TEXT,
    error_message TEXT
);

CREATE INDEX IF NOT EXISTS idx_job_runs_started_at
    ON job_runs (started_at DESC);

CREATE TABLE IF NOT EXISTS dead_letter_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    original_job_id INTEGER NOT NULL,
    job_type TEXT NOT NULL,
    unique_key TEXT,
    payload_json TEXT NOT NULL,
    attempts INTEGER NOT NULL,
    error_message TEXT NOT NULL,
    failed_at TEXT NOT NULL,
    replayed_at TEXT,
    replay_job_id INTEGER
);

CREATE INDEX IF NOT EXISTS idx_dead_letter_jobs_failed_at
    ON dead_letter_jobs (failed_at DESC);

CREATE INDEX IF NOT EXISTS idx_dead_letter_jobs_type
    ON dead_letter_jobs (job_type, failed_at DESC);

CREATE TABLE IF NOT EXISTS job_locks (
    lock_key TEXT PRIMARY KEY,
    owner TEXT NOT NULL,
    acquired_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_job_locks_expires_at
    ON job_locks (expires_at);
