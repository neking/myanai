const CACHE_NAME = '-branch-v6'noodlehaus-v1781149114';
const STATIC_CACHE = 'noodlehaus-static-v20260610';
const QUEUE_DB = 'noodlehaus-queue';

// Cache မည့် files
const STATIC_ASSETS = [
  '/',
  '/index.html',
  '/menu_api.php',
  '/site_settings.php?action=get',
];

// Install — static assets cache
self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(STATIC_CACHE).then(cache =>
      cache.addAll(['/index.html', '/'])
    ).then(() => self.skipWaiting())
  );
});

// Activate — old cache ဖျက်
self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== STATIC_CACHE && k !== CACHE_NAME).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

// Fetch — network first, cache fallback
self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  
  // Order submit — offline queue
  if (url.pathname === '/order_handler.php' && e.request.method === 'POST') {
    e.respondWith(
      fetch(e.request.clone()).catch(async () => {
        // Offline — queue မှာ သိမ်း
        const body = await e.request.clone().text();
        await queueOfflineOrder(body);
        return new Response(JSON.stringify({
          success: true,
          order_id: 'OFFLINE-' + Date.now(),
          db_id: null,
          message: 'Order saved offline — will sync when online',
          offline: true,
        }), {headers: {'Content-Type': 'application/json'}});
      })
    );
    return;
  }

  // API calls — network first, cache fallback
  const isApi = url.pathname.includes('.php');
  const isStatic = ['.html','.js','.css','.json','.png','.jpg','.webp','.ico'].some(ext => url.pathname.endsWith(ext));

  if (e.request.method === 'GET' && isApi) {
    // Network first — always fresh data
    e.respondWith(
      fetch(e.request).then(res => {
        if (res.ok) {
          const clone = res.clone();
          caches.open(CACHE_NAME).then(c => c.put(e.request, clone));
        }
        return res;
      }).catch(() => caches.match(e.request).then(cached => {
        return cached || new Response(JSON.stringify({ok:false,msg:'Offline',items:[]}),
          {headers:{'Content-Type':'application/json'}});
      }))
    );
    return;
  }

  if (e.request.method === 'GET' && isStatic) {
    // Cache first for static — then update
    e.respondWith(
      caches.match(e.request).then(cached => {
        const fetchPromise = fetch(e.request).then(res => {
          if (res.ok) {
            const clone = res.clone();
            caches.open(CACHE_NAME).then(c => c.put(e.request, clone));
          }
          return res;
        });
        return cached || fetchPromise;
      })
    );
    return;
  }

  // Other GET — network only
  if (e.request.method === 'GET') {
    e.respondWith(fetch(e.request).catch(() => caches.match(e.request)));
  }
});

// IndexedDB helper
function openQueueDB() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(QUEUE_DB, 1);
    req.onupgradeneeded = e => {
      e.target.result.createObjectStore('orders', {keyPath: 'id', autoIncrement: true});
    };
    req.onsuccess = e => resolve(e.target.result);
    req.onerror = () => reject(req.error);
  });
}

async function queueOfflineOrder(body) {
  const db = await openQueueDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction('orders', 'readwrite');
    tx.objectStore('orders').add({body, timestamp: Date.now(), synced: false});
    tx.oncomplete = resolve;
    tx.onerror = () => reject(tx.error);
  });
}

// Background sync
self.addEventListener('sync', e => {
  if (e.tag === 'sync-orders') {
    e.waitUntil(syncOfflineOrders());
  }
});

async function syncOfflineOrders() {
  const db = await openQueueDB();
  const tx = db.transaction('orders', 'readwrite');
  const store = tx.objectStore('orders');
  const orders = await new Promise((res, rej) => {
    const req = store.getAll();
    req.onsuccess = () => res(req.result);
    req.onerror = () => rej(req.error);
  });

  for (const order of orders.filter(o => !o.synced)) {
    try {
      const r = await fetch('/order_handler.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: order.body,
      });
      if (r.ok) {
        const tx2 = db.transaction('orders', 'readwrite');
        tx2.objectStore('orders').delete(order.id);
      }
    } catch(e) {}
  }
  
  // Notify clients
  const clients = await self.clients.matchAll();
  clients.forEach(c => c.postMessage({type: 'SYNC_COMPLETE'}));
}

