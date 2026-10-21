'use strict';
const BASE = new URL('./', self.location.href);
const PREFIX = 'neurosaas-webapp-' + encodeURIComponent(BASE.pathname) + '-';
const CACHE = PREFIX + 'v1';
const OFFLINE = new URL('admin/webapp/offline.html', BASE).href;
self.addEventListener('install', event => { event.waitUntil(caches.open(CACHE).then(cache=>cache.add(OFFLINE)).then(()=>self.skipWaiting())); });
self.addEventListener('activate', event => { event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(key=>key.startsWith(PREFIX)&&key!==CACHE).map(key=>caches.delete(key)))).then(()=>self.clients.claim())); });
self.addEventListener('fetch', event => {
  if (event.request.method==='GET' && event.request.mode==='navigate' && new URL(event.request.url).origin===BASE.origin) {
    event.respondWith(fetch(event.request).catch(()=>caches.match(OFFLINE)));
  }
});
self.addEventListener('push', event => {
  let data={}; try { data=event.data?event.data.json():{}; } catch (_) { return; }
  event.waitUntil(self.registration.showNotification(String(data.title||'Мой сервис'),{body:String(data.body||''),icon:new URL('admin/webapp/icon.svg',BASE).href,data:{url:String(data.url||BASE.href)}}));
});
self.addEventListener('notificationclick', event => {
  event.notification.close();
  let target; try { target=new URL(event.notification.data?.url||BASE.href,BASE); } catch (_) { target=BASE; }
  if(target.origin!==BASE.origin || !target.pathname.startsWith(BASE.pathname)) target=BASE;
  event.waitUntil(self.clients.matchAll({type:'window',includeUncontrolled:true}).then(async windows=>{
    const current=windows.find(client=>client.url===target.href);
    if(current) return current.focus();
    return self.clients.openWindow(target.href);
  }));
});
