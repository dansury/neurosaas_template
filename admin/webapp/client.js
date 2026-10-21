(() => {
  'use strict';
  const script=document.currentScript, base=new URL(script?.dataset.webappBase||'../../',location.href);
  let prompt=null, registration=null;
  const status=text=>document.querySelectorAll('[data-webapp-status]').forEach(node=>node.textContent=text);
  const controls=document.querySelector('[data-webapp-controls]'), out=controls?.querySelector('[data-webapp-push-status]');
  const tell=text=>{if(out)out.textContent=text;};
  const supported=window.isSecureContext&&'serviceWorker' in navigator;
  const ready=supported?navigator.serviceWorker.register(new URL('webapp-sw.js',base),{scope:base.pathname}).then(reg=>{registration=reg;return navigator.serviceWorker.ready;}):Promise.reject(Error('Нужен HTTPS и браузер с поддержкой приложений.'));
  ready.catch(error=>{status(error.message);tell(error.message);});
  window.addEventListener('beforeinstallprompt',event=>{event.preventDefault();prompt=event;status('Приложение можно установить.');});
  window.addEventListener('appinstalled',()=>status('Приложение установлено.'));
  document.querySelectorAll('[data-webapp-install]').forEach(button=>button.addEventListener('click',async()=>{
    if(!supported){status('Установка требует HTTPS и поддерживаемого браузера.');return;}
    if(prompt){await prompt.prompt();const choice=await prompt.userChoice;status(choice.outcome==='accepted'?'Приложение установлено.':'Установка отменена.');prompt=null;}
    else status(window.matchMedia('(display-mode: standalone)').matches?'Приложение уже установлено.':'Откройте меню браузера → «Установить приложение» или «На экран Домой». На iPhone: Safari → «Поделиться» → «На экран Домой».');
  }));
  if(!controls)return;
  const api=async(action,fields={})=>{
    const body=new URLSearchParams({csrf:controls.dataset.csrf,webapp_action:action,...fields});
    const response=await fetch('?webapp=1',{method:'POST',body});let result;
    try{result=await response.json();}catch(_){throw Error('Сессия устарела. Откройте админку заново.');}
    if(!response.ok||result.error)throw Error(result.error||'Не удалось сохранить настройки.');return result;
  };
  const keyBytes=value=>Uint8Array.from(atob(value.replace(/-/g,'+').replace(/_/g,'/')+'='.repeat((4-value.length%4)%4)),character=>character.charCodeAt(0));
  const run=async(button,action)=>{button.disabled=true;try{await action();}catch(error){tell(error.message);}finally{button.disabled=false;}};
  controls.querySelector('[data-webapp-subscribe]').addEventListener('click',event=>run(event.currentTarget,async()=>{
    if(!supported)throw Error('Уведомления требуют HTTPS и поддерживаемого браузера.');
    if(!('Notification' in window)||!('PushManager' in window))throw Error('Браузер не поддерживает push. На iPhone сначала установите приложение на экран Домой.');
    if(Notification.permission==='denied')throw Error('Уведомления запрещены в настройках браузера. Разрешите их для этого сайта.');
    const permission=Notification.permission==='granted'?'granted':await Notification.requestPermission();
    if(permission!=='granted')throw Error('Разрешение на уведомления не получено.');
    const reg=await ready, key=await api('key');
    const existing=await reg.pushManager.getSubscription();
    const subscription=existing||await reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:keyBytes(key.publicKey)});
    try { await api('subscribe',{subscription:JSON.stringify(subscription)}); }
    catch(error){if(!existing)await subscription.unsubscribe();throw error;}
    tell('Уведомления на этом устройстве включены.');
  }));
  controls.querySelector('[data-webapp-unsubscribe]').addEventListener('click',event=>run(event.currentTarget,async()=>{
    const reg=await ready, subscription=await reg.pushManager.getSubscription();
    if(subscription){await api('unsubscribe',{endpoint:subscription.endpoint});await subscription.unsubscribe();}
    tell('Уведомления на этом устройстве отключены.');
  }));
  controls.querySelector('[data-webapp-test]').addEventListener('click',event=>run(event.currentTarget,async()=>{
    const reg=await ready, subscription=await reg.pushManager.getSubscription();
    if(!subscription)throw Error('Сначала включите уведомления на этом устройстве.');
    const result=await api('test',{endpoint:subscription.endpoint});
    tell(result.accepted>0?'Сервис доставки принял проверочное уведомление.':result.expired>0?'Подписка истекла. Включите уведомления заново.':'Уведомление не отправлено. Проверьте подключение и подписку.');
  }));
})();
