<?php
require_once __DIR__.'/functions.php'; require_once __DIR__.'/ai.php'; require_login(); require_page_access();
$PAGE_TITLE = 'AI Assistant';
require __DIR__.'/includes/header.php';
$hasGemini = gemini_configured();
$hasAnthropic = ai_configured();
$hasKey = $hasGemini || $hasAnthropic;
$u = current_user();
?>
<div class="page-head">
  <div><h1>🤖 AI Assistant</h1><p>Ask anything — it can look up your live sales, expenses, stock and courier data (read-only) to answer, plus general questions, writing and ideas.</p></div>
  <button class="btn" onclick="clearChat()">🗑 Clear Chat</button>
</div>

<?php if (!$hasKey): ?>
<div class="flash" style="background:var(--amber-bg,#fef3c7);color:#8a5a00">
  ⚠️ No AI key configured yet. Add your <b>Google Gemini API key</b> in
  <a href="settings.php#ai-assistant" style="color:#8a5a00;font-weight:800;text-decoration:underline">Settings → AI Assistant</a>
  to start chatting. It's free to get a key at <span style="font-weight:700">aistudio.google.com/apikey</span>.
</div>
<?php else: ?>
<div class="flash" style="background:var(--green-bg,#dcfce7);color:#166534">
  ✅ Connected — running on <b><?= $hasGemini ? 'Google Gemini' : 'Anthropic Claude' ?></b><?= $hasGemini ? ' with read-only access to your sales, expenses, stock and courier data' : '' ?>.
</div>
<?php endif; ?>

<div class="card" id="chatCard">
  <div id="chatMessages" class="chat-msgs"></div>
  <div class="chat-inputbar">
    <textarea id="chatInput" placeholder="<?= $hasKey ? 'Ask me anything… (Enter to send, Shift+Enter for a new line)' : 'Add an AI key in Settings first…' ?>" rows="1" <?= $hasKey?'':'disabled' ?>></textarea>
    <button class="btn btn-primary" id="chatSendBtn" onclick="sendChat()" <?= $hasKey?'':'disabled' ?>>➤ Send</button>
  </div>
</div>

<style>
#chatCard{display:flex;flex-direction:column;height:calc(100vh - 260px);min-height:420px;overflow:hidden;margin-top:14px}
.chat-msgs{flex:1;overflow-y:auto;padding:20px;display:flex;flex-direction:column;gap:14px}
.cmsg{max-width:78%;padding:11px 15px;border-radius:16px;font-size:14px;line-height:1.55;white-space:pre-wrap;word-wrap:break-word}
.cmsg.user{align-self:flex-end;background:var(--brand);color:#fff;border-bottom-right-radius:4px}
.cmsg.bot{align-self:flex-start;background:var(--surface-2);color:var(--ink);border-bottom-left-radius:4px}
.cmsg.err{align-self:flex-start;background:var(--red-bg,#fee2e2);color:#991b1b;border-bottom-left-radius:4px}
.cmsg code{background:rgba(0,0,0,.12);padding:1px 5px;border-radius:5px;font-size:12.5px}
.cmsg.user code{background:rgba(255,255,255,.22)}
.chat-welcome{align-self:center;text-align:center;color:var(--muted);font-size:13px;padding:30px 10px}
.chat-typing{align-self:flex-start;background:var(--surface-2);border-radius:16px;border-bottom-left-radius:4px;padding:12px 16px;display:flex;gap:4px}
.chat-typing i{width:6px;height:6px;border-radius:50%;background:var(--muted);display:inline-block;animation:ctb 1.1s infinite}
.chat-typing i:nth-child(2){animation-delay:.15s}.chat-typing i:nth-child(3){animation-delay:.3s}
@keyframes ctb{0%,60%,100%{opacity:.3;transform:translateY(0)}30%{opacity:1;transform:translateY(-3px)}}
.chat-inputbar{display:flex;gap:10px;align-items:flex-end;padding:14px 16px;border-top:1px solid var(--border)}
.chat-inputbar textarea{flex:1;resize:none;max-height:140px;border:1px solid var(--border);border-radius:12px;padding:10px 14px;font:inherit;font-size:13.5px;background:var(--surface);color:var(--ink)}
.chat-inputbar button{flex:none}
@media(max-width:640px){ .cmsg{max-width:90%} #chatCard{height:calc(100vh - 300px)} }
</style>

<script>
var CSRF = window.ERP_CSRF;
var HAS_KEY = <?= $hasKey ? 'true' : 'false' ?>;
var STORE_KEY = 'ai_assistant_history_v1';
var chatHistory = [];
try { chatHistory = JSON.parse(localStorage.getItem(STORE_KEY) || '[]'); } catch(e) { chatHistory = []; }

function escapeHtml(s){ var d=document.createElement('div'); d.textContent=String(s==null?'':s); return d.innerHTML; }
function renderText(t){
  var h = escapeHtml(t);
  h = h.replace(/`([^`]+)`/g, '<code>$1</code>');
  h = h.replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>');
  return h;
}
function scrollBottom(){ var m=document.getElementById('chatMessages'); m.scrollTop = m.scrollHeight; }

function renderAll(){
  var box = document.getElementById('chatMessages');
  box.innerHTML='';
  if (!chatHistory.length) {
    box.innerHTML = '<div class="chat-welcome">👋 Hi <?= e(explode(" ",$u['name'])[0] ?? '') ?>! Ask me anything to get started.</div>';
    return;
  }
  chatHistory.forEach(function(m){
    var d = document.createElement('div');
    d.className = 'cmsg ' + (m.role==='user' ? 'user' : (m.error ? 'err' : 'bot'));
    d.innerHTML = renderText(m.content);
    box.appendChild(d);
  });
  scrollBottom();
}
function save(){ try { localStorage.setItem(STORE_KEY, JSON.stringify(chatHistory)); } catch(e) {} }

function clearChat(){
  if (chatHistory.length && !confirm('Clear this conversation? This cannot be undone.')) return;
  chatHistory = []; save(); renderAll();
}

function autosize(){
  var t = document.getElementById('chatInput');
  t.style.height='auto'; t.style.height=Math.min(140, t.scrollHeight)+'px';
}

function sendChat(){
  if (!HAS_KEY) return;
  var input = document.getElementById('chatInput');
  var text = input.value.trim();
  if (!text) return;
  chatHistory.push({role:'user', content:text});
  save(); renderAll();
  input.value=''; autosize();

  var box = document.getElementById('chatMessages');
  var typing = document.createElement('div');
  typing.className = 'chat-typing'; typing.id = 'chatTyping';
  typing.innerHTML = '<i></i><i></i><i></i>';
  box.appendChild(typing); scrollBottom();

  var btn = document.getElementById('chatSendBtn'); btn.disabled = true;
  var body = 'csrf='+encodeURIComponent(CSRF)+'&action=chat&history='+encodeURIComponent(JSON.stringify(chatHistory));
  fetch('ai.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, credentials:'same-origin', body:body})
    .then(function(r){ return r.json(); })
    .then(function(d){
      var t = document.getElementById('chatTyping'); if (t) t.remove();
      if (d.ok) chatHistory.push({role:'assistant', content:d.text});
      else chatHistory.push({role:'assistant', content:'⚠️ '+(d.error||'Something went wrong.'), error:true});
      save(); renderAll();
    })
    .catch(function(){
      var t = document.getElementById('chatTyping'); if (t) t.remove();
      chatHistory.push({role:'assistant', content:'⚠️ Network error — please try again.', error:true});
      save(); renderAll();
    })
    .finally(function(){ btn.disabled = false; input.focus(); });
}

document.getElementById('chatInput') && document.getElementById('chatInput').addEventListener('keydown', function(ev){
  if (ev.key==='Enter' && !ev.shiftKey) { ev.preventDefault(); sendChat(); }
});
document.getElementById('chatInput') && document.getElementById('chatInput').addEventListener('input', autosize);

renderAll();
</script>

<?php require __DIR__.'/includes/footer.php'; ?>
