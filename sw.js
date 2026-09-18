/* Luprah ERP service worker — enables install; network-first (data always fresh) */
self.addEventListener('install', function(e){ self.skipWaiting(); });
self.addEventListener('activate', function(e){ e.waitUntil(self.clients.claim()); });
self.addEventListener('fetch', function(e){
  e.respondWith(fetch(e.request).catch(function(){
    return new Response('<h2 style="font-family:sans-serif;text-align:center;margin-top:40px">📴 You are offline<br><small>Reconnect to use Luprah ERP</small></h2>',{headers:{'Content-Type':'text/html'}});
  }));
});
