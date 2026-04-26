self.addEventListener('push', function (event) {
    let payload = {
        title: 'Galancy Bildirim',
        body: 'Yeni bildiriminiz var.',
        url: './index.php?module=bildirim'
    };

    if (event.data) {
        try {
            payload = Object.assign(payload, event.data.json());
        } catch (error) {
            payload.body = event.data.text();
        }
    }

    event.waitUntil(
        self.registration.showNotification(payload.title || 'Galancy Bildirim', {
            body: payload.body || 'Yeni bildiriminiz var.',
            icon: './favicon.ico',
            badge: './favicon.ico',
            data: { url: payload.url || './index.php?module=bildirim' }
        })
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    const targetUrl = event.notification.data && event.notification.data.url
        ? event.notification.data.url
        : './index.php?module=bildirim';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clients) {
            for (const client of clients) {
                if ('focus' in client) {
                    client.navigate(targetUrl);
                    return client.focus();
                }
            }

            return self.clients.openWindow(targetUrl);
        })
    );
});
