(() => {
  'use strict';
  window.runBlogImport = async function ({ file, getCsrf, updateCsrf, parseJsonResponse, onProgress }) {
    const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
    const request = async (data, retryable) => {
      for (let attempt = 0; ; attempt++) {
        data.set('csrf_token', getCsrf());
        try {
          const response = await fetch('/admin/blog-import.php', {
            method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' }, body: data,
          });
          if (retryable && (response.status === 429 || response.status >= 500) && attempt < 3) {
            onProgress('Import paused briefly; retrying the current batch… Keep this page open.');
            await wait(response.status === 429 ? Math.max(1, Number(response.headers.get('Retry-After')) || 60) * 1000 : 1000 * (attempt + 1));
            continue;
          }
          const result = await parseJsonResponse(response);
          updateCsrf(result.csrfToken);
          if (!response.ok || !result.ok) throw Object.assign(new Error(result.error || 'Blog import failed.'), { permanent: true });
          return result;
        } catch (error) {
          if (!retryable || error.permanent || attempt >= 3) throw error;
          onProgress('Connection interrupted; retrying the current batch… Keep this page open.');
          await wait(1000 * (attempt + 1));
        }
      }
    };
    const warnBeforeLeaving = (event) => { event.preventDefault(); event.returnValue = ''; };
    window.addEventListener('beforeunload', warnBeforeLeaving);
    try {
      const upload = new FormData();
      upload.append('import_file', file);
      onProgress('Uploading and preparing import… Keep this page open.');
      let result = await request(upload, false);
      while (!result.done) {
        onProgress(result.phase === 'images'
          ? `Restoring images: ${result.imagesProcessed} / ${result.imagesTotal}. Keep this page open.`
          : `Importing posts: ${result.processed} / ${result.total}. Keep this page open.`);
        const batch = new FormData();
        batch.append('job_id', result.jobId);
        batch.append('revision', String(result.revision));
        await wait(2200);
        result = await request(batch, true);
      }
      return result;
    } catch (error) {
      throw new Error(`${error.message || 'Blog import failed.'} Completed batches are saved. You can select the file again to retry.`);
    } finally {
      window.removeEventListener('beforeunload', warnBeforeLeaving);
    }
  };
})();
