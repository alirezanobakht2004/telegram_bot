CREATE TABLE IF NOT EXISTS conversation_states (
    actor_key TEXT PRIMARY KEY,
    state TEXT NOT NULL,
    payload TEXT NOT NULL DEFAULT '{}',
    expires_at INTEGER NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_conversation_states_expires_at
    ON conversation_states (expires_at);
