(() => {
  const output = document.getElementById('ph-recheck-status');
  const controls = [...document.querySelectorAll('[data-recheck-ph]')];
  if (!output || !controls.length) return;
  let running = false;
  controls.forEach(button => button.addEventListener('click', async () => {
    if (running) return;
    running = true;
    controls.forEach(control => { control.disabled = true; });
    output.replaceChildren();
    const text = document.createElement('span');
    output.append(text);
    const totals = { processed: 0, checked: 0, changed: 0, available: 0, restricted: 0, unpublished: 0, unknown: 0 };
    let afterId = 0, maxId = null;
    const slotId = button.dataset.recheckPh;
    text.textContent = slotId ? 'Checking this game for PH availability…' : 'Rechecking the catalogue for PH availability…';
    try {
      do {
        const body = new URLSearchParams({ csrf_token: document.getElementById('admin-session-config')?.dataset.csrf || '' });
        if (slotId) body.set('slot_id', slotId);
        else {
          body.set('after_id', String(afterId));
          if (maxId !== null) body.set('max_id', String(maxId));
        }
        const response = await fetch('/admin/plugin/games/recheck-ph.php', {
          method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' }
        });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'Availability check failed.');
        for (const key of Object.keys(totals)) totals[key] += result[key] || 0;
        afterId = result.next_after_id;
        maxId = result.max_id;
        text.textContent = `Processed ${totals.processed} of ${result.total}. PH available: ${totals.available}; PH restricted: ${totals.restricted}; unpublished: ${totals.unpublished}; unknown (kept unchanged): ${totals.unknown}.`;
        if (result.warning) throw new Error(result.warning);
        if (result.done) break;
        // Stay below the provider limit and the existing admin write limit.
        await new Promise(resolve => setTimeout(resolve, 2200));
      } while (!slotId);
      text.textContent = `Recheck complete. ${totals.checked} games checked; ${totals.changed} updated. PH available: ${totals.available}; PH restricted: ${totals.restricted}; unpublished: ${totals.unpublished}; unknown (kept unchanged): ${totals.unknown}.`;
    } catch (error) {
      text.textContent = `${error.message || 'Availability check failed.'} ${totals.processed ? `${totals.processed} games processed; ${totals.changed} updates already saved.` : ''}`;
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
