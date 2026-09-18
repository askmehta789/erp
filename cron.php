<?php
/* ============================================================
   Luprah ERP — Background Worker (run every 10-15 minutes)
   cPanel → Cron Jobs →
     /usr/local/bin/php /home/YOURUSER/public_html/erp/cron.php YOUR_TOKEN
   or URL style:
     wget -qO- "https://luprah.online/erp/cron.php?token=YOUR_TOKEN"
   The token is shown in Settings → Automation.
   Does: NCM auto-sync (every run) · daily DB backup · weekly email report
   ============================================================ */
require_once __DIR__.'/db.php';
require_once __DIR__.'/ncm_api.php';

/* ---- token guard (no session needed) ---- */
$given = trim((string)($_GET['token'] ?? ($argv[1] ?? '')));
$want  = trim((string)setting('cron_token',''));
if ($want === '') { http_response_code(403); die("No cron token set yet — open Settings once (it auto-generates), then retry.\n"); }
if ($given === '' || !hash_equals($want, $given)) { http_response_code(403); die("Invalid cron token — copy the current token from Settings > Automation Setup.\n"); }

function csay($m){ echo '['.date('H:i:s')."] $m\n"; }
function cnotify($msg,$type='info',$link='',$dedupe=12){
  try{
    q("CREATE TABLE IF NOT EXISTS notifications(id INT AUTO_INCREMENT PRIMARY KEY,type VARCHAR(30) DEFAULT 'info',message VARCHAR(255) NOT NULL,link VARCHAR(120) DEFAULT '',is_read TINYINT(1) DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    if($dedupe>0 && (int)val("SELECT COUNT(*) FROM notifications WHERE message=? AND created_at>=DATE_SUB(NOW(),INTERVAL ? HOUR)",[$msg,$dedupe])) return;
    q("INSERT INTO notifications(type,message,link) VALUES(?,?,?)",[$type,$msg,$link]);
  }catch(Exception $e){}
}
function cstock($pid,$qty,$old,$new,$oid=0){ fifo_status_change((int)$oid,(int)$pid,(int)$qty,$old,$new); }

/* ============ 1b) NCM today's-comments full scan (auto, at most every 20 min) ============ */
try {
  if (ncm()->configured()) {
    $cc = kv_get('ncm_today_comments');
    $stale = !$cc || ($cc['v']['date'] ?? '') !== date('Y-m-d') || (time()-strtotime($cc['at'])) > 1200;
    if ($stale) {
      if(function_exists('set_time_limit')) @set_time_limit(180);
      ncm_scan_today_comments(120);
      $log[] = "NCM today-comments scan refreshed";
    }
  }
} catch (Exception $e) { $log[] = "NCM comment scan error: ".$e->getMessage(); }

/* ============ 1) NCM AUTO-SYNC (every run) ============ */
try {
  if (ncm()->configured()) {
    ncm_ensure_cols();
    /* RETURNED and CANCELLED orders are NEVER touched by this sync — a return decided
       by you (or by the "Sent to Vendor" rule) is final; NCM's feed keeps saying
       "Delivered" forever on returned parcels (= delivered back to YOU), and that
       must never resurrect an order. */
    $rows = rows("SELECT o.* FROM orders o LEFT JOIN couriers c ON c.id=o.courier_id
                  WHERE c.name LIKE '%NCM%' AND COALESCE(o.ncm_order_id,'')<>''
                    AND o.status NOT IN ('delivered','cancelled','returned')");
    if ($rows) {
      $ids = array_map(fn($o)=>(int)$o['ncm_order_id'], $rows);
      $res = ncm()->ordersStatuses($ids);
      $live = (isset($res['result'])&&is_array($res['result'])) ? $res['result'] : [];
      $n=0; $healed=0;
      foreach ($rows as $o) {
        $nid=(string)$o['ncm_order_id']; if(!isset($live[$nid])) continue;
        $liveRaw=(string)$live[$nid]; $stg=ncm_return_stage($liveRaw);
        $curFlag=(int)($o['ncm_return_flag'] ?? 0);   /* 0 none · 1 confirm · 2 returned by you · 6 delivered by you */
        $prevRaw=(string)($o['ncm_status'] ?? '');
        $mapped = ncm_to_local_status($liveRaw);

        /* human decisions are final */
        if ($curFlag===2 || $curFlag===6) $mapped=null;

        /* "Sent to Vendor" / RTV is definitive — mark RETURNED now and lock it */
        if ($curFlag!==2 && $curFlag!==6 && ncm_vendor_bound($liveRaw)) {
          $mapped='returned'; $curFlag=2; $stg='final';
        }

        /* plain "Delivered" after a return-ish journey = handed back to YOU or a re-delivery —
           ambiguous, so HOLD for manual decision on the NCM page (no status change) */
        $wasReturnward = ($curFlag===1) || (ncm_return_stage($prevRaw)!=='none');
        if ($mapped==='delivered' && $stg==='none' && $wasReturnward && $curFlag!==2 && $curFlag!==6) {
          $mapped=null; $stg='progress';
        }

        $newFlag=($curFlag===2||$curFlag===6)?$curFlag:((($stg==='progress'||$stg==='ambiguous')?1:0));
        try { q("UPDATE orders SET ncm_status=?, ncm_return_flag=? WHERE id=?", [$liveRaw,$newFlag,$o['id']]); } catch (Exception $e) {}
        if ($mapped && $mapped !== $o['status']) {
          if ($mapped==='delivered') q("UPDATE orders SET status='delivered',payment_status='paid' WHERE id=?",[$o['id']]);
          else q("UPDATE orders SET status=? WHERE id=?",[$mapped,$o['id']]);
          cstock($o['product_id'],$o['qty'],$o['status'],$mapped,$o['id']);
          $oc=$o['code']?:('#'.$o['id']);
          if($mapped==='delivered') cnotify("NCM delivered order $oc ✅",'delivered','ncm.php',24);
          elseif($mapped==='returned'){
            if(!(isset($o['cancel_charge']) && (float)$o['cancel_charge']>0)){
              $rc=ncm_return_charge($o['ncm_order_id']??'', isset($o['delivery_charge'])?(float)$o['delivery_charge']:null);
              if($rc!==null && $rc>0){ try{ q("UPDATE orders SET cancel_charge=? WHERE id=?",[$rc,$o['id']]); }catch(Exception $e){} }
            }
            cnotify("NCM returned order $oc ↩️",'ncm','ncm.php',24);
          }
          elseif($mapped==='cancelled') cnotify("NCM cancelled order $oc",'ncm','ncm.php',24);
          $n++;
        }
      }
      csay("NCM sync: ".count($rows)." open orders checked, $n updated");
    } else csay("NCM sync: no open NCM orders");
  } else csay("NCM sync skipped: no API key");
} catch (Exception $e) { csay("NCM sync error: ".$e->getMessage()); }

/* ============ 2) DAILY DATABASE BACKUP ============ */
try {
  $today = date('Y-m-d');
  if (setting('last_backup_date','') !== $today) {
    $dir = __DIR__.'/backups';
    if (!is_dir($dir)) { mkdir($dir,0755,true); file_put_contents($dir.'/.htaccess',"Require all denied\n"); file_put_contents($dir.'/index.html',''); }
    $file = $dir."/backup-$today.sql.gz";
    $gz = gzopen($file,'w6');
    gzwrite($gz,"-- Luprah ERP backup $today ".date('H:i:s')."\nSET FOREIGN_KEY_CHECKS=0;\n");
    $tables = array_map(fn($r)=>array_values($r)[0], rows("SHOW TABLES"));
    foreach ($tables as $t) {
      $create = row("SHOW CREATE TABLE `$t`");
      gzwrite($gz,"\nDROP TABLE IF EXISTS `$t`;\n".array_values($create)[1].";\n");
      $off=0;
      while (true) {
        $chunk = rows("SELECT * FROM `$t` LIMIT 500 OFFSET $off"); if(!$chunk) break; $off+=500;
        foreach ($chunk as $r) {
          $vals=implode(',',array_map(fn($v)=>$v===null?'NULL':db()->quote((string)$v),array_values($r)));
          gzwrite($gz,"INSERT INTO `$t` VALUES($vals);\n");
        }
      }
    }
    gzwrite($gz,"SET FOREIGN_KEY_CHECKS=1;\n"); gzclose($gz);
    /* keep newest 14 */
    $all=glob($dir.'/backup-*.sql.gz'); rsort($all);
    foreach(array_slice($all,14) as $old) @unlink($old);
    q("INSERT INTO settings(skey,svalue) VALUES('last_backup_date',?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)",[$today]);

    /* off-site copy — this hosting account is a single point of failure otherwise */
    $gdNote = '';
    require_once __DIR__.'/gdrive_api.php';
    if (gdrive_configured()) {
      try { gdrive_upload_file($file, basename($file)); $gdNote = ' · uploaded to Google Drive ✓'; }
      catch (Exception $e) { $gdNote = ' · ⚠ Google Drive upload failed: '.$e->getMessage(); }
    }

    cnotify("Daily backup saved (".basename($file).")".$gdNote,'info','settings.php',20);
    csay("Backup written: ".basename($file)." (".round(filesize($file)/1024)." KB), ".count($tables)." tables".$gdNote);
  } else csay("Backup: already done today");
} catch (Exception $e) { csay("Backup error: ".$e->getMessage()); }

/* ============ 2b) COD THRESHOLD ALERT (every run, deduped 24h) ============ */
try {
  $thr=(float)setting('cod_alert_threshold',100000);
  if($thr>0){
    foreach(cod_holdings() as $h){
      if($h['held']>$thr)
        cnotify("💰 ".$h['courier']." holding Rs.".number_format($h['held'])." COD — above your Rs.".number_format($thr)." limit",'warning','cod.php',24);
    }
    csay("COD threshold checked");
  } else csay("COD threshold: off");
} catch (Exception $e) { csay("COD check error: ".$e->getMessage()); }

/* ============ 3) WEEKLY EMAIL REPORT (Mondays) ============ */
try {
  $week = date('o-W');
  $emailTo = trim((string)setting('report_email', setting('store_email','')));
  if (date('N')==1 && $emailTo!=='' && setting('last_report_week','')!==$week) {
    $from=date('Y-m-d',strtotime('-7 days')); $to=date('Y-m-d',strtotime('-1 day'));
    $os=rows("SELECT * FROM orders WHERE order_date BETWEEN ? AND ?",[$from,$to]);
    $rev=0;$prof=0;$del=0;$ret=0;$units=0;
    foreach($os as $o){ if($o['status']==='delivered'){$del++;$units+=(int)$o['qty'];$rev+=(float)$o['sell_price']*(int)$o['qty'];$prof+=((float)$o['sell_price']-(float)$o['cost_price'])*(int)$o['qty']-(float)$o['delivery_charge'];}
      elseif(in_array($o['status'],['returned','cancelled'],true))$ret++; }
    $exp=(float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN ? AND ?",[$from,$to]);
    $lowStock=(int)val("SELECT COUNT(*) FROM products WHERE stock<=low_stock");
    $store=setting('store_name','Luprah Trading');
    $body="<h2>$store — Weekly Report</h2><p>$from → $to</p><table cellpadding='6' border='1' style='border-collapse:collapse'>
      <tr><td>Orders</td><td><b>".count($os)."</b></td></tr>
      <tr><td>Delivered</td><td><b>$del orders · $units pcs</b></td></tr>
      <tr><td>Returned/Cancelled</td><td><b>$ret</b></td></tr>
      <tr><td>Revenue</td><td><b>Rs.".number_format($rev)."</b></td></tr>
      <tr><td>Gross Profit (after delivery)</td><td><b>Rs.".number_format($prof)."</b></td></tr>
      <tr><td>Expenses</td><td><b>Rs.".number_format($exp)."</b></td></tr>
      <tr><td>Net</td><td><b>Rs.".number_format($prof-$exp)."</b></td></tr>
      <tr><td>Low/Out-of-stock products</td><td><b>$lowStock</b></td></tr></table>";
    $holds=cod_holdings();
    if($holds){ $body.="<h3>Couriers holding your COD</h3><ul>";
      foreach($holds as $h){ $body.="<li>".$h['courier'].": <b>Rs.".number_format($h['held'])."</b></li>"; }
      $body.="</ul>"; }
    $reo=reorder_suggestions();
    if($reo){ $body.="<h3>Reorder soon</h3><ul>";
      foreach(array_slice($reo,0,10) as $r){ $body.="<li>".$r['name'].": ".$r['stock']." left (~".$r['per_week']."/week) — order ~".$r['suggest']." pcs</li>"; }
      $body.="</ul>"; }
    $body.="<p>Open your dashboard: https://luprah.online/erp/</p>";
    $hdr="MIME-Version: 1.0\r\nContent-type: text/html; charset=utf-8\r\nFrom: $store <no-reply@luprah.online>\r\n";
    $ok=@mail($emailTo,"[$store] Weekly report $from → $to",$body,$hdr);
    q("INSERT INTO settings(skey,svalue) VALUES('last_report_week',?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)",[$week]);
    csay("Weekly report ".($ok?'sent to ':'attempted to ').$emailTo);
  } else csay("Weekly report: not due");
} catch (Exception $e) { csay("Report error: ".$e->getMessage()); }

csay("done");