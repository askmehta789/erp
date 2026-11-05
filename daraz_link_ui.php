<?php
/* The shared "Link / check by Daraz order ID" window, used by Daraz Sales and the
   Dashboard. Everything Daraz sends is written with textContent (never innerHTML),
   so customer names and the like can't inject markup into the ERP. */
function daraz_link_ui(): string {
  $csrf = csrf();
  ob_start(); ?>
<div class="modal-bg" id="dzlModal" style="z-index:99990"><div class="modal" style="width:780px;max-width:96vw">
  <div class="modal-head"><span id="dzlTitle">🔗 Link / check a Daraz order</span><span class="mx" onclick="dzlClose()">✕</span></div>
  <div class="modal-body" style="display:block">
    <div id="dzlSingleBar" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <input id="dzlId" placeholder="Daraz order number, e.g. 204188712345678" style="flex:1;min-width:220px" onkeydown="if(event.key==='Enter'){event.preventDefault();dzlCheck();}">
      <label style="display:flex;gap:6px;align-items:center;font-size:12px;margin:0"><input type="checkbox" id="dzlFin" checked style="width:auto"> include real fees</label>
      <button type="button" class="btn btn-primary" onclick="dzlCheck()">Check with Daraz</button>
    </div>
    <div id="dzlOut" style="margin-top:10px"></div>
  </div>
  <div class="modal-foot">
    <button type="button" class="btn" onclick="dzlClose()">Close</button>
    <button type="button" class="btn btn-primary" id="dzlApply" style="display:none" onclick="dzlApply()">⬇ Update ERP from Daraz</button>
  </div>
</div></div>
<script>
var DZL_CSRF=<?= json_encode($csrf) ?>, dzlCur=null, dzlChanged=false;
function dzlEl(tag,attrs,txt){var e=document.createElement(tag);if(attrs)for(var k in attrs){if(k==='class')e.className=attrs[k];else if(k==='style')e.style.cssText=attrs[k];else e.setAttribute(k,attrs[k]);}if(txt!==undefined&&txt!==null)e.textContent=txt;return e;}
function dzlMoney(v){if(v===null||v===undefined||v==='—')return '—';var n=parseFloat(v);if(isNaN(n))return String(v);return 'Rs. '+Math.round(n).toLocaleString('en-IN');}
function dzlNote(cls,txt){return dzlEl('div',{'class':'dzl-note '+cls},txt);}
function dzlOpen(id){
  dzlCur=null;
  document.getElementById('dzlTitle').textContent='🔗 Link / check a Daraz order';
  document.getElementById('dzlSingleBar').style.display='flex';
  document.getElementById('dzlOut').replaceChildren();
  document.getElementById('dzlApply').style.display='none';
  document.getElementById('dzlId').value=id||'';
  document.getElementById('dzlModal').classList.add('open');document.body.classList.add('modal-open');
  if(id)dzlCheck(); else setTimeout(function(){document.getElementById('dzlId').focus();},50);
}
function dzlClose(){document.getElementById('dzlModal').classList.remove('open');document.body.classList.remove('modal-open');if(dzlChanged)location.reload();}
function dzlCheck(){
  var id=(document.getElementById('dzlId').value||'').replace(/\D+/g,''), out=document.getElementById('dzlOut');
  document.getElementById('dzlApply').style.display='none';
  if(!id){out.replaceChildren(dzlNote('bad','Enter a Daraz order number first.'));return;}
  out.replaceChildren(dzlEl('div',{'class':'muted'},'Asking Daraz for order #'+id+'…'));
  var fin=document.getElementById('dzlFin').checked?'1':'0';
  fetch('daraz_link.php?action=check&id='+encodeURIComponent(id)+'&fin='+fin,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
    if(!j.ok){out.replaceChildren(dzlNote('bad',j.error||'Could not check this order.'));return;}
    dzlCur=j; dzlRender(j,out);
    var b=document.getElementById('dzlApply');
    b.style.display=''; b.textContent=j.compare.linked?'⬇ Update ERP from Daraz':'＋ Add this order to the ERP';
  }).catch(function(){out.replaceChildren(dzlNote('bad','Network error — could not reach the ERP.'));});
}
function dzlRender(j,out){
  var o=j.order,c=j.compare,frag=document.createDocumentFragment();
  frag.appendChild(dzlEl('div',{style:'font-weight:800;font-size:14px'},'Daraz #'+o.id+' · '+(o.customer||'—')+' · '+(o.phone||'no phone')));
  frag.appendChild(dzlEl('div',{'class':'muted',style:'font-size:12px'},'Ordered '+o.created+(o.city?' · '+o.city:'')+' · '+(o.payment==='cod'?'Cash on delivery':'Prepaid')));
  if(!c.linked)frag.appendChild(dzlNote('warn','This order is not in the ERP yet. "Add this order" creates it from Daraz.'));
  else if(c.diffs===0)frag.appendChild(dzlNote('ok','✓ The ERP matches Daraz for this order.'));
  else frag.appendChild(dzlNote('warn','⚠ '+c.diffs+' difference(s) found — highlighted below. Nothing changes until you press Update.'));
  if(c.totals.ship_as_cost)frag.appendChild(dzlNote('bad','The ERP counts the customer’s shipping ('+dzlMoney(c.totals.ship_customer)+') as your cost. Update fixes this.'));
  if(o.finance.ok)frag.appendChild(dzlNote('ok','Real Daraz fees found: '+dzlMoney(o.finance.fees)+' · payout '+dzlMoney(o.finance.payout)));
  else if(o.finance.error)frag.appendChild(dzlNote('warn',o.finance.error));
  c.lines.forEach(function(L){
    var t=dzlEl('table',{'class':'dzl-tbl'}),hd=dzlEl('tr');
    ['Item: '+(L.name||L.sku||'item')+(L.sku?' ('+L.sku+')':''),'ERP now','Daraz says'].forEach(function(h,i){hd.appendChild(dzlEl('th',i?{'class':'r'}:null,h));});
    t.appendChild(hd);
    L.fields.forEach(function(f){var tr=dzlEl('tr',f.diff?{'class':'dzl-diff'}:null);tr.appendChild(dzlEl('td',null,f.label));
      tr.appendChild(dzlEl('td',{'class':'r'},f.money?dzlMoney(f.erp):String(f.erp)));tr.appendChild(dzlEl('td',{'class':'r'},f.money?dzlMoney(f.daraz):String(f.daraz)));t.appendChild(tr);});
    var i=L.info,tr2=dzlEl('tr'),td=dzlEl('td',{colspan:'3','class':'muted',style:'font-size:11.5px'});
    td.textContent='Daraz list price '+dzlMoney(i.list_unit)+' · your discount '+dzlMoney(i.seller_discount)+' · Daraz voucher (not your cost) '+dzlMoney(i.platform_voucher)+' · customer paid '+dzlMoney(i.paid)+' · customer shipping '+dzlMoney(i.ship_customer)+(L.erp_code?' · ERP '+L.erp_code:' · new line');
    tr2.appendChild(td);t.appendChild(tr2);frag.appendChild(t);
  });
  if(c.extra_erp.length)frag.appendChild(dzlNote('bad','In the ERP but not on Daraz: '+c.extra_erp.map(function(x){return x.code+' ('+(x.product||'item')+' ×'+x.qty+', '+x.status+')';}).join(', ')+'. Check these in Daraz Sales — Update does not delete them.'));
  var tt=dzlEl('table',{'class':'dzl-tbl'}),h2=dzlEl('tr');
  ['Order totals','ERP now','Daraz'].forEach(function(h,i){h2.appendChild(dzlEl('th',i?{'class':'r'}:null,h));});tt.appendChild(h2);
  [['Your revenue',c.totals.erp_revenue,c.totals.daraz_revenue],
   ['Daraz fees (your cost)',c.totals.erp_fees,c.totals.daraz_fees===null?'—':c.totals.daraz_fees],
   ['Payout from Daraz','—',c.totals.payout===null?'—':c.totals.payout]].forEach(function(r){
    var d=r[2]!=='—'&&r[1]!=='—'&&Math.abs(parseFloat(r[1])-parseFloat(r[2]))>0.009,tr=dzlEl('tr',d?{'class':'dzl-diff'}:null);
    tr.appendChild(dzlEl('td',null,r[0]));tr.appendChild(dzlEl('td',{'class':'r'},dzlMoney(r[1])));tr.appendChild(dzlEl('td',{'class':'r'},dzlMoney(r[2])));tt.appendChild(tr);});
  frag.appendChild(tt);
  out.replaceChildren(frag);
}
function dzlApply(){
  if(!dzlCur)return;
  var btn=document.getElementById('dzlApply'),out=document.getElementById('dzlOut');btn.disabled=true;
  var body=new URLSearchParams({action:'apply',id:dzlCur.order.id,csrf:DZL_CSRF,fin:document.getElementById('dzlFin').checked?'1':'0'});
  fetch('daraz_link.php',{method:'POST',credentials:'same-origin',body:body}).then(function(r){return r.json();}).then(function(j){
    btn.disabled=false;
    if(!j.ok){out.insertBefore(dzlNote('bad',j.error||'Update failed.'),out.firstChild);return;}
    dzlChanged=true;var r=j.result;
    out.insertBefore(dzlNote('ok','✓ Done — '+r.updated+' line(s) updated, '+r.created+' added'+(r.unmatched?', '+r.unmatched+' with an unmapped SKU':'')+'. The page refreshes when you close this window.'),out.firstChild);
    btn.style.display='none';
  }).catch(function(){btn.disabled=false;out.insertBefore(dzlNote('bad','Network error.'),out.firstChild);});
}
/* re-check many orders one by one, and list only the ones that differ */
function dzlRecheck(ids){
  ids=(ids||[]).filter(function(x,i,a){return x&&a.indexOf(x)===i;});
  if(!ids.length){alert('No Daraz order numbers to check.');return;}
  dzlOpen('');document.getElementById('dzlSingleBar').style.display='none';
  document.getElementById('dzlTitle').textContent='🔄 Re-check '+ids.length+' Daraz order(s)';
  var out=document.getElementById('dzlOut'),prog=dzlEl('div',{'class':'muted'},'Starting…'),tbl=dzlEl('table',{'class':'dzl-tbl'}),hd=dzlEl('tr');
  ['Daraz order','Result','Revenue ERP → Daraz',''].forEach(function(h){hd.appendChild(dzlEl('th',null,h));});tbl.appendChild(hd);
  var allBtn=dzlEl('button',{'class':'btn btn-sm',type:'button',style:'margin-top:8px;display:none'},'⬇ Update all that differ');
  out.replaceChildren(prog,tbl,allBtn);
  var diffIds=[],done=0,ok=0;
  function row(id,res,rev,can){var tr=dzlEl('tr',can?{'class':'dzl-diff'}:null);tr.appendChild(dzlEl('td',null,'#'+id));tr.appendChild(dzlEl('td',null,res));tr.appendChild(dzlEl('td',null,rev));
    var td=dzlEl('td');if(can){var b=dzlEl('button',{'class':'btn btn-sm',type:'button'},'View');b.onclick=function(){dzlOpen(id);};td.appendChild(b);}tr.appendChild(td);tbl.appendChild(tr);}
  function next(){
    if(done>=ids.length){prog.textContent='Finished: '+ok+' match, '+diffIds.length+' differ, '+(done-ok-diffIds.length)+' could not be checked.';if(diffIds.length)allBtn.style.display='';return;}
    var id=ids[done];prog.textContent='Checking '+(done+1)+' of '+ids.length+'…';
    fetch('daraz_link.php?action=check&fin=0&id='+encodeURIComponent(id),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
      done++;
      if(!j.ok)row(id,'⚠ '+(j.error||'error'),'',false);
      else if(j.compare.diffs===0)ok++;
      else{diffIds.push(id);row(id,j.compare.diffs+' difference(s)',dzlMoney(j.compare.totals.erp_revenue)+' → '+dzlMoney(j.compare.totals.daraz_revenue),true);}
      next();
    }).catch(function(){done++;row(id,'⚠ network error','',false);next();});
  }
  allBtn.onclick=function(){
    if(!confirm('Update '+diffIds.length+' order(s) in the ERP with Daraz’s values?'))return;
    allBtn.disabled=true;var i=0,fails=0;
    (function step(){
      if(i>=diffIds.length){prog.textContent='✓ Updated '+(diffIds.length-fails)+' order(s)'+(fails?', '+fails+' failed':'')+'. Close this window to refresh.';dzlChanged=true;return;}
      prog.textContent='Updating '+(i+1)+' of '+diffIds.length+'…';
      fetch('daraz_link.php',{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'apply',id:diffIds[i],csrf:DZL_CSRF,fin:'1'})})
        .then(function(r){return r.json();}).then(function(j){if(!j.ok)fails++;i++;step();}).catch(function(){fails++;i++;step();});
    })();
  };
  next();
}
(function(){var m=document.getElementById('dzlModal');m.addEventListener('click',function(e){if(e.target===m)dzlClose();});})();
</script>
<?php return ob_get_clean();
}
