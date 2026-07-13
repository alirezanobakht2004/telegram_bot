CREATE TABLE IF NOT EXISTS quiz_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE COLLATE NOCASE,
    name TEXT NOT NULL,
    description TEXT,
    enabled INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_quiz_categories_enabled
    ON quiz_categories (
        enabled,
        sort_order,
        name
    );

CREATE TABLE IF NOT EXISTS quiz_questions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL,
    question_type TEXT NOT NULL DEFAULT 'trivia'
        CHECK (
            question_type IN (
                'trivia',
                'word'
            )
        ),
    difficulty TEXT NOT NULL DEFAULT 'medium'
        CHECK (
            difficulty IN (
                'easy',
                'medium',
                'hard'
            )
        ),
    question_text TEXT NOT NULL,
    explanation TEXT,
    points INTEGER NOT NULL DEFAULT 10
        CHECK (points BETWEEN 1 AND 1000),
    xp_reward INTEGER NOT NULL DEFAULT 10
        CHECK (xp_reward BETWEEN 1 AND 1000),
    answer_timeout_seconds INTEGER NOT NULL DEFAULT 30
        CHECK (
            answer_timeout_seconds
            BETWEEN 5 AND 300
        ),
    enabled INTEGER NOT NULL DEFAULT 1,
    source TEXT NOT NULL DEFAULT 'admin',
    created_by TEXT,
    times_served INTEGER NOT NULL DEFAULT 0,
    correct_count INTEGER NOT NULL DEFAULT 0,
    incorrect_count INTEGER NOT NULL DEFAULT 0,
    timeout_count INTEGER NOT NULL DEFAULT 0,
    last_served_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,

    FOREIGN KEY (category_id)
        REFERENCES quiz_categories (id)
        ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_quiz_questions_select
    ON quiz_questions (
        enabled,
        question_type,
        difficulty,
        category_id
    );

CREATE INDEX IF NOT EXISTS idx_quiz_questions_difficulty
    ON quiz_questions (
        correct_count,
        incorrect_count,
        timeout_count
    );

CREATE TABLE IF NOT EXISTS quiz_question_options (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    question_id INTEGER NOT NULL,
    option_text TEXT NOT NULL,
    is_correct INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0,

    FOREIGN KEY (question_id)
        REFERENCES quiz_questions (id)
        ON DELETE CASCADE,

    UNIQUE (
        question_id,
        sort_order
    )
);

CREATE INDEX IF NOT EXISTS idx_quiz_options_question
    ON quiz_question_options (
        question_id,
        sort_order
    );

CREATE TABLE IF NOT EXISTS quiz_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token TEXT NOT NULL UNIQUE,
    user_id INTEGER NOT NULL,
    chat_id INTEGER NOT NULL,
    chat_type TEXT NOT NULL,
    mode TEXT NOT NULL
        CHECK (
            mode IN (
                'quiz',
                'trivia',
                'math',
                'word',
                'daily'
            )
        ),
    question_id INTEGER,
    category_slug TEXT,
    category_name TEXT,
    difficulty TEXT NOT NULL
        CHECK (
            difficulty IN (
                'easy',
                'medium',
                'hard'
            )
        ),
    question_text TEXT NOT NULL,
    options_json TEXT NOT NULL,
    correct_option INTEGER NOT NULL
        CHECK (correct_option BETWEEN 0 AND 9),
    explanation TEXT,
    points INTEGER NOT NULL,
    xp_reward INTEGER NOT NULL,
    answer_timeout_seconds INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'active'
        CHECK (
            status IN (
                'active',
                'answered',
                'expired',
                'cancelled'
            )
        ),
    selected_option INTEGER,
    is_correct INTEGER,
    score_awarded INTEGER NOT NULL DEFAULT 0,
    xp_awarded INTEGER NOT NULL DEFAULT 0,
    started_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    answered_at INTEGER,
    message_id INTEGER,
    daily_date TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,

    FOREIGN KEY (user_id)
        REFERENCES users (telegram_id)
        ON DELETE CASCADE,

    FOREIGN KEY (chat_id)
        REFERENCES chats (telegram_id)
        ON DELETE CASCADE,

    FOREIGN KEY (question_id)
        REFERENCES quiz_questions (id)
        ON DELETE SET NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_quiz_sessions_active_user
    ON quiz_sessions (user_id)
    WHERE status = 'active';

CREATE INDEX IF NOT EXISTS idx_quiz_sessions_expiry
    ON quiz_sessions (
        status,
        expires_at
    );

CREATE INDEX IF NOT EXISTS idx_quiz_sessions_user_history
    ON quiz_sessions (
        user_id,
        id DESC
    );

CREATE INDEX IF NOT EXISTS idx_quiz_sessions_chat_history
    ON quiz_sessions (
        chat_id,
        id DESC
    );

CREATE TABLE IF NOT EXISTS quiz_user_scores (
    user_id INTEGER PRIMARY KEY,
    score INTEGER NOT NULL DEFAULT 0,
    xp INTEGER NOT NULL DEFAULT 0,
    level INTEGER NOT NULL DEFAULT 1,
    total_answers INTEGER NOT NULL DEFAULT 0,
    correct_answers INTEGER NOT NULL DEFAULT 0,
    wrong_answers INTEGER NOT NULL DEFAULT 0,
    current_correct_streak INTEGER NOT NULL DEFAULT 0,
    longest_correct_streak INTEGER NOT NULL DEFAULT 0,
    daily_streak INTEGER NOT NULL DEFAULT 0,
    longest_daily_streak INTEGER NOT NULL DEFAULT 0,
    last_activity_date TEXT,
    last_daily_challenge_date TEXT,
    daily_challenges INTEGER NOT NULL DEFAULT 0,
    daily_correct INTEGER NOT NULL DEFAULT 0,
    math_correct INTEGER NOT NULL DEFAULT 0,
    word_correct INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL,

    FOREIGN KEY (user_id)
        REFERENCES users (telegram_id)
        ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_quiz_user_scores_score
    ON quiz_user_scores (
        score DESC,
        xp DESC,
        user_id
    );

CREATE INDEX IF NOT EXISTS idx_quiz_user_scores_xp
    ON quiz_user_scores (
        xp DESC,
        score DESC,
        user_id
    );

CREATE TABLE IF NOT EXISTS quiz_group_scores (
    chat_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    score INTEGER NOT NULL DEFAULT 0,
    xp INTEGER NOT NULL DEFAULT 0,
    total_answers INTEGER NOT NULL DEFAULT 0,
    correct_answers INTEGER NOT NULL DEFAULT 0,
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

CREATE INDEX IF NOT EXISTS idx_quiz_group_scores_rank
    ON quiz_group_scores (
        chat_id,
        score DESC,
        xp DESC,
        user_id
    );

CREATE TABLE IF NOT EXISTS quiz_daily_challenges (
    challenge_date TEXT PRIMARY KEY,
    question_id INTEGER NOT NULL,
    created_at TEXT NOT NULL,

    FOREIGN KEY (question_id)
        REFERENCES quiz_questions (id)
        ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS quiz_daily_attempts (
    challenge_date TEXT NOT NULL,
    user_id INTEGER NOT NULL,
    session_id INTEGER NOT NULL,
    is_correct INTEGER NOT NULL,
    score_awarded INTEGER NOT NULL DEFAULT 0,
    xp_awarded INTEGER NOT NULL DEFAULT 0,
    answered_at TEXT NOT NULL,

    PRIMARY KEY (
        challenge_date,
        user_id
    ),

    FOREIGN KEY (user_id)
        REFERENCES users (telegram_id)
        ON DELETE CASCADE,

    FOREIGN KEY (session_id)
        REFERENCES quiz_sessions (id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS quiz_achievements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    description TEXT NOT NULL,
    icon TEXT NOT NULL DEFAULT '🏆',
    metric TEXT NOT NULL
        CHECK (
            metric IN (
                'total_answers',
                'correct_answers',
                'score',
                'level',
                'current_correct_streak',
                'daily_streak',
                'daily_challenges',
                'math_correct',
                'word_correct'
            )
        ),
    threshold INTEGER NOT NULL
        CHECK (threshold > 0),
    enabled INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_quiz_achievements_enabled
    ON quiz_achievements (
        enabled,
        sort_order,
        threshold
    );

CREATE TABLE IF NOT EXISTS quiz_user_achievements (
    user_id INTEGER NOT NULL,
    achievement_id INTEGER NOT NULL,
    unlocked_at TEXT NOT NULL,

    PRIMARY KEY (
        user_id,
        achievement_id
    ),

    FOREIGN KEY (user_id)
        REFERENCES users (telegram_id)
        ON DELETE CASCADE,

    FOREIGN KEY (achievement_id)
        REFERENCES quiz_achievements (id)
        ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_quiz_user_achievements_user
    ON quiz_user_achievements (
        user_id,
        unlocked_at DESC
    );

INSERT OR IGNORE INTO quiz_categories (
    id,
    slug,
    name,
    description,
    enabled,
    sort_order,
    created_at,
    updated_at
) VALUES
    (
        1,
        'general',
        'اطلاعات عمومی',
        'پرسش‌های عمومی و دانستنی‌های روزمره',
        1,
        10,
        datetime('now'),
        datetime('now')
    ),
    (
        2,
        'science',
        'علوم',
        'فیزیک، شیمی، زیست‌شناسی و نجوم',
        1,
        20,
        datetime('now'),
        datetime('now')
    ),
    (
        3,
        'geography',
        'جغرافیا',
        'کشورها، شهرها، طبیعت و نقشه',
        1,
        30,
        datetime('now'),
        datetime('now')
    ),
    (
        4,
        'history',
        'تاریخ',
        'تمدن‌ها و رویدادهای تاریخی',
        1,
        40,
        datetime('now'),
        datetime('now')
    ),
    (
        5,
        'technology',
        'فناوری',
        'رایانه، اینترنت و برنامه‌نویسی',
        1,
        50,
        datetime('now'),
        datetime('now')
    ),
    (
        6,
        'words',
        'واژگان',
        'معنی، مترادف و کاربرد واژه‌ها',
        1,
        60,
        datetime('now'),
        datetime('now')
    );

INSERT OR IGNORE INTO quiz_questions (
    id,
    category_id,
    question_type,
    difficulty,
    question_text,
    explanation,
    points,
    xp_reward,
    answer_timeout_seconds,
    enabled,
    source,
    created_by,
    created_at,
    updated_at
) VALUES
    (
        1, 1, 'trivia', 'easy',
        'کدام سیاره به «سیاره سرخ» مشهور است؟',
        'وجود اکسید آهن در سطح مریخ، ظاهر سرخ‌رنگ آن را ایجاد می‌کند.',
        10, 10, 30, 1, 'seed', 'migration:013',
        datetime('now'), datetime('now')
    ),
    (
        2, 1, 'trivia', 'medium',
        'واحد اندازه‌گیری شدت جریان الکتریکی چیست؟',
        'شدت جریان الکتریکی با واحد آمپر اندازه‌گیری می‌شود.',
        20, 18, 25, 1, 'seed', 'migration:013',
        datetime('now'), datetime('now')
    ),
    (
        3, 1, 'trivia', 'hard',
        'کدام عدد تنها عدد اول زوج است؟',
        'عدد ۲ تنها عدد زوجی است که دقیقاً دو مقسوم‌علیه مثبت دارد.',
        30, 26, 20, 1, 'seed', 'migration:013',
        datetime('now'), datetime('now')
    ),
    (
        4, 2, 'trivia', 'easy',
        'بزرگ‌ترین اندام بدن انسان کدام است؟',
        'پوست بزرگ‌ترین اندام بدن انسان است.',
        10, 10, 30, 1, 'seed', 'migration:013',
        datetime('now'), datetime('now')
    ),
    (
        5, 2, 'trivia', 'medium',
        'نماد شیمیایی عنصر طلا چیست؟',
        'نماد Au از نام لاتین طلا، Aurum، گرفته شده است.',
        20, 18, 25, 1, 'seed', 'migration:013',
        datetime('now'), datetime('now')
    ),
    (
        6, 2, 'trivia', 'hard',
        'کدام بخش سلول محل اصلی تولید ATP است؟',
        'میتوکندری با تنفس سلولی بخش عمده ATP را تولید می‌کند.',
        30, 26, 20, 1, 'seed', 'migration:013',
        datetime('now'), datetime('now')
    ),
    (
        7, 3, 'trivia', 'easy',
        'پایتخت ژاپن کدام شهر است؟',
        'توکیو پایتخت و پرجمعیت‌ترین منطقه شهری ژاپن است.',
        10, 10, 30, 1, 'seed', 'migration:013',
        datetime('now'), datetime('now')
    ),
    (
        8, 3, 'trivia', 'medium',
        'رود نیل به کدام دریا می‌ریزد؟',
        'رود نیل پس از عبور از شمال آفریقا به دریای مدیترانه می‌ریزد.',
        20, 18, 25, 1, 'seed', 'migration:013',
        datetime('now'), datetime('now')
    ),
    (
        9, 3, 'trivia', 'hard',
        'کدام کشور بیشترین تعداد جزیره ثبت‌شده را دارد؟',
        'سوئد با صدها هزار جزیره، بیشترین تعداد جزیره ثبت‌شده را دارد.',
        30, 26, 20, 1, 'seed', 'migration:013',
        datetime('now'), datetime('now')
    ),
    (
        10, 4, 'trivia', 'easy',
        'تخت جمشید به کدام دوره تاریخی تعلق دارد؟',
        'تخت جمشید از مهم‌ترین یادگارهای شاهنشاهی هخامنشی است.',
        10, 10, 30, 1, 'seed', 'migration:013',
        datetime('now'), datetime('now')
    ),
    (
        11, 4, 'trivia', 'medium',
        'انقلاب صنعتی نخست در کدام کشور آغاز شد؟',
        'انقلاب صنعتی در سده هجدهم ابتدا در بریتانیا آغاز شد.',
        20, 18, 25, 1, 'seed', 'migration:013',
        datetime('now'), datetime('now')
    ),
    (
        12, 4, 'trivia', 'hard',
        'خط میخی سومری نخستین‌بار در کدام منطقه شکل گرفت؟',
        'خط میخی در بین‌النهرین و در تمدن سومر شکل گرفت.',
        30, 26, 20, 1, 'seed', 'migration:013',
        datetime('now'), datetime('now')
    ),
    (
        13, 5, 'trivia', 'easy',
        'HTML در اصل برای چه کاری استفاده می‌شود؟',
        'HTML ساختار و محتوای صفحات وب را مشخص می‌کند.',
        10, 10, 30, 1, 'seed', 'migration:013',
        datetime('now'), datetime('now')
    ),
    (
        14, 5, 'trivia', 'medium',
        'کدام ساختار داده بر پایه اصل LIFO کار می‌کند؟',
        'در Stack آخرین عضو واردشده، نخستین عضو خارج‌شده است.',
        20, 18, 25, 1, 'seed', 'migration:013',
        datetime('now'), datetime('now')
    ),
    (
        15, 5, 'trivia', 'hard',
        'در پایگاه داده، ACID به کدام ویژگی‌ها اشاره دارد؟',
        'Atomicity، Consistency، Isolation و Durability چهار ویژگی ACID هستند.',
        30, 26, 20, 1, 'seed', 'migration:013',
        datetime('now'), datetime('now')
    ),
    (
        16, 6, 'word', 'easy',
        'کدام واژه با «خوشحال» هم‌معنی است؟',
        'شاد و خوشحال مترادف‌اند.',
        10, 10, 30, 1, 'seed', 'migration:013',
        datetime('now'), datetime('now')
    ),
    (
        17, 6, 'word', 'medium',
        'کدام واژه متضاد «فراوان» است؟',
        'اندک در برابر فراوان قرار می‌گیرد.',
        20, 18, 25, 1, 'seed', 'migration:013',
        datetime('now'), datetime('now')
    ),
    (
        18, 6, 'word', 'hard',
        'واژه «مقتصد» به چه معناست؟',
        'مقتصد به فرد صرفه‌جو و میانه‌رو در هزینه‌کردن گفته می‌شود.',
        30, 26, 20, 1, 'seed', 'migration:013',
        datetime('now'), datetime('now')
    );

INSERT OR IGNORE INTO quiz_question_options (
    question_id,
    option_text,
    is_correct,
    sort_order
) VALUES
    (1, 'مریخ', 1, 0),
    (1, 'زهره', 0, 1),
    (1, 'مشتری', 0, 2),
    (1, 'عطارد', 0, 3),

    (2, 'آمپر', 1, 0),
    (2, 'ولت', 0, 1),
    (2, 'وات', 0, 2),
    (2, 'اهم', 0, 3),

    (3, '۲', 1, 0),
    (3, '۴', 0, 1),
    (3, '۶', 0, 2),
    (3, '۸', 0, 3),

    (4, 'پوست', 1, 0),
    (4, 'کبد', 0, 1),
    (4, 'قلب', 0, 2),
    (4, 'ریه', 0, 3),

    (5, 'Au', 1, 0),
    (5, 'Ag', 0, 1),
    (5, 'Fe', 0, 2),
    (5, 'Cu', 0, 3),

    (6, 'میتوکندری', 1, 0),
    (6, 'هسته', 0, 1),
    (6, 'ریبوزوم', 0, 2),
    (6, 'دستگاه گلژی', 0, 3),

    (7, 'توکیو', 1, 0),
    (7, 'کیوتو', 0, 1),
    (7, 'اوساکا', 0, 2),
    (7, 'ساپورو', 0, 3),

    (8, 'مدیترانه', 1, 0),
    (8, 'سرخ', 0, 1),
    (8, 'سیاه', 0, 2),
    (8, 'عرب', 0, 3),

    (9, 'سوئد', 1, 0),
    (9, 'اندونزی', 0, 1),
    (9, 'فیلیپین', 0, 2),
    (9, 'ژاپن', 0, 3),

    (10, 'هخامنشی', 1, 0),
    (10, 'ساسانی', 0, 1),
    (10, 'اشکانی', 0, 2),
    (10, 'صفوی', 0, 3),

    (11, 'بریتانیا', 1, 0),
    (11, 'فرانسه', 0, 1),
    (11, 'آلمان', 0, 2),
    (11, 'ایتالیا', 0, 3),

    (12, 'بین‌النهرین', 1, 0),
    (12, 'دره سند', 0, 1),
    (12, 'مصر', 0, 2),
    (12, 'آناتولی', 0, 3),

    (13, 'ساختار صفحات وب', 1, 0),
    (13, 'مدیریت پایگاه داده', 0, 1),
    (13, 'رمزنگاری شبکه', 0, 2),
    (13, 'پردازش تصویر', 0, 3),

    (14, 'Stack', 1, 0),
    (14, 'Queue', 0, 1),
    (14, 'Graph', 0, 2),
    (14, 'Heap', 0, 3),

    (15, 'Atomicity, Consistency, Isolation, Durability', 1, 0),
    (15, 'Access, Control, Index, Data', 0, 1),
    (15, 'Async, Cache, Input, Debug', 0, 2),
    (15, 'Array, Class, Interface, Dependency', 0, 3),

    (16, 'شاد', 1, 0),
    (16, 'اندوهگین', 0, 1),
    (16, 'خشمگین', 0, 2),
    (16, 'خاموش', 0, 3),

    (17, 'اندک', 1, 0),
    (17, 'بسیار', 0, 1),
    (17, 'گسترده', 0, 2),
    (17, 'انبوه', 0, 3),

    (18, 'صرفه‌جو', 1, 0),
    (18, 'شتاب‌زده', 0, 1),
    (18, 'خودخواه', 0, 2),
    (18, 'سخنور', 0, 3);

INSERT OR IGNORE INTO quiz_achievements (
    id,
    code,
    name,
    description,
    icon,
    metric,
    threshold,
    enabled,
    sort_order,
    created_at,
    updated_at
) VALUES
    (
        1, 'first_answer', 'شروع‌کننده',
        'اولین پاسخ خود را ثبت کن.',
        '🎬', 'total_answers', 1, 1, 10,
        datetime('now'), datetime('now')
    ),
    (
        2, 'first_correct', 'اولین پاسخ درست',
        'اولین پاسخ درست را ثبت کن.',
        '✅', 'correct_answers', 1, 1, 20,
        datetime('now'), datetime('now')
    ),
    (
        3, 'correct_10', 'دانش‌جو',
        '۱۰ پاسخ درست ثبت کن.',
        '📘', 'correct_answers', 10, 1, 30,
        datetime('now'), datetime('now')
    ),
    (
        4, 'correct_50', 'دانش‌پژوه',
        '۵۰ پاسخ درست ثبت کن.',
        '🎓', 'correct_answers', 50, 1, 40,
        datetime('now'), datetime('now')
    ),
    (
        5, 'score_100', 'صد امتیازی',
        'به امتیاز ۱۰۰ برس.',
        '💯', 'score', 100, 1, 50,
        datetime('now'), datetime('now')
    ),
    (
        6, 'score_1000', 'قهرمان امتیاز',
        'به امتیاز ۱۰۰۰ برس.',
        '🏆', 'score', 1000, 1, 60,
        datetime('now'), datetime('now')
    ),
    (
        7, 'level_5', 'سطح پنج',
        'به سطح ۵ برس.',
        '⭐', 'level', 5, 1, 70,
        datetime('now'), datetime('now')
    ),
    (
        8, 'correct_streak_5', 'پنج پاسخ پیاپی',
        '۵ پاسخ درست پیاپی ثبت کن.',
        '🔥', 'current_correct_streak', 5, 1, 80,
        datetime('now'), datetime('now')
    ),
    (
        9, 'daily_streak_3', 'سه روز فعال',
        'سه روز پیاپی در مسابقه شرکت کن.',
        '📅', 'daily_streak', 3, 1, 90,
        datetime('now'), datetime('now')
    ),
    (
        10, 'daily_streak_7', 'هفته طلایی',
        'هفت روز پیاپی در مسابقه شرکت کن.',
        '🌟', 'daily_streak', 7, 1, 100,
        datetime('now'), datetime('now')
    ),
    (
        11, 'math_correct_10', 'محاسب سریع',
        '۱۰ سؤال ریاضی را درست پاسخ بده.',
        '🧮', 'math_correct', 10, 1, 110,
        datetime('now'), datetime('now')
    ),
    (
        12, 'word_correct_10', 'واژه‌شناس',
        '۱۰ سؤال واژگان را درست پاسخ بده.',
        '📝', 'word_correct', 10, 1, 120,
        datetime('now'), datetime('now')
    );

INSERT INTO feature_flags (
    flag_key,
    enabled,
    rollout_percentage,
    description,
    updated_at,
    updated_by
) VALUES (
    'quiz_games',
    1,
    100,
    'Quiz, trivia, math, word games, daily challenge, scoring and leaderboards.',
    datetime('now'),
    'migration:013'
)
ON CONFLICT(flag_key) DO UPDATE SET
    enabled = 1,
    rollout_percentage = 100,
    description = excluded.description,
    updated_at = excluded.updated_at,
    updated_by = excluded.updated_by;
