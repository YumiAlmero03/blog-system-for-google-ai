(() => {
  const output = document.getElementById('ph-recheck-status');
  const controls = [...document.querySelectorAll('[data-recheck-ph]')];
  if (!output || !controls.length) return;
  let running = false;
  const pending = new Map();
  controls.forEach(button => button.addEventListener('click', async () => {
    if (running) return;
    running = true;
    controls.forEach(control => { control.disabled = true; });
    output.replaceChildren();
    const text = document.createElement('span');
    output.append(text);
    const slotId = button.dataset.recheckPh;
    const key = slotId || 'catalogue';
    const progress = pending.get(key) || {
      totals: { processed: 0, checked: 0, changed: 0, available: 0, restricted: 0, unpublished: 0, unknown: 0 },
      afterId: 0, maxId: null, warning: '',
    };
    pending.set(key, progress);
    const totals = progress.totals;
    text.textContent = slotId ? 'Checking this game for PH availability…' : `${totals.processed ? 'Resuming' : 'Rechecking'} the catalogue for PH availability…`;
    try {
      do {
        const body = new URLSearchParams({ csrf_token: document.getElementById('admin-session-config')?.dataset.csrf || '' });
        if (slotId) body.set('slot_id', slotId);
        else {
          body.set('after_id', String(progress.afterId));
          if (progress.maxId !== null) body.set('max_id', String(progress.maxId));
        }
        const response = await fetch('/admin/plugin/games/recheck-ph.php', {
          method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' }
        });
        let result;
        try { result = JSON.parse(await response.text()); }
        catch {
          throw new Error(`The server returned an invalid availability response (HTTP ${response.status}). Please retry; completed updates are saved.`);
        }
        if (!response.ok || !result?.ok) throw new Error(result?.error || 'Availability check failed.');
        for (const key of Object.keys(totals)) totals[key] += result[key] || 0;
        progress.afterId = result.next_after_id;
        progress.maxId = result.max_id;
        if (result.warning) progress.warning = result.warning;
        text.textContent = `Processed ${totals.processed} of ${result.total}. PH available: ${totals.available}; PH restricted: ${totals.restricted}; unpublished: ${totals.unpublished}; unknown (kept unchanged): ${totals.unknown}.`;
        if (progress.warning) text.textContent += ` ${progress.warning}`;
        if (result.done) break;
        // Stay below the provider limit and the existing admin write limit.
        await new Promise(resolve => setTimeout(resolve, 2200));
      } while (!slotId);
      text.textContent = `Recheck complete. ${totals.checked} games checked; ${totals.changed} updated. PH available: ${totals.available}; PH restricted: ${totals.restricted}; unpublished: ${totals.unpublished}; unknown (kept unchanged): ${totals.unknown}.`;
      if (progress.warning) text.textContent += ` ${progress.warning}`;
      pending.delete(key);
      button.textContent = slotId ? 'Recheck PH' : 'Recheck PH Availability';
    } catch (error) {
      text.textContent = `${error.message || 'Availability check failed.'} ${totals.processed ? `${totals.processed} games processed; ${totals.changed} updates already saved.` : ''}`;
      button.textContent = slotId ? 'Retry PH Recheck' : 'Resume PH Recheck';
    } finally {
      const reload = document.createElement('a');
      reload.href = window.location.href;
      reload.textContent = ' Reload games';
      output.append(reload);
      running = false;
      controls.forEach(control => { control.disabled = false; });
    }
  }));
})();
