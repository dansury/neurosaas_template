const {chromium}=require('playwright');
const fs=require('fs'),path=require('path'),os=require('os'),cp=require('child_process'),net=require('net'),assert=require('assert');
const base=path.resolve(__dirname,'..'),tmp=fs.mkdtempSync(path.join(os.tmpdir(),'feedback-browser-')),root=path.join(tmp,'site');
fs.cpSync(base,root,{recursive:true,filter:p=>!path.relative(base,p).split(path.sep).some(x=>['.git','.serena','data','node_modules','work','__pycache__'].includes(x))});
const php=process.env.PHP_BIN||'php',env={...process.env,GENLA_DATA_DIR:path.join(tmp,'private'),SELFHEAL_REPORTING:'off',GITHUB_TOKEN:'fixture-wrong-environment-token',FEEDBACK_GITHUB_TOKEN:'fixture-issue-only'};
function cli(source){return cp.execFileSync(php,['-r',source],{cwd:root,env,encoding:'utf8'});}
cli("file_put_contents('pull-config.php','<?php return '.var_export(['repo'=>'fixture/repo','branch'=>'main','gh_token'=>'fixture-issue-only','password_hash'=>password_hash('feedback-fixture-pass',PASSWORD_DEFAULT),'admin_email'=>'owner@example.test','admin_email_verified'=>true,'auto_pull'=>false],true).';');");
for(const rel of ['pull.php','admin/deployment/panel.php']){
  const file=path.join(root,rel);let source=fs.readFileSync(file,'utf8');
  source=source.replace(/function gh_api\([^\n]+\n[\s\S]*?(?=\nfunction )/,"function gh_api(string $path,string $token): array { return [null,'Offline fixture']; }\n");
  source=source.replace(/function github_create_feedback\([^\n]+\n[\s\S]*?(?=\nfunction )/,`function github_create_feedback(string $repo,string $token,string $title,string $body): array {
    file_put_contents(PULL_ROOT.'/fixture-issue.json',json_encode(['repo'=>$repo,'title'=>$title,'body'=>$body,'support_key'=>$token==='fixture-issue-only']));
    return [201,['html_url'=>'https://github.com/'.$repo.'/issues/123']];
}
`);fs.writeFileSync(file,source);
}
const config=path.join(root,'admin/llm_connector/config.php');
let source=fs.readFileSync(config,'utf8').replace('return $config;',`$config['GENROUTER_API_KEY']='fixture-audio-key';
$config['AVAILABLE_MODELS']=[['provider'=>'genrouter','full_id'=>'voice-one','label'=>'Voice one','audio'=>true,'audio_transport'=>'transcriptions'],['provider'=>'genrouter','full_id'=>'voice-two','label'=>'Voice two','audio'=>true]];
$config['LLM_AUDIO_MODEL']='genrouter:voice-one';return $config;`);fs.writeFileSync(config,source);
const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jN9sAAAAASUVORK5CYII=','base64');
async function port(){return new Promise(resolve=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});}
(async()=>{
  const number=await port(),url='http://127.0.0.1:'+number;
  const server=cp.spawn(php,['-d','opcache.enable_cli=0','-d','opcache.enable=0','-S','127.0.0.1:'+number,'-t',root],{env,stdio:'ignore'});
  await new Promise(r=>setTimeout(r,400));const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH,headless:true,args:['--no-sandbox']});
  try {
    for(const width of [1280,390]){
      const page=await browser.newPage({viewport:{width,height:950}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
      await page.addInitScript(()=>{
        Object.defineProperty(navigator,'mediaDevices',{value:{getUserMedia:async()=>({getTracks:()=>[{stop(){}}]})}});
        window.MediaRecorder=class {constructor(){this.state='inactive';this.mimeType='audio/webm';} start(){this.state='recording';}stop(){this.state='inactive';this.ondataavailable({data:new Blob(['offline-audio'],{type:this.mimeType})});this.onstop();}};
      });
      let audioModel;
      await page.route('**/admin/**',async route=>{
        const request=route.request();
        if(request.method()==='POST' && (request.postData()||'').includes('feedback_transcribe')){
          audioModel=(request.postData()||'').includes('genrouter:voice-two');
          await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({text:'Надиктованный текст 42.',warning:''})});
        } else await route.continue();
      });
      await page.goto(url+'/admin/?system=1');await page.locator('[name=password]').fill('feedback-fixture-pass');await page.getByRole('button',{name:'Войти',exact:true}).click();
      await page.waitForSelector('[name="provider_order[]"]');assert.equal(await page.locator('[name="provider_order[]"]').count(),3);
      await page.locator('header a[href="?feedback=1"]').click();await page.locator('dialog[open]').waitFor();
      const frame=page.locator('dialog');await frame.locator('[name=description]').waitFor();
      assert.equal(await page.locator('iframe').count(),0);assert.equal(await frame.locator('[name=audio_model] option').count(),2);assert(!(await frame.locator('[name=audio_model]').isVisible()));
      if(fs.existsSync(path.join(base,'lib/bootstrap.php')))assert.equal(await frame.getByRole('link',{name:'Войти в GitHub',exact:true}).count(),0);else {const login=await frame.getByRole('link',{name:'Войти в GitHub',exact:true}).getAttribute('href');assert(login.startsWith('https://github.com/login?return_to='));}
      if(fs.existsSync(path.join(base,'lib/bootstrap.php')))assert.equal(await frame.locator('[name=feedback_key],[name=feedback_token]').count(),0,'GENLA reuses installer token without a second credential form');
      await frame.locator('[name=description]').fill('Исходный текст обращения.');await frame.locator('.feedback-models summary').click();await frame.locator('[name=audio_model]').selectOption('genrouter:voice-two');
      await frame.getByRole('button',{name:'Голосом',exact:true}).click();await frame.getByRole('button',{name:'Остановить',exact:true}).click();
      await frame.getByText('Расшифровка добавлена.',{exact:true}).waitFor();assert(audioModel);assert((await frame.locator('[name=description]').inputValue()).startsWith('Исходный текст обращения.'));
      await frame.locator('[name=description]').evaluate((el,bytes)=>{const transfer=new DataTransfer();transfer.items.add(new File([new Uint8Array(bytes)],'clipboard.png',{type:'image/png'}));el.dispatchEvent(new ClipboardEvent('paste',{clipboardData:transfer,bubbles:true,cancelable:true}));},[...png]);
      assert.equal(await frame.locator('[name="screenshots[]"]').evaluate(el=>el.files.length),1);
      await frame.locator('[name="screenshots[]"]').setInputFiles([{name:'one.png',mimeType:'image/png',buffer:png},{name:'two.png',mimeType:'image/png',buffer:png}]);await frame.getByRole('button',{name:'Убрать one.png',exact:true}).click();assert.equal(await frame.locator('[name="screenshots[]"]').evaluate(el=>el.files.length),1);
    if(width===1280){
      await page.evaluate(()=>{window.micResolvers=[];window.micStops=[0,0];navigator.mediaDevices.getUserMedia=()=>new Promise(resolve=>window.micResolvers.push(resolve));});
      await frame.getByRole('button',{name:'Голосом',exact:true}).click();await page.waitForFunction(()=>window.micResolvers.length===1);
      await page.getByRole('button',{name:'Закрыть окно доработки',exact:true}).click();await page.locator('header a[href="?feedback=1"]').click();
      await frame.getByRole('button',{name:'Голосом',exact:true}).click();await page.waitForFunction(()=>window.micResolvers.length===2);
      await page.evaluate(()=>window.micResolvers[1]({getTracks:()=>[{stop(){window.micStops[1]++;}}]}));await frame.getByRole('button',{name:'Остановить',exact:true}).waitFor();
      await page.evaluate(()=>window.micResolvers[0]({getTracks:()=>[{stop(){window.micStops[0]++;}}]}));await page.waitForFunction(()=>window.micStops[0]>0);
      await page.getByRole('button',{name:'Закрыть окно доработки',exact:true}).click();await page.waitForFunction(()=>window.micStops[1]>0);
      await page.locator('header a[href="?feedback=1"]').click();assert(await frame.getByRole('button',{name:'Голосом',exact:true}).isVisible());
    }
    const box=await page.locator('dialog').boundingBox();await page.locator('.feedback-dialog-handle').focus();await page.keyboard.press('ArrowLeft');const moved=await page.locator('dialog').boundingBox();assert(moved.x<=box.x);
      fs.mkdirSync(path.join(base,'work'),{recursive:true});await page.screenshot({path:path.join(base,'work','feedback-'+width+'.png'),fullPage:true});
      if(width===1280){
        await frame.getByRole('button',{name:'Отправить идею',exact:true}).click();await frame.getByText('Спасибо! Идея отправлена.',{exact:false}).waitFor({timeout:5000}).catch(async()=>{throw Error(await frame.innerText());});
        const issue=JSON.parse(fs.readFileSync(path.join(root,'fixture-issue.json'),'utf8'));assert(issue.support_key && issue.body.includes('feedback_image=') && !issue.body.includes('fixture-issue-only'));
        const image=issue.body.match(/!\[Скриншот\]\(([^)]+)\)/)[1];const response=await page.request.get(image);assert.equal(response.status(),200);assert((response.headers()['content-type']).startsWith('image/png'));
        const id=new URL(image).searchParams.get('feedback_image'),file=path.join(root,'admin/account/data/feedback-media',id);fs.utimesSync(file,new Date(0),new Date(0));assert.equal((await page.request.get(image)).status(),404);
        assert.equal((await page.request.get(url+'/admin/?feedback_image=../pull-config.php')).status(),404);
      }
      await page.getByRole('button',{name:'Закрыть окно доработки',exact:true}).click();await page.locator('header a[href="?feedback=1"]').click();assert(await page.locator('dialog').evaluate(el=>el.open));
      if(width===390)assert((await frame.locator('[name=description]').inputValue()).includes('Исходный текст'));
      assert.deepEqual(errors,[]);await page.close();console.log('Feedback dialog/media/credential workflow '+width+'px passed');
    }
    fs.writeFileSync(config,source.replace("'fixture-audio-key'","''"));
    const settingsPage=await browser.newPage();
    await settingsPage.goto(url+'/admin/?feedback=1');await settingsPage.locator('[name=password]').fill('feedback-fixture-pass');await settingsPage.getByRole('button',{name:'Войти',exact:true}).click();
    assert.equal(await settingsPage.locator('[name=audio_model]').count(),0);
    const connect=settingsPage.locator('[data-connect-voice]');assert.equal(await connect.getAttribute('target'),'_blank');assert((await connect.getAttribute('href')).includes('toolkit=1'));
    await settingsPage.goto(url+'/admin/?toolkit=1');
    assert.equal(await settingsPage.locator('form h2').first().innerText(),'API-ключи');
    const yandex=settingsPage.locator('[name=YANDEX_API_KEY]'),folder=settingsPage.locator('[data-yandex-folder]');assert(!(await folder.isVisible()));await yandex.locator('xpath=ancestor::details').locator('summary').first().click();await yandex.fill('fixture-yandex-key');assert(await folder.isVisible());await yandex.fill('');assert(!(await folder.isVisible()));
    await settingsPage.close();console.log('Keyless voice entry, API keys first and conditional Yandex folder passed');
  } finally {await browser.close();server.kill();fs.rmSync(tmp,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exit(1);});
