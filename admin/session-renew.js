(() => {
  const config = document.getElementById('admin-session-config');
  if (!config) return;
  let lastActivity = Date.now(), lastAttempt = 0, busy = false, expired = false;
  const idleTimeout = Number(config.dataset.idleTimeout) * 1000;
  function notice(text) {
    let box = document.getElementById('admin-session-notice');
    if (!box) {
      box = document.createElement('p'); box.id = 'admin-session-notice'; box.setAttribute('role', 'alert');
      box.style.cssText = 'padding:12px;margin:12px;border:1px solid var(--border);background:var(--surface);';
      config.after(box);
    }
    box.replaceChildren(document.createTextNode(text + ' '));
    if (expired) {
      const link = document.createElement('a'); link.href = '/admin/login.php'; link.target = '_blank'; link.rel = 'noopener';
      link.textContent = 'Sign in in a new tab'; box.append(link);
    }
  }
  async function renew() {
    if (busy || expired || Date.now() - lastAttempt < 60000) return;
    const idle = Date.now() - lastActivity;
    if (idle >= idleTimeout) return;
    busy = true; lastAttempt = Date.now();
    // Pages that rotate CSRF after an operation keep their form token current.
    const csrf = document.querySelector('input[name="csrf_token"]')?.value || config.dataset.csrf;
    try {
      const response = await fetch('/admin/session-renew.php', {
        method: 'POST', headers: { Accept: 'application/json' }, credentials: 'same-origin',
        body: new URLSearchParams({ csrf_token: csrf, idle_seconds: String(Math.floor(idle / 1000)) }),
        signal: AbortSignal.timeout(10000),
      });
      const data = await response.json();
      if (response.status === 403) {
        expired = true;
        notice('Your session could not be renewed. Keep this page open to preserve unsaved work.');
      } else if (!response.ok || !data.ok) {
        notice('Session renewal is temporarily unavailable. Retrying automatically.');
      } else {
        config.dataset.csrf = data.csrfToken;
        document.getElementById('admin-session-notice')?.remove();
      }
    } catch { notice('Could not reach the server to renew your session. Retrying automatically.'); }
    finally { busy = false; }
  }
  function activity() { lastActivity = Date.now(); renew(); }
  for (const event of ['pointerdown', 'pointermove', 'keydown', 'scroll', 'touchstart']) {
    document.addEventListener(event, activity, { passive: true, capture: true });
  }
  document.addEventListener('visibilitychange', () => { if (!document.hidden) activity(); });
  window.addEventListener('focus', activity);
  setInterval(renew,60000);
  renew();
})();
