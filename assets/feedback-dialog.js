(() => {
    if (window.neurosaasFeedbackDialog) return;
    window.neurosaasFeedbackDialog = true;
    let dialog, content, returnFocus, target, loading = false;
    function mount(html, files) {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        if (!doc.querySelector('#feedback form')) { location.href = target; return false; }
        content.replaceChildren();
        for (const element of doc.querySelectorAll('main > .notice, main > aside, main > [data-feedback-auth], main > section.success, #feedback')) content.append(document.importNode(element, true));
        const form = content.querySelector('#feedback form');
        content.querySelectorAll('form').forEach(item=>item.action=target);
        for (const old of content.querySelectorAll('script')) {
            if (old.src) { old.remove(); continue; }
            const script = document.createElement('script');
            script.textContent = old.textContent; old.replaceWith(script);
        }
        if (files?.length) {
            const input = form.querySelector('[name="screenshots[]"]'), transfer = new DataTransfer();
            files.forEach(file => transfer.items.add(file)); input.files = transfer.files;
            input.dispatchEvent(new Event('change', {bubbles:true}));
        }
        return true;
    }
    function create() {
        dialog = document.createElement('dialog'); dialog.className = 'feedback-dialog'; dialog.setAttribute('aria-label', 'Заказать доработку');
        dialog.innerHTML = '<div class="feedback-dialog-bar"><button type="button" class="feedback-dialog-handle" title="Перетащите или используйте стрелки на клавиатуре">Заказать доработку</button><button type="button" data-feedback-close aria-label="Закрыть окно доработки">✕</button></div><div class="feedback-dialog-content"></div>';
        content = dialog.querySelector('.feedback-dialog-content');
        dialog.querySelector('[data-feedback-close]').onclick = () => dialog.close(); document.body.append(dialog);
        const handle = dialog.querySelector('.feedback-dialog-handle'); let drag;
        const move = (left, top) => {dialog.style.left=Math.max(0,Math.min(innerWidth-dialog.offsetWidth,left))+'px';dialog.style.top=Math.max(0,Math.min(innerHeight-dialog.offsetHeight,top))+'px';dialog.style.margin='0';};
        handle.onpointerdown=e=>{if(e.button!==0)return;const r=dialog.getBoundingClientRect();drag={x:e.clientX-r.left,y:e.clientY-r.top};handle.setPointerCapture(e.pointerId);};
        handle.onpointermove=e=>{if(drag)move(e.clientX-drag.x,e.clientY-drag.y);}; handle.onpointerup=handle.onpointercancel=()=>{drag=null;};
        handle.onkeydown=e=>{const d={ArrowLeft:[-20,0],ArrowRight:[20,0],ArrowUp:[0,-20],ArrowDown:[0,20]}[e.key];if(!d)return;e.preventDefault();const r=dialog.getBoundingClientRect();move(r.left+d[0],r.top+d[1]);};
        window.addEventListener('resize',()=>{const r=dialog.getBoundingClientRect();move(r.left,r.top);});
        dialog.addEventListener('close',()=>{content.querySelector('form')?.dispatchEvent(new Event('feedback-close'));returnFocus?.focus();});
        dialog.addEventListener('submit',async event=>{
            const form=event.target.closest('form');if(!form||!content.contains(form))return;
            event.preventDefault();if(loading)return;loading=true;
            const button=event.submitter,files=[...(form.querySelector('[name="screenshots[]"]')?.files||[])];if(button)button.disabled=true;
            try{const response=await fetch(target,{method:'POST',body:new FormData(form),credentials:'same-origin'});if(mount(await response.text(),response.ok?[]:files))content.scrollTop=0;}
            catch(_){const status=form.querySelector('[data-shot-status]');if(status)status.textContent='Не удалось отправить. Проверьте соединение и повторите.';}
            finally{loading=false;if(button)button.disabled=false;}
        });
    }
    document.addEventListener('click',async event=>{
        const link=event.target.closest('a[href*="feedback=1"]');
        if(!link||event.ctrlKey||event.metaKey||event.shiftKey||event.button||window!==window.top||new URL(location.href).searchParams.has('feedback'))return;
        const url=new URL(link.href);if(url.origin!==location.origin||!window.HTMLDialogElement||loading)return;
        event.preventDefault();
        if(dialog && target===url.href && content.querySelector('form')) {returnFocus=link;if(!dialog.open)dialog.showModal();return;}
        loading=true;target=url.href;returnFocus=link;
        try{const response=await fetch(target,{credentials:'same-origin'});if(!response.ok){location.href=target;return;}if(!dialog)create();if(mount(await response.text())){if(!dialog.open)dialog.showModal();content.querySelector('textarea')?.focus();}}
        catch(_){location.href=target;}finally{loading=false;}
    });
})();
