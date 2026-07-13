(() => {
    'use strict';

    const tg = window.Telegram?.WebApp ?? null;
    const page = document.getElementById('page');
    const app = document.getElementById('app');
    const nav = document.getElementById('navigation');
    const refreshButton = document.getElementById('refresh-button');
    const toastElement = document.getElementById('toast');
    const modalRoot = document.getElementById('modal-root');
    const userCaption = document.getElementById('user-caption');
    const appTitle = document.getElementById('app-title');

    const state = {
        csrf: '',
        user: null,
        settings: {},
        app: {},
        currentPage: 'dashboard',
        cache: new Map(),
        authDashboard: null,
        loading: false,
        toastTimer: null,
    };

    const pageMeta = {
        dashboard: ['داشبورد', 'نمای کلی ابزارهای شخصی'],
        reminders: ['یادآورهای تقویمی', 'ساخت، لغو و مشاهده برنامه زمانی'],
        alerts: ['هشدارهای هوشمند', 'آب‌وهوا، دما، باد و نرخ ارز'],
        subscriptions: ['اشتراک‌ها', 'گزارش روزانه، هفتگی و ماهانه'],
        monitors: ['مانیتورهای سایت', 'Uptime، وضعیت و زمان پاسخ'],
        favorites: ['علاقه‌مندی‌ها', 'ابزارهای پرکاربرد و پین‌شده'],
        shortcuts: ['میان‌برها', 'دستورهای شخصی با نام کوتاه'],
        cities: ['شهرهای منتخب', 'شهرهای ذخیره‌شده برای آب‌وهوا'],
        currencies: ['ارزهای منتخب', 'جفت‌ارزهای پرکاربرد'],
        countries: ['کشورهای ذخیره‌شده', 'دسترسی سریع به اطلاعات کشورها'],
        history: ['تاریخچه دستورات', 'آخرین استفاده‌های ثبت‌شده'],
        quiz: ['آزمون‌ها و امتیاز', 'Score، XP، Level و Achievement'],
        settings: ['تنظیمات شخصی', 'زبان، عدد، تاریخ و منطقه زمانی'],
        more: ['بخش‌های بیشتر', 'تمام قابلیت‌های Mini App'],
    };

    const apiActions = new Set([
        'dashboard', 'reminders', 'alerts', 'subscriptions', 'monitors',
        'favorites', 'shortcuts', 'cities', 'currencies', 'countries',
        'history', 'quiz', 'settings',
    ]);

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function asNumber(value, fallback = 0) {
        const number = Number(value);
        return Number.isFinite(number) ? number : fallback;
    }

    function formatNumber(value, maximumFractionDigits = 0) {
        const locale = state.settings.number_format === 'persian'
            ? 'fa-IR'
            : 'en-US';

        return new Intl.NumberFormat(locale, {
            maximumFractionDigits,
        }).format(asNumber(value));
    }

    function formatDate(timestamp, includeTime = true) {
        if (timestamp === null || timestamp === undefined || timestamp === '') {
            return '—';
        }

        let date;

        if (typeof timestamp === 'number' || /^\d+$/.test(String(timestamp))) {
            const numeric = Number(timestamp);
            date = new Date(numeric < 10_000_000_000 ? numeric * 1000 : numeric);
        } else {
            date = new Date(String(timestamp));
        }

        if (Number.isNaN(date.getTime())) {
            return escapeHtml(timestamp);
        }

        const locale = state.settings.date_format === 'local'
            ? 'fa-IR-u-ca-persian'
            : 'fa-IR';

        return new Intl.DateTimeFormat(locale, {
            year: 'numeric',
            month: 'short',
            day: '2-digit',
            ...(includeTime ? {
                hour: '2-digit',
                minute: '2-digit',
            } : {}),
        }).format(date);
    }

    function dateKey(timestamp) {
        const date = new Date(Number(timestamp) * 1000);

        if (Number.isNaN(date.getTime())) {
            return 'نامشخص';
        }

        return new Intl.DateTimeFormat('fa-IR', {
            year: 'numeric',
            month: 'long',
            day: '2-digit',
            weekday: 'long',
        }).format(date);
    }

    function statusBadge(status) {
        const value = String(status ?? 'unknown');
        const labels = {
            active: 'فعال',
            paused: 'متوقف',
            cancelled: 'لغوشده',
            pending: 'در انتظار',
            processing: 'در حال پردازش',
            sent: 'ارسال‌شده',
            failed: 'ناموفق',
            up: 'Up',
            down: 'Down',
            unknown: 'نامشخص',
            answered: 'پاسخ‌داده‌شده',
            expired: 'منقضی',
        };
        const css = ['active', 'sent', 'up', 'answered'].includes(value)
            ? 'success'
            : ['failed', 'down'].includes(value)
                ? 'danger'
                : ['pending', 'processing', 'paused'].includes(value)
                    ? 'warning'
                    : '';

        return `<span class="badge ${css}">${escapeHtml(labels[value] ?? value)}</span>`;
    }

    function pageHeader(name, description, action = '') {
        return `
            <div class="page-header">
                <div>
                    <h1>${escapeHtml(name)}</h1>
                    <p>${escapeHtml(description)}</p>
                </div>
                ${action}
            </div>
        `;
    }

    function emptyState(text) {
        return `<div class="empty-state">${escapeHtml(text)}</div>`;
    }

    function toast(message, isError = false) {
        clearTimeout(state.toastTimer);
        toastElement.textContent = message;
        toastElement.classList.toggle('error', isError);
        toastElement.classList.add('show');
        state.toastTimer = setTimeout(() => {
            toastElement.classList.remove('show');
        }, 3600);
    }

    function haptic(type = 'light') {
        try {
            if (type === 'error') {
                tg?.HapticFeedback?.notificationOccurred('error');
            } else if (type === 'success') {
                tg?.HapticFeedback?.notificationOccurred('success');
            } else {
                tg?.HapticFeedback?.impactOccurred(type);
            }
        } catch (_) {
        }
    }

    async function confirmAction(message) {
        if (tg?.showConfirm) {
            return new Promise(resolve => tg.showConfirm(message, resolve));
        }

        return window.confirm(message);
    }

    async function request(action, method = 'GET', data = null) {
        const options = {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
        };

        if (method === 'POST') {
            options.headers['Content-Type'] = 'application/json';

            if (state.csrf) {
                options.headers['X-CSRF-Token'] = state.csrf;
            }

            options.body = JSON.stringify(data ?? {});
        }

        const response = await fetch(`./api.php?action=${encodeURIComponent(action)}`, options);
        let payload;

        try {
            payload = await response.json();
        } catch (_) {
            throw new Error('پاسخ Backend قابل خواندن نیست.');
        }

        if (!response.ok || payload.ok !== true) {
            const error = new Error(payload?.error?.message ?? 'عملیات ناموفق بود.');
            error.code = payload?.error?.code ?? 'api_error';
            error.status = response.status;
            throw error;
        }

        return payload.data;
    }

    async function authenticate() {
        if (!tg || !tg.initData) {
            throw new Error('Mini App باید از داخل Telegram باز شود.');
        }

        const data = await request('auth', 'POST', {
            init_data: tg.initData,
        });

        state.csrf = data.csrf_token;
        state.user = data.user;
        state.settings = data.settings ?? {};
        state.app = data.app ?? {};
        state.authDashboard = data.dashboard ?? null;
        state.cache.clear();

        appTitle.textContent = state.app.name || 'جعبه ابزار';
        const fullName = [state.user.first_name, state.user.last_name]
            .filter(Boolean)
            .join(' ');
        userCaption.textContent = fullName || `کاربر ${state.user.telegram_id}`;
        app.setAttribute('aria-busy', 'false');
    }

    function renderFatal(message) {
        page.innerHTML = `
            <section class="fatal-card">
                <h1>ورود Mini App ممکن نشد</h1>
                <p>${escapeHtml(message)}</p>
                <button class="button" type="button" data-reload>تلاش دوباره</button>
            </section>
        `;
    }

    function renderLoading() {
        page.innerHTML = `
            <div class="skeleton"></div>
            <div class="skeleton"></div>
            <div class="skeleton"></div>
        `;
    }

    function setActiveNavigation(pageName) {
        nav.querySelectorAll('button').forEach(button => {
            const target = button.dataset.page;
            const active = target === pageName
                || (pageName !== 'dashboard'
                    && !['reminders', 'alerts', 'monitors'].includes(pageName)
                    && target === 'more');
            button.classList.toggle('active', active);
        });
    }

    async function navigate(pageName, force = false) {
        if (!pageMeta[pageName] || state.loading) {
            return;
        }

        state.currentPage = pageName;
        setActiveNavigation(pageName);
        window.scrollTo({ top: 0, behavior: 'smooth' });
        haptic();

        if (pageName === 'more') {
            renderMore();
            return;
        }

        state.loading = true;
        renderLoading();

        try {
            let data;

            if (pageName === 'dashboard' && state.authDashboard && !force) {
                data = state.authDashboard;
                state.authDashboard = null;
            } else if (!force && state.cache.has(pageName)) {
                data = state.cache.get(pageName);
            } else {
                data = await request(pageName);
                state.cache.set(pageName, data);
            }

            renderPage(pageName, data);
            page.focus({ preventScroll: true });
        } catch (error) {
            if (error.status === 401) {
                renderFatal('Session منقضی شده است؛ Mini App را ببند و دوباره باز کن.');
            } else {
                page.innerHTML = `
                    ${pageHeader('خطا', 'دریافت اطلاعات این بخش ناموفق بود')}
                    <section class="card">
                        <p>${escapeHtml(error.message)}</p>
                        <button class="button" type="button" data-refresh-page>تلاش دوباره</button>
                    </section>
                `;
            }
            toast(error.message, true);
            haptic('error');
        } finally {
            state.loading = false;
        }
    }

    function renderPage(pageName, data) {
        const renderers = {
            dashboard: renderDashboard,
            reminders: renderReminders,
            alerts: renderAlerts,
            subscriptions: renderSubscriptions,
            monitors: renderMonitors,
            favorites: renderFavorites,
            shortcuts: renderShortcuts,
            cities: renderCities,
            currencies: renderCurrencies,
            countries: renderCountries,
            history: renderHistory,
            quiz: renderQuiz,
            settings: renderSettings,
        };

        renderers[pageName](data);
    }

    function renderDashboard(data) {
        const profile = data.profile ?? {};
        const counts = data.counts ?? {};
        const quiz = data.quiz ?? {};
        const name = [profile.first_name, profile.last_name].filter(Boolean).join(' ');
        const initial = (profile.first_name || 'ج').slice(0, 1);
        const nextReminders = data.next_reminders ?? [];
        const favorites = data.favorites ?? [];

        page.innerHTML = `
            ${pageHeader('داشبورد', 'نمای یکپارچه همه ابزارهای شخصی')}
            <section class="card hero-card">
                <div class="hero-row">
                    <div>
                        <h2 class="hero-title">سلام ${escapeHtml(name || 'دوست من')} 👋</h2>
                        <p class="hero-subtitle">Level ${formatNumber(quiz.level ?? 1)} · ${formatNumber(quiz.score ?? 0)} امتیاز · ${formatNumber(quiz.xp ?? 0)} XP</p>
                    </div>
                    <div class="avatar">${escapeHtml(initial)}</div>
                </div>
            </section>

            <section class="stat-grid">
                ${dashboardStat('⏰ یادآور', counts.reminders)}
                ${dashboardStat('🔔 هشدار', counts.alerts)}
                ${dashboardStat('📡 مانیتور', counts.monitors, counts.monitors_down > 0)}
                ${dashboardStat('⭐ علاقه‌مندی', counts.favorites)}
                ${dashboardStat('📆 اشتراک', counts.subscriptions)}
                ${dashboardStat('⚡ میان‌بر', counts.shortcuts)}
                ${dashboardStat('🎯 پاسخ صحیح', quiz.correct_answers)}
                ${dashboardStat('🔥 Streak', quiz.daily_streak)}
            </section>

            <div class="section-title"><h2>دسترسی سریع</h2></div>
            <section class="quick-grid">
                ${quickButton('⏰', 'یادآور جدید', 'reminders')}
                ${quickButton('🔔', 'هشدار جدید', 'alerts')}
                ${quickButton('📡', 'مانیتور سایت', 'monitors')}
                ${quickButton('🎯', 'امتیاز آزمون', 'quiz')}
            </section>

            <div class="section-title"><h2>یادآورهای بعدی</h2><button class="button small secondary" data-nav="reminders">همه</button></div>
            <section class="card">
                ${nextReminders.length
                    ? `<div class="list">${nextReminders.map(reminderItem).join('')}</div>`
                    : emptyState('یادآور فعالی نداری.')}
            </section>

            <div class="section-title"><h2>علاقه‌مندی‌های پین‌شده</h2><button class="button small secondary" data-nav="favorites">مدیریت</button></div>
            <section class="card">
                ${favorites.length
                    ? `<div class="list">${favorites.map(favoriteItem).join('')}</div>`
                    : emptyState('هنوز علاقه‌مندی ذخیره نکرده‌ای.')}
            </section>
        `;
    }

    function dashboardStat(label, value, danger = false) {
        const displayValue = typeof value === 'string'
            ? value
            : formatNumber(value ?? 0);

        return `
            <article class="stat-card">
                <span>${escapeHtml(label)}</span>
                <strong ${danger ? 'style="color:var(--danger)"' : ''}>${escapeHtml(displayValue)}</strong>
            </article>
        `;
    }

    function quickButton(icon, label, target) {
        return `
            <button type="button" class="quick-button" data-nav="${escapeHtml(target)}">
                <span>${icon}</span>
                <strong>${escapeHtml(label)}</strong>
            </button>
        `;
    }

    function reminderItem(row) {
        const active = ['pending', 'processing'].includes(String(row.status));
        return `
            <article class="list-item">
                <div class="list-head">
                    <h3 class="list-title">${escapeHtml(row.reminder_text)}</h3>
                    ${statusBadge(row.status)}
                </div>
                <div class="list-meta">
                    <span>🗓 ${formatDate(row.scheduled_at)}</span>
                    <span>🌐 ${escapeHtml(row.timezone)}</span>
                </div>
                ${active ? `
                    <div class="actions">
                        <button class="button small danger" data-api-action="reminder.cancel" data-id="${asNumber(row.id)}">لغو</button>
                    </div>
                ` : `
                    <div class="actions">
                        <button class="button small danger" data-api-action="reminder.delete" data-id="${asNumber(row.id)}">حذف تاریخچه</button>
                    </div>
                `}
            </article>
        `;
    }

    function renderReminders(data) {
        const items = data.items ?? [];
        const grouped = new Map();

        items.forEach(item => {
            const key = dateKey(item.scheduled_at);
            if (!grouped.has(key)) grouped.set(key, []);
            grouped.get(key).push(item);
        });

        const timezone = state.settings.timezone || 'Asia/Tehran';
        const minimum = new Date(Date.now() + 60_000).toISOString().slice(0, 16);

        page.innerHTML = `
            ${pageHeader(...pageMeta.reminders)}
            <section class="card">
                <h2>یادآور جدید</h2>
                <form data-form="reminder.create" class="form-grid">
                    <label>متن یادآور
                        <textarea name="text" maxlength="1000" required placeholder="پرداخت قبض، تماس، جلسه…"></textarea>
                    </label>
                    <div class="form-grid two">
                        <label>تاریخ و ساعت دستگاه
                            <input type="datetime-local" name="date_time" min="${minimum}" required>
                        </label>
                        <label>منطقه زمانی
                            <input name="timezone" value="${escapeHtml(timezone)}" required>
                        </label>
                    </div>
                    <button class="button" type="submit">ذخیره یادآور</button>
                </form>
            </section>
            ${items.length
                ? [...grouped.entries()].map(([key, rows]) => `
                    <section class="calendar-group">
                        <div class="calendar-date">${escapeHtml(key)}</div>
                        <div class="list">${rows.map(reminderItem).join('')}</div>
                    </section>
                `).join('')
                : `<section class="card">${emptyState('یادآوری برای نمایش وجود ندارد.')}</section>`}
        `;
    }

    function renderAlerts(data) {
        const items = data.items ?? [];
        page.innerHTML = `
            ${pageHeader(...pageMeta.alerts)}
            <section class="card">
                <h2>هشدار جدید</h2>
                <form data-form="alert.create" class="form-grid">
                    <div class="form-grid two">
                        <label>نوع هشدار
                            <select name="alert_type" required>
                                <option value="weather_condition">شرایط آب‌وهوا</option>
                                <option value="temperature">دما</option>
                                <option value="wind">باد</option>
                                <option value="currency">نرخ ارز</option>
                            </select>
                        </label>
                        <label>عملگر
                            <select name="operator" required>
                                <option value="above">بالاتر از</option>
                                <option value="below">پایین‌تر از</option>
                                <option value="equals">برابر</option>
                                <option value="changes">تغییر</option>
                                <option value="contains">شامل</option>
                                <option value="starts">شروع شرایط</option>
                                <option value="stops">پایان شرایط</option>
                            </select>
                        </label>
                        <label>موضوع؛ شهر یا ارز مبدا
                            <input name="subject" maxlength="200" required placeholder="Tehran یا USD">
                        </label>
                        <label>موضوع دوم؛ فقط ارز مقصد
                            <input name="secondary_subject" maxlength="20" placeholder="EUR">
                        </label>
                        <label>مقدار عددی
                            <input name="threshold_value" type="number" step="any" placeholder="0.90 یا 40">
                        </label>
                        <label>شرط متنی
                            <input name="comparison_value" maxlength="100" placeholder="rain یا snow">
                        </label>
                    </div>
                    <button class="button" type="submit">ساخت هشدار</button>
                </form>
            </section>
            <section class="list">
                ${items.length ? items.map(alertItem).join('') : emptyState('هشدار فعالی وجود ندارد.')}
            </section>
        `;
    }

    function alertItem(row) {
        const subject = row.secondary_subject
            ? `${row.subject} → ${row.secondary_subject}`
            : row.subject;
        const value = row.threshold_value ?? row.comparison_value ?? '—';
        return `
            <article class="list-item">
                <div class="list-head">
                    <h3 class="list-title">${escapeHtml(subject)}</h3>
                    ${statusBadge(row.status)}
                </div>
                <div class="list-meta">
                    <span>${escapeHtml(row.alert_type)}</span>
                    <span>${escapeHtml(row.operator)} ${escapeHtml(value)}</span>
                    <span>آخرین مقدار: ${escapeHtml(row.last_observed_value ?? '—')}</span>
                    <span>بررسی بعدی: ${formatDate(row.next_check_at)}</span>
                </div>
                ${statusActions('alert', row)}
            </article>
        `;
    }

    function renderSubscriptions(data) {
        const items = data.items ?? [];
        const timezone = state.settings.timezone || 'Asia/Tehran';
        page.innerHTML = `
            ${pageHeader(...pageMeta.subscriptions)}
            <section class="card">
                <h2>اشتراک جدید</h2>
                <form data-form="subscription.create" class="form-grid">
                    <div class="form-grid two">
                        <label>نوع گزارش
                            <select name="subscription_type">
                                <option value="weather">آب‌وهوا</option>
                                <option value="country">کشور</option>
                            </select>
                        </label>
                        <label>موضوع
                            <input name="subject" maxlength="200" required placeholder="Tehran یا Iran">
                        </label>
                        <label>تناوب
                            <select name="frequency">
                                <option value="daily">روزانه</option>
                                <option value="weekly">هفتگی</option>
                                <option value="monthly">ماهانه</option>
                            </select>
                        </label>
                        <label>ساعت
                            <input name="schedule_time" type="time" value="08:00" required>
                        </label>
                        <label>روز هفته؛ 0 یکشنبه تا 6 شنبه
                            <input name="weekday" type="number" min="0" max="6" value="6">
                        </label>
                        <label>روز ماه
                            <input name="month_day" type="number" min="1" max="31" value="1">
                        </label>
                        <label>منطقه زمانی
                            <input name="timezone" value="${escapeHtml(timezone)}" required>
                        </label>
                    </div>
                    <button class="button" type="submit">ساخت اشتراک</button>
                </form>
            </section>
            <section class="list">
                ${items.length ? items.map(subscriptionItem).join('') : emptyState('اشتراک فعالی وجود ندارد.')}
            </section>
        `;
    }

    function subscriptionItem(row) {
        return `
            <article class="list-item">
                <div class="list-head">
                    <h3 class="list-title">${escapeHtml(row.subject)}</h3>
                    ${statusBadge(row.status)}
                </div>
                <div class="list-meta">
                    <span>${escapeHtml(row.subscription_type)}</span>
                    <span>${escapeHtml(row.frequency)} · ${escapeHtml(row.schedule_time)}</span>
                    <span>اجرای بعدی: ${formatDate(row.next_run_at)}</span>
                    <span>${escapeHtml(row.timezone)}</span>
                </div>
                ${statusActions('subscription', row)}
            </article>
        `;
    }

    function renderMonitors(data) {
        const items = data.items ?? [];
        const timezone = state.settings.timezone || 'Asia/Tehran';
        page.innerHTML = `
            ${pageHeader(...pageMeta.monitors)}
            <section class="card">
                <h2>مانیتور جدید</h2>
                <form data-form="monitor.create" class="form-grid">
                    <label>آدرس کامل سایت
                        <input type="url" name="url" maxlength="2000" required placeholder="https://example.com">
                    </label>
                    <div class="form-grid two">
                        <label>فاصله بررسی
                            <select name="interval_seconds">
                                <option value="300">۵ دقیقه</option>
                                <option value="600">۱۰ دقیقه</option>
                                <option value="900">۱۵ دقیقه</option>
                                <option value="1800">۳۰ دقیقه</option>
                                <option value="3600">۱ ساعت</option>
                            </select>
                        </label>
                        <label>منطقه زمانی
                            <input name="timezone" value="${escapeHtml(timezone)}" required>
                        </label>
                    </div>
                    <button class="button" type="submit">افزودن مانیتور</button>
                </form>
            </section>
            <section class="list">
                ${items.length ? items.map(monitorItem).join('') : emptyState('مانیتوری برای نمایش وجود ندارد.')}
            </section>
        `;
    }

    function monitorItem(row) {
        const uptime = row.uptime_percent_30d === null
            ? '—'
            : `${formatNumber(row.uptime_percent_30d, 2)}%`;
        return `
            <article class="list-item">
                <div class="list-head">
                    <h3 class="list-title" dir="ltr">${escapeHtml(row.url)}</h3>
                    ${statusBadge(row.last_state)}
                </div>
                <div class="list-meta">
                    <span>وضعیت مانیتور: ${escapeHtml(row.status)}</span>
                    <span>HTTP: ${escapeHtml(row.last_status_code ?? '—')}</span>
                    <span>Response: ${escapeHtml(row.last_response_ms ?? '—')} ms</span>
                    <span>Uptime 30d: ${uptime}</span>
                    <span>آخرین بررسی: ${formatDate(row.last_checked_at)}</span>
                </div>
                ${statusActions('monitor', row)}
            </article>
        `;
    }

    function statusActions(resource, row) {
        const status = String(row.status);
        if (status === 'cancelled') return '';
        return `
            <div class="actions">
                ${status === 'active'
                    ? `<button class="button small secondary" data-api-action="${resource}.status" data-id="${asNumber(row.id)}" data-status="paused">توقف</button>`
                    : `<button class="button small" data-api-action="${resource}.status" data-id="${asNumber(row.id)}" data-status="active">ادامه</button>`}
                <button class="button small danger" data-api-action="${resource}.status" data-id="${asNumber(row.id)}" data-status="cancelled">لغو</button>
            </div>
        `;
    }

    function renderFavorites(data) {
        const items = data.items ?? [];
        page.innerHTML = `
            ${pageHeader(...pageMeta.favorites)}
            <section class="card">
                <h2>افزودن علاقه‌مندی</h2>
                <form data-form="favorite.create" class="form-grid">
                    <div class="form-grid two">
                        <label>نوع
                            <select name="type">
                                <option value="weather">آب‌وهوا</option>
                                <option value="currency">ارز</option>
                                <option value="country">کشور</option>
                                <option value="wiki">ویکی</option>
                                <option value="github">GitHub</option>
                                <option value="calc">محاسبه</option>
                            </select>
                        </label>
                        <label>مقدار
                            <input name="value" maxlength="500" required placeholder="Tehran یا php/php-src">
                        </label>
                    </div>
                    <button class="button" type="submit">ذخیره</button>
                </form>
            </section>
            <section class="list">
                ${items.length ? items.map(favoriteItem).join('') : emptyState('علاقه‌مندی ذخیره‌شده‌ای وجود ندارد.')}
            </section>
        `;
    }

    function favoriteItem(row) {
        return `
            <article class="list-item">
                <div class="list-head">
                    <h3 class="list-title">${escapeHtml(row.label)}</h3>
                    ${asNumber(row.is_pinned) === 1 ? '<span class="badge success">پین‌شده</span>' : ''}
                </div>
                <code class="code">/${escapeHtml(row.command_text)}</code>
                <div class="actions">
                    <button class="button small secondary" data-copy="/${escapeHtml(row.command_text)}">کپی دستور</button>
                    <button class="button small secondary" data-api-action="favorite.pin" data-id="${asNumber(row.id)}" data-pinned="${asNumber(row.is_pinned) === 1 ? '0' : '1'}">${asNumber(row.is_pinned) === 1 ? 'برداشتن پین' : 'پین'}</button>
                    <button class="button small danger" data-api-action="favorite.delete" data-id="${asNumber(row.id)}">حذف</button>
                </div>
            </article>
        `;
    }

    function renderShortcuts(data) {
        const items = data.items ?? [];
        page.innerHTML = `
            ${pageHeader(...pageMeta.shortcuts)}
            <section class="card">
                <h2>میان‌بر جدید</h2>
                <form data-form="shortcut.save" class="form-grid">
                    <div class="form-grid two">
                        <label>نام انگلیسی
                            <input name="name" maxlength="32" pattern="[a-z][a-z0-9_]{2,31}" required placeholder="officeweather">
                        </label>
                        <label>دستور هدف بدون / ابتدا
                            <input name="command" maxlength="1000" required placeholder="weather Tehran">
                        </label>
                    </div>
                    <button class="button" type="submit">ذخیره میان‌بر</button>
                </form>
            </section>
            <section class="list">
                ${items.length ? items.map(shortcutItem).join('') : emptyState('میان‌بری ذخیره نشده است.')}
            </section>
        `;
    }

    function shortcutItem(row) {
        return `
            <article class="list-item">
                <div class="list-head">
                    <h3 class="list-title">/${escapeHtml(row.shortcut_name)}</h3>
                    <span class="badge">میان‌بر</span>
                </div>
                <code class="code">/${escapeHtml(row.command_text)}</code>
                <div class="actions">
                    <button class="button small secondary" data-copy="/${escapeHtml(row.shortcut_name)}">کپی</button>
                    <button class="button small danger" data-api-action="shortcut.delete" data-name="${escapeHtml(row.shortcut_name)}">حذف</button>
                </div>
            </article>
        `;
    }

    function renderCities(data) {
        renderSelectedList({
            title: pageMeta.cities,
            formAction: 'city.save',
            fieldName: 'city',
            placeholder: 'Tehran یا شیراز',
            button: 'ذخیره شهر',
            items: data.items ?? [],
        });
    }

    function renderCountries(data) {
        renderSelectedList({
            title: pageMeta.countries,
            formAction: 'country.save',
            fieldName: 'country',
            placeholder: 'Iran یا Japan',
            button: 'ذخیره کشور',
            items: data.items ?? [],
        });
    }

    function renderSelectedList(config) {
        page.innerHTML = `
            ${pageHeader(...config.title)}
            <section class="card">
                <form data-form="${escapeHtml(config.formAction)}" class="form-grid">
                    <label>نام
                        <input name="${escapeHtml(config.fieldName)}" maxlength="150" required placeholder="${escapeHtml(config.placeholder)}">
                    </label>
                    <button class="button" type="submit">${escapeHtml(config.button)}</button>
                </form>
            </section>
            <section class="list">
                ${config.items.length ? config.items.map(favoriteItem).join('') : emptyState('مورد ذخیره‌شده‌ای وجود ندارد.')}
            </section>
        `;
    }

    function renderCurrencies(data) {
        const items = data.items ?? [];
        page.innerHTML = `
            ${pageHeader(...pageMeta.currencies)}
            <section class="card">
                <form data-form="currency.save" class="form-grid">
                    <div class="form-grid two">
                        <label>ارز مبدا
                            <input name="base" maxlength="3" pattern="[A-Za-z]{3}" required value="USD">
                        </label>
                        <label>ارز مقصد
                            <input name="quote" maxlength="3" pattern="[A-Za-z]{3}" required value="EUR">
                        </label>
                    </div>
                    <button class="button" type="submit">ذخیره جفت‌ارز</button>
                </form>
            </section>
            <section class="list">
                ${items.length ? items.map(favoriteItem).join('') : emptyState('جفت‌ارزی ذخیره نشده است.')}
            </section>
        `;
    }

    function renderHistory(data) {
        const items = data.items ?? [];
        page.innerHTML = `
            ${pageHeader(...pageMeta.history, `
                <button class="button small danger" data-api-action="history.clear">پاک‌کردن</button>
            `)}
            <section class="list">
                ${items.length ? items.map(row => `
                    <article class="list-item">
                        <div class="list-head">
                            <h3 class="list-title">/${escapeHtml(row.command)}</h3>
                            ${asNumber(row.success) === 1 ? '<span class="badge success">موفق</span>' : '<span class="badge danger">خطا</span>'}
                        </div>
                        <div class="list-meta">
                            <span>${escapeHtml(row.module)}</span>
                            <span>${escapeHtml(row.source)}</span>
                            <span>${formatNumber(row.duration_ms, 1)} ms</span>
                            <span>${formatDate(row.created_at)}</span>
                        </div>
                        ${row.arguments_preview ? `<code class="code">${escapeHtml(row.arguments_preview)}</code>` : ''}
                    </article>
                `).join('') : emptyState('تاریخچه‌ای ثبت نشده است.')}
            </section>
        `;
    }

    function renderQuiz(data) {
        const score = data.score ?? {};
        const achievements = data.achievements ?? [];
        const recent = data.recent_sessions ?? [];
        const total = asNumber(score.total_answers);
        const correct = asNumber(score.correct_answers);
        const accuracy = total ? correct / total * 100 : 0;

        page.innerHTML = `
            ${pageHeader(...pageMeta.quiz, `
                <button class="button small" data-open-bot>بازکردن ربات</button>
            `)}
            <section class="card hero-card">
                <div class="hero-row">
                    <div>
                        <small>رتبه جهانی</small>
                        <h2 class="hero-title">#${formatNumber(data.global_rank ?? 1)}</h2>
                        <p class="hero-subtitle">${formatNumber(score.score)} امتیاز · ${formatNumber(score.xp)} XP</p>
                    </div>
                    <div class="avatar">${formatNumber(score.level ?? 1)}</div>
                </div>
            </section>
            <section class="stat-grid">
                ${dashboardStat('Level', score.level)}
                ${dashboardStat('پاسخ‌ها', total)}
                ${dashboardStat('دقت', `${formatNumber(accuracy, 1)}%`)}
                ${dashboardStat('Streak روزانه', score.daily_streak)}
            </section>
            <div class="section-title"><h2>Achievementها</h2></div>
            <section class="card">
                <div class="list">
                    ${achievements.length ? achievements.map(item => {
                        const unlocked = Boolean(item.unlocked_at);
                        const current = Math.min(asNumber(score[item.metric]), asNumber(item.threshold));
                        const percent = Math.min(100, asNumber(item.threshold) ? current / asNumber(item.threshold) * 100 : 0);
                        return `
                            <article class="list-item">
                                <div class="list-head">
                                    <h3 class="list-title">${escapeHtml(item.icon)} ${escapeHtml(item.name)}</h3>
                                    ${unlocked ? '<span class="badge success">بازشده</span>' : '<span class="badge">قفل</span>'}
                                </div>
                                <p class="muted">${escapeHtml(item.description)}</p>
                                <div class="progress"><span style="width:${percent}%"></span></div>
                                <div class="list-meta"><span>${formatNumber(current)} / ${formatNumber(item.threshold)}</span></div>
                            </article>
                        `;
                    }).join('') : emptyState('Achievement فعالی وجود ندارد.')}
                </div>
            </section>
            <div class="section-title"><h2>آخرین آزمون‌ها</h2></div>
            <section class="list">
                ${recent.length ? recent.map(item => `
                    <article class="list-item">
                        <div class="list-head">
                            <h3 class="list-title">${escapeHtml(item.question_text)}</h3>
                            ${item.is_correct === null ? statusBadge(item.status) : (asNumber(item.is_correct) === 1 ? '<span class="badge success">درست</span>' : '<span class="badge danger">غلط</span>')}
                        </div>
                        <div class="list-meta">
                            <span>${escapeHtml(item.mode)}</span>
                            <span>${escapeHtml(item.difficulty)}</span>
                            <span>+${formatNumber(item.score_awarded)} امتیاز</span>
                            <span>${formatDate(item.answered_at ?? item.started_at)}</span>
                        </div>
                    </article>
                `).join('') : emptyState('هنوز در آزمونی شرکت نکرده‌ای.')}
            </section>
        `;
    }

    function renderSettings(data) {
        const settings = data.settings ?? {};
        state.settings = { ...state.settings, ...settings };
        page.innerHTML = `
            ${pageHeader(...pageMeta.settings)}
            <section class="card">
                <form data-form="settings.update" class="form-grid">
                    <div class="form-grid two">
                        <label>منطقه زمانی
                            <input name="timezone" value="${escapeHtml(settings.timezone ?? 'Asia/Tehran')}" required>
                        </label>
                        <label>زبان خروجی
                            <select name="output_language">
                                <option value="fa" ${settings.output_language === 'fa' ? 'selected' : ''}>فارسی</option>
                                <option value="en" ${settings.output_language === 'en' ? 'selected' : ''}>English</option>
                            </select>
                        </label>
                        <label>قالب عدد
                            <select name="number_format">
                                <option value="latin" ${settings.number_format === 'latin' ? 'selected' : ''}>لاتین</option>
                                <option value="persian" ${settings.number_format === 'persian' ? 'selected' : ''}>فارسی</option>
                            </select>
                        </label>
                        <label>قالب تاریخ
                            <select name="date_format">
                                <option value="iso" ${settings.date_format === 'iso' ? 'selected' : ''}>میلادی</option>
                                <option value="local" ${settings.date_format === 'local' ? 'selected' : ''}>محلی / شمسی</option>
                            </select>
                        </label>
                    </div>
                    <label>ترتیب منوی ربات
                        <textarea name="menu_order" maxlength="1000" placeholder="default یا weather,currency,...">${escapeHtml(settings.menu_order ?? 'default')}</textarea>
                    </label>
                    <button class="button" type="submit">ذخیره تنظیمات</button>
                </form>
            </section>
            <section class="card">
                <h2>امنیت Session</h2>
                <p class="muted">Session از initData امضاشده ساخته شده، Cookie آن HttpOnly و SameSite است و عملیات تغییردهنده CSRF دارند.</p>
                <button class="button danger" type="button" data-logout>خروج از Mini App</button>
            </section>
        `;
    }

    function renderMore() {
        page.innerHTML = `
            ${pageHeader(...pageMeta.more)}
            <section class="more-grid">
                ${moreButton('📆', 'اشتراک‌ها', 'subscriptions')}
                ${moreButton('⭐', 'علاقه‌مندی‌ها', 'favorites')}
                ${moreButton('⚡', 'میان‌برها', 'shortcuts')}
                ${moreButton('🌤', 'شهرها', 'cities')}
                ${moreButton('💱', 'ارزها', 'currencies')}
                ${moreButton('🌍', 'کشورها', 'countries')}
                ${moreButton('🧾', 'تاریخچه', 'history')}
                ${moreButton('🎯', 'آزمون و امتیاز', 'quiz')}
                ${moreButton('⚙️', 'تنظیمات شخصی', 'settings')}
            </section>
            <section class="card" style="margin-top:14px">
                <h2>ربات تلگرام</h2>
                <p class="muted">برای اجرای دستورها، آزمون جدید یا ابزارهایی که هنوز رابط گرافیکی ندارند، چت ربات را باز کن.</p>
                <button class="button" type="button" data-open-bot>بازکردن @${escapeHtml(state.app.bot_username ?? 'SmartToolboxFaBot')}</button>
            </section>
        `;
    }

    function moreButton(icon, label, target) {
        return `
            <button class="more-button" type="button" data-nav="${escapeHtml(target)}">
                <span>${icon}</span>
                <strong>${escapeHtml(label)}</strong>
            </button>
        `;
    }

    async function mutate(action, payload, confirmMessage = null) {
        if (confirmMessage && !(await confirmAction(confirmMessage))) {
            return null;
        }

        try {
            state.loading = true;
            const result = await request(action, 'POST', payload);
            state.cache.clear();
            state.authDashboard = null;
            toast('عملیات با موفقیت انجام شد.');
            haptic('success');
            state.loading = false;
            await navigate(state.currentPage, true);
            return result;
        } catch (error) {
            toast(error.message, true);
            haptic('error');
            return null;
        } finally {
            state.loading = false;
        }
    }

    function formPayload(form) {
        const data = {};
        new FormData(form).forEach((value, key) => {
            data[key] = typeof value === 'string' ? value.trim() : value;
        });
        return data;
    }

    async function handleForm(form) {
        const action = form.dataset.form;
        const data = formPayload(form);

        if (action === 'reminder.create') {
            const timestamp = Math.floor(new Date(data.date_time).getTime() / 1000);
            if (!Number.isFinite(timestamp)) {
                toast('تاریخ و ساعت معتبر نیست.', true);
                return;
            }
            delete data.date_time;
            data.scheduled_at = timestamp;
        }

        if (action === 'settings.update') {
            await mutate(action, { settings: data });
            return;
        }

        const result = await mutate(action, data);

        if (result !== null) {
            form.reset();
        }
    }

    async function handleApiButton(button) {
        const action = button.dataset.apiAction;
        const payload = {};

        ['id', 'status', 'name', 'pinned'].forEach(key => {
            if (button.dataset[key] !== undefined) {
                payload[key] = button.dataset[key];
            }
        });

        const destructive = action.endsWith('.delete')
            || action === 'history.clear'
            || payload.status === 'cancelled';

        await mutate(
            action,
            payload,
            destructive ? 'این عملیات قابل بازگشت نیست. ادامه می‌دهی؟' : null
        );
    }

    async function copyText(text) {
        try {
            await navigator.clipboard.writeText(text);
            toast('در کلیپ‌بورد کپی شد.');
            haptic('success');
        } catch (_) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            textarea.remove();
            toast('کپی شد.');
        }
    }

    function openBot() {
        const username = state.app.bot_username || 'SmartToolboxFaBot';
        const url = `https://t.me/${username}`;
        if (tg?.openTelegramLink) tg.openTelegramLink(url);
        else window.location.href = url;
    }

    nav.addEventListener('click', event => {
        const button = event.target.closest('button[data-page]');
        if (button) navigate(button.dataset.page);
    });

    page.addEventListener('click', async event => {
        const navButton = event.target.closest('[data-nav]');
        if (navButton) {
            await navigate(navButton.dataset.nav);
            return;
        }

        const apiButton = event.target.closest('[data-api-action]');
        if (apiButton) {
            await handleApiButton(apiButton);
            return;
        }

        const copyButton = event.target.closest('[data-copy]');
        if (copyButton) {
            await copyText(copyButton.dataset.copy);
            return;
        }

        if (event.target.closest('[data-open-bot]')) {
            openBot();
            return;
        }

        if (event.target.closest('[data-refresh-page]')) {
            await navigate(state.currentPage, true);
            return;
        }

        if (event.target.closest('[data-reload]')) {
            window.location.reload();
            return;
        }

        if (event.target.closest('[data-logout]')) {
            if (await confirmAction('از Session Mini App خارج می‌شوی؟')) {
                try {
                    await request('logout', 'POST', {});
                } catch (_) {
                }
                state.csrf = '';
                tg?.close?.();
                renderFatal('Session بسته شد. Mini App را دوباره باز کن.');
            }
        }
    });

    page.addEventListener('submit', async event => {
        const form = event.target.closest('form[data-form]');
        if (!form) return;
        event.preventDefault();
        const submit = form.querySelector('[type="submit"]');
        if (submit) submit.disabled = true;
        try {
            await handleForm(form);
        } finally {
            if (submit) submit.disabled = false;
        }
    });

    refreshButton.addEventListener('click', async () => {
        refreshButton.classList.add('rotating');
        try {
            state.cache.clear();
            state.authDashboard = null;
            await navigate(state.currentPage, true);
        } finally {
            refreshButton.classList.remove('rotating');
        }
    });

    window.addEventListener('unhandledrejection', event => {
        console.error(event.reason);
    });

    async function boot() {
        try {
            tg?.ready?.();
            tg?.expand?.();
            tg?.enableClosingConfirmation?.();
            await authenticate();
            await navigate('dashboard');
        } catch (error) {
            console.error(error);
            renderFatal(error.message || 'ورود Mini App ناموفق بود.');
            toast(error.message || 'ورود ناموفق بود.', true);
            haptic('error');
        }
    }

    boot();
})();
