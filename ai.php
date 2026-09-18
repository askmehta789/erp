<?php
require_once __DIR__.'/functions.php'; require_login();

/* ============================================================
   AI assistant — works FREE with no API key (built-in rules).
   If an Anthropic API key is set in Settings, it upgrades to
   the smarter LLM automatically.
   ============================================================ */
function ai_configured(): bool { return trim((string)setting('ai_api_key','')) !== ''; }

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
    throw new Exception('Unknown AI action.');
  } catch (Exception $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
  }
}
