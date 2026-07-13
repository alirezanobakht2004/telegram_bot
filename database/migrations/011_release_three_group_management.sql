CREATE TABLE IF NOT EXISTS group_settings (
    chat_id INTEGER PRIMARY KEY,

    warnings_threshold INTEGER NOT NULL DEFAULT 3,
    warning_action TEXT NOT NULL DEFAULT 'mute'
        CHECK (
            warning_action IN (
                'none',
                'mute',
                'ban'
            )
        ),
    warning_action_duration_seconds
        INTEGER NOT NULL DEFAULT 3600,

    anti_spam_enabled INTEGER NOT NULL DEFAULT 0,
    flood_max_messages INTEGER NOT NULL DEFAULT 6,
    flood_window_seconds INTEGER NOT NULL DEFAULT 10,
    duplicate_max_messages INTEGER NOT NULL DEFAULT 3,
    duplicate_window_seconds INTEGER NOT NULL DEFAULT 30,

    anti_link_enabled INTEGER NOT NULL DEFAULT 0,
    bad_words_enabled INTEGER NOT NULL DEFAULT 0,

    captcha_enabled INTEGER NOT NULL DEFAULT 0,
    captcha_timeout_seconds INTEGER NOT NULL DEFAULT 120,
    captcha_max_attempts INTEGER NOT NULL DEFAULT 3,
    captcha_failure_action TEXT NOT NULL DEFAULT 'kick'
        CHECK (
            captcha_failure_action IN (
                'kick',
                'ban'
            )
        ),

    welcome_enabled INTEGER NOT NULL DEFAULT 0,
    welcome_message TEXT,
    goodbye_enabled INTEGER NOT NULL DEFAULT 0,
    goodbye_message TEXT,
    rules_text TEXT,

    bot_slow_mode_seconds INTEGER NOT NULL DEFAULT 0,

    join_request_mode TEXT NOT NULL DEFAULT 'manual'
        CHECK (
            join_request_mode IN (
                'manual',
                'approve',
                'decline'
            )
        ),

    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,

    FOREIGN KEY (chat_id)
        REFERENCES chats (telegram_id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS group_warnings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    chat_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    admin_id INTEGER NOT NULL,

    reason TEXT,
    active INTEGER NOT NULL DEFAULT 1,

    created_at TEXT NOT NULL,
    revoked_at TEXT,
    revoked_by INTEGER,

    FOREIGN KEY (chat_id)
        REFERENCES chats (telegram_id)
        ON DELETE CASCADE,

    FOREIGN KEY (user_id)
        REFERENCES users (telegram_id)
        ON DELETE CASCADE,

    FOREIGN KEY (admin_id)
        REFERENCES users (telegram_id)
        ON DELETE CASCADE,

    FOREIGN KEY (revoked_by)
        REFERENCES users (telegram_id)
        ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_group_warnings_target
    ON group_warnings (
        chat_id,
        user_id,
        active,
        id DESC
    );

CREATE TABLE IF NOT EXISTS group_sanctions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    chat_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    admin_id INTEGER,

    sanction_type TEXT NOT NULL
        CHECK (
            sanction_type IN (
                'mute',
                'ban',
                'kick',
                'captcha',
                'warning_action'
            )
        ),

    status TEXT NOT NULL DEFAULT 'active'
        CHECK (
            status IN (
                'active',
                'expired',
                'revoked',
                'failed'
            )
        ),

    reason TEXT,
    until_at INTEGER,
    telegram_applied INTEGER NOT NULL DEFAULT 0,
    last_error TEXT,

    created_at TEXT NOT NULL,
    lifted_at TEXT,

    FOREIGN KEY (chat_id)
        REFERENCES chats (telegram_id)
        ON DELETE CASCADE,

    FOREIGN KEY (user_id)
        REFERENCES users (telegram_id)
        ON DELETE CASCADE,

    FOREIGN KEY (admin_id)
        REFERENCES users (telegram_id)
        ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_group_sanctions_due
    ON group_sanctions (
        status,
        until_at,
        sanction_type
    );

CREATE INDEX IF NOT EXISTS idx_group_sanctions_target
    ON group_sanctions (
        chat_id,
        user_id,
        status,
        id DESC
    );

CREATE TABLE IF NOT EXISTS group_domain_whitelist (
    chat_id INTEGER NOT NULL,
    domain TEXT NOT NULL,
    created_by INTEGER NOT NULL,
    created_at TEXT NOT NULL,

    PRIMARY KEY (
        chat_id,
        domain
    ),

    FOREIGN KEY (chat_id)
        REFERENCES chats (telegram_id)
        ON DELETE CASCADE,

    FOREIGN KEY (created_by)
        REFERENCES users (telegram_id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS group_bad_words (
    chat_id INTEGER NOT NULL,
    normalized_word TEXT NOT NULL,
    display_word TEXT NOT NULL,
    created_by INTEGER NOT NULL,
    created_at TEXT NOT NULL,

    PRIMARY KEY (
        chat_id,
        normalized_word
    ),

    FOREIGN KEY (chat_id)
        REFERENCES chats (telegram_id)
        ON DELETE CASCADE,

    FOREIGN KEY (created_by)
        REFERENCES users (telegram_id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS group_member_roles (
    chat_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,

    status TEXT NOT NULL,
    is_admin INTEGER NOT NULL DEFAULT 0,
    permissions_json TEXT NOT NULL DEFAULT '{}',
    checked_at INTEGER NOT NULL,
    updated_at TEXT NOT NULL,

    PRIMARY KEY (
        chat_id,
        user_id
    ),

    FOREIGN KEY (chat_id)
        REFERENCES chats (telegram_id)
        ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_group_member_roles_admin
    ON group_member_roles (
        chat_id,
        is_admin,
        checked_at
    );

CREATE TABLE IF NOT EXISTS group_member_activity (
    chat_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,

    window_started_at INTEGER NOT NULL DEFAULT 0,
    message_count INTEGER NOT NULL DEFAULT 0,

    last_message_at INTEGER NOT NULL DEFAULT 0,
    last_text_hash TEXT,
    duplicate_count INTEGER NOT NULL DEFAULT 0,

    last_violation_at INTEGER,
    updated_at TEXT NOT NULL,

    PRIMARY KEY (
        chat_id,
        user_id
    ),

    FOREIGN KEY (chat_id)
        REFERENCES chats (telegram_id)
        ON DELETE CASCADE,

    FOREIGN KEY (user_id)
        REFERENCES users (telegram_id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS group_captcha_challenges (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    chat_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,

    question TEXT NOT NULL,
    correct_answer TEXT NOT NULL,

    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (
            status IN (
                'pending',
                'passed',
                'failed',
                'expired',
                'cancelled'
            )
        ),

    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 3,
    expires_at INTEGER NOT NULL,

    message_id INTEGER,
    created_at TEXT NOT NULL,
    completed_at TEXT,

    FOREIGN KEY (chat_id)
        REFERENCES chats (telegram_id)
        ON DELETE CASCADE,

    FOREIGN KEY (user_id)
        REFERENCES users (telegram_id)
        ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_group_captcha_pending
    ON group_captcha_challenges (
        chat_id,
        user_id
    )
    WHERE status = 'pending';

CREATE INDEX IF NOT EXISTS idx_group_captcha_expiry
    ON group_captcha_challenges (
        status,
        expires_at
    );

CREATE TABLE IF NOT EXISTS group_invite_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    chat_id INTEGER NOT NULL,
    created_by INTEGER NOT NULL,

    invite_link TEXT NOT NULL UNIQUE,
    link_name TEXT,
    expire_at INTEGER,
    member_limit INTEGER,
    creates_join_request INTEGER NOT NULL DEFAULT 0,

    status TEXT NOT NULL DEFAULT 'active'
        CHECK (
            status IN (
                'active',
                'revoked',
                'expired'
            )
        ),

    created_at TEXT NOT NULL,
    revoked_at TEXT,

    FOREIGN KEY (chat_id)
        REFERENCES chats (telegram_id)
        ON DELETE CASCADE,

    FOREIGN KEY (created_by)
        REFERENCES users (telegram_id)
        ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_group_invite_links_chat
    ON group_invite_links (
        chat_id,
        status,
        id DESC
    );

CREATE TABLE IF NOT EXISTS group_join_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    chat_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    user_chat_id INTEGER,

    bio TEXT,
    invite_link TEXT,

    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (
            status IN (
                'pending',
                'approved',
                'declined',
                'expired'
            )
        ),

    requested_at TEXT NOT NULL,
    resolved_at TEXT,
    resolved_by INTEGER,

    FOREIGN KEY (chat_id)
        REFERENCES chats (telegram_id)
        ON DELETE CASCADE,

    FOREIGN KEY (user_id)
        REFERENCES users (telegram_id)
        ON DELETE CASCADE,

    FOREIGN KEY (resolved_by)
        REFERENCES users (telegram_id)
        ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_group_join_requests_chat
    ON group_join_requests (
        chat_id,
        status,
        id DESC
    );

CREATE UNIQUE INDEX IF NOT EXISTS idx_group_join_requests_pending_unique
    ON group_join_requests (
        chat_id,
        user_id
    )
    WHERE status = 'pending';

CREATE TABLE IF NOT EXISTS group_audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    chat_id INTEGER NOT NULL,
    actor_id INTEGER,
    target_user_id INTEGER,

    action TEXT NOT NULL,
    details_json TEXT NOT NULL DEFAULT '{}',

    success INTEGER NOT NULL DEFAULT 1,
    error_message TEXT,
    created_at TEXT NOT NULL,

    FOREIGN KEY (chat_id)
        REFERENCES chats (telegram_id)
        ON DELETE CASCADE,

    FOREIGN KEY (actor_id)
        REFERENCES users (telegram_id)
        ON DELETE SET NULL,

    FOREIGN KEY (target_user_id)
        REFERENCES users (telegram_id)
        ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_group_audit_chat
    ON group_audit_logs (
        chat_id,
        id DESC
    );

CREATE INDEX IF NOT EXISTS idx_group_audit_action
    ON group_audit_logs (
        action,
        created_at
    );

INSERT INTO feature_flags (
    flag_key,
    enabled,
    rollout_percentage,
    description,
    updated_at,
    updated_by
) VALUES (
    'group_management',
    1,
    100,
    'Professional group moderation, automation, captcha and join requests.',
    datetime('now'),
    'migration:011'
)
ON CONFLICT(flag_key) DO UPDATE SET
    enabled = 1,
    rollout_percentage = 100,
    description = excluded.description,
    updated_at = excluded.updated_at,
    updated_by = excluded.updated_by;
