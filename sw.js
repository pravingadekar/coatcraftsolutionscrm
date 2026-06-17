const CACHE_NAME = 'coatcraft-pwa-v2';
// Only static, non-authenticated assets belong here. Dynamic/login-protected
// pages (crm-dashboard.php, view-leads.php) must never be precached: caching
// a redirect-to-login response and later replaying it for a logged-in
// navigation causes a hard ERR_FAILED in the browser.
const FILES_TO_CACHE = [
    '/enquiry.html',
    '/manifest.json',
    '/new_logo.png'
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(FILES_TO_CACHE))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys => Promise.all(
            keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key))
        ))
    );
    self.clients.claim();
});

self.addEventListener('fetch', event => {
    if (event.request.method !== 'GET') {
        return;
    }

    // Page navigations (e.g. view-leads.php, crm-dashboard.php) must always
    // go to the network so login/session state is never served stale from
    // cache. Only fall back to cache when fully offline.
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).catch(() => caches.match('/enquiry.html'))
        );
        return;
    }

    event.respondWith(
        caches.match(event.request)
            .then(response => response || fetch(event.request))
            .catch(() => caches.match('/enquiry.html'))
    );
});

self.addEventListener('push', event => {
    const data = event.data ? event.data.json() : {};
    let title = data.title || 'CoatCraft Notification';
    let body = data.body || 'You have a new notification.';
    let url = data.url || '/crm-dashboard.php';

    // Customize based on type
    if (data.type === 'new_enquiry') {
        title = 'New Enquiry Submitted';
        body = data.body || 'A new enquiry has been received.';
        url = '/view-leads.php';
    } else if (data.type === 'followup_reminder') {
        title = 'Follow-up Reminder';
        body = data.body || 'You have pending follow-ups.';
        url = '/crm-dashboard.php?view=followups';
    }

    const options = {
        body: body,
        icon: '/new_logo.png',
        badge: '/new_logo.png',
        data: url,
        tag: data.type || 'general' // Prevents duplicate notifications
    };
    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    event.waitUntil(
        clients.openWindow(event.notification.data || '/view-leads.php')
    );
});
