<?php
/* ============================================================
   DAILY & MONTHLY REPORT
   Full detail, product-wise, for either daily or monthly grouping.
   Self-contained — doesn't depend on analytics.php having run.
   ============================================================ */
require_once __DIR__.'/functions.php'; require_login(); require_page_access();
repair_zero_cost_profit();
$PAGE_TITLE='Daily & Monthly Report';

$view = ($_GET['view'] ?? 'daily')==='monthly' ? 'monthly' : 'daily';
$to   = $_GET['to']   ?? date('Y-m-d');
$from = $_GET['from'] ?? date('Y-m-d', strtotime($view==='monthly' ? '-6 months' : '-30 days'));
if ($from > $to) { $t=$from; $from=$to; $to=$t; }
$pid  = (int)($_GET['pid'] ?? 0);

function ar_pkey($date,$view){ return $view==='monthly' ? date('Y-m',strtotime($date)) : date('Y-m-d',strtotime($date)); }
function ar_plabel($k,$view){ return $view==='monthly' ? date('F Y',strtotime($k.'-01')) : date('l, F j, Y',strtotime($k)); }

$usdRate = max(1,(float)setting('usd_rate',140));
$adKw=['advert','ad spend','ads','marketing','facebook','fb','insta','google','tiktok','boost','meta','ppc','campaign','promotion'];
$allProducts = rows("SELECT id,name FROM products ORDER BY name");
$pName = $pid ? (string)val("SELECT name FROM products WHERE id=?",[$pid]) : '';
$pw = $pid ? " AND o.product_id = ".$pid : "";

$orders = rows("SELECT o.*, p.name AS product_name FROM orders o LEFT JOIN products p ON p.id=o.product_id
                WHERE o.order_date BETWEEN ? AND ? $pw ORDER BY o.order_date",[$from,$to]);

/* zero-filled period map so empty days/months still show */
$periodMap=[];
if ($view==='daily') { $cur=strtotime($from); $end=strtotime($to); while($cur<=$end){ $periodMap[date('Y-m-d',$cur)]=1; $cur=strtotime('+1 day',$cur); } }
else { $cur=strtotime(date('Y-m-01',strtotime($from))); $end=strtotime($to); while($cur<=$end){ $periodMap[date('Y-m',$cur)]=1; $cur=strtotime('+1 month',$cur); } }

$P=[]; $ensureP=function(&$a,$k){ if(!isset($a[$k])) $a[$k]=['q'=>0,'rev'=>0,'del'=>0,'cogs'=>0,'rloss'=>0,'gross'=>0,'products'=>[]]; };
foreach(array_keys($periodMap) as $k) $ensureP($P,$k);

foreach($orders as $o){
  $k = ar_pkey($o['order_date'],$view); if(!isset($P[$k])) $ensureP($P,$k);
  $pn = $o['product_name'] ?: '(no product)';
  if(!isset($P[$k]['products'][$pn])) $P[$k]['products'][$pn]=['q'=>0,'rev'=>0,'del'=>0,'cogs'=>0,'rloss'=>0,'gross'=>0];
  if($o['status']==='delivered'){
    $q=(int)$o['qty']; $line=(float)$o['sell_price']*$q; $prof=((float)$o['sell_price']-(float)$o['cost_price'])*$q-(float)$o['delivery_charge'];
    $cogsLine=(float)$o['cost_price']*$q;
    $P[$k]['q']+=$q; $P[$k]['rev']+=$line; $P[$k]['del']+=(float)$o['delivery_charge']; $P[$k]['cogs']+=$cogsLine; $P[$k]['gross']+=$prof;
    $P[$k]['products'][$pn]['q']+=$q; $P[$k]['products'][$pn]['rev']+=$line; $P[$k]['products'][$pn]['del']+=(float)$o['delivery_charge']; $P[$k]['products'][$pn]['cogs']+=$cogsLine; $P[$k]['products'][$pn]['gross']+=$prof;
  } elseif(in_array($o['status'],['returned','cancelled'],true)){
    $P[$k]['gross']-=(float)$o['cancel_charge']; $P[$k]['rloss']+=(float)$o['cancel_charge'];
    $P[$k]['products'][$pn]['gross']-=(float)$o['cancel_charge']; $P[$k]['products'][$pn]['rloss']+=(float)$o['cancel_charge'];
  }
}

/* ads, matched into the same periods */
$adRows=rows("SELECT expense_date, amount, category, COALESCE(usd_amount,0) usd_amount, COALESCE(product,'') product FROM expenses WHERE expense_date BETWEEN ? AND ?",[$from,$to]);
$adFits=function($e) use($pid,$pName){ if(!$pid) return true; return strcasecmp(trim((string)$e['product']),trim((string)$pName))===0; };
$adsP=[]; $adsUsdP=[]; $adsUntagged=0;
foreach($adRows as $e){
  $cat=strtolower((string)$e['category']); $isAd=false; foreach($adKw as $kw){ if(strpos($cat,$kw)!==false){$isAd=true;break;} }
  if(!$isAd) continue;
  if(!$adFits($e)){ $adsUntagged+=(float)$e['amount']; continue; }
  $k=ar_pkey($e['expense_date'],$view);
  $adsP[$k]=($adsP[$k]??0)+(float)$e['amount'];
  $adsUsdP[$k]=($adsUsdP[$k]??0)+(float)$e['usd_amount'];
}
krsort($P);

/* ---- summary totals across the whole range ---- */
$sumQ=0; $sumRev=0; $sumAds=0; $sumNet=0;
foreach($P as $k=>$v){ $ads=$adsP[$k]??0; $sumQ+=$v['q']; $sumRev+=$v['rev']; $sumAds+=$ads; $sumNet+=($v['gross']-$ads); }
$sumAvgPPU = $sumQ>0 ? round($sumNet/$sumQ) : 0;

/* ---- best/worst period by net profit, among periods that actually had activity ---- */
$bestK=null; $worstK=null; $bestV=null; $worstV=null;
foreach($P as $k=>$v){
  $ads=$adsP[$k]??0; $net=$v['gross']-$ads;
  if($v['q']==0 && $ads==0) continue;   // skip empty periods for best/worst purposes
  if($bestK===null || $net>$bestV){ $bestK=$k; $bestV=$net; }
  if($worstK===null || $net<$worstV){ $worstK=$k; $worstV=$net; }
}

/* ---- trend series: chronological (oldest→newest) net profit per period, most recent 14 ---- */
$trendKeys = array_keys($P); sort($trendKeys); // ascending — $P itself is krsorted (desc) for display
$trendKeys = array_slice($trendKeys, -14);
$trend=[]; foreach($trendKeys as $k){ $ads=$adsP[$k]??0; $trend[$k]=$P[$k]['gross']-$ads; }
$trendMax = $trend ? max(1, max(array_map('abs',$trend))) : 1;

/* ---- period-over-period % change vs the immediately preceding period (chronologically) ---- */
$chronoKeys = array_keys($P); sort($chronoKeys); // ascending
$prevNet = null; $deltaOf = [];
foreach($chronoKeys as $k){
  $ads=$adsP[$k]??0; $net=$P[$k]['gross']-$ads;
  if($prevNet !== null && $prevNet != 0){ $deltaOf[$k] = round((($net-$prevNet)/abs($prevNet))*100); }
  $prevNet = $net;
}

/* month-picker convenience: quick jump for daily view */
$curMonth = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');

require __DIR__.'/includes/header.php';
?>
<style>
.ar-hero{background:linear-gradient(120deg,#0369a1,#0c4a6e);border-radius:22px;padding:22px 26px;color:#fff;margin-bottom:14px;box-shadow:0 18px 40px rgba(3,74,110,.28)}
.ar-hero h1{font-size:21px;font-weight:900;margin:0}
.ar-hero p{opacity:.85;font-size:12px;margin:2px 0 0}
.ar-toggle{display:inline-flex;background:rgba(255,255,255,.18);border-radius:99px;padding:3px;margin-top:14px}
.ar-toggle a{padding:7px 18px;border-radius:99px;font-size:12px;font-weight:800;text-decoration:none;color:#fff;opacity:.75}
.ar-toggle a.on{background:#fff;color:#0369a1;opacity:1}
.ar-controls{background:#fff;border-radius:16px;padding:14px 18px;box-shadow:0 6px 18px rgba(30,41,80,.07);margin-bottom:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:end}
.ar-controls label{font-size:10px;color:#8a93a8;font-weight:800;text-transform:uppercase;display:block;margin-bottom:4px}
.ar-controls input,.ar-controls select{border:1px solid var(--border);border-radius:9px;padding:7px 10px;font-size:12.5px;font:inherit}

/* summary band */
.ar-sumgrid{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:14px}
.ar-sumtile{background:#fff;border-radius:14px;padding:13px;box-shadow:0 6px 16px rgba(30,41,80,.07);border-top:3px solid #ddd}
.ar-sumtile.q{border-top-color:#0369a1}.ar-sumtile.r{border-top-color:#16a34a}.ar-sumtile.a{border-top-color:#dc2626}.ar-sumtile.p{border-top-color:#7c3aed}.ar-sumtile.avg{border-top-color:#d97706}
.ar-sumtile b{font-size:16px;font-weight:900;display:block;overflow-wrap:anywhere}
.ar-sumtile span{font-size:9px;color:#8a93a8;font-weight:700;text-transform:uppercase}

/* trend chart */
.ar-trendcard{background:#fff;border-radius:16px;padding:16px 18px;box-shadow:0 6px 18px rgba(30,41,80,.07);margin-bottom:14px}
.ar-trendcard h3{font-size:12.5px;font-weight:900;margin-bottom:10px}
.ar-trend{display:flex;align-items:flex-end;gap:5px;height:70px}
.ar-tbar-wrap{flex:1;display:flex;align-items:flex-end;height:100%}
.ar-tbar{width:100%;border-radius:3px 3px 0 0;background:linear-gradient(180deg,#4ade80,#16a34a);min-height:3px}
.ar-tbar.neg{background:linear-gradient(180deg,#f87171,#dc2626)}
.ar-tbar.best{background:linear-gradient(180deg,#fbbf24,#d97706)}

/* best/worst highlight cards */
.ar-hlrow{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px}
.ar-hlcard{border-radius:14px;padding:13px 16px;color:#fff}
.ar-hlcard.best{background:linear-gradient(120deg,#16a34a,#15803d)}
.ar-hlcard.worst{background:linear-gradient(120deg,#dc2626,#991b1b)}
.ar-hlcard .l{font-size:9.5px;opacity:.85;font-weight:800;text-transform:uppercase}
.ar-hlcard .v{font-size:15px;font-weight:900;margin-top:3px}
.ar-hlcard .s{font-size:11px;opacity:.85;margin-top:2px}

.ar-card{background:#fff;border-radius:16px;box-shadow:0 6px 18px rgba(30,41,80,.07);overflow:hidden;margin-bottom:12px}
.ar-prow{border-bottom:1px solid #f1f4f9;border-left:4px solid #ddd}
.ar-prow.pos{border-left-color:#16a34a}.ar-prow.neg{border-left-color:#dc2626}
.ar-prow:last-child{border-bottom:0}
.ar-ptop{display:flex;align-items:center;gap:10px;padding:12px 18px 12px 14px;cursor:pointer}
.ar-ptop:hover{background:#f8fafc}
.ar-car{color:#8a93a8;font-size:10px;transition:transform .15s;flex:none}
.ar-prow.open .ar-car{transform:rotate(90deg)}
.ar-pname{font-weight:800;font-size:12.5px;color:#334;flex:1}
.ar-stats{display:flex;gap:16px;flex-wrap:wrap}
.ar-stat{text-align:right}
.ar-stat b{display:block;font-size:12.5px;font-weight:900}
.ar-stat span{font-size:8.5px;color:#8a93a8;font-weight:700;text-transform:uppercase}
.ar-delta{font-size:9px;font-weight:800;border-radius:99px;padding:1px 6px;margin-top:2px;display:inline-block}
.ar-delta.up{background:#dcfce7;color:#16a34a}
.ar-delta.down{background:#fee2e2;color:#dc2626}
.ar-pos{color:#16a34a}.ar-neg{color:#dc2626}
.ar-prodlist{display:none;background:#f8fafc;padding:6px 18px 12px 42px}
.ar-prow.open .ar-prodlist{display:block}
.ar-prodrow{display:flex;align-items:center;gap:10px;padding:7px 0;font-size:11.5px;border-bottom:1px solid #eef1f7}
.ar-prodrow:last-child{border-bottom:0}
.ar-prodrow .nm{width:130px;flex:none;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ar-pbar-bg{flex:1;height:6px;background:#e4e8f2;border-radius:99px;overflow:hidden;min-width:30px}
.ar-pbar{height:100%;background:linear-gradient(90deg,#60a5fa,#0369a1)}
.ar-prodrow .amt{width:80px;flex:none;text-align:right;font-weight:800}
.ar-empty{padding:24px 18px;text-align:center;color:#8a93a8;font-size:12px}
@media(max-width:640px){
  .ar-sumgrid{grid-template-columns:repeat(2,1fr)}
  .ar-hlrow{grid-template-columns:1fr}
  .ar-ptop{flex-wrap:wrap}
  .ar-pname{flex:1 1 100%;order:1}
  .ar-car{order:0}
  .ar-stats{flex:1 1 100%;order:2;justify-content:space-between;gap:6px;margin-top:6px}
  .ar-stat{text-align:left}
  .ar-controls{flex-direction:column;align-items:stretch}
  .ar-controls > div, .ar-controls button{width:100%}
  .ar-prodrow .nm{width:90px}
}
</style>

<div class="ar-hero">
  <h1>📋 Daily &amp; Monthly Report</h1>
  <p>Every number, product by product, day by day or rolled up by month</p>
  <div class="ar-toggle">
    <a href="?view=daily&pid=<?= $pid ?>" class="<?= $view==='daily'?'on':'' ?>">📅 Daily</a>
    <a href="?view=monthly&pid=<?= $pid ?>" class="<?= $view==='monthly'?'on':'' ?>">🗓️ Monthly</a>
  </div>
</div>
<?php if($fl=flash()) echo '<div class="flash">'.e($fl).'</div>'; ?>

<form class="ar-controls" method="get">
  <input type="hidden" name="view" value="<?= e($view) ?>">
  <div><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <div><label>Product</label><select name="pid"><option value="0">All products</option>
    <?php foreach($allProducts as $ap): ?><option value="<?= (int)$ap['id'] ?>" <?= $pid===(int)$ap['id']?'selected':'' ?>><?= e($ap['name']) ?></option><?php endforeach; ?>
  </select></div>
  <button class="btn btn-primary">Apply</button>
  <button type="button" class="btn" onclick="arExpandAll()">⤢ Expand all</button>
  <button type="button" class="btn" onclick="arExportCsv()">⬇ Export CSV</button>
</form>

<div style="margin-bottom:10px"><input id="arSearch" placeholder="🔍 Search a date or month…" style="width:100%;border:1px solid var(--border);border-radius:10px;padding:9px 14px;font-size:12.5px;font:inherit"></div>

<div class="ar-sumgrid">
  <div class="ar-sumtile q"><b><?= number_format($sumQ) ?></b><span>Total Qty</span></div>
  <div class="ar-sumtile r"><b><?= money($sumRev) ?></b><span>Total Revenue</span></div>
  <div class="ar-sumtile a"><b><?= money($sumAds) ?></b><span>Total Ads</span></div>
  <div class="ar-sumtile p"><b class="<?= $sumNet>=0?'ar-pos':'ar-neg' ?>"><?= money($sumNet) ?></b><span>Total Net Profit</span></div>
  <div class="ar-sumtile avg"><b><?= money($sumAvgPPU) ?></b><span>Avg Profit/Unit</span></div>
</div>

<?php if(count($trend)>=2): ?>
<div class="ar-trendcard">
  <h3>📈 Net Profit Trend — Last <?= count($trend) ?> <?= $view==='monthly'?'Months':'Periods' ?></h3>
  <div class="ar-trend">
    <?php foreach($trend as $tk=>$tv): $h=max(6,round(abs($tv)/$trendMax*100)); $cls = $tk===$bestK?'best':($tv<0?'neg':''); ?>
    <div class="ar-tbar-wrap" title="<?= e(ar_plabel($tk,$view)) ?>: <?= money($tv) ?>"><div class="ar-tbar <?= $cls ?>" style="height:<?= $h ?>%"></div></div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if($bestK!==null): ?>
<div class="ar-hlrow">
  <div class="ar-hlcard best"><div class="l">🏆 Best <?= $view==='monthly'?'Month':'Day' ?></div><div class="v"><?= e(ar_plabel($bestK,$view)) ?></div><div class="s"><?= money($bestV) ?> net profit</div></div>
  <div class="ar-hlcard worst"><div class="l"><?= $worstV<0?'⚠️':'📉' ?> Worst <?= $view==='monthly'?'Month':'Day' ?></div><div class="v"><?= e(ar_plabel($worstK,$view)) ?></div><div class="s"><?= money($worstV) ?> net <?= $worstV<0?'loss':'profit' ?></div></div>
</div>
<?php endif; ?>

<div class="ar-card">
  <?php foreach($P as $k=>$v):
    $ads = $adsP[$k] ?? 0; $usd=($adsUsdP[$k]??0)>0?$adsUsdP[$k]:($ads>0?$ads/$usdRate:0);
    $net = $v['gross'] - $ads;
    $apu = $v['q']>0 ? $ads/$v['q'] : 0; $ppu = $v['q']>0 ? $net/$v['q'] : 0;
    $hasData = $v['q']>0 || $ads>0;
    $rowCls = $hasData ? ($net>=0?'pos':'neg') : '';
    $delta = $deltaOf[$k] ?? null;
    $maxProdAbs = $v['products'] ? max(array_map(fn($pv)=>abs($pv['gross']),$v['products'])) : 0;
  ?>
  <div class="ar-prow <?= $rowCls ?>" data-s="<?= e(mb_strtolower(ar_plabel($k,$view))) ?>">
    <div class="ar-ptop" onclick="this.parentElement.classList.toggle('open')">
      <span class="ar-car">▸</span>
      <span class="ar-pname"><?= e(ar_plabel($k,$view)) ?><?php if(count($v['products'])>0): ?> <span style="color:#8a93a8;font-weight:600;font-size:10.5px">· <?= count($v['products']) ?> product<?= count($v['products'])===1?'':'s' ?></span><?php endif; ?></span>
      <div class="ar-stats">
        <div class="ar-stat"><b><?= number_format($v['q']) ?></b><span>Qty</span></div>
        <div class="ar-stat"><b><?= money($v['rev']) ?></b><span>Revenue</span></div>
        <div class="ar-stat"><b class="ar-neg"><?= money($ads) ?></b><span>Ads</span></div>
        <div class="ar-stat"><b class="<?= $net>=0?'ar-pos':'ar-neg' ?>"><?= money($net) ?></b><span>Net Profit</span>
          <?php if($delta!==null && $hasData): ?><span class="ar-delta <?= $delta>=0?'up':'down' ?>"><?= $delta>=0?'▲':'▼' ?> <?= abs($delta) ?>%</span><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="ar-prodlist">
      <?php if($v['products']): foreach($v['products'] as $pn=>$pv): $barPct = $maxProdAbs>0 ? max(4,round(abs($pv['gross'])/$maxProdAbs*100)) : 0; ?>
        <div class="ar-prodrow">
          <span class="nm" title="<?= e($pn) ?>"><?= e($pn) ?></span>
          <div class="ar-pbar-bg"><div class="ar-pbar" style="width:<?= $barPct ?>%<?= $pv['gross']<0?';background:linear-gradient(90deg,#f87171,#dc2626)':'' ?>"></div></div>
          <span class="amt <?= $pv['gross']>=0?'ar-pos':'ar-neg' ?>"><?= money($pv['gross']) ?></span>
        </div>
      <?php endforeach; else: ?>
        <div class="ar-prodrow" style="color:#8a93a8">No orders this period.</div>
      <?php endif; ?>
      <div class="ar-prodrow" style="border-top:1px solid #e4e8f2;margin-top:4px;padding-top:8px;color:#8a93a8">
        Revenue Rs. <?= number_format($v['rev']) ?> − COGS Rs. <?= number_format($v['cogs']) ?> − Delivery Rs. <?= number_format($v['del']) ?><?= $v['rloss']>0?' − Returns Rs. '.number_format($v['rloss']):'' ?> = Gross Rs. <?= number_format($v['gross']) ?> · − Ads Rs. <?= number_format($ads) ?> = Net Rs. <?= number_format($net) ?>
      </div>
      <div class="ar-prodrow" style="padding-top:2px;color:#8a93a8">
        Ads/unit Rs. <?= number_format($apu,1) ?> · Profit/unit Rs. <?= number_format($ppu) ?> · $<?= number_format($usd,0) ?>
      </div>
    </div>
  </div>
  <?php endforeach; if(!$P): ?><div class="ar-empty">No data in this range.</div><?php endif; ?>
</div>

<table id="arCsvSrc" style="display:none">
  <thead><tr><th>Period</th><th>Product</th><th>Qty</th><th>Revenue</th><th>COGS</th><th>Delivery</th><th>Return Loss</th><th>Gross Profit</th></tr></thead>
  <tbody>
  <?php foreach($P as $k=>$v): $lbl=ar_plabel($k,$view); ?>
    <?php if($v['products']): foreach($v['products'] as $pn=>$pv): ?>
    <tr><td><?= e($lbl) ?></td><td><?= e($pn) ?></td><td><?= $pv['q'] ?></td><td><?= round($pv['rev']) ?></td><td><?= round($pv['cogs']) ?></td><td><?= round($pv['del']) ?></td><td><?= round($pv['rloss']) ?></td><td><?= round($pv['gross']) ?></td></tr>
    <?php endforeach; else: ?>
    <tr><td><?= e($lbl) ?></td><td>—</td><td>0</td><td>0</td><td>0</td><td>0</td><td>0</td><td>0</td></tr>
    <?php endif; ?>
  <?php endforeach; ?>
  </tbody>
</table>

<script>
function arExpandAll(){ document.querySelectorAll('.ar-prow').forEach(function(r){ r.classList.add('open'); }); }
var arS=document.getElementById('arSearch');
if(arS) arS.addEventListener('input',function(){
  var q=this.value.toLowerCase().trim();
  document.querySelectorAll('.ar-prow[data-s]').forEach(function(r){
    r.style.display = (!q || (r.getAttribute('data-s')||'').indexOf(q)!==-1) ? '' : 'none';
  });
});
function arExportCsv(){
  var rows=[]; document.querySelectorAll('#arCsvSrc tr').forEach(function(tr){
    var cells=Array.prototype.map.call(tr.children,function(td){ var t=(td.textContent||'').replace(/"/g,'""'); return '"'+t+'"'; });
    rows.push(cells.join(','));
  });
  var blob=new Blob([rows.join('\n')],{type:'text/csv'});
  var a=document.createElement('a'); a.href=URL.createObjectURL(blob); a.download='<?= $view ?>-report-<?= e($from) ?>-to-<?= e($to) ?>.csv'; a.click();
}
</script>
<?php require __DIR__.'/includes/footer.php'; ?>