const app = document.querySelector('[data-booking-app]');

if (app) {
    const errorSummary = document.querySelector('[data-error-summary]');
    if (errorSummary instanceof HTMLElement) {
        errorSummary.focus();
    }

    document.querySelectorAll('form[data-disable-submit]').forEach((form) => {
        let submitting = false;
        const disableSubmit = () => {
            form.setAttribute('aria-busy', 'true');
            form.querySelectorAll('button[type="submit"]').forEach((button) => {
                button.disabled = true;
                button.setAttribute('aria-disabled', 'true');
            });
        };
        form.addEventListener('click', (event) => {
            if (!(event.target instanceof HTMLButtonElement) || event.target.type !== 'submit') return;
            if (submitting) {
                event.preventDefault();
                event.stopImmediatePropagation();
                return;
            }
            if (!form.checkValidity()) return;
            submitting = true;
            window.setTimeout(disableSubmit, 0);
        }, true);
        form.addEventListener('submit', () => {
            if (submitting) {
                disableSubmit();
                return;
            }
            submitting = true;
            disableSubmit();
        });
    });

    const countdown = document.querySelector('[data-countdown]');
    if (countdown instanceof HTMLElement) {
        const output = countdown.querySelector('[data-countdown-output]');
        const warning = document.querySelector('[data-timeout-warning]');
        const expiresAt = Date.parse(countdown.dataset.countdown ?? '');
        const warningSeconds = Number(countdown.dataset.warningSeconds ?? 300);
        let warningShown = false;
        const updateCountdown = () => {
            const seconds = Math.max(0, Math.floor((expiresAt - Date.now()) / 1000));
            const minutes = Math.floor(seconds / 60);
            const remainder = seconds % 60;
            if (output) output.textContent = `${minutes}:${String(remainder).padStart(2, '0')}`;
            if (!warningShown && seconds <= warningSeconds && warning instanceof HTMLElement) {
                warning.hidden = false;
                warningShown = true;
            }
            if (seconds > 0) window.setTimeout(updateCountdown, 1000);
        };
        if (Number.isFinite(expiresAt)) updateCountdown();
    }

    const pollRoot = document.querySelector('[data-status-poll]');
    if (pollRoot instanceof HTMLElement) {
        const live = pollRoot.querySelector('[data-status-live]');
        const delays = [2000, 4000, 8000, 15000, 30000, 30000, 30000, 30000];
        let attempt = 0;
        const announce = (message) => { if (live) live.textContent = message; };
        const poll = async () => {
            if (!navigator.onLine) {
                announce(live?.dataset.offline ?? 'Offline');
                return;
            }
            announce(live?.dataset.checking ?? 'Checking status');
            try {
                const response = await fetch(pollRoot.dataset.statusPoll, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
                if (response.ok) {
                    const status = await response.json();
                    if (status.state !== pollRoot.dataset.initialState) {
                        announce(live?.dataset.changed ?? 'Status changed');
                        window.location.reload();
                        return;
                    }
                }
            } catch {
                announce(live?.dataset.offline ?? 'Offline');
            }
            if (attempt < delays.length) window.setTimeout(poll, delays[attempt++]);
        };
        window.setTimeout(poll, delays[attempt++]);
        window.addEventListener('online', poll, { once: true });
        window.addEventListener('offline', () => announce(live?.dataset.offline ?? 'Offline'));
    }

    const consentKey = 'inn_analytics_consent_v1';
    const consent = document.querySelector('[data-analytics-consent]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    const analyticsEvent = document.querySelector('[data-analytics-event]')?.getAttribute('data-analytics-event');
    const sendAnalytics = (event) => {
        if (!event || !app.dataset.analyticsUrl || window.localStorage.getItem(consentKey) !== 'accepted') return;
        fetch(app.dataset.analyticsUrl, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ event, locale: app.dataset.locale }),
        }).catch(() => undefined);
    };
    let analyticsChoice = null;
    try { analyticsChoice = window.localStorage.getItem(consentKey); } catch { analyticsChoice = 'declined'; }
    if (!analyticsChoice && consent instanceof HTMLElement) consent.hidden = false;
    if (analyticsChoice === 'accepted') sendAnalytics(analyticsEvent);
    consent?.querySelectorAll('[data-analytics-choice]').forEach((button) => {
        button.addEventListener('click', () => {
            const choice = button.getAttribute('data-analytics-choice') === 'accepted' ? 'accepted' : 'declined';
            try { window.localStorage.setItem(consentKey, choice); } catch { /* Storage may be unavailable. */ }
            consent.hidden = true;
            if (choice === 'accepted') sendAnalytics(analyticsEvent);
        });
    });
    document.querySelectorAll('[data-analytics-submit]').forEach((form) => {
        form.addEventListener('submit', () => sendAnalytics(form.getAttribute('data-analytics-submit')));
    });
}
