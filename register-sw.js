// Run a one-time cleanup for legacy SW/caches, then register current worker.
// Avoid reload loops by reloading at most once per page session.
(async function() {
  if (!('serviceWorker' in navigator)) return;

  try {
    const CLEANUP_KEY = 'tacam_sw_cleanup_v12_done';
    const hasCleaned = localStorage.getItem(CLEANUP_KEY) === '1';
    if (!hasCleaned) {
      const registrations = await navigator.serviceWorker.getRegistrations();
      for (const reg of registrations) {
        await reg.unregister();
      }

      const cacheNames = await caches.keys();
      await Promise.all(cacheNames.map(name => caches.delete(name)));
      localStorage.setItem(CLEANUP_KEY, '1');
    }

    const registration = await navigator.serviceWorker.register('./sw.js?v=12');
    registration.update();

    const RELOAD_KEY = 'tacam_sw_reloaded_once';
    navigator.serviceWorker.addEventListener('controllerchange', () => {
      if (sessionStorage.getItem(RELOAD_KEY) === '1') return;
      sessionStorage.setItem(RELOAD_KEY, '1');
      window.location.reload();
    });

    const activateUpdate = worker => {
      if (!worker) return;
      worker.postMessage({ type: 'SKIP_WAITING' });
    };

    if (registration.waiting) {
      activateUpdate(registration.waiting);
    }

    registration.addEventListener('updatefound', () => {
      const newWorker = registration.installing;
      if (!newWorker) return;
      newWorker.addEventListener('statechange', () => {
        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
          activateUpdate(newWorker);
        }
      });
    });
  } catch {
    // no-op: app continues without offline cache
  }
})();
