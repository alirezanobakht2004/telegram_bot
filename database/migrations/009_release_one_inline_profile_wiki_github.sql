CREATE TABLE IF NOT EXISTS user_favorites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    favorite_type TEXT NOT NULL,
    command_text TEXT NOT NULL,
    label TEXT NOT NULL,
    payload_json TEXT NOT NULL DEFAULT '{}',
    is_pinned INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id)
        REFERENCES users (telegram_id)
        ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_user_favorites_unique
    ON user_favorites (user_id, favorite_type, command_text);

CREATE INDEX IF NOT EXISTS idx_user_favorites_user
    ON user_favorites (
        user_id,
        is_pinned DESC,
        sort_order ASC,
        id DESC
    );

CREATE TABLE IF NOT EXISTS user_shortcuts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    shortcut_name TEXT NOT NULL,
    command_text TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id)
        REFERENCES users (telegram_id)
        ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_user_shortcuts_unique
    ON user_shortcuts (user_id, shortcut_name);

CREATE INDEX IF NOT EXISTS idx_user_shortcuts_user
    ON user_shortcuts (user_id, shortcut_name);

CREATE TABLE IF NOT EXISTS github_release_watches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    chat_id INTEGER NOT NULL,
    owner TEXT NOT NULL,
    repository TEXT NOT NULL,
    last_release_id INTEGER,
    last_tag_name TEXT,
    last_checked_at TEXT,
    last_notified_at TEXT,
    status TEXT NOT NULL DEFAULT 'active'
        CHECK (status IN ('active', 'paused')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id)
        REFERENCES users (telegram_id)
        ON DELETE CASCADE,
    FOREIGN KEY (chat_id)
        REFERENCES chats (telegram_id)
        ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_github_release_watches_unique
    ON github_release_watches (user_id, owner, repository);

CREATE INDEX IF NOT EXISTS idx_github_release_watches_status
    ON github_release_watches (status, last_checked_at, id);

CREATE TABLE IF NOT EXISTS inline_result_selections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    result_id TEXT NOT NULL,
    inline_message_id TEXT,
    query_text TEXT,
    selected_at TEXT NOT NULL,
    FOREIGN KEY (user_id)
        REFERENCES users (telegram_id)
        ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_inline_result_selections_user
    ON inline_result_selections (
        user_id,
        selected_at DESC
    );


INSERT INTO feature_flags (
    flag_key,
    enabled,
    rollout_percentage,
    description,
    updated_at,
    updated_by
) VALUES (
    'inline_routing',
    1,
    100,
    'Inline mode handlers for release one.',
    datetime('now'),
    'migration:009'
)
ON CONFLICT(flag_key) DO UPDATE SET
    enabled = 1,
    rollout_percentage = 100,
    description = excluded.description,
    updated_at = excluded.updated_at,
    updated_by = excluded.updated_by;
