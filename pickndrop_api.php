<?php
/* ============================================================
   pickndrop_api.php — Pick & Drop Nepal API client
   Docs: https://pickndrop.apidog.io/  (Frappe-framework backend)
   Base URLs:
     Test:       https://app-t.pickndropnepal.com
     Production: https://pickndropnepal.com
   Auth header: Authorization: token <api_key>:<api_secret>

   Documented endpoints used here:
     GET  /api/method/logi360.api.get_branches
     GET  /api/method/logi360.api.business_address
     GET  /api/method/logi360.api.get_vendor_address_info
     POST /api/method/logi360.api.create_vendor_address_info
     GET  /api/method/logi360.api.get_delivery_rate
     POST /api/v2/method/logi360.api.create_order
     PUT  /api/method/logi360.api.cancel_order
     GET  /api/method/logi360.api.get_order_details
     POST /api/v2/method/logi360.api.pickup_notification
     POST /api/v2/create_webhook
   ============================================================ */
require_once __DIR__.'/functions.php';

class PickDrop {
  private string $base;
  private string $key;
  private string $secret;

  public function __construct() {
    $this->key    = trim((string)setting('pd_api_key',''));
    $this->secret = trim((string)setting('pd_api_secret',''));
    $sandbox      = setting('pd_sandbox','') === 'yes';
    $this->base   = $sandbox ? 'https://app-t.pickndropnepal.com' : 'https://pickndropnepal.com';
  }

  public function configured(): bool { return $this->key !== '' && $this->secret !== ''; }

  private function headers(): array {
    return ['Authorization: token ' . $this->key . ':' . $this->secret, 'Accept: application/json', 'Content-Type: application/json'];
  }

  private function decode($body, int $code) {
    if ($body === false) throw new Exception('Could not reach Pick & Drop.');
    $data = json_decode($body, true);
    if ($code < 200 || $code > 299) {
      $msg = $body;
      if (is_array($data)) {
        /* raw Frappe framework error (a full Python traceback) — this shows up when the
           API key's role lacks permission for the endpoint being called, not just on a
           genuinely bad request. Surface a short, actionable message instead of the dump. */
        /* two different 401 body shapes have been seen in practice: {exc_type:'...'} on some
           endpoints, and {errors:[{type:'AuthenticationError',...}]} on create_order — treat
           either as the same "key lacks permission for this endpoint" case. */
        $errType = $data['errors'][0]['type'] ?? null;
        if ($code == 401 && (isset($data['exc_type']) || $errType === 'AuthenticationError')) {
          $msg = 'Authentication rejected for this endpoint — the Api Key/Secret may not have '
               . 'permission for this action. Ask Pick & Drop support to grant it Order API access.';
        } else {
          $m = $data['message'] ?? null;
          if (is_array($m) && isset($m['message'])) $msg = $m['message'];
          elseif (is_string($m)) $msg = $m;
          elseif (isset($data['message']) && is_string($data['message'])) $msg = $data['message'];
          elseif (isset($data['exc_type'])) $msg = (string)$data['exc_type'];
        }
      }
      throw new Exception('Pick & Drop API error (' . $code . '): ' . $msg);
    }
    /* PD wraps almost everything as {message:{status:'error',...}} even on HTTP 200 */
    if (is_array($data) && isset($data['message']['status']) && $data['message']['status'] === 'error') {
      throw new Exception('Pick & Drop: ' . ($data['message']['message'] ?? 'request failed'));
    }
    /* create_order (at least for Express) instead returns a flat {status:'error',message:'...'}
       on HTTP 200 — message here is a plain string, not the nested object above. Without this
       check that shape slips past as a "success" with no recognizable fields, and the caller
       wrongly reports "order accepted but no id returned" instead of surfacing PD's real reason. */
    if (is_array($data) && ($data['status'] ?? '') === 'error') {
      throw new Exception('Pick & Drop: ' . (is_string($data['message'] ?? null) ? $data['message'] : 'request failed'));
    }
    return $data === null ? [] : $data;
  }

  private function call(string $method, string $path, array $query = [], ?array $json = null) {
    if (!$this->configured()) throw new Exception('Pick & Drop API key/secret not set. Add them in Settings → Courier / Pick & Drop.');
    $url = $this->base . $path;
    if ($query) $url .= '?' . http_build_query($query);

    if (function_exists('curl_init')) {
      $ch = curl_init();
      curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $this->headers(),
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_POSTFIELDS     => $json !== null ? json_encode($json) : '',
      ]);
      $body = curl_exec($ch);
      $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $err  = curl_error($ch);
      curl_close($ch);
      if ($body === false) throw new Exception('Could not reach Pick & Drop: ' . $err);
    } else {
      $opts = ['http'=>['method'=>$method,'header'=>implode("\r\n",$this->headers()),'timeout'=>20,'ignore_errors'=>true]];
      if ($json !== null) $opts['http']['content'] = json_encode($json);
      $body = @file_get_contents($url, false, stream_context_create($opts));
      $code = 200;
      if (isset($http_response_header)) foreach ($http_response_header as $h)
        if (preg_match('#HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
      if ($body === false) throw new Exception('Could not reach Pick & Drop (cURL not available on server).');
    }
    return $this->decode($body, $code);
  }

  /* Frappe/logi360 endpoints are documented as {message:{data:{...}}}, but
     create_order's real response (confirmed from a live error log) is
     {status,message:"Order created successfully.",data:{...}} — "message" is a
     plain human-readable string there, not the nested object the old code
     assumed, and the real payload sits at the top-level "data" key instead.
     Checking is_array() before treating "message" as a container makes that
     explicit instead of leaning on PHP's null-coalescing to paper over it. */
  private function msgArray($raw) {
    return (is_array($raw) && isset($raw['message']) && is_array($raw['message'])) ? $raw['message'] : null;
  }

  /* GET get_branches -> {data:{branches:[...]}} (or nested under message) — cached 6h */
  public function branches(): array {
    $c = kv_get('pickndrop_branches_cache');
    if ($c && !empty($c['v']) && (time() - strtotime((string)$c['at'])) < 6*3600) return $c['v'];
    try {
      $data = $this->call('GET', '/api/method/logi360.api.get_branches');
      $m = $this->msgArray($data);
      $list = $data['data']['branches'] ?? ($m['data']['branches'] ?? []);
      kv_set('pickndrop_branches_cache', $list);
      return $list;
    } catch (Exception $e) {
      if ($c && !empty($c['v'])) return $c['v'];
      throw $e;
    }
  }

  /* GET business_address -> {data:{vendor_name,addresses:[...]}} (or nested under message) */
  public function businessAddresses(): array {
    $data = $this->call('GET', '/api/method/logi360.api.business_address');
    $m = $this->msgArray($data);
    return $data['data'] ?? ($m['data'] ?? ['vendor_name'=>'','addresses'=>[]]);
  }

  /* GET get_vendor_address_info -> {data:{vendor_address_info:{address_info:[...]}}} */
  public function vendorLocations(): array {
    $data = $this->call('GET', '/api/method/logi360.api.get_vendor_address_info');
    $m = $this->msgArray($data);
    return $data['data']['vendor_address_info']['address_info']
        ?? ($m['data']['vendor_address_info']['address_info'] ?? []);
  }

  /* POST create_vendor_address_info */
  public function createVendorLocation(array $p): array {
    return $this->call('POST', '/api/method/logi360.api.create_vendor_address_info', [], $p);
  }

  /* GET get_delivery_rate (sent as query params) -> {data:{delivery_amount}} (or nested under message) */
  public function rate(array $p): array {
    $data = $this->call('GET', '/api/method/logi360.api.get_delivery_rate', $p);
    return $this->msgArray($data) ?? $data;
  }

  /* POST /api/v2/method/logi360.api.create_order -> {status,message:"...",data:{orderID,delivery_charge,status,tracking_url}} */
  public function createOrder(array $p): array {
    $data = $this->call('POST', '/api/v2/method/logi360.api.create_order', [], $p);
    $m = $this->msgArray($data);
    return $data['data'] ?? ($m['data'] ?? []);
  }

  /* PUT cancel_order {orderID} */
  public function cancelOrder(string $orderId): array {
    return $this->call('PUT', '/api/method/logi360.api.cancel_order', [], ['orderID'=>$orderId]);
  }

  /* GET get_order_details?order_id= -> {data:[{...}]} (or nested under message) */
  public function orderDetails(string $orderId): array {
    $data = $this->call('GET', '/api/method/logi360.api.get_order_details', ['order_id'=>$orderId]);
    $m = $this->msgArray($data);
    $d = $data['data'] ?? ($m['data'] ?? []);
    return is_array($d) && isset($d[0]) ? $d[0] : $d;
  }

  /* POST pickup_notification {vendor_address} */
  public function pickupRequest(string $vendorAddress): array {
    return $this->call('POST', '/api/v2/method/logi360.api.pickup_notification', [], ['vendor_address'=>$vendorAddress]);
  }

  /* POST /api/v2/create_webhook {request_url,webhook_secret,enabled} */
  public function createWebhook(string $url, string $secret, bool $enabled = true): array {
    return $this->call('POST', '/api/v2/create_webhook', [], ['request_url'=>$url,'webhook_secret'=>$secret,'enabled'=>$enabled]);
  }
}

function pickndrop(): PickDrop { static $i=null; return $i ?? ($i = new PickDrop()); }

/* orders table needs a few extra columns for live Pick & Drop state (self-healing, like NCM's) */
function pd_ensure_cols(){ static $ok=false; if($ok)return;
  $need=[
    'pd_order_id'      => "ALTER TABLE orders ADD COLUMN pd_order_id VARCHAR(40) NULL",
    'pd_status'        => "ALTER TABLE orders ADD COLUMN pd_status VARCHAR(64) NULL",
    'pd_tracking_url'  => "ALTER TABLE orders ADD COLUMN pd_tracking_url VARCHAR(255) NULL",
    'pd_order_type'    => "ALTER TABLE orders ADD COLUMN pd_order_type VARCHAR(16) NULL",
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

/* Pick & Drop needs a clean 10-digit Nepali mobile (strip spaces/+977/leading 0s, keep last 10) */
function pd_norm_phone($p){
  $d=preg_replace('/[^0-9]/','',(string)$p);
  $d=preg_replace('/^977/','',$d);
  $d=ltrim($d,'0');
  if(strlen($d)>10) $d=substr($d,-10);
  return (strlen($d)===10 && $d[0]==='9') ? $d : '';
}
/* This app's own Regular/Express delivery-speed selection (UI dropdown, pd_order_type
   column, badges). NOT the same thing as Pick & Drop's own "orderType" API field, which
   only takes Regular/Exchange/Return — Express/speed is a separate express_delivery 0/1
   flag on their side (see pd_book_one() in pickndrop.php). Anything unrecognized here
   falls back to Regular so a bad/missing value never blocks a booking. */
function pd_order_type_norm($v){
  return strtolower(trim((string)$v))==='express' ? 'Express' : 'Regular';
}
/* match free text to Pick & Drop's exact branch name (case-insensitive, then substring) */
function pd_match_branch($name,$names){
  $t = mb_strtoupper(trim((string)$name));
  if ($t==='') return '';
  foreach ($names as $b) if (mb_strtoupper($b)===$t) return $b;
  foreach ($names as $b) if (mb_strpos(mb_strtoupper($b),$t)!==false || mb_strpos($t,mb_strtoupper($b))!==false) return $b;
  return '';
}
/* map a Pick & Drop status string (from get_order_details or the webhook payload) to our
   Sales status. Terminal "returned" states only fire once the parcel is actually back —
   mid-journey return attempts are left as 'shipped' so a retry doesn't kill a live sale. */
function pd_to_local_status(string $s): ?string {
  $s = strtolower(trim($s));
  if ($s==='') return null;
  if (str_contains($s,'cancel')) return 'cancelled';
  /* two different vocabularies show up in practice: get_order_details' coarse
     order-level status ("Open","Processing","Completed","Failed Attempt",…) and the
     webhook's granular snake_case tracking codes ("delivered","out_for_delivery",…).
     "Completed" is their word for delivered — handle both spellings. */
  if (in_array($s, ['completed','delivered'], true)) return 'delivered';
  if (in_array($s, ['package_returned','package_returned_from_lastmile_sation_to_transporter','returned'], true)) return 'returned';
  if (str_contains($s,'return')) return 'shipped';   /* mid-transit return attempt — don't close the sale yet */
  if (in_array($s, ['open','package_pickup_assigned','waiting_for_drop_off'], true)) return 'processing';
  return 'shipped';   /* everything else (in transit, failed attempt, etc.) is somewhere between pickup and delivery */
}

/* dig a phone number out of a Pick & Drop order-details payload (field name is
   documented as primary_mobile_no, but walk the whole thing so minor API drift
   doesn't break linking) */
function pd_extract_phone($d){
  foreach (['primary_mobile_no','primaryMobileNo','phone'] as $k)
    if (isset($d[$k])) { $p=pd_norm_phone($d[$k]); if ($p!=='') return $p; }
  $found='';
  $walk=function($v)use(&$walk,&$found){
    if($found!=='')return;
    if(is_array($v)){foreach($v as $x)$walk($x);return;}
    if(is_string($v)||is_numeric($v)){ $p=pd_norm_phone($v); if($p!=='')$found=$p; }
  };
  $walk($d);
  return $found;
}
/* Pick & Drop's own docs promise one field name per endpoint, but in practice the
   create_order response, get_order_details response, and the webhook payload have
   each been seen using a different spelling for the same value — so every place
   that needs an order id / delivery charge / tracking link walks all the known
   spellings instead of trusting a single key. This is what lets an order that
   Pick & Drop DID create get linked even when their response shape drifts. */
/* Every extractor below also falls back to a nested "data" key, one level deep,
   if none of the direct keys match — so these still work even when whatever
   called them handed over the whole {status,message,data:{...}} wrapper instead
   of the already-unwrapped inner object (e.g. because createOrder()'s own
   unwrapping got skipped by a stale/partial deploy of just one of these two
   files). Cheap safety net, no reason it should ever hurt. */
function pd_extract_order_id($d){
  foreach (['orderID','order_id','orderId','order_no','orderNo','name','id'] as $k)
    if (isset($d[$k]) && trim((string)$d[$k])!=='') return (string)$d[$k];
  if (isset($d['data']) && is_array($d['data'])) return pd_extract_order_id($d['data']);
  return '';
}
function pd_extract_charge($d){
  foreach (['delivery_charge','delivery_amount','deliveryCharge','delivery_fee','deliveryAmount'] as $k)
    if (isset($d[$k]) && is_numeric($d[$k])) return (float)$d[$k];
  if (isset($d['data']) && is_array($d['data'])) return pd_extract_charge($d['data']);
  return null;
}
function pd_extract_tracking_url($d){
  foreach (['tracking_url','trackingUrl','tracking_link','trackingLink'] as $k)
    if (isset($d[$k]) && trim((string)$d[$k])!=='') return (string)$d[$k];
  if (isset($d['data']) && is_array($d['data'])) return pd_extract_tracking_url($d['data']);
  return '';
}
/* order type as Pick & Drop itself reports it back (order details / webhook) —
   returns null (leave our stored value alone) when the field isn't present at all */
function pd_extract_order_type($d){
  foreach (['orderType','order_type'] as $k)
    if (isset($d[$k]) && trim((string)$d[$k])!=='') return pd_order_type_norm($d[$k]);
  if (isset($d['data']) && is_array($d['data'])) return pd_extract_order_type($d['data']);
  return null;
}
function pd_extract_name($d){
  foreach(['customer_name','customerName','name'] as $k)
    if(isset($d[$k]) && is_string($d[$k]) && trim($d[$k])!=='') return trim($d[$k]);
  return '';
}

/* colour class for a Pick & Drop status label (reuses the app's pill classes) */
function pd_status_class(string $s): string {
  $s = strtolower($s);
  if ($s==='delivered') return 'p-green';
  if (str_contains($s,'cancel') || $s==='package_returned' || str_contains($s,'return')) return 'p-red';
  if (str_contains($s,'out_for_delivery') || str_contains($s,'about_to_deliver') || str_contains($s,'dispatch')) return 'p-yellow';
  if (str_contains($s,'pickup') || str_contains($s,'waiting')) return 'p-blue';
  return 'p-grey';
}
