(() => {
 const form=document.currentScript.closest('form');if(!form)return;
 const input=form.querySelector('[name="screenshots[]"]'),list=form.querySelector('[data-shot-list]'),shotStatus=form.querySelector('[data-shot-status]');
 const body=form.querySelector('[name=description]'),button=form.querySelector('[data-dictate]'),status=form.querySelector('[data-voice-status]');
 let selected=[...input.files],rec,timer,stream,cancelled=false;
 function draw(){
  const transfer=new DataTransfer();selected.forEach(file=>transfer.items.add(file));input.files=transfer.files;list.replaceChildren();
  selected.forEach((file,index)=>{const chip=document.createElement('span'),label=document.createElement('span'),remove=document.createElement('button');chip.className='feedback-shot';label.textContent=file.name;remove.type='button';remove.textContent='✕';remove.setAttribute('aria-label','Убрать '+file.name);remove.onclick=()=>{selected.splice(index,1);draw();};chip.append(label,remove);list.append(chip);});
  shotStatus.textContent=selected.length?'Приложено: '+selected.length+' из 5.':'Можно вставить изображение из буфера обмена.';
 }
 input.addEventListener('change',()=>{selected=[...input.files].slice(0,5);draw();});
 form.addEventListener('paste',event=>{const images=[...(event.clipboardData?.files||[])].filter(file=>/^image\/(png|jpeg|webp)$/.test(file.type));if(!images.length)return;event.preventDefault();selected=[...selected,...images].slice(0,5);draw();});
 form.addEventListener('feedback-close',()=>{cancelled=true;clearInterval(timer);if(rec?.state==='recording')rec.stop();stream?.getTracks().forEach(track=>track.stop());});
 if(!button)return;
 button.addEventListener('click',async()=>{
  if(rec?.state==='recording'){rec.stop();return;}
  try{
   cancelled=false;stream=await navigator.mediaDevices.getUserMedia({audio:true});const chunks=[];rec=new MediaRecorder(stream);const started=Date.now();
   rec.ondataavailable=event=>{if(event.data.size)chunks.push(event.data);};
   rec.onstop=async()=>{
    clearInterval(timer);stream.getTracks().forEach(track=>track.stop());if(cancelled)return;
    button.disabled=true;status.textContent='Распознаём…';const data=new FormData();data.set('audio_model',form.querySelector('[name=audio_model]').value);data.set('action','feedback_transcribe');data.set('csrf',form.querySelector('[name=csrf]').value);data.set('audio',new Blob(chunks,{type:rec.mimeType}),'voice.webm');
    try{const response=await fetch(form.action,{method:'POST',body:data,credentials:'same-origin'}),result=await response.json();if(!response.ok||result.error)throw Error(result.error||'Ошибка распознавания');if(cancelled)return;body.value+=(body.value?'\n':'')+result.text;body.dispatchEvent(new Event('input',{bubbles:true}));status.textContent=result.warning||'Расшифровка добавлена.';}
    catch(error){if(!cancelled)status.textContent=error.message;}
    finally{button.disabled=false;button.textContent='Голосом';}
   };
   rec.start();button.textContent='Остановить';timer=setInterval(()=>{const left=Math.max(0,30-Math.floor((Date.now()-started)/1000));status.textContent='Осталось секунд: '+left;if(!left&&rec.state==='recording')rec.stop();},300);
  }catch(error){stream?.getTracks().forEach(track=>track.stop());status.textContent='Для записи нужен HTTPS и доступ к микрофону.';}
 });
})();
