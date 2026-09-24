function toggleSidebar(){var s=document.getElementById('sidebar'),b=document.getElementById('backdrop');if(s)s.classList.toggle('open');if(b)b.classList.toggle('show');}
function toggleTheme(){var d=document.body.classList.toggle('dark');try{localStorage.setItem('erp_dark',d?'1':'0');}catch(e){}var b=document.getElementById('themeBtn');if(b)b.textContent=d?'☀️':'🌙';}
(function(){try{if(localStorage.getItem('erp_dark')==='1'){document.body.classList.add('dark');var b=document.getElementById('themeBtn');if(b)b.textContent='☀️';}}catch(e){}})();
function openForm(){var m=document.getElementById('modalBg');if(!m)return;document.getElementById('mAction').value='add';document.getElementById('mId').value='';document.getElementById('mTitle').textContent='Add';m.querySelectorAll('[name]').forEach(function(el){if(['csrf','_action','id'].indexOf(el.name)<0){if(el.tagName==='SELECT')el.selectedIndex=0;else el.value='';}});m.classList.add('open');}
function editRow(btn){var m=document.getElementById('modalBg');var r=JSON.parse(btn.getAttribute('data-rec'));document.getElementById('mAction').value='update';document.getElementById('mId').value=r.id;document.getElementById('mTitle').textContent='Edit';m.querySelectorAll('[name]').forEach(function(el){if(r[el.name]!==undefined&&r[el.name]!==null)el.value=r[el.name];});m.classList.add('open');}
function closeForm(){var m=document.getElementById('modalBg');if(m)m.classList.remove('open');}
function filterTable(inp){var q=inp.value.toLowerCase();var scope=(inp.closest&&inp.closest('.card'))||document;var rows=scope.querySelectorAll('table tbody tr');if(!rows.length)rows=document.querySelectorAll('#mainTable tbody tr');rows.forEach(function(tr){tr.style.display=tr.textContent.toLowerCase().indexOf(q)>-1?'':'none';});}

/* ---- click ripple animation ---- */
(function(){
  var SEL = '.btn,.tb-add,.tb-icon,.nav a,.bottomnav a,.metric,.kc';
  document.addEventListener('click', function(e){
    var t = e.target.closest(SEL);
    if(!t) return;
    var rect = t.getBoundingClientRect();
    var size = Math.max(rect.width, rect.height);
    var ink  = document.createElement('span');
    ink.className = 'ripple-ink';
    ink.style.width = ink.style.height = size + 'px';
    ink.style.left = (e.clientX - rect.left - size/2) + 'px';
    ink.style.top  = (e.clientY - rect.top  - size/2) + 'px';
    t.appendChild(ink);
    setTimeout(function(){ if(ink.parentNode) ink.parentNode.removeChild(ink); }, 600);
  }, false);
})();



/* ============================================================
   Live Notification Center — badge + dropdown + toasts
   Polls every 8s, renders instantly from cache, marks read inline
   ============================================================ */
(function(){
  var CSRF = window.ERP_CSRF || '';
  var state = {items:[], unread:0};
  var ICONS = {order:'🛒',delivered:'✅',status:'🔄',ncm:'🚚',comment:'💬',alert:'⚠️',info:'🔔'};
  var COLORS= {order:'#3b82f6',delivered:'#10b981',status:'#6366f1',ncm:'#f59e0b',comment:'#8b5cf6',alert:'#ef4444',info:'#64748b'};

  function esc(t){var d=document.createElement('div');d.textContent=t;return d.innerHTML;}
  function ago(ts){
    var s=Math.max(1,Math.floor(Date.now()/1000-ts));
    if(s<60)return 'just now';
    if(s<3600)return Math.floor(s/60)+'m ago';
    if(s<86400)return Math.floor(s/3600)+'h ago';
    if(s<172800)return 'yesterday';
    return Math.floor(s/86400)+'d ago';
  }

  /* ---- badge ---- */
  function paintBadge(){
    var b=document.getElementById('notifBadge'); if(!b)return;
    if(state.unread>0){ b.style.display='grid'; var was=b.textContent; b.textContent=state.unread>99?'99+':state.unread;
      if(was!==b.textContent){ b.classList.remove('pop'); void b.offsetWidth; b.classList.add('pop'); } }
    else b.style.display='none';
    var c=document.getElementById('nhCount'); if(c) c.textContent=state.unread>0?('· '+state.unread+' new'):'';
  }

  /* ---- dropdown list ---- */
  function paintList(){
    var box=document.getElementById('notifList'); if(!box)return;
    if(!state.items.length){ box.innerHTML='<div class="notif-empty"><div style="font-size:26px;margin-bottom:6px">🔕</div>All caught up!</div>'; return; }
    box.innerHTML=state.items.map(function(n){
      var col=COLORS[n.type]||COLORS.info, ico=ICONS[n.type]||'🔔';
      return '<a class="notif-item'+(n.is_read?'':' unread')+'" href="'+esc(n.link||'notifications.php')+'" data-id="'+n.id+'">'
        +'<span class="ni-ico" style="background:'+col+'1a;color:'+col+'">'+ico+'</span>'
        +'<span class="ni-body"><span class="nm">'+esc(n.message)+'</span><span class="nt">'+ago(n.ts)+'</span></span>'
        +(n.is_read?'':'<span class="ni-dot" style="background:'+col+'"></span>')
        +'</a>';
    }).join('');
    Array.prototype.forEach.call(box.querySelectorAll('.notif-item'),function(el){
      el.addEventListener('click',function(){ markRead(el.getAttribute('data-id')); });
    });
  }

  function markRead(id){
    var it=state.items.find(function(x){return String(x.id)===String(id);});
    if(it&&!it.is_read){ it.is_read=1; state.unread=Math.max(0,state.unread-1); paintBadge(); paintList(); }
    var fd='ajax_read='+encodeURIComponent(id)+'&csrf='+encodeURIComponent(CSRF);
    fetch('notifications.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},credentials:'same-origin',body:fd}).catch(function(){});
  }
  window.markAllNotif=function(e){
    if(e){e.preventDefault();e.stopPropagation();}
    state.items.forEach(function(x){x.is_read=1;}); state.unread=0; paintBadge(); paintList();
    fetch('notifications.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},credentials:'same-origin',
      body:'ajax_read=all&csrf='+encodeURIComponent(CSRF)}).catch(function(){});
  };

  /* ---- toasts with progress bar ---- */
  function toast(n){
    var wrap=document.getElementById('toastWrap'); if(!wrap)return;
    var col=COLORS[n.type]||COLORS.info, ico=ICONS[n.type]||'🔔';
    var el=document.createElement('a'); el.className='toast'; el.href=n.link||'notifications.php';
    el.style.borderLeftColor=col;
    el.innerHTML='<span class="ti" style="background:'+col+'1a;color:'+col+'">'+ico+'</span>'
      +'<span class="tm">'+esc(n.message)+'<span class="tt">'+ago(n.ts)+'</span></span>'
      +'<span class="tx">✕</span><span class="tp" style="background:'+col+'"></span>';
    el.querySelector('.tx').onclick=function(ev){ev.preventDefault();ev.stopPropagation();dismiss();};
    el.addEventListener('click',function(){ markRead(n.id); });
    wrap.appendChild(el);
    requestAnimationFrame(function(){ el.classList.add('show'); });
    var t=setTimeout(dismiss,7000);
    function dismiss(){ clearTimeout(t); el.classList.remove('show'); setTimeout(function(){el.remove();},350); }
  }

  /* ---- polling ---- */
  var KEY='luprah_lastNotif';
  function apply(d){
    if(!d||!d.items)return;
    var now=Math.floor(Date.now()/1000);
    d.items.forEach(function(n){ n.ts = now - (n.age||0); });   /* age from server = timezone-proof */
    state.items=d.items; state.unread=d.unread||0;
    paintBadge(); paintList();
    try{
      var last=parseInt(localStorage.getItem(KEY)||'0',10), maxId=last;
      d.items.forEach(function(n){ if(n.id>maxId)maxId=n.id; });
      if(last>0){ d.items.filter(function(n){return n.id>last;}).sort(function(a,b){return a.id-b.id;}).forEach(toast); }
      if(maxId>last) localStorage.setItem(KEY,String(maxId));
    }catch(e){}
  }
  function poll(){
    fetch('notifications.php?poll=1',{credentials:'same-origin'})
      .then(function(r){return r.json();}).then(apply).catch(function(){});
  }

  /* dropdown open/close */
  window.toggleNotif=function(e){
    if(e)e.stopPropagation();
    var p=document.getElementById('notifPanel'); if(!p)return;
    p.classList.toggle('open');
    if(p.classList.contains('open')) paintList();
  };
  document.addEventListener('click',function(e){
    var p=document.getElementById('notifPanel');
    if(p&&p.classList.contains('open')&&!e.target.closest('.notif-wrap'))p.classList.remove('open');
  });

  if(document.getElementById('notifBell')){
    poll(); setInterval(poll, 8000);
    setInterval(function(){ var p=document.getElementById('notifPanel'); if(p&&p.classList.contains('open')) paintList(); }, 30000);
  }
})();

/* ---- PWA: register service worker so the ERP can be installed as an app ---- */
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function(){ navigator.serviceWorker.register('sw.js').catch(function(){}); });
}

/* entity-aware CRUD modal (namespaced ids: mbg_<entity> etc.) */
function crudOpen(n){var m=document.getElementById('mbg_'+n);if(!m)return;
  document.getElementById('mAction_'+n).value='add';document.getElementById('mId_'+n).value='';
  document.getElementById('mTitle_'+n).textContent='Add';
  m.querySelectorAll('[name]').forEach(function(el){if(['csrf','_action','_entity','id'].indexOf(el.name)<0){
    if(el.type==='file'){el.value='';var p=document.getElementById('preview_'+el.name);if(p){p.style.display='none';p.src='';}}
    else if(el.tagName==='SELECT')el.selectedIndex=0;else el.value='';
  }});
  m.classList.add('open');document.body.classList.add('modal-open');}
function crudEdit(n,btn){var m=document.getElementById('mbg_'+n);if(!m)return;
  var r=JSON.parse(btn.getAttribute('data-rec'));
  document.getElementById('mAction_'+n).value='update';document.getElementById('mId_'+n).value=r.id;
  document.getElementById('mTitle_'+n).textContent='Edit';
  m.querySelectorAll('[name]').forEach(function(el){
    if(el.type==='file'){el.value='';var p=document.getElementById('preview_'+el.name);if(p){if(r[el.name]){p.src=r[el.name];p.style.display='';}else{p.style.display='none';p.src='';}}return;}
    if(r[el.name]!==undefined&&r[el.name]!==null)el.value=r[el.name];
  });
  m.classList.add('open');document.body.classList.add('modal-open');}
function crudClose(n){var m=document.getElementById('mbg_'+n);if(m)m.classList.remove('open');document.body.classList.remove('modal-open');}