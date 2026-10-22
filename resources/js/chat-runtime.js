import { initializeMessageScroll } from './message-scroll.js';
import { initializeChatReadState } from './chat-read-state.js';
import { createMessageSubmission } from './message-submission.js';
import { reconcileMessageRows } from './message-reconciliation.js';

export function initializeChatRuntime(document, window, config) {
    if (!config.conversationId) return () => {};
    const lifecycle = new AbortController();
    let active = true;
    const timers = new Set();
    const later = (callback, delay) => { const timer = setTimeout(() => { timers.delete(timer); if (active) callback(); }, delay); timers.add(timer); };
    const fetch = async (url, options = {}) => { const response = await window.fetch(url, {...options, signal: lifecycle.signal}); if (!active) throw new DOMException('Chat closed', 'AbortError'); return response; };
    const Swal = window.Swal;
    const conversationId = config.conversationId;
    const currentUserId = config.currentUserId;
    const canWrite = config.canWrite;
    const editWindowMinutes = config.editWindowMinutes;
    const deleteWindowMinutes = config.deleteWindowMinutes;
    const box = document.getElementById('messages');
    const composer = document.getElementById('composer');
    const input = document.getElementById('message');
    const sendButton = document.getElementById('send-button');
    const resizeMessageInput = () => { if (!input) return; input.style.height = 'auto'; input.style.height = Math.min(input.scrollHeight, 112) + 'px'; };
    input?.addEventListener('input', resizeMessageInput);
    const errorBox = document.getElementById('chat-error');
    const selection = document.getElementById('attachment-selection');
    const attachmentMenu = document.getElementById('attachment-menu');
    const attachmentMenuButton = document.getElementById('attachment-menu-button');
    const attachmentInput = document.getElementById('attachment-input');
    const selectedFiles = [];
    const submission = createMessageSubmission(window.crypto);
    let replyToMessage = null;
    let forwardMessageId = null;
    const replyBar = document.getElementById('reply-bar');
    const replyUser = document.getElementById('reply-user');
    const replyPreview = document.getElementById('reply-preview');
    const forwardModal = document.getElementById('forward-modal');
    const forwardError = document.getElementById('forward-error');
    const seen = new Set([...box.querySelectorAll('[data-message-id]')].map(el => String(el.dataset.messageId)));
    const icon = (path) => `<svg viewBox="0 0 24 24" aria-hidden="true">${path}</svg>`;

    const showError = (message) => { if (!active || !errorBox) return; errorBox.textContent = message || 'Something went wrong. Please try again.'; errorBox.classList.remove('hidden'); };
    const clearError = () => errorBox?.classList.add('hidden');
    const scrolling = initializeMessageScroll(box, input, window);
    const scrollBottom = () => scrolling.toLatest();
    const messageKey = m => [new Date(m.created_at || 0).getTime() || 0, Number(m.id || 0)];
    const compareMessages = (a,b) => { const [at,ai]=messageKey(a), [bt,bi]=messageKey(b); return at-bt || ai-bi; };
    const formatBytes = bytes => { if (!bytes) return '0 B'; const units=['B','KB','MB','GB']; const i=Math.min(Math.floor(Math.log(bytes)/Math.log(1024)),units.length-1); return `${(bytes/Math.pow(1024,i)).toFixed(i?1:0)} ${units[i]}`; };
    const formatTime = value => new Date(value).toLocaleTimeString([], {hour:'numeric',minute:'2-digit'});

    let activeMessageMenuRow = null;
    let activeMessageMenuButton = null;
    const globalMessageMenu = document.createElement('div');
    globalMessageMenu.id = 'global-message-menu';
    globalMessageMenu.className = 'nc-message-menu nc-message-menu-global hidden';
    globalMessageMenu.setAttribute('role', 'menu');
    globalMessageMenu.setAttribute('aria-label', 'Message actions');
    document.body.appendChild(globalMessageMenu);

    const closeAllMessageMenus = () => {
        globalMessageMenu.classList.add('hidden');
        activeMessageMenuButton?.setAttribute('aria-expanded', 'false');
        activeMessageMenuRow = null;
        activeMessageMenuButton = null;
    };

    const messageActionIcon = {
        reply: '<path d="M9 17 4 12l5-5"/><path d="M5 12h9a6 6 0 0 1 6 6"/>',
        forward: '<path d="m13 5 7 7-7 7"/><path d="M20 12H7a4 4 0 0 0-4 4v1"/>',
        edit: '<path d="M12 20h9"/><path d="m16.5 3.5 4 4L8 20H4v-4Z"/>',
        delete: '<path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 14h10l1-14M9 7V4h6v3"/>',
    };

    const openMessageMenu = (row, button) => {
        if (!row || !button || row.classList.contains('is-deleted')) return;
        if (activeMessageMenuRow === row && !globalMessageMenu.classList.contains('hidden')) {
            closeAllMessageMenus();
            return;
        }
        closeAllMessageMenus();
        activeMessageMenuRow = row;
        activeMessageMenuButton = button;
        button.setAttribute('aria-expanded', 'true');
        globalMessageMenu.innerHTML = '';
        const addAction = (label, action, danger = false) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = danger ? 'danger' : '';
            item.dataset.messageAction = action;
            item.setAttribute('role', 'menuitem');
            item.innerHTML = `${icon(messageActionIcon[action])}<span>${label}</span>`;
            globalMessageMenu.appendChild(item);
        };
        addAction('Reply', 'reply');
        addAction('Forward', 'forward');
        if (row.dataset.editable === '1') addAction('Edit', 'edit');
        if (row.dataset.deletable === '1') addAction('Unsend', 'delete', true);
        globalMessageMenu.classList.remove('hidden');
        globalMessageMenu.style.visibility = 'hidden';
        globalMessageMenu.style.display = 'block';
        globalMessageMenu.style.maxHeight = 'calc(100vh - 16px)';

        const rect = button.getBoundingClientRect();
        const gap = 6;
        const viewportPadding = 8;
        const viewportWidth = document.documentElement.clientWidth || window.innerWidth;
        const viewportHeight = document.documentElement.clientHeight || window.innerHeight;
        let menuRect = globalMessageMenu.getBoundingClientRect();
        const availableBelow = Math.max(0, viewportHeight - rect.bottom - viewportPadding - gap);
        const availableAbove = Math.max(0, rect.top - viewportPadding - gap);
        const preferredBelow = availableBelow >= menuRect.height || availableBelow >= availableAbove;
        const availableSpace = preferredBelow ? availableBelow : availableAbove;

        if (menuRect.height > availableSpace && availableSpace > 40) {
            globalMessageMenu.style.maxHeight = `${Math.floor(availableSpace)}px`;
            menuRect = globalMessageMenu.getBoundingClientRect();
        }

        let left = rect.right - menuRect.width;
        const maxLeft = Math.max(viewportPadding, viewportWidth - menuRect.width - viewportPadding);
        left = Math.max(viewportPadding, Math.min(left, maxLeft));

        let top = preferredBelow
            ? rect.bottom + gap
            : rect.top - menuRect.height - gap;
        const maxTop = Math.max(viewportPadding, viewportHeight - menuRect.height - viewportPadding);
        top = Math.max(viewportPadding, Math.min(top, maxTop));

        globalMessageMenu.style.left = `${Math.round(left)}px`;
        globalMessageMenu.style.top = `${Math.round(top)}px`;
        globalMessageMenu.style.visibility = 'visible';
    };

    box.addEventListener('click', event => {
        const more = event.target.closest('.nc-message-more');
        if (more) {
            event.preventDefault();
            event.stopPropagation();
            openMessageMenu(more.closest('[data-message-id]'), more);
            return;
        }
        const jump = event.target.closest('[data-jump-message]');
        if (jump) {
            const target = box.querySelector(`[data-message-id="${jump.dataset.jumpMessage}"]`);
            target?.scrollIntoView({behavior:'smooth',block:'center'});
            target?.classList.add('nc-message-highlight');
            later(() => target?.classList.remove('nc-message-highlight'), 1200);
        }
    });

    document.addEventListener('click', event => {
        if (!event.target.closest('#global-message-menu') && !event.target.closest('.nc-message-more')) closeAllMessageMenus();
        if (!event.target.closest('.nc-attachment-menu-wrap')) { attachmentMenu?.classList.add('hidden'); attachmentMenuButton?.setAttribute('aria-expanded','false'); }
    }, {signal:lifecycle.signal});
    document.addEventListener('keydown', event => { if (event.key === 'Escape') closeAllMessageMenus(); }, {signal:lifecycle.signal});
    box.addEventListener('scroll', closeAllMessageMenus, {passive:true});
    window.addEventListener('resize', closeAllMessageMenus, {signal:lifecycle.signal});

    const downloadIcon = () => `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v11"/><path d="m7 10 5 5 5-5"/><path d="M5 20h14"/></svg>`;
    const attachmentDownloadButton = (downloadUrl, name) => {
        const button=document.createElement('a'); button.href=downloadUrl||'#'; button.className='nc-attachment-download';
        button.setAttribute('download', name || 'attachment'); button.setAttribute('aria-label', `Download ${name || 'attachment'}`); button.title='Download attachment'; button.innerHTML=downloadIcon(); return button;
    };
    const attachmentMarkup = attachment => {
        const wrapper=document.createElement('div'); wrapper.className='nc-attachment';
        const mime=attachment.mime_type||'', previewUrl=attachment.preview_url, downloadUrl=attachment.download_url;
        const name=attachment.name||attachment.original_name||'Attachment';
        if (mime.startsWith('image/') && previewUrl) {
            const mediaWrap=document.createElement('div'); mediaWrap.className='nc-media-attachment';
            const link=document.createElement('a'); link.href=previewUrl; link.dataset.attachmentPreviewLink=''; link.className='nc-image-attachment';
            const img=document.createElement('img'); img.src=previewUrl; img.alt=name; img.loading='eager'; img.decoding='async';
            img.addEventListener('error',()=>{img.classList.add('hidden'); const fallback=document.createElement('span'); fallback.className='nc-attachment-fallback'; fallback.textContent='Image unavailable'; link.appendChild(fallback);});
            link.appendChild(img); mediaWrap.append(link, attachmentDownloadButton(downloadUrl,name)); wrapper.appendChild(mediaWrap);
        } else if (mime.startsWith('video/') && previewUrl) {
            const mediaWrap=document.createElement('div'); mediaWrap.className='nc-media-attachment';
            const video=document.createElement('video'); video.className='nc-video-attachment'; video.controls=true; video.preload='metadata';
            const source=document.createElement('source'); source.src=previewUrl; source.type=mime; video.appendChild(source);
            mediaWrap.append(video, attachmentDownloadButton(downloadUrl,name)); wrapper.appendChild(mediaWrap);
        } else {
            const card=document.createElement('div'); card.className='nc-file-attachment';
            const open=document.createElement('a'); open.href=previewUrl||downloadUrl; open.target='_blank'; open.rel='noopener'; open.className='nc-file-open';
            const iconEl=document.createElement('span'); iconEl.className='nc-file-icon'; iconEl.textContent=mime==='application/pdf'?'PDF':(mime.includes('word')||/\.docx?$/i.test(name)?'DOC':mime.includes('spreadsheet')||/\.(xls|xlsx|csv)$/i.test(name)?'XLS':mime.includes('presentation')||/\.(ppt|pptx)$/i.test(name)?'PPT':'FILE');
            const text=document.createElement('span'); text.className='min-w-0'; const strong=document.createElement('strong'); strong.className='block truncate'; strong.textContent=name;
            const small=document.createElement('small'); small.textContent=`${formatBytes(Number(attachment.size||0))} · ${previewUrl?'Open':'Download'}`; text.append(strong,small); open.append(iconEl,text); card.append(open,attachmentDownloadButton(downloadUrl,name)); wrapper.appendChild(card);
        }
        return wrapper;
    };
    const renderAttachments = (row, attachments=[]) => { row.querySelector('.nc-attachments')?.remove(); if (!attachments.length) return; const wrap=document.createElement('div'); wrap.className='nc-attachments'; attachments.forEach(a=>wrap.appendChild(attachmentMarkup(a))); row.querySelector('.nc-bubble')?.appendChild(wrap); };

    const buildActionMenu = () => {
        const wrap=document.createElement('div'); wrap.className='nc-message-menu-wrap';
        const more=document.createElement('button'); more.type='button'; more.className='nc-message-more'; more.setAttribute('aria-label','Message actions'); more.setAttribute('aria-expanded','false'); more.title='Message actions'; more.innerHTML=icon('<circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/>');
        wrap.append(more); return wrap;
    };

    const buildMessageRow = m => {
        const row=document.createElement('div'); const deleted=!!m.deleted_at;
        row.className='nc-message-row'+(Number(m.user_id)===currentUserId?' mine':'')+(deleted?' is-deleted':''); row.dataset.messageId=m.id; row.dataset.revision=m.revision||''; row.dataset.editedAt=m.edited_at||''; row.dataset.createdAt=m.created_at||''; row.dataset.userId=m.user_id;
        const canEdit=canWrite && Number(m.user_id)===currentUserId && !!m.body && !deleted && (new Date(m.created_at).getTime()+editWindowMinutes*60000>Date.now());
        const canDelete=canWrite && Number(m.user_id)===currentUserId && !deleted && (new Date(m.created_at).getTime()+deleteWindowMinutes*60000>Date.now());
        row.dataset.editable=canEdit?'1':'0'; row.dataset.deletable=canDelete?'1':'0';
        const bubble=document.createElement('div'); bubble.className='nc-bubble';
        const head=document.createElement('div'); head.className='nc-message-head'; const meta=document.createElement('div'); meta.className='nc-message-meta'; meta.textContent=`${m.user_name} · ${formatTime(m.created_at)}`;
        if(m.edited_at){meta.append(' · '); const edited=document.createElement('span'); edited.className='nc-edited-label'; edited.textContent='Edited'; meta.appendChild(edited);} head.appendChild(meta);
        if(!deleted && canWrite) head.appendChild(buildActionMenu()); bubble.appendChild(head);
        if(deleted){ const d=document.createElement('div'); d.className='nc-deleted-message'; d.innerHTML=`${icon('<path d="M12 9v4"/><path d="M12 17h.01"/><circle cx="12" cy="12" r="9"/>')}<span>This message was unsent</span>`; bubble.appendChild(d); }
        else {
            if(m.reply_to){ const reply=document.createElement('button'); reply.type='button'; reply.className='nc-reply-preview'; reply.dataset.jumpMessage=m.reply_to.id; const strong=document.createElement('strong'); strong.textContent=`Replying to ${m.reply_to.user_name||'employee'}`; const span=document.createElement('span'); span.textContent=m.reply_to.body||'Attachment'; reply.append(strong,span); bubble.appendChild(reply); }
            if(m.forwarded_from){ const f=document.createElement('div'); f.className='nc-forwarded-label'; f.textContent='Forwarded from '+(m.forwarded_from.user_name||'employee'); bubble.appendChild(f); }
            if(m.body){ const body=document.createElement('div'); body.className='nc-message-body whitespace-pre-wrap break-words'; body.textContent=m.body; bubble.appendChild(body); }

        }
        row.appendChild(bubble); if(!deleted && m.attachments?.length) renderAttachments(row,m.attachments); return row;
    };

    const updateRowBody=(row,body,editedAt)=>{ let bodyEl=row.querySelector('.nc-message-body'); if(!bodyEl){bodyEl=document.createElement('div');bodyEl.className='nc-message-body whitespace-pre-wrap break-words';row.querySelector('.nc-bubble')?.appendChild(bodyEl);} bodyEl.textContent=body; let edited=row.querySelector('.nc-edited-label'); if(!edited){edited=document.createElement('span');edited.className='nc-edited-label';row.querySelector('.nc-message-meta')?.append(' · ',edited);} edited.textContent='Edited';row.dataset.editedAt=editedAt||''; };
    const markDeleted=(row)=>{ row.classList.add('is-deleted'); row.querySelector('.nc-message-menu-wrap')?.remove(); row.querySelector('.nc-reply-preview')?.remove(); row.querySelector('.nc-forwarded-label')?.remove(); row.querySelector('.nc-message-body')?.remove(); row.querySelector('.nc-attachments')?.remove(); const bubble=row.querySelector('.nc-bubble'); if(!bubble.querySelector('.nc-deleted-message')){const d=document.createElement('div');d.className='nc-deleted-message';d.innerHTML=`${icon('<path d="M12 9v4"/><path d="M12 17h.01"/><circle cx="12" cy="12" r="9"/>')}<span>This message was unsent</span>`;bubble.appendChild(d);} row.dataset.editable='0';row.dataset.deletable='0'; };
    const scheduleWindow=(row,minutes,dataAttr)=>{
        if(row.dataset[dataAttr]!=='1')return;
        const remaining=new Date(row.dataset.createdAt).getTime()+minutes*60000-Date.now();
        if(remaining<=0){row.dataset[dataAttr]='0';return;}
        later(()=>{row.dataset[dataAttr]='0';},remaining);
    };
    const addMessage=m=>{if(!active)return;if(m.id&&seen.has(String(m.id)))return;const near=box.scrollHeight-box.scrollTop-box.clientHeight<140;if(m.id)seen.add(String(m.id));document.getElementById('empty-chat')?.remove();const row=buildMessageRow(m);const rows=[...box.querySelectorAll('[data-message-id]')];const before=rows.find(existing=>compareMessages(m,{id:existing.dataset.messageId,created_at:existing.dataset.createdAt})<0);if(before)box.insertBefore(row,before);else box.appendChild(row);scheduleWindow(row,editWindowMinutes,'editable');scheduleWindow(row,deleteWindowMinutes,'deletable');if(near||Number(m.user_id)===currentUserId)scrollBottom();};


    document.getElementById('load-history')?.addEventListener('click',async event=>{
        const button=event.currentTarget, first=box.querySelector('[data-message-id]');
        if(!first)return;button.disabled=true;
        const height=box.scrollHeight, position=box.scrollTop;
        try{
            const response=await fetch('/conversations/'+conversationId+'/history?before='+encodeURIComponent(first.dataset.messageId),{credentials:'same-origin',headers:{Accept:'application/json'}});
            const payload=await response.json();if(!response.ok)throw new Error(payload.message||'Could not load earlier messages.');
            scrolling.pause();
            payload.messages.forEach(addMessage);button.hidden=!payload.has_more;
            box.scrollTop=position+box.scrollHeight-height;
            scrolling.resume();
        }catch(error){showError(error.message);}finally{scrolling.resume();button.disabled=false;}
    });

    const openEdit=async row=>{if(row.dataset.userId!=currentUserId||row.dataset.editable!=='1')return;const bodyEl=row.querySelector('.nc-message-body');if(!bodyEl||row.querySelector('.nc-edit-form'))return;const original=bodyEl.textContent;const form=document.createElement('form');form.className='nc-edit-form';const textarea=document.createElement('textarea');textarea.className='nc-input';textarea.maxLength=10000;textarea.value=original;textarea.rows=3;const actions=document.createElement('div');actions.className='nc-edit-actions';const cancel=document.createElement('button');cancel.type='button';cancel.className='nc-btn-secondary';cancel.textContent='Cancel';const save=document.createElement('button');save.type='submit';save.className='nc-btn';save.textContent='Save';actions.append(cancel,save);form.append(textarea,actions);bodyEl.replaceWith(form);textarea.focus();cancel.addEventListener('click',()=>form.replaceWith(bodyEl));form.addEventListener('submit',async e=>{e.preventDefault();const value=textarea.value.trim();if(!value)return;save.disabled=cancel.disabled=true;try{const response=await fetch(`${config.messagesUrl}/${row.dataset.messageId}`,{method:'PATCH',credentials:'same-origin',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify({body:value})});const payload=await response.json().catch(()=>({}));if(!response.ok)throw new Error(payload.message||payload.errors?.body?.[0]||'The message could not be edited.');updateRowBody(row,payload.message.body,payload.message.edited_at);form.remove();row.dataset.editable='0';window.PharmaFeedback?.success('Message updated.');}catch(error){showError(error.message);save.disabled=cancel.disabled=false;}});};
    const setReply=row=>{const body=row.querySelector('.nc-message-body')?.textContent||(row.querySelector('.nc-attachments')?'Attachment':'');replyToMessage={id:Number(row.dataset.messageId),user_name:row.querySelector('.nc-message-meta')?.textContent?.split(' · ')[0]||'Employee',body};replyUser.textContent=replyToMessage.user_name;replyPreview.textContent=replyToMessage.body||'Attachment';replyBar?.classList.remove('hidden');input?.focus();};
    const clearReply=()=>{replyToMessage=null;replyBar?.classList.add('hidden');};
    document.getElementById('cancel-reply')?.addEventListener('click',clearReply);
    const openForward=row=>{forwardMessageId=Number(row.dataset.messageId);forwardError?.classList.add('hidden');forwardModal?.classList.remove('hidden');};
    const closeForward=()=>{forwardMessageId=null;forwardModal?.classList.add('hidden');document.querySelectorAll('#forward-list input[type=checkbox]').forEach(cb=>cb.checked=false);};
    document.getElementById('close-forward')?.addEventListener('click',closeForward);document.getElementById('cancel-forward')?.addEventListener('click',closeForward);forwardModal?.addEventListener('click',e=>{if(e.target===forwardModal)closeForward();});
    document.getElementById('confirm-forward')?.addEventListener('click',async()=>{if(!forwardMessageId)return;const ids=[...document.querySelectorAll('#forward-list input[type=checkbox]:checked')].map(cb=>Number(cb.value));if(!ids.length){forwardError.textContent='Select at least one conversation.';forwardError.classList.remove('hidden');window.PharmaFeedback?.error(forwardError.textContent);return;}const button=document.getElementById('confirm-forward');button.disabled=true;try{const response=await fetch(`${config.messagesUrl}/${forwardMessageId}/forward`,{method:'POST',credentials:'same-origin',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify({conversation_ids:ids})});const payload=await response.json().catch(()=>({}));if(!response.ok)throw new Error(payload.message||Object.values(payload.errors||{}).flat()[0]||'The message could not be forwarded.');closeForward();window.PharmaFeedback?.success('Message forwarded.');}catch(error){forwardError.textContent=error.message;forwardError.classList.remove('hidden');window.PharmaFeedback?.error(error.message);}finally{button.disabled=false;}});

    const unsend=async row=>{ if(row.dataset.userId!=currentUserId||row.dataset.deletable!=='1')return; const result=await Swal.fire({title:'Unsend message?',text:'This will remove the message and its attachments for everyone in this conversation.',icon:'warning',showCancelButton:true,confirmButtonText:'Unsend message',cancelButtonText:'Keep message',reverseButtons:true,focusCancel:true,buttonsStyling:false,customClass:{popup:'nc-swal-popup',title:'nc-swal-title',htmlContainer:'nc-swal-text',confirmButton:'nc-swal-confirm danger',cancelButton:'nc-swal-cancel'}}); if(!result.isConfirmed)return; try{const response=await fetch(`${config.messagesUrl}/${row.dataset.messageId}`,{method:'DELETE',credentials:'same-origin',headers:{'Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content}});const payload=await response.json().catch(()=>({}));if(!response.ok)throw new Error(payload.message||payload.errors?.message?.[0]||'The message could not be unsent.');markDeleted(row);await Swal.fire({title:'Message unsent',text:'The message has been removed for everyone.',icon:'success',timer:1500,showConfirmButton:false});}catch(error){showError(error.message);} };

    globalMessageMenu.addEventListener('click', event => {
        const action = event.target.closest('[data-message-action]');
        if (!action || !activeMessageMenuRow) return;
        const row = activeMessageMenuRow;
        const type = action.dataset.messageAction;
        closeAllMessageMenus();
        if (type === 'reply') setReply(row);
        else if (type === 'forward') openForward(row);
        else if (type === 'edit') openEdit(row);
        else if (type === 'delete') unsend(row);
    });

    const renderSelection=()=>{selection.innerHTML='';if(!selectedFiles.length){selection.classList.add('hidden');return;}selection.classList.remove('hidden');selectedFiles.forEach((file,index)=>{const item=document.createElement('div');item.className='nc-selected-file';const name=document.createElement('span');name.className='min-w-0 truncate';name.textContent=`${file.name} · ${formatBytes(file.size)}`;const remove=document.createElement('button');remove.type='button';remove.className='nc-selected-file-remove';remove.textContent='×';remove.setAttribute('aria-label',`Remove ${file.name}`);remove.addEventListener('click',()=>{selectedFiles.splice(index,1);renderSelection();});item.append(name,remove);selection.appendChild(item);});};
    const validateSelectedFilesClient=()=>{const maxTotal=config.attachments.maxTotalMb*1024*1024,imageMax=config.attachments.maxImageMb*1024*1024,videoMax=config.attachments.maxVideoMb*1024*1024,documentMax=config.attachments.maxDocumentMb*1024*1024,total=selectedFiles.reduce((s,f)=>s+f.size,0);if(total>maxTotal)return`The combined attachment size cannot exceed ${formatBytes(maxTotal)}.`;for(const file of selectedFiles){const limit=file.type.startsWith('image/')?imageMax:(file.type.startsWith('video/')?videoMax:documentMax);if(file.size>limit)return`${file.name} is too large. The maximum for this file type is ${formatBytes(limit)}.`;}return null;};
    const addFiles=fileList=>{const maxFiles=config.attachments.maxFiles,maxTotalBytes=config.attachments.maxTotalMb*1024*1024,existingKeys=new Set(selectedFiles.map(file=>`${file.name}:${file.size}:${file.lastModified}`));let rejected=false;[...fileList].forEach(file=>{const key=`${file.name}:${file.size}:${file.lastModified}`;if(existingKeys.has(key))return;if(selectedFiles.length>=maxFiles){rejected=true;return;}selectedFiles.push(file);existingKeys.add(key);});const total=selectedFiles.reduce((s,f)=>s+f.size,0);if(rejected)showError(`You can attach up to ${maxFiles} files per message.`);else if(total>maxTotalBytes)showError(`The combined attachment size cannot exceed ${formatBytes(maxTotalBytes)}.`);else clearError();renderSelection();};
    attachmentInput?.addEventListener('change',()=>{addFiles(attachmentInput.files);attachmentInput.value='';attachmentMenu.classList.add('hidden');attachmentMenuButton.setAttribute('aria-expanded','false');});
    attachmentMenuButton?.addEventListener('click',()=>{const open=!attachmentMenu.classList.contains('hidden');attachmentMenu.classList.toggle('hidden',open);attachmentMenuButton.setAttribute('aria-expanded',String(!open));});
    document.getElementById('add-photos-files')?.addEventListener('click',()=>{attachmentInput?.click();attachmentMenu?.classList.add('hidden');attachmentMenuButton?.setAttribute('aria-expanded','false');});
    composer?.addEventListener('dragover',e=>{e.preventDefault();composer.classList.add('dragging');});composer?.addEventListener('dragleave',()=>composer.classList.remove('dragging'));composer?.addEventListener('drop',e=>{e.preventDefault();composer.classList.remove('dragging');addFiles(e.dataTransfer.files);});
    input?.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey&&window.innerWidth>520){e.preventDefault();composer.requestSubmit();}});

    box.querySelectorAll('[data-message-id]').forEach(row=>{scheduleWindow(row,editWindowMinutes,'editable');scheduleWindow(row,deleteWindowMinutes,'deletable');});
    scrollBottom();
    const markRead = initializeChatReadState(document, window, box, config, lifecycle.signal);
    window.addEventListener('pharma:message-reconcile', event => {
        if (!active || Number(event.detail.conversationId) !== Number(conversationId)) return;
        reconcileMessageRows({box, seen, messages: event.detail.messages, removed: event.detail.removed, buildRow: buildMessageRow, schedule: row => {
            scheduleWindow(row, editWindowMinutes, 'editable');
            scheduleWindow(row, deleteWindowMinutes, 'deletable');
        }});
    }, {signal:lifecycle.signal});
    window.addEventListener('pharma:recovery-complete', event => {
        if (!active || Number(event.detail.conversationId) !== Number(conversationId)) return;
        config.recoveryCursor = event.detail.cursor;
        markRead();
    }, {signal:lifecycle.signal});
    window.addEventListener('pharma:message', event => {
        if (!active || Number(event.detail.conversationId) !== Number(conversationId)) return;
        addMessage(event.detail.message);
        markRead();
    }, {signal:lifecycle.signal});
    window.PharmaStrategies.listenConversation(conversationId, message => {
        if (!active) return;
        addMessage(message);
        markRead();
        window.dispatchEvent(new CustomEvent('pharma:chat-list-refresh'));
    });
    window.PharmaStrategies.listenConversationEdits(conversationId,event=>{if(!active)return;const row=box.querySelector(`[data-message-id="${event.id}"]`);if(row&&!row.classList.contains('is-deleted'))updateRowBody(row,event.body,event.edited_at);});
    window.PharmaStrategies.listenConversationDeletes(conversationId,event=>{if(!active)return;const row=box.querySelector(`[data-message-id="${event.id}"]`);if(row)markDeleted(row);});


    composer?.addEventListener('submit',async event=>{event.preventDefault();if(sendButton.disabled)return;clearError();const body=input.value.trim();if(!body&&!selectedFiles.length)return;const fileError=validateSelectedFilesClient();if(fileError){showError(fileError);return;}sendButton.disabled=true;input.readOnly=true;sendButton.querySelector('span').textContent=selectedFiles.length?'Uploading…':'Sending…';try{const formData=new FormData();formData.append('client_message_id',submission.key(body,replyToMessage?.id||null,selectedFiles));if(body)formData.append('body',body);if(replyToMessage?.id)formData.append('reply_to_message_id',replyToMessage.id);selectedFiles.forEach(file=>formData.append('attachments[]',file,file.name));const response=await fetch(config.sendUrl,{method:'POST',credentials:'same-origin',headers:{'Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:formData});const payload=await response.json().catch(()=>({}));if(!response.ok)throw new Error(payload.message||Object.values(payload.errors||{}).flat()[0]||'The message could not be sent.');if(payload.message)addMessage({id:payload.message.id,body:payload.message.body,user_id:payload.message.user_id,user_name:payload.message.user?.name||config.currentUserName,created_at:payload.message.created_at,edited_at:payload.message.edited_at,reply_to:payload.message.reply_to||null,forwarded_from:payload.message.forwarded_from||null,deleted_at:payload.message.deleted_at||null,attachments:payload.message.attachments||[]});submission.reset();input.value='';resizeMessageInput();selectedFiles.splice(0,selectedFiles.length);renderSelection();clearReply();window.dispatchEvent(new CustomEvent('pharma:chat-list-refresh'));}catch(error){showError(error.message);}finally{sendButton.disabled=false;input.readOnly=false;sendButton.querySelector('span').textContent='Send';if(active)input.focus({preventScroll:true});}});

    document.querySelectorAll('[data-attachment-preview-image]').forEach(image => {
        image.addEventListener('error', () => {
            const link = image.closest('[data-attachment-preview-link]');
            if (!link) return;
            image.classList.add('hidden');
            link.classList.add('nc-attachment-preview-missing');
            link.querySelector('.nc-attachment-fallback')?.classList.remove('hidden');
        }, {once:true});
    });

    const showConversationList = () => {
        window.dispatchEvent(new CustomEvent('pharma:chat-close'));
    };
    document.getElementById('close-chat')?.addEventListener('click', showConversationList);
    document.getElementById('mobile-back')?.addEventListener('click', showConversationList);
    return () => { window.dispatchEvent(new CustomEvent('pharma:close-image')); active = false; lifecycle.abort(); timers.forEach(clearTimeout); scrolling.dispose(); globalMessageMenu.remove(); window.Echo?.leave('conversation.' + conversationId); };
}
