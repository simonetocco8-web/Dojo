self.addEventListener('push', event => {
  let data = {};
  try { data = event.data.json(); } catch(e) { data = { title:'Notifica', body:event.data?.text()||'' }; }
  const title = data.title || 'Notifica';
  const options = {
    body: data.body || '',
    icon: '/icons/icon-192.png',
    badge: '/icons/badge-72.png',
    data: data.url || '/'
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  const url = event.notification.data || '/';
  event.waitUntil(clients.matchAll({ type:'window', includeUncontrolled:true }).then(clientsArr => {
    for (const c of clientsArr) {
      if (c.url === url && 'focus' in c) return c.focus();
    }
    if (clients.openWindow) return clients.openWindow(url);
  }));
});
