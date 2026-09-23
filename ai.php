<?php
require_once __DIR__.'/functions.php'; require_login();

/* ============================================================
   AI assistant — works FREE with no API key (built-in rules).
   If an Anthropic API key is set in Settings, it upgrades to
   the smarter LLM automatically.
   ============================================================ */
function ai_configured(): bool { return trim((string)setting('ai_api_key','')) !== ''; }
function gemini_configured(): bool { return trim((string)setting('gemini_api_key','')) !== ''; }

/* ============================================================
   Read-only "database access" for the AI Assistant chat page.
   The model NEVER sees raw SQL or a DB connection — it can only
   call these fixed, parameterized, SELECT-only lookups via
   Gemini function calling. There is no write/delete tool, so a
   confused or manipulated model can't change or destroy data.
   ============================================================ */
function ai_gemini_tools(): array {
  return [[
    'functionDeclarations' => [
      ['name'=>'get_business_snapshot',
       'description'=>'Overall snapshot: today + this month revenue, orders, expenses, estimated profit, stock alerts, pending COD. Use for general "how is business doing" questions.',
       'parameters'=>['type'=>'OBJECT','properties'=>new stdClass()]],
      ['name'=>'search_orders',
       'description'=>'Search orders by customer name, phone number, or order code. Returns up to 10 matches with status, product, amount, date.',
       'parameters'=>['type'=>'OBJECT','properties'=>[
         'query'=>['type'=>'STRING','description'=>'Customer name, phone number, or order code'],
       ],'required'=>['query']]],
      ['name'=>'get_sales_summary',
       'description'=>'Sales totals for a date range: order count, delivered/cancelled counts, revenue, profit.',
       'parameters'=>['type'=>'OBJECT','properties'=>[
         'from'=>['type'=>'STRING','description'=>'Start date, YYYY-MM-DD'],
         'to'=>['type'=>'STRING','description'=>'End date, YYYY-MM-DD'],
       ],'required'=>['from','to']]],
      ['name'=>'get_expenses_summary',
       'description'=>'Total expenses and a category breakdown for a date range, optionally filtered to one category.',
       'parameters'=>['type'=>'OBJECT','properties'=>[
         'from'=>['type'=>'STRING','description'=>'Start date, YYYY-MM-DD'],
         'to'=>['type'=>'STRING','description'=>'End date, YYYY-MM-DD'],
         'category'=>['type'=>'STRING','description'=>'Optional expense category to filter to'],
       ],'required'=>['from','to']]],
      ['name'=>'get_product_stock',
       'description'=>'Product stock levels, price and cost. Optionally filtered by a name search; otherwise returns the lowest-stock products first.',
       'parameters'=>['type'=>'OBJECT','properties'=>[
         'name'=>['type'=>'STRING','description'=>'Optional product name or partial name to filter to'],
       ]]],
      ['name'=>'get_courier_performance',
       'description'=>'Per-courier order counts, delivered/cancelled counts, and revenue.',
       'parameters'=>['type'=>'OBJECT','properties'=>new stdClass()]],
    ],
  ]];
}

function ai_tool_get_business_snapshot(): array {
  $today = date('Y-m-d'); $monthStart = date('Y-m-01');
  $todayRev = (float)val("SELECT COALESCE(SUM(sell_price*qty),0) FROM orders WHERE status='delivered' AND order_date=?", [$today]);
  $monthRev = (float)val("SELECT COALESCE(SUM(sell_price*qty),0) FROM orders WHERE status='delivered' AND order_date>=?", [$monthStart]);
  $monthOrders = (int)val("SELECT COUNT(*) FROM orders WHERE order_date>=?", [$monthStart]);
  $monthExp = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date>=?", [$monthStart]);
  $stkOut = (int)val("SELECT COUNT(*) FROM products WHERE stock<=0");
  $stkLow = (int)val("SELECT COUNT(*) FROM products WHERE stock>0 AND stock<=low_stock");
  $codPending = 0;
  try {
    $codIn = (float)val("SELECT COALESCE(SUM(sell_price*qty),0) FROM orders WHERE status='delivered' AND payment_type='cod'");
    $codReleased = (float)val("SELECT COALESCE(SUM(amount),0) FROM cod_ledger WHERE type='in'");
    $codPending = $codIn - $codReleased;
  } catch (Exception $e) {}
  return [
    'currency'=>CURRENCY,
    'today_delivered_revenue'=>$todayRev,
    'this_month_delivered_revenue'=>$monthRev,
    'this_month_order_count'=>$monthOrders,
    'this_month_expenses'=>$monthExp,
    'this_month_estimated_net'=>$monthRev-$monthExp,
    'products_out_of_stock'=>$stkOut,
    'products_low_stock'=>$stkLow,
    'cod_pending_estimate'=>$codPending,
  ];
}
function ai_tool_search_orders(string $query): array {
  $query = trim($query); if ($query==='') return ['error'=>'Empty search query.'];
  $q = '%'.$query.'%';
  $rows = rows("SELECT o.code,o.order_date,o.customer,o.phone,p.name AS product,o.qty,o.sell_price,o.status,o.payment_type,o.payment_status
                FROM orders o LEFT JOIN products p ON p.id=o.product_id
                WHERE o.code LIKE ? OR o.customer LIKE ? OR o.phone LIKE ?
                ORDER BY o.order_date DESC LIMIT 10", [$q,$q,$q]);
  return ['currency'=>CURRENCY,'match_count'=>count($rows),'orders'=>$rows];
}
function ai_tool_get_sales_summary(string $from, string $to): array {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)) $from = date('Y-m-01');
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to))   $to   = date('Y-m-d');
  $r = row("SELECT COUNT(*) n, SUM(status='delivered') delivered, SUM(status IN ('cancelled','returned')) cancelled,
            SUM(CASE WHEN status='delivered' THEN sell_price*qty ELSE 0 END) revenue,
            SUM(CASE WHEN status='delivered' THEN (sell_price-cost_price)*qty-delivery_charge
                     WHEN status IN ('cancelled','returned') THEN -cancel_charge ELSE 0 END) profit
            FROM orders WHERE order_date BETWEEN ? AND ?", [$from,$to]);
  return ['currency'=>CURRENCY,'from'=>$from,'to'=>$to,'total_orders'=>(int)($r['n']??0),
    'delivered'=>(int)($r['delivered']??0),'cancelled_or_returned'=>(int)($r['cancelled']??0),
    'revenue'=>(float)($r['revenue']??0),'profit'=>(float)($r['profit']??0)];
}
function ai_tool_get_expenses_summary(string $from, string $to, ?string $category=null): array {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)) $from = date('Y-m-01');
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to))   $to   = date('Y-m-d');
  $where = "expense_date BETWEEN ? AND ?"; $params=[$from,$to];
  $category = trim((string)$category); if ($category!=='') { $where .= " AND category=?"; $params[]=$category; }
  $total = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE $where", $params);
  $byCat = rows("SELECT category, SUM(amount) amt FROM expenses WHERE $where GROUP BY category ORDER BY amt DESC", $params);
  return ['currency'=>CURRENCY,'from'=>$from,'to'=>$to,'category_filter'=>($category!==''?$category:null),'total'=>$total,'by_category'=>$byCat];
}
function ai_tool_get_product_stock(?string $name=null): array {
  $name = trim((string)$name);
  if ($name !== '') {
    $rows = rows("SELECT name,sku,stock,low_stock,price,cost FROM products WHERE name LIKE ? ORDER BY name LIMIT 20", ['%'.$name.'%']);
  } else {
    $rows = rows("SELECT name,sku,stock,low_stock,price,cost FROM products ORDER BY stock ASC LIMIT 30");
  }
  return ['currency'=>CURRENCY,'count'=>count($rows),'products'=>$rows];
}
function ai_tool_get_courier_performance(): array {
  $rows = rows("SELECT c.name, COUNT(o.id) n, SUM(o.status='delivered') delivered, SUM(o.status IN ('cancelled','returned')) cancelled,
                SUM(CASE WHEN o.status='delivered' THEN o.sell_price*o.qty ELSE 0 END) revenue
                FROM couriers c LEFT JOIN orders o ON o.courier_id=c.id
                GROUP BY c.id, c.name ORDER BY n DESC");
  return ['currency'=>CURRENCY,'couriers'=>$rows];
}
function ai_run_tool(string $name, array $args): array {
  try {
    switch ($name) {
      case 'get_business_snapshot':   return ai_tool_get_business_snapshot();
      case 'search_orders':           return ai_tool_search_orders((string)($args['query'] ?? ''));
      case 'get_sales_summary':       return ai_tool_get_sales_summary((string)($args['from'] ?? ''), (string)($args['to'] ?? ''));
      case 'get_expenses_summary':    return ai_tool_get_expenses_summary((string)($args['from'] ?? ''), (string)($args['to'] ?? ''), $args['category'] ?? null);
      case 'get_product_stock':       return ai_tool_get_product_stock($args['name'] ?? null);
      case 'get_courier_performance': return ai_tool_get_courier_performance();
      default: return ['error'=>'Unknown tool: '.$name];
    }
  } catch (Exception $e) {
    return ['error'=>'Lookup failed: '.$e->getMessage()];
  }
}

/* Google Gemini — used by the AI Assistant chat page. Accepts a history of
   ['role'=>'user'|'assistant','content'=>string] and returns the reply text.
   When $withTools is true, the model may call the read-only tools above to
   look up live data; results are fed back to it in a loop (capped) until it
   answers with plain text. */
function ai_call_gemini(string $system, array $history, int $maxTokens = 1500, bool $withTools = false): string {
  $key = trim((string)setting('gemini_api_key',''));
  if ($key === '') throw new Exception('no-key');
  $model = trim((string)setting('gemini_model','')); if ($model === '') $model = 'gemini-2.0-flash';
  $contents = [];
  foreach ($history as $m) {
    $role = ($m['role'] ?? '') === 'assistant' ? 'model' : 'user';
    $contents[] = ['role'=>$role, 'parts'=>[['text'=>(string)($m['content'] ?? '')]]];
  }
  $url = 'https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent?key='.urlencode($key);
  $maxRounds = $withTools ? 5 : 1;

  for ($round = 0; $round < $maxRounds; $round++) {
    $payload = [
      'contents' => $contents,
      'systemInstruction' => ['parts'=>[['text'=>$system]]],
      'generationConfig' => ['maxOutputTokens'=>$maxTokens],
    ];
    if ($withTools) $payload['tools'] = ai_gemini_tools();

    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($payload),
      CURLOPT_HTTPHEADER=>['content-type: application/json'], CURLOPT_TIMEOUT=>60]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($res === false) throw new Exception('Gemini request failed: '.$err);
    $d = json_decode($res, true);
    if ($code >= 400) throw new Exception('Gemini error: '.($d['error']['message'] ?? $res));

    $parts = $d['candidates'][0]['content']['parts'] ?? [];
    $text = ''; $funcCalls = [];
    foreach ($parts as $part) {
      if (isset($part['functionCall'])) $funcCalls[] = $part['functionCall'];
      if (isset($part['text'])) $text .= $part['text'];
    }

    if ($funcCalls && $withTools) {
      $contents[] = ['role'=>'model', 'parts'=>$parts];
      $respParts = [];
      foreach ($funcCalls as $fc) {
        $fname = (string)($fc['name'] ?? '');
        $fargs = is_array($fc['args'] ?? null) ? $fc['args'] : [];
        $respParts[] = ['functionResponse'=>['name'=>$fname,'response'=>ai_run_tool($fname,$fargs)]];
      }
      $contents[] = ['role'=>'function', 'parts'=>$respParts];
      continue;
    }

    if (trim($text) !== '') return trim($text);
    $reason = $d['candidates'][0]['finishReason'] ?? ($d['promptFeedback']['blockReason'] ?? 'empty response');
    throw new Exception('Gemini returned no text (reason: '.$reason.').');
  }
  throw new Exception('Took too many steps looking up your data — try asking a simpler or more specific question.');
}

function ai_call(string $system, string $user, int $maxTokens = 400): string {
  $key = trim((string)setting('ai_api_key',''));
  if ($key === '') throw new Exception('no-key');
  $model = trim((string)setting('ai_model','')); if ($model === '') $model = 'claude-3-5-haiku-20241022';
  $payload = json_encode(['model'=>$model,'max_tokens'=>$maxTokens,'system'=>$system,'messages'=>[['role'=>'user','content'=>$user]]]);
  $ch = curl_init('https://api.anthropic.com/v1/messages');
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,
    CURLOPT_HTTPHEADER=>['content-type: application/json','x-api-key: '.$key,'anthropic-version: 2023-06-01'],CURLOPT_TIMEOUT=>40]);
  $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  if ($res === false) throw new Exception('AI request failed: '.$err);
  $d = json_decode($res, true);
  if ($code >= 400) throw new Exception('AI error: '.($d['error']['message'] ?? $res));
  $text = ''; if (!empty($d['content'])) foreach ($d['content'] as $b) if (($b['type']??'')==='text') $text .= $b['text'];
  return trim($text);
}

/* ---------- FREE rule-based generators (no key needed) ---------- */
function ai_local_ncm_reply(string $comment, string $ctx): string {
  $c = strtolower($comment);
  $name = ''; if (preg_match('/customer\s+([^,]+)/i', $ctx, $m)) $name = trim($m[1]);
  $hi = $name ? "Namaste $name, " : "Namaste, ";
  if (preg_match('/(not receiv|no response|unreachable|switch|not pick|no answer|not answer|busy|not reachable|off\b)/', $c))
    return $hi."we tried reaching you about your delivery. Please share a convenient time and we'll have NCM re-attempt delivery. Thank you!";
  if (preg_match('/(reschedul|tomorrow|later|next day|not today|postpone|busy)/', $c))
    return $hi."no problem — we'll ask NCM to deliver at your preferred time. Please confirm the date/time that suits you.";
  if (preg_match('/(address|location|where|wrong|galti)/', $c))
    return $hi."could you please confirm your full delivery address with a nearby landmark? We'll update NCM right away.";
  if (preg_match('/(price|cod|amount|payment|charge|kati|paisa|rupee|rs)/', $c))
    return $hi."please keep the COD amount for your order ready at delivery. Let us know if you have any questions. Thank you!";
  if (preg_match('/(cancel|refus|don\'?t want|do not want|return|chaidaina)/', $c))
    return $hi."we're sorry to hear that. Could you share the reason? We'd love to help so you can still receive your order.";
  if (preg_match('/(thank|received|got it|delivered|good|nice|dhanyabad)/', $c))
    return $hi."thank you so much for your order — we hope you love it! 🙏";
  return $hi."thank you for your message. We'll coordinate with NCM for a smooth delivery. Please let us know if you need anything.";
}
function ai_local_insights(array $p): string {
  $out = [];
  $rr = (float)($p['returnRate'] ?? 0);
  if ($rr >= 15)      $out[] = "Return rate is high at {$rr}% — phone-confirm orders and reply fast to unreachable-customer comments to cut returns.";
  elseif ($rr > 0)    $out[] = "Return rate is {$rr}% — keep confirming orders by phone to keep it low.";
  if ((int)($p['stkOut'] ?? 0) > 0)  $out[] = ((int)$p['stkOut'])." product(s) are OUT of stock — restock now to avoid lost sales.";
  if ((int)($p['stkLow'] ?? 0) > 0)  $out[] = ((int)$p['stkLow'])." product(s) are low on stock — reorder soon.";
  if ((float)($p['codPending'] ?? 0) > 0) $out[] = "Rs.".round($p['codPending'])." COD still to collect — follow up on in-transit orders.";
  $net = (float)($p['net'] ?? 0);
  if ($net < 0)       $out[] = "Net profit is negative (Rs.".round($net).") — review delivery charges, cancellations and expenses.";
  else                $out[] = "Net profit is Rs.".round($net)." after expenses — reinvest in your best sellers.";
  if ((int)($p['returned'] ?? 0) > 0 && $rr < 15) $out[] = ((int)$p['returned'])." returned/cancelled order(s) — each one costs delivery + handling.";
  if (!$out) $out[] = "Everything looks healthy — keep confirming orders and watching stock levels.";
  return "- ".implode("\n- ", $out);
}
function ai_local_ask(string $q, array $p): string {
  $c = strtolower($q);
  if (strpos($c,'return')!==false) return "Your return rate is ".($p['returnRate']??0)."%. To lower it: phone-confirm orders and reply quickly to NCM comments from unreachable customers.";
  if (strpos($c,'profit')!==false || strpos($c,'earn')!==false || strpos($c,'loss')!==false) return "Net profit is Rs.".round($p['net']??0)." (gross Rs.".round($p['profit']??0)." minus expenses Rs.".round($p['expenses']??0)."). Profit already subtracts delivery charges.";
  if (strpos($c,'stock')!==false || strpos($c,'inventory')!==false) return ($p['stkLow']??0)." low and ".($p['stkOut']??0)." out of stock. Total stock value is Rs.".round($p['stkValue']??0).".";
  if (strpos($c,'cod')!==false) return "COD pending is Rs.".round($p['codPending']??0)." to collect; collected so far Rs.".round($p['codCollected']??0).".";
  if (strpos($c,'revenue')!==false || strpos($c,'sale')!==false) return "Delivered revenue is Rs.".round($p['revenue']??0)." from ".($p['delivered']??0)." delivered orders.";
  if (strpos($c,'order')!==false) return "You have ".($p['total']??0)." orders — ".($p['delivered']??0)." delivered, ".($p['returned']??0)." returned/cancelled.";
  return "I can answer about profit, revenue, returns, COD and stock from your data. For open-ended questions, add an AI API key in Settings to unlock the full assistant.";
}

/* ---------- unified helpers (LLM if key set, else free rules) ---------- */
function ai_ncm_reply(string $comment, string $ctx): string {
  if (ai_configured()) { try {
    return ai_call("You are a polite, warm customer-service agent for Luprah Trading coordinating parcel delivery via NCM courier. Write a SHORT reply (1-3 sentences), reassuring the customer and encouraging them to accept delivery or reschedule. No invented facts. Output only the reply.",
      "Order context: $ctx\n\nLatest comment: \"$comment\"\n\nReply only.", 300);
  } catch (Exception $e) {} }
  return ai_local_ncm_reply($comment, $ctx);
}

/* ---------- JSON endpoint ---------- */
if (basename($_SERVER['PHP_SELF']) === 'ai.php' && (isset($_POST['action']) || isset($_GET['action']))) {
  header('Content-Type: application/json');
  /* CSRF: all AI calls are POST with the session token */
  $tok = $_POST['csrf'] ?? '';
  if (!is_string($tok) || !hash_equals(csrf(), $tok)) { echo json_encode(['ok'=>false,'error'=>'Session expired — please reload the page.']); exit; }
  $action = $_POST['action'] ?? $_GET['action'];
  $p = $_POST; // numeric context fields for the free generators
  try {
    if ($action === 'ncm_reply') {
      $comment = trim($_POST['comment'] ?? ''); if ($comment === '') throw new Exception('No comment to reply to.');
      echo json_encode(['ok'=>true,'text'=>ai_ncm_reply($comment, trim($_POST['context'] ?? '')),'free'=>!ai_configured()]); exit;
    }
    if ($action === 'insights') {
      if (ai_configured()) { try {
        $txt = ai_call("You are a sharp retail-operations analyst for a Nepali e-commerce store (Rs.). Produce 3-5 concise, specific, actionable bullet points starting with '- '. Prioritise returns, COD risk, aging deliveries, low/out stock, profit. Reference numbers, no preamble.", "Metrics:\n".($_POST['stats']??''), 500);
        echo json_encode(['ok'=>true,'text'=>$txt,'free'=>false]); exit;
      } catch (Exception $e) {} }
      echo json_encode(['ok'=>true,'text'=>ai_local_insights($p),'free'=>true]); exit;
    }
    if ($action === 'ask') {
      $q = trim($_POST['q'] ?? ''); if ($q === '') throw new Exception('Ask a question first.');
      if (ai_configured()) { try {
        $txt = ai_call("You are the AI assistant in Luprah Trading's ERP. Answer briefly and practically using ONLY the provided context. Currency Rs.", "Context:\n".($_POST['stats']??'')."\n\nQuestion: $q", 500);
        echo json_encode(['ok'=>true,'text'=>$txt,'free'=>false]); exit;
      } catch (Exception $e) {} }
      echo json_encode(['ok'=>true,'text'=>ai_local_ask($q, $p),'free'=>true]); exit;
    }
    if ($action === 'chat') {
      $historyRaw = json_decode($_POST['history'] ?? '[]', true);
      if (!is_array($historyRaw)) $historyRaw = [];
      $clean = [];
      foreach ($historyRaw as $m) {
        $role = (($m['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
        $content = trim((string)($m['content'] ?? ''));
        if ($content !== '') $clean[] = ['role'=>$role,'content'=>$content];
      }
      if (!$clean) throw new Exception('Type a message first.');
      $today = date('Y-m-d');
      $system = "You are a helpful AI assistant built into Luprah Trading's ERP system (currency: ".CURRENCY."). "
        ."Today's date is $today. You have READ-ONLY tools to look up live sales, expenses, stock, orders and "
        ."courier data — use them whenever a question needs real numbers instead of guessing. Call a tool "
        ."yourself rather than asking the user to go check a page. You cannot add, edit or delete anything — "
        ."for changes, tell the user which ERP page to use. Answer clearly and concisely; you can also help "
        ."with general questions, writing, calculations, or ideas unrelated to the business.";
      if (gemini_configured()) {
        $txt = ai_call_gemini($system, $clean, 1536, true);
        echo json_encode(['ok'=>true,'text'=>$txt,'provider'=>'gemini']); exit;
      }
      if (ai_configured()) {
        $convo = ''; foreach ($clean as $m) $convo .= ($m['role']==='assistant'?'Assistant: ':'User: ').$m['content']."\n";
        $txt = ai_call($system, $convo, 1536);
        echo json_encode(['ok'=>true,'text'=>$txt,'provider'=>'anthropic']); exit;
      }
      throw new Exception('No AI API key configured yet. Add your Gemini API key in Settings → AI Assistant.');
    }
    throw new Exception('Unknown AI action.');
  } catch (Exception $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
  }
}
