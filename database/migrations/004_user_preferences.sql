CREATE TABLE IF NOT EXISTS user_preferences (
    actor_key TEXT NOT NULL,
    preference_key TEXT NOT NULL,
    preference_value TEXT NOT NULL,
    updated_at TEXT NOT NULL,

    PRIMARY KEY (actor_key, preference_key)
);

CREATE INDEX IF NOT EXISTS idx_user_preferences_updated_at
    ON user_preferences (updated_at);
