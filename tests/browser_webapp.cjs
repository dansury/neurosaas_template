const {chromium}=require('playwright');
const fs=require('fs'),vm=require('vm'),assert=require('assert');
const {execFileSync}=require('child_process');
const root=process.cwd();
const html=execFileSync(process.env.PHP_BIN||'php',['-r',`function page_start($title){echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{background:white;color:#111;font:16px system-ui;max-width:760px;padding:20px;margin:auto}button{max-width:100%;white-space:normal;padding:10px;margin:4px}</style></head><body>';}function page_end(){echo '</body></html>';}$_SERVER['SCRIPT_NAME']='/nested/service/admin/index.php';require 'admin/webapp/controller.php';NeuroSaaS\\webapp_admin('fixture-csrf','owner@example.test');`],{encoding:'utf8'});

async function workerChecks(){
  const handlers={},deleted=[],opened=[];let cached=[];
  const context={URL,console,fetch:async()=>{throw Error('offline');},caches:{open:async()=>({add:async url=>cached.push(url)}),keys:async()=>['another-app','neurosaas-webapp-%2Fother%2F-v0','neurosaas-webapp-%2Fnested%2Fservice%2F-v0','neurosaas-webapp-%2Fnested%2Fservice%2F-v1'],delete:async key=>deleted.push(key),match:async url=>'offline:'+url},self:{location:{href:'https://fixture.test/nested/service/webapp-sw.js'},addEventListener:(name,fn)=>handlers[name]=fn,skipWaiting:()=>{},registration:{showNotification:async()=>{}},clients:{claim:async()=>{},matchAll:async()=>[],openWindow:async url=>opened.push(url)}}};
  vm.runInNewContext(fs.readFileSync('webapp-sw.js','utf8'),context);
  let pending;handlers.install({waitUntil:promise=>pending=promise});await pending;
  assert.deepEqual(cached,['https://fixture.test/nested/service/admin/webapp/offline.html']);
  handlers.activate({waitUntil:promise=>pending=promise});await pending;assert.deepEqual(deleted,['neurosaas-webapp-%2Fnested%2Fservice%2F-v0']);
  let response;handlers.fetch({request:{method:'GET',mode:'navigate',url:'https://fixture.test/nested/service/admin/'},respondWith:promise=>response=promise});
  assert.match(await response,/offline/);
  response=null;handlers.fetch({request:{method:'GET',mode:'cors',url:'https://fixture.test/nested/service/api/private'},respondWith:promise=>response=promise});assert.equal(response,null,'no authenticated API caching');
  handlers.notificationclick({notification:{close(){},data:{url:'https://evil.test/steal'}},waitUntil:promise=>pending=promise});await pending;
  assert.deepEqual(opened,['https://fixture.test/nested/service/'],'external notification click cannot navigate away');
}

(async()=>{
  await workerChecks();
  const browser=await chromium.launch({headless:true});
  try{
    for(const width of [390,1280]){
      const page=await browser.newPage({viewport:{width,height:1000}}),errors=[];
      page.on('pageerror',error=>errors.push(error.message));
      await page.addInitScript(()=>{
        window.permissionRequests=0;window.apiActions=[];window.subscribed=null;
        window.Notification={permission:'default',requestPermission:async()=>{++permissionRequests;Notification.permission='granted';return 'granted';}};
        window.PushManager=function(){};
        const reg={pushManager:{getSubscription:async()=>subscribed,subscribe:async()=>{subscribed={endpoint:'https://updates.push.services.mozilla.com/wpush/v2/fixture',keys:{p256dh:'fixture',auth:'fixture'},unsubscribe:async()=>{subscribed=null;return true;}};return subscribed;}}};
        Object.defineProperty(navigator,'serviceWorker',{value:{register:async(url,options)=>{window.workerURL=String(url);window.workerScope=options.scope;return reg;},ready:Promise.resolve(reg)},configurable:true});
      });
      await page.route('https://fixture.test/**',async route=>{
        const request=route.request();
        if(request.url().includes('/client.js'))return route.fulfill({contentType:'text/javascript',body:fs.readFileSync('admin/webapp/client.js','utf8')});
        if(request.method()==='POST'){
          const fields=new URLSearchParams(request.postData());const action=fields.get('webapp_action');
          assert.equal(fields.get('csrf'),'fixture-csrf');await page.evaluate(action=>apiActions.push(action),action);
          return route.fulfill({contentType:'application/json',body:JSON.stringify(action==='key'?{publicKey:'B'.repeat(87)}:action==='test'?{accepted:1,expired:0,failed:0}:{ok:true})});
        }
        return route.fulfill({contentType:'text/html',body:html});
      });
      await page.goto('https://fixture.test/nested/service/admin/?webapp=1');
      assert.equal(await page.evaluate(()=>permissionRequests),0,'no automatic permission request');
      assert.equal(await page.evaluate(()=>workerScope),'/nested/service/');
      await page.getByRole('button',{name:'Установить приложение',exact:true}).click();
      assert.match(await page.locator('[data-webapp-status]').innerText(),/На экран Домой/);
      await page.evaluate(()=>{window.installPrompts=0;const event=new Event('beforeinstallprompt');event.prompt=async()=>{++installPrompts;};event.userChoice=Promise.resolve({outcome:'accepted'});dispatchEvent(event);});
      assert.equal(await page.evaluate(()=>installPrompts),0);
      await page.locator('[data-webapp-install]').click();
      assert.equal(await page.evaluate(()=>installPrompts),1,'native installation requires a click');
      assert.match(await page.locator('[data-webapp-status]').innerText(),/установлено/);
      await page.locator('[data-webapp-subscribe]').click();
      await page.waitForFunction(()=>document.querySelector('[data-webapp-push-status]').textContent.includes('включены'));
      assert.equal(await page.evaluate(()=>permissionRequests),1);
      assert.deepEqual(await page.evaluate(()=>apiActions),['key','subscribe']);
      await page.locator('[data-webapp-test]').click();
      await page.waitForFunction(()=>document.querySelector('[data-webapp-push-status]').textContent.includes('принял'));
      await page.locator('[data-webapp-unsubscribe]').click();
      await page.waitForFunction(()=>document.querySelector('[data-webapp-push-status]').textContent.includes('отключены'));
      await page.evaluate(()=>Notification.permission='denied');
      await page.locator('[data-webapp-subscribe]').click();
      assert.match(await page.locator('[data-webapp-push-status]').innerText(),/запрещены/);
      assert.equal(await page.evaluate(()=>permissionRequests),1,'denied permission is not requested again');
      assert.deepEqual(await page.evaluate(()=>apiActions),['key','subscribe','test','unsubscribe']);
      await page.evaluate(()=>{delete window.Notification;});
      await page.locator('[data-webapp-subscribe]').click();
      assert.match(await page.locator('[data-webapp-push-status]').innerText(),/не поддерживает/);
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'controls fit viewport');
      fs.mkdirSync('work',{recursive:true});await page.screenshot({path:`work/webapp-${width}.png`,fullPage:true});
      assert.deepEqual(errors,[]);await page.close();
    }
    console.log('OK webapp install, consent, device controls, safe offline worker and desktop/mobile');
  }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
