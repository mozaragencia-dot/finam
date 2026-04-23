// Force unregister old service workers and clear all caches on first load
// Then register the new network-first service worker
(async function() {
  if (!('serviceWorker' in navigator)) return;

  try {
    // Unregister ALL existing service workers to clear stale cache-first behavior
    const registrations = await navigator.serviceWorker.getRegistrations();
    for (const reg of registrations) {
      await reg.unregister();
    }

    // Clear all caches to remove stale assets
    const cacheNames = await caches.keys();
    await Promise.all(cacheNames.map(name => caches.delete(name)));

    // Register the new network-first service worker
    const registration = await navigator.serviceWorker.register('./sw.js?v=12');
    registration.update();

    let refreshing = false;
    navigator.serviceWorker.addEventListener('controllerchange', () => {
      if (refreshing) return;
      refreshing = true;
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
