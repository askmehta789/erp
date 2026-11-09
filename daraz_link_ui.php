<?php
/* The shared "Link / check by Daraz order ID" window, used by Daraz Sales and the
   Dashboard. Orders are booked by hand in the ERP; this window links an ERP order to
   its Daraz order number and syncs only the STATUS and the DARAZ CHARGES.
   Everything Daraz sends is written with textContent (never innerHTML). */
function daraz_link_ui(): string {
  $csrf = csrf();
  ob_start(); ?>
<div class="modal-bg" id="dzlModal" style="z-index:99990"><div class="modal" style="width:780px;max-width:96vw">
  <div class="modal-head"><span id="dzlTitle">🔗 Link / check a Daraz order</span><span class="mx" onclick="dzlClose()">✕</span></div>
  <div class="modal-body" style="display:block">
    <div id="dzlFor" class="dzl-note warn" style="display:none;margin:0 0 8px"></div>
    <div id="dzlSingleBar" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <input id="dzlId" placeholder="Daraz order number, e.g. 204188712345678" style="flex:1;min-width:220px" onkeydown="if(event.key==='Enter'){event.preventDefault();dzlCheck();}">
      <label style="display:flex;gap:6px;align-items:center;font-size:12px;margin:0"><input type="checkbox" id="dzlFin" checked style="width:auto"> include real charges</label>
      <button type="button" class="btn btn-primary" onclick="dzlCheck()">Check with Daraz</button>
    </div>
    <div id="dzlOut" style="margin-top:10px"></div>
  </div>
  <div class="modal-foot">
    <button type="button" class="btn" onclick="dzlClose()">Close</button>
    <button type="button" class="btn btn-primary" id="dzlApply" style="display:none" onclick="dzlApply()">⬇ Sync status &amp; charges</button>
  </div>
</div></div>
<script>
var DZL_CSRF=<?= json_encode($csrf) ?>, dzlCur=null, dzlChanged=false, dzlForErp=null;
function dzlEl(tag,attrs,txt){var e=document.createElement(tag);if(attrs)for(var k in attrs){if(k==='class')e.className=attrs[k];else if(k==='style')e.style.cssText=attrs[k];else e.setAttribute(k,attrs[k]);}if(txt!==undefined&&txt!==null)e.textContent=txt;return e;}
function dzlMoney(v){if(v===null||v===undefined||v==='—')return '—';var n=parseFloat(v);if(isNaN(n))return String(v);return 'Rs. '+Math.round(n).toLocaleString('en-IN');}
function dzlNote(cls,txt){return dzlEl('div',{'class':'dzl-note '+cls},txt);}
function dzlPost(params){params.csrf=DZL_CSRF;return fetch('daraz_link.php',{method:'POST',credentials:'same-origin',body:new URLSearchParams(params)}).then(function(r){return r.json();});}
function dzlShow(){document.getElementById('dzlModal').classList.add('open');document.body.classList.add('modal-open');}
/* open for a Daraz number (or empty to type one) */
function dzlOpen(id){
  dzlCur=null; dzlForErp=null;
  document.getElementById('dzlTitle').textContent='🔗 Link / check a Daraz order';
  document.getElementById('dzlFor').style.display='none';
  document.getElementById('dzlSingleBar').style.display='flex';
  document.getElementById('dzlOut').replaceChildren();
  document.getElementById('dzlApply').style.display='none';
  document.getElementById('dzlId').value=id||'';
  dzlShow();
  if(id)dzlCheck(); else setTimeout(function(){document.getElementById('dzlId').focus();},50);
}
/* open from an unlinked ERP order row: type its Daraz number, check, then link */
function dzlOpenFor(erpId,code,summary){
  dzlOpen('');
  dzlForErp={id:erpId,code:code};
  document.getElementById('dzlTitle').textContent='🔗 Link ERP order '+code+' to Daraz';
  var f=document.getElementById('dzlFor');f.style.display='';f.textContent='Linking ERP order '+code+(summary?' — '+summary:'')+'. Enter its Daraz order number and press Check.';
}
function dzlClose(){document.getElementById('dzlModal').classList.remove('open');document.body.classList.remove('modal-open');if(dzlChanged)location.reload();}
function dzlCheck(){
  var id=(document.getElementById('dzlId').value||'').replace(/\D+/g,''), out=document.getElementById('dzlOut');
  document.getElementById('dzlApply').style.display='none';
  if(!id){out.replaceChildren(dzlNote('bad','Enter a Daraz order number first.'));return;}
  out.replaceChildren(dzlEl('div',{'class':'muted'},'Asking Daraz for order #'+id+'…'));
  var fin=document.getElementById('dzlFin').checked?'1':'0';
  fetch('daraz_link.php?action=check&id='+encodeURIComponent(id)+'&fin='+fin,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
    if(!j.ok){
      out.replaceChildren(dzlNote('bad',j.error||'Could not check this order.'));
      /* opened from an ERP row: still allow saving the link, it syncs once Daraz answers */
      if(dzlForErp){var lb=dzlEl('button',{'class':'btn btn-sm',type:'button',style:'margin-top:8px'},'🔗 Link '+dzlForErp.code+' to #'+id+' without checking');
        lb.onclick=function(){dzlLink(dzlForErp.id,null,id);};out.appendChild(lb);}
      return;
    }
    dzlCur=j; dzlRender(j,out);
    document.getElementById('dzlApply').style.display=j.compare.linked?'':'none';
  }).catch(function(){out.replaceChildren(dzlNote('bad','Network error — could not reach the ERP.'));});
}
function dzlLink(erpId,erpCode,darazId){
  var out=document.getElementById('dzlOut');
  var p={action:'link',id:darazId||(dzlCur&&dzlCur.order.id)||''};if(erpId)p.erp_id=erpId;else p.erp_code=erpCode;
  dzlPost(p).then(function(j){
    if(!j.ok){out.insertBefore(dzlNote('bad',j.error||'Could not link.'),out.firstChild);return;}
    dzlChanged=true;dzlForErp=null;document.getElementById('dzlFor').style.display='none';
    if(darazId){out.replaceChildren(dzlNote('ok','✓ Linked '+j.linked.code+' to Daraz #'+p.id+'. Press 🔄 on the row later to sync status and charges.'));return;}
    dzlCheck();
  }).catch(function(){out.insertBefore(dzlNote('bad','Network error.'),out.firstChild);});
}
function dzlRender(j,out){
  var o=j.order,c=j.compare,frag=document.createDocumentFragment();
  frag.appendChild(dzlEl('div',{style:'font-weight:800;font-size:14px'},'Daraz #'+o.id+' · '+(o.customer||'—')+' · '+(o.phone||'no phone')));
  frag.appendChild(dzlEl('div',{'class':'muted',style:'font-size:12px'},'Ordered '+o.created+(o.city?' · '+o.city:'')+' · '+(o.payment==='cod'?'Cash on delivery':'Prepaid')));

  if(!c.linked){
    frag.appendChild(dzlNote('warn','This Daraz order isn’t linked to an ERP order yet. Orders are booked in Sales — pick the matching ERP order below to link it.'));
    var box=dzlEl('div',{style:'margin-top:8px'});
    if(dzlForErp){
      var b0=dzlEl('button',{'class':'btn btn-primary',type:'button',style:'margin-bottom:8px'},'🔗 Link ERP order '+dzlForErp.code+' to Daraz #'+o.id);
      b0.onclick=function(){dzlLink(dzlForErp.id,null);};box.appendChild(b0);
    }
    if(j.candidates&&j.candidates.length){
      box.appendChild(dzlEl('div',{'class':'muted',style:'font-size:12px;margin:4px 0'},'Unlinked Daraz orders in the ERP around that date (best match first):'));
      var t=dzlEl('table',{'class':'dzl-tbl'});
      j.candidates.forEach(function(k){
        var tr=dzlEl('tr',k.why?{'class':'dzl-diff'}:null);
        tr.appendChild(dzlEl('td',null,k.code+' · '+k.date));
        tr.appendChild(dzlEl('td',null,(k.customer||'—')+' · '+(k.phone||'')+(k.why?' ✓ '+k.why:'')));
        tr.appendChild(dzlEl('td',null,(k.product||'item')+' ×'+k.qty+' · '+k.status));
        var td=dzlEl('td',{'class':'r'}),b=dzlEl('button',{'class':'btn btn-sm',type:'button'},'Link');
        b.onclick=function(){dzlLink(k.id,null);};td.appendChild(b);tr.appendChild(td);t.appendChild(tr);
      });
      box.appendChild(t);
    } else if(!dzlForErp) box.appendChild(dzlNote('warn','No unlinked Daraz orders found in the ERP within 15 days of this order. Book it in Sales (courier Daraz) first, or link by ERP order code:'));
    if(!dzlForErp){
      var row=dzlEl('div',{style:'display:flex;gap:6px;margin-top:8px'}),inp=dzlEl('input',{placeholder:'ERP order code, e.g. ORD-0123',style:'flex:1'}),bb=dzlEl('button',{'class':'btn btn-sm',type:'button'},'Link by code');
      bb.onclick=function(){if(inp.value.trim())dzlLink(null,inp.value.trim());};row.appendChild(inp);row.appendChild(bb);box.appendChild(row);
    }
    frag.appendChild(box);
    out.replaceChildren(frag);return;
  }

  if(c.diffs===0)frag.appendChild(dzlNote('ok','✓ Status, price and Daraz charges already match.'));
  else frag.appendChild(dzlNote('warn','⚠ '+c.diffs+' thing(s) to sync — highlighted below. Sync sets the status, the price the customer paid, and the Daraz charges. Qty and product stay as booked.'));
  if(c.totals.ship_as_cost)frag.appendChild(dzlNote('bad','The ERP counts the customer’s shipping ('+dzlMoney(c.totals.ship_customer)+') as your Daraz charge. Sync resets it.'));
  if(o.finance.ok)frag.appendChild(dzlNote('ok','Real Daraz charges found: '+dzlMoney(o.finance.fees)+' · payout '+dzlMoney(o.finance.payout)));
  else if(o.finance.error)frag.appendChild(dzlNote('warn',o.finance.error));
  (c.notes||[]).forEach(function(n){frag.appendChild(dzlNote('warn',n));});
  c.lines.forEach(function(L){
    var t=dzlEl('table',{'class':'dzl-tbl'}),hd=dzlEl('tr');
    ['Item: '+(L.name||L.sku||'item')+(L.erp_code?' — ERP '+L.erp_code:''),'ERP now','Daraz says'].forEach(function(h,i){hd.appendChild(dzlEl('th',i?{'class':'r'}:null,h));});
    t.appendChild(hd);
    L.fields.forEach(function(f){
      var tr=dzlEl('tr',f.diff?{'class':'dzl-diff'}:null);
      var lab=dzlEl('td',f.sync?null:{'class':'muted'},f.label);tr.appendChild(lab);
      var st=f.warn?'color:#b45309;font-weight:700':(f.sync?'':'color:var(--muted)');
      tr.appendChild(dzlEl('td',{'class':'r',style:st},f.money?dzlMoney(f.erp):String(f.erp)));
      tr.appendChild(dzlEl('td',{'class':'r',style:st},f.money?dzlMoney(f.daraz):String(f.daraz)));t.appendChild(tr);});
    frag.appendChild(t);
  });
  if(c.extra_erp.length)frag.appendChild(dzlNote('warn','ERP line(s) Daraz doesn’t list: '+c.extra_erp.map(function(x){return x.code+' ('+(x.product||'item')+' ×'+x.qty+')';}).join(', ')+' — their status is left as is.'));
  var tt=dzlEl('table',{'class':'dzl-tbl'}),h2=dzlEl('tr');
  ['Order','ERP now','After sync'].forEach(function(h,i){h2.appendChild(dzlEl('th',i?{'class':'r'}:null,h));});tt.appendChild(h2);
  var feeAfter=c.totals.fee_target===null?c.totals.erp_fees:c.totals.fee_target;
  [['Daraz charges (your cost)',c.totals.erp_fees,feeAfter,true],['Payout from Daraz','—',c.totals.payout===null?'—':c.totals.payout,false],['Order value (customer paid)',c.totals.erp_revenue,c.totals.daraz_revenue,true]].forEach(function(r){
    var d=r[3]&&Math.abs(parseFloat(r[1])-parseFloat(r[2]))>0.009,tr=dzlEl('tr',d?{'class':'dzl-diff'}:null);
    tr.appendChild(dzlEl('td',null,r[0]));tr.appendChild(dzlEl('td',{'class':'r'},dzlMoney(r[1])));tr.appendChild(dzlEl('td',{'class':'r'},dzlMoney(r[2])));tt.appendChild(tr);});
  frag.appendChild(tt);
  if(c.totals.fee_target===null&&!o.finance.ok)frag.appendChild(dzlEl('div',{'class':'muted',style:'font-size:11.5px;margin-top:6px'},'Daraz charges stay as typed in the ERP until Daraz’s finance data is available for this order.'));
  out.replaceChildren(frag);
}
function dzlApply(){
  if(!dzlCur)return;
  var btn=document.getElementById('dzlApply'),out=document.getElementById('dzlOut');btn.disabled=true;
  dzlPost({action:'apply',id:dzlCur.order.id,fin:document.getElementById('dzlFin').checked?'1':'0'}).then(function(j){
    btn.disabled=false;
    if(!j.ok){out.insertBefore(dzlNote('bad',j.error||'Sync failed.'),out.firstChild);return;}
    dzlChanged=true;var r=j.result;
    out.insertBefore(dzlNote('ok','✓ Synced — '+r.status_changed+' status change(s)'+(r.price_changed?', price updated on '+r.price_changed+' line(s)':'')+(r.fees_set?', Daraz charges updated':'')+'. The page refreshes when you close this window.'),out.firstChild);
    btn.style.display='none';
  }).catch(function(){btn.disabled=false;out.insertBefore(dzlNote('bad','Network error.'),out.firstChild);});
}
/* re-check many linked orders one by one, list only the ones that need syncing */
function dzlRecheck(ids){
  ids=(ids||[]).filter(function(x,i,a){return x&&a.indexOf(x)===i;});
  if(!ids.length){alert('None of these orders are linked to a Daraz order number yet. Link them first (🔗 on each row).');return;}
  dzlOpen('');document.getElementById('dzlSingleBar').style.display='none';
  document.getElementById('dzlTitle').textContent='🔄 Check '+ids.length+' linked order(s) with Daraz';
  var out=document.getElementById('dzlOut'),prog=dzlEl('div',{'class':'muted'},'Starting…'),tbl=dzlEl('table',{'class':'dzl-tbl'}),hd=dzlEl('tr');
  ['Daraz order','To sync','',''].forEach(function(h){hd.appendChild(dzlEl('th',null,h));});tbl.appendChild(hd);
  var allBtn=dzlEl('button',{'class':'btn btn-sm btn-primary',type:'button',style:'margin-top:8px;display:none'},'⬇ Sync status & charges for all');
  out.replaceChildren(prog,tbl,allBtn);
  var diffIds=[],done=0,ok=0;
  function row(id,res,can){var tr=dzlEl('tr',can?{'class':'dzl-diff'}:null);tr.appendChild(dzlEl('td',null,'#'+id));tr.appendChild(dzlEl('td',null,res));tr.appendChild(dzlEl('td'));
    var td=dzlEl('td');if(can){var b=dzlEl('button',{'class':'btn btn-sm',type:'button'},'View');b.onclick=function(){dzlOpen(id);};td.appendChild(b);}tr.appendChild(td);tbl.appendChild(tr);}
  function summary(c){var s=[];c.lines.forEach(function(L){L.fields.forEach(function(f){if(f.diff)s.push(f.erp+' → '+f.daraz);});});
    if(c.totals.fee_target!==null&&Math.abs(c.totals.erp_fees-c.totals.fee_target)>0.009)s.push('charges '+dzlMoney(c.totals.erp_fees)+' → '+dzlMoney(c.totals.fee_target));return s.join(' · ');}
  function next(){
    if(done>=ids.length){prog.textContent='Finished: '+ok+' already match, '+diffIds.length+' need syncing, '+(done-ok-diffIds.length)+' could not be checked.';if(diffIds.length)allBtn.style.display='';return;}
    var id=ids[done];prog.textContent='Checking '+(done+1)+' of '+ids.length+'…';
    fetch('daraz_link.php?action=check&fin=1&id='+encodeURIComponent(id),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
      done++;
      if(!j.ok)row(id,'⚠ '+(j.error||'error'),false);
      else if(!j.compare.linked)row(id,'not linked in the ERP',false);
      else if(j.compare.diffs===0)ok++;
      else{diffIds.push(id);row(id,summary(j.compare),true);}
      next();
    }).catch(function(){done++;row(id,'⚠ network error',false);next();});
  }
  allBtn.onclick=function(){
    if(!confirm('Sync status and Daraz charges for '+diffIds.length+' order(s)?'))return;
    allBtn.disabled=true;var i=0,fails=0;
    (function step(){
      if(i>=diffIds.length){prog.textContent='✓ Synced '+(diffIds.length-fails)+' order(s)'+(fails?', '+fails+' failed':'')+'. Close this window to refresh.';dzlChanged=true;return;}
      prog.textContent='Syncing '+(i+1)+' of '+diffIds.length+'…';
      dzlPost({action:'apply',id:diffIds[i],fin:'1'}).then(function(j){if(!j.ok)fails++;i++;step();}).catch(function(){fails++;i++;step();});
    })();
  };
  next();
}
function dzlUnlink(erpId,code){
  if(!confirm('Unlink ERP order '+code+' from its Daraz order number?'))return;
  dzlPost({action:'unlink',erp_id:erpId}).then(function(j){if(!j.ok){alert(j.error||'Could not unlink.');return;}location.reload();});
}
(function(){var m=document.getElementById('dzlModal');m.addEventListener('click',function(e){if(e.target===m)dzlClose();});})();
</script>
<?php return ob_get_clean();
}
