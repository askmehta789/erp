<?php
/* ============================================================
   ncm_api.php — Nepal Can Move (NCM) API client
   Real endpoints (base https://portal.nepalcanmove.com/api/):
     GET  /v2/branches
     GET  /v1/shipping-rate?creation=&destination=&type=
     POST /v1/order/create
     GET  /v1/order?id=
     GET  /v1/order/status?id=
     POST /v1/orders/statuses         {orders:[...]}
     GET  /v1/order/comment?id=
     POST /v1/comment                 {orderid,comments}
     GET  /v1/order/getbulkcomments
   Auth header:  Authorization: Token <api_key>
   ============================================================ */
require_once __DIR__.'/functions.php';

class NCM {
  private string $base;
  private string $key;

  public function __construct() {
    $this->key  = trim((string)setting('ncm_api_key',''));
    $sandbox    = setting('ncm_sandbox','') === 'yes';
    $default    = $sandbox ? 'https://demo.nepalcanmove.com/api' : 'https://portal.nepalcanmove.com/api';
    $this->base = rtrim((string)setting('ncm_base', $default), '/');
  }

  public function configured(): bool { return $this->key !== ''; }

  private function call(string $method, string $path, array $query = [], ?array $json = null) {
    if (!$this->configured()) throw new Exception('NCM API key not set. Add it in Settings → Courier / NCM API.');
    $url = $this->base . $path;
    if ($query) $url .= '?' . http_build_query($query);
    $headers = [
      'Authorization: Token ' . $this->key,
      'Accept: application/json',
      'Content-Type: application/json',
    ];

    if (function_exists('curl_init')) {
      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
      ]);
      if ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
      $body = curl_exec($ch);
      $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $err  = curl_error($ch);
      curl_close($ch);
      if ($body === false) throw new Exception('Could not reach NCM: ' . $err);
    } else {
      $opts = ['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'timeout'=>30,'ignore_errors'=>true]];
      if ($json !== null) $opts['http']['content'] = json_encode($json);
      $body = @file_get_contents($url, false, stream_context_create($opts));
      $code = 200;
      if (isset($http_response_header)) foreach ($http_response_header as $h)
        if (preg_match('#HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
      if ($body === false) throw new Exception('Could not reach NCM (cURL not available on server).');
    }

    $data = json_decode($body, true);
    if ($code < 200 || $code > 299) {
      $msg = $body;
      if (is_array($data)) {
        foreach (['Error','detail','message'] as $k) if (isset($data[$k])) { $msg = $data[$k]; break; }
        if ($msg === $body) {  /* DRF-style {"field":["problem"]} → "field: problem" */
          $parts = [];
          foreach ($data as $k=>$v) $parts[] = $k.': '.(is_array($v)?implode(' ',array_map('strval',$v)):(string)$v);
          if ($parts) $msg = implode(' · ', $parts);
        }
      }
      if (is_array($msg)) $msg = json_encode($msg);
      throw new Exception('NCM API error (' . $code . '): ' . $msg);
    }
    return $data === null ? [] : $data;
  }

  /* ---- endpoints ---- */
  public function branches(): array            { return $this->call('GET','/v2/branches'); }
  public function rate($from,$to,$type)         { return $this->call('GET','/v1/shipping-rate',['creation'=>$from,'destination'=>$to,'type'=>$type]); }
  public function createOrder(array $p): array  { return $this->call('POST','/v1/order/create',[],$p); }
  public function order($id): array             { return $this->call('GET','/v1/order',['id'=>$id]); }
  public function statusHistory($id): array     { return $this->call('GET','/v1/order/status',['id'=>$id]); }
  public function ordersStatuses(array $ids)    { return $this->call('POST','/v1/orders/statuses',[],['orders'=>array_values($ids)]); }
  public function comments($id): array          { return $this->call('GET','/v1/order/comment',['id'=>$id]); }
  public function addComment($id,$c): array     { return $this->call('POST','/v1/comment',[],['orderid'=>$id,'comments'=>$c]); }
  public function bulkComments(): array         { return $this->call('GET','/v1/order/getbulkcomments'); }
}

function ncm(): NCM { static $i=null; return $i ?? ($i = new NCM()); }

/* delivery types for order/create + shipping-rate */
/* When NCM returns a parcel it discounts the delivery charge to a return fee and shows
   that figure on the order. Read it straight from NCM so the sale carries the real charge.
   Returns the rupee amount NCM decided, or null if it can't be read. */
/* NCM vendor-portal link for an order. the real per-order URL is /accounts/vendor/order/<id>. Override in Settings → "NCM Portal Order URL" with {id} where the number goes. */
if(!function_exists('ncm_portal_url')){
  /* PORTAL_FIX_V2 — per-order deep link */
  function ncm_portal_url($nid){
    $tpl = trim((string)setting('ncm_portal_tpl',''));
    /* a custom template is honoured ONLY if it has {id} and is not a known-broken NCM path.
       The real NCM per-order URL is /accounts/vendor/order/<id> — older guesses
       (/login/, /order/{id}, /vendor/order/{id}) bounce to NCM's homepage, so ignore them. */
    if($tpl!=='' && strpos($tpl,'{id}')!==false){
      $bad = (stripos($tpl,'nepalcanmove.com')!==false && stripos($tpl,'/accounts/vendor/order/')===false);
      if(!$bad) return str_replace('{id}', rawurlencode((string)$nid), $tpl);
    }
    return 'https://portal.nepalcanmove.com/accounts/vendor/order/'.rawurlencode((string)$nid);
  }
}

function ncm_return_charge($ncmId, $fallback=null) {
  $ncmId = trim((string)$ncmId);
  if ($ncmId==='' || !ncm()->configured()) return $fallback;
  try {
    $od = ncm()->order($ncmId);
    // NCM exposes the (now discounted) charge as delivery_charge on the returned order
    foreach (['delivery_charge','return_charge','charge'] as $k) {
      if (isset($od[$k]) && is_numeric($od[$k])) return (float)$od[$k];
    }
  } catch (Exception $e) {}
  return $fallback;
}

function ncm_delivery_types(): array {
  return [
    'Door2Door'   => ['label'=>'Door to Door','rate'=>'Pickup/Collect'],
    'Branch2Door' => ['label'=>'Branch to Door','rate'=>'Send'],
    'Door2Branch' => ['label'=>'Door to Branch','rate'=>'D2B'],
    'Branch2Branch'=>['label'=>'Branch to Branch','rate'=>'B2B'],
  ];
}

/* colour class for an NCM status label */
function ncm_status_class(string $s): string {
  $s = strtolower($s);
  if (str_contains($s,'deliver') && !str_contains($s,'sent')) return 'p-green';
  if (str_contains($s,'cancel') || str_contains($s,'return')) return 'p-red';
  if (str_contains($s,'dispatch') || str_contains($s,'sent for delivery') || str_contains($s,'arrived')) return 'p-yellow';
  if (str_contains($s,'pickup') || str_contains($s,'created') || str_contains($s,'collect')) return 'p-blue';
  return 'p-grey';
}


/* ---- shared NCM helpers (used by ncm.php and cron.php) ---- */
function ncm_no_response(string $txt): bool {
  $t = strtolower($txt);
  foreach (['not receiv','not respond','no response','unreachable','switch off','switched off',
            'phone off','number off','not picking','not pick','no answer','not answer','call not',
            'not available','busy','reschedul','out of contact','off ','not reachable','no contact',
            'declined','refus','postpone','not in contact'] as $kw) {
    if (strpos($t,$kw)!==false) return true;
  }
  return false;
}

/* ------------------------------------------------------------------
   Return classification.
   NCM uses the word "return" long before a parcel is actually back with
   us: a failed delivery attempt sends the parcel back to the branch /
   warehouse overnight and the rider retries the next day. Marking those
   as 'returned' kills a live sale and inflates the return rate.
   Stages:  none | progress (still in play) | final (really back with us)
            ambiguous (bare "Returned" — needs a human to confirm)
   ------------------------------------------------------------------ */
function ncm_return_stage(string $ncm): string {
  $s = strtolower(trim($ncm));
  if ($s==='') return 'none';
  if (strpos($s,'return')===false && strpos($s,'rtv')===false && strpos($s,'vendor')===false) return 'none';

  /* still moving / will be retried — NEVER auto-mark these */
  foreach ([
    'sent for return','send for return','sent to vendor','sending to vendor','return pending','pending return',
    'return request','requested return','return initiated','return process','return processing','returning',
    'return to branch','returned to branch','return to warehouse','returned to warehouse','back to branch',
    'at branch','to branch','warehouse','reattempt','re-attempt','re attempt','retry','next day',
    'on hold','hold','in transit','on the way','out for'
  ] as $kw) if (strpos($s,$kw)!==false) return 'progress';

  /* really finished — parcel is physically back with the vendor */
  foreach ([
    'return complete','returned complete','return completed','returned completed','return received',
    'received by vendor','returned to vendor','return to vendor','delivered to vendor','vendor received',
    'rtv complete','rtv completed','return delivered','return closed','return done','return finished'
  ] as $kw) if (strpos($s,$kw)!==false) return 'final';

  return 'ambiguous';   /* e.g. a bare "Returned" */
}

/* ---- timeline verdict: did the FINAL "Delivered" go to the customer or back to the vendor? ----
   The step immediately before the last plain "Delivered" tells the truth:
     … Sent to Vendor            → Delivered   = parcel handed BACK TO VENDOR (a return)
     … Sent for Delivery / Arrived at <branch> → Delivered   = genuine CUSTOMER delivery
   Mid-journey wobbles like "Returned to Warehouse" (wrong-branch fixes) must NOT count. */
function ncm_hist_items($raw): array {
  if (!is_array($raw)) return [];
  foreach (['data','result','history','statuses'] as $k)
    if (isset($raw[$k]) && is_array($raw[$k])) { $raw=$raw[$k]; break; }
  $out=[];
  foreach ($raw as $h) {
    if (is_array($h)) $out[]=['status'=>(string)($h['status'] ?? ($h['title'] ?? '')), 'added_time'=>(string)($h['added_time'] ?? ($h['addedTime'] ?? ''))];
    elseif (is_string($h)) $out[]=['status'=>$h,'added_time'=>''];
  }
  return array_values(array_filter($out, fn($x)=>trim($x['status'])!==''));
}
function ncm_timeline_verdict(array $items): string {
  if (!$items) return 'unknown';
  /* order oldest → newest using timestamps when available */
  $ts=array_map(fn($x)=>strtotime($x['added_time'] ?: '') ?: null, $items);
  $parseable=count(array_filter($ts, fn($t)=>$t!==null));
  if ($parseable >= max(2, (int)ceil(count($items)/2))) {
    array_multisort(array_map(fn($t)=>$t ?? PHP_INT_MAX,$ts), SORT_ASC, $items);
  }
  $isPlainDeliver=function(string $st): bool {
    $l=strtolower($st);
    return strpos($l,'deliver')!==false && strpos($l,'sent for')===false && strpos($l,'send for')===false
        && strpos($l,'out for')===false && strpos($l,'arrived')===false && strpos($l,'dispatch')===false
        && strpos($l,'vendor')===false && strpos($l,'return')===false;
  };
  $last=-1;
  foreach ($items as $i=>$x) if ($isPlainDeliver($x['status'])) $last=$i;
  if ($last<0) return 'unknown';
  for ($i=$last-1; $i>=0; $i--) {
    $l=strtolower($items[$i]['status']);
    foreach (['sent to vendor','send to vendor','sending to vendor','to vendor','vendor','rtv',
              'dispatched to return','dispatch to return','arrived at return','sent for return','send for return'] as $kw)
      if (strpos($l,$kw)!==false) return 'vendor';
    foreach (['sent for delivery','send for delivery','out for delivery','out for','arrived at','dispatched to',
              'dispatch to','reattempt','re-attempt','pickup'] as $kw)
      if (strpos($l,$kw)!==false && strpos($l,'return')===false) return 'customer';
    /* neutral steps ("Returned to Warehouse", holds) — keep walking back */
  }
  return 'unknown';
}

/* definitive: the parcel is on its way back to YOU — "Sent to Vendor" / RTV.
   ("Returned to Warehouse" is NOT definitive — NCM uses it for wrong-branch fixes.) */
function ncm_vendor_bound(string $st): bool {
  $l=strtolower($st);
  foreach (['sent to vendor','send to vendor','sending to vendor','rtv'] as $kw)
    if (strpos($l,$kw)!==false) return true;
  return false;
}

/* map an NCM status label to our Sales status (or null to leave unchanged) */
function ncm_to_local_status(string $ncm): ?string {
  $s = strtolower(trim($ncm));
  if ($s==='') return null;
  if (strpos($s,'cancel')!==false) return 'cancelled';

  /* returns are judged BEFORE 'deliver' so "Return Delivered" / "Delivered to Vendor"
     can never be mistaken for a successful delivery */
  $stage = ncm_return_stage($s);
  if ($stage !== 'none') {
    $policy = setting('ncm_return_policy','confirm');   /* confirm | auto | manual */
    if ($policy==='auto')   return 'returned';
    if ($policy==='manual') return null;
    return $stage==='final' ? 'returned' : null;        /* default: only confirmed returns */
  }

  if (strpos($s,'deliver')!==false && strpos($s,'sent')===false && strpos($s,'out for')===false) return 'delivered';
  if (strpos($s,'dispatch')!==false||strpos($s,'sent for delivery')!==false||strpos($s,'arrived')!==false||strpos($s,'out for')!==false) return 'shipped';
  if (strpos($s,'pickup')!==false||strpos($s,'collect')!==false||strpos($s,'created')!==false) return 'processing';
  return null;
}

/* orders table needs two extra columns for live NCM state (self-healing) */
function ncm_ensure_cols(){ static $ok=false; if($ok)return;
  /* portable across MySQL and MariaDB: probe information_schema, then plain ALTER */
  $need=[
    'ncm_order_id'    => "ALTER TABLE orders ADD COLUMN ncm_order_id VARCHAR(40) NULL",
    'ncm_status'      => "ALTER TABLE orders ADD COLUMN ncm_status VARCHAR(64) NULL",
    'ncm_return_flag' => "ALTER TABLE orders ADD COLUMN ncm_return_flag TINYINT(1) NOT NULL DEFAULT 0",
  ];
  foreach($need as $col=>$ddl){
    try {
      $has=(int)val("SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders' AND COLUMN_NAME=?",[$col]);
      if(!$has) q($ddl);
    } catch (Exception $e) {}
  }
  $ok=true;
}

/* NCM's bulk feed only returns the latest ~25 comments — this scans every active
   order's own thread and collects ALL of today's comments into a cache. */
function ncm_scan_today_comments($cap=120){
  $today=date('Y-m-d');
  $ids=[];
  foreach(rows("SELECT ncm_order_id FROM orders WHERE COALESCE(ncm_order_id,'')<>'' AND status NOT IN ('delivered','cancelled','returned') ORDER BY id DESC LIMIT ".(int)$cap) as $r)
    $ids[(string)$r['ncm_order_id']]=1;
  /* include ids seen in the bulk feed too (covers just-delivered ones still chatting) */
  try { foreach(ncm()->bulkComments() as $c){ $oid=(string)($c['orderid']??($c['order']??'')); if($oid!=='')$ids[$oid]=1; } } catch (Exception $e) {}
  $ids=array_slice(array_keys($ids),0,$cap);
  $items=[]; $seen=[]; $scanned=0;
  foreach($ids as $nid){
    try { $th=ncm()->comments((int)$nid); } catch (Exception $e) { continue; }
    $scanned++;
    if(is_array($th)) foreach($th as $c){
      if(!is_array($c)) continue;
      $t=(string)($c['added_time']??($c['addedTime']??'')); $ts=strtotime($t);
      if(!$ts || date('Y-m-d',$ts)!==$today) continue;
      $c['orderid']=$c['orderid']??($c['order']??$nid);
      $key=$c['orderid'].'|'.$t.'|'.md5((string)($c['comments']??($c['comment']??'')));
      if(isset($seen[$key]))continue; $seen[$key]=1;
      $c['_ts']=$ts; $items[]=$c;
    }
    usleep(60000);
  }
  usort($items,fn($a,$b)=>($b['_ts']??0)<=>($a['_ts']??0));
  kv_set('ncm_today_comments',['date'=>$today,'items'=>$items,'scanned'=>$scanned]);
  return [count($items),$scanned];
}