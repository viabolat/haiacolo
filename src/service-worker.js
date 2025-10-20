// service-worker.js

self.addEventListener('push', function(event) {
    const data = event.data.json();
    const options = {
        body: data.body,
        icon: data.icon || './public/logo.png', // Use icon from payload or default
        data: { // Store additional data, like a URL to open
            url: data.url || '/',
        },
    };

    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

// Handle notification click
self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    const urlToOpen = event.notification.data.url;
    event.waitUntil(
        clients.openWindow(urlToOpen)
    );
});