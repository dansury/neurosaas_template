(() => {
  if (window.neurosaasFeedbackDialog) return;
  window.neurosaasFeedbackDialog = true;
  let dialog, frame, returnFocus;
  document.addEventListener('click', async event => {
    const link = event.target.closest('a[href*="feedback=1"]');
    if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.button || window !== window.top || new URL(location.href).searchParams.has('feedback')) return;
    const url = new URL(link.href);
    if (url.origin !== location.origin || !window.HTMLDialogElement) return;
    event.preventDefault();
    if (!dialog) {
      try {
        const response = await fetch(url, {credentials:'same-origin'});
        const policy = response.headers.get('content-security-policy') || '';
        if (!response.ok || /deny/i.test(response.headers.get('x-frame-options') || '') || /frame-ancestors\s+'none'/i.test(policy)) { location.href=url.href; return; }
      } catch { location.href=url.href; return; }
      dialog = document.createElement('dialog'); dialog.className='feedback-dialog'; dialog.setAttribute('aria-label','Заказать доработку');
      const bar = document.createElement('div'); bar.className='feedback-dialog-bar';
      const handle = document.createElement('button'); handle.type='button'; handle.className='feedback-dialog-handle'; handle.textContent='Заказать доработку · переместить'; handle.title='Перетащите или используйте стрелки на клавиатуре';
      const direct = document.createElement('a'); direct.href=url.href; direct.textContent='Открыть страницу';
      const close = document.createElement('button'); close.type='button'; close.textContent='Закрыть'; close.setAttribute('aria-label','Закрыть окно доработки'); close.onclick=()=>dialog.close();
      bar.append(handle,direct,close); frame=document.createElement('iframe'); frame.title='Форма заказа доработки'; frame.src=url.href;
      dialog.append(bar,frame); document.body.append(dialog);
      let drag;
      const move=(left,top)=>{dialog.style.left=Math.max(0,Math.min(innerWidth-dialog.offsetWidth,left))+'px';dialog.style.top=Math.max(0,Math.min(innerHeight-dialog.offsetHeight,top))+'px';dialog.style.margin='0';};
      handle.onpointerdown=e=>{if(e.button!==0)return;const box=dialog.getBoundingClientRect();drag={x:e.clientX-box.left,y:e.clientY-box.top};handle.setPointerCapture(e.pointerId);};
      handle.onpointermove=e=>{if(drag)move(e.clientX-drag.x,e.clientY-drag.y);};
      handle.onpointerup=handle.onpointercancel=()=>{drag=null;};
      handle.onkeydown=e=>{const offsets={ArrowLeft:[-20,0],ArrowRight:[20,0],ArrowUp:[0,-20],ArrowDown:[0,20]};if(!offsets[e.key])return;e.preventDefault();const box=dialog.getBoundingClientRect(),[x,y]=offsets[e.key];move(box.left+x,box.top+y);};
      window.addEventListener('resize',()=>{const box=dialog.getBoundingClientRect();move(box.left,box.top);});
      dialog.addEventListener('close',()=>returnFocus?.focus());
    }
    returnFocus=link; if (!dialog.open) dialog.showModal();
  });
})();
