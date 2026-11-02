<?php
/**
 * টেস্ট কনসোল — WhatsApp ছাড়াই bot-এর সাথে কথা বলে দেখা।
 * https://workspace.example.com/chat.php
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/engine.php';

require_once __DIR__ . '/lib/auth.php';
requireLogin();

$TEST_WA = 'TEST-15555550100';

/* ---------- AJAX ---------- */
if (($_GET['a'] ?? '') === 'send') {
    header('Content-Type: application/json; charset=utf-8');
    $text = trim((string)($_POST['text'] ?? ''));
    if ($text === '') exit(json_encode(['reply' => null]));
    $t0 = microtime(true);
    try {
        $r = handleCustomerMessage($TEST_WA, $text, 'টেস্ট গ্রাহক');
    } catch (Exception $e) {
        $r = '⚠️ ত্রুটি: ' . $e->getMessage();
    }
    $conv = getConv($TEST_WA);
    $tk = all("SELECT id,kind,status,question FROM tickets WHERE conv_id=? ORDER BY id DESC LIMIT 5", [$conv['id']]);
    $marks = bisMark();
    $parts = [];
    foreach ($marks as $m) $parts[] = $m[0] . ' ' . $m[1] . 'ms';
    exit(json_encode([
        'reply'   => $r,
        'ms'      => round((microtime(true) - $t0) * 1000),
        'detail'  => implode(' · ', $parts) . ' · SQL ' . bisQueryCount(),
        'tickets' => $tk,
    ], JSON_UNESCAPED_UNICODE));
}

if (($_GET['a'] ?? '') === 'reset') {
    $conv = one("SELECT id FROM conversations WHERE wa_id=?", [$TEST_WA]);
    if ($conv) {
        q("DELETE FROM messages WHERE conv_id=?", [$conv['id']]);
        q("DELETE FROM tickets WHERE conv_id=?", [$conv['id']]);
        q("UPDATE conversations SET last_product=0,last_variation=0,handoff=0 WHERE id=?", [$conv['id']]);
    }
    header('Location: chat.php'); exit;
}

$conv = getConv($TEST_WA, 'টেস্ট গ্রাহক');
$hist = all("SELECT direction, body, created_at FROM messages WHERE conv_id=? ORDER BY id ASC LIMIT 200", [$conv['id']]);
$stats = [
    'products'  => (int)one("SELECT COUNT(*) c FROM products")['c'],
    'knowledge' => (int)one("SELECT COUNT(*) c FROM knowledge")['c'],
    'open'      => (int)one("SELECT COUNT(*) c FROM tickets WHERE status IN ('queued','sent_owner')")['c'],
];
?>
<!doctype html><html lang="bn"><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bot টেস্ট কনসোল</title>
<style>
 *{box-sizing:border-box}
 body{font:15px/1.6 system-ui,'Noto Sans Bengali',sans-serif;margin:0;background:#e9e2db}
 .wrap{max-width:720px;margin:0 auto;height:100vh;display:flex;flex-direction:column}
 header{background:#075E54;color:#fff;padding:12px 16px;display:flex;justify-content:space-between;align-items:center}
 header b{font-size:16px}
 header small{opacity:.85}
 header a{color:#fff;font-size:13px}
 #log{flex:1;overflow-y:auto;padding:16px;background:#efe7de}
 .m{max-width:78%;padding:9px 13px;border-radius:9px;margin:0 0 9px;white-space:pre-wrap;word-wrap:break-word;
    box-shadow:0 1px 1px rgba(0,0,0,.08)}
 .in{background:#fff;margin-right:auto}
 .out{background:#dcf8c6;margin-left:auto}
 .meta{font-size:11px;color:#888;text-align:center;margin:6px 0}
 form{display:flex;gap:8px;padding:10px;background:#f4f0ec;border-top:1px solid #ddd}
 input[type=text]{flex:1;padding:11px 14px;border:1px solid #ccc;border-radius:22px;font-size:15px}
 button{padding:11px 20px;border:0;border-radius:22px;background:#075E54;color:#fff;font-size:15px;cursor:pointer}
 .bar{background:#fff;padding:6px 16px;font-size:12px;color:#555;border-bottom:1px solid #e2e2e2}
</style>
<div class="wrap">
 <header>
   <div><b>Demo Commerce Bot</b><br><small>টেস্ট কনসোল — WhatsApp সংযোগ ছাড়া</small></div>
   <a href="?a=reset" onclick="return confirm('এই কথোপকথন মুছে ফেলব?')">নতুন করে শুরু</a>
 </header>
 <div class="bar">
   পণ্য: <b><?= $stats['products'] ?></b> ·
   জানা তথ্য: <b><?= $stats['knowledge'] ?></b> ·
   খোলা টিকিট: <b id="tk"><?= $stats['open'] ?></b> ·
   <?= cfg('rules.dry_run') ? 'DRY RUN চালু' : '<span style="color:#b91c1c">সরাসরি লিখছে</span>' ?>
 </div>
 <div id="log">
 <?php if (!$hist): ?>
   <div class="meta">গ্রাহক সেজে কিছু লিখে দেখুন — যেমন “এই পর্দাটা আছে?”</div>
 <?php endif; ?>
 <?php foreach ($hist as $m): ?>
   <div class="m <?= $m['direction'] === 'in' ? 'in' : 'out' ?>"><?= htmlspecialchars($m['body']) ?></div>
 <?php endforeach; ?>
 </div>
 <form id="f" autocomplete="off">
   <input type="text" id="t" placeholder="গ্রাহক হিসেবে লিখুন…" autofocus>
   <button>পাঠান</button>
 </form>
</div>
<script>
const log = document.getElementById('log');
const f = document.getElementById('f');
const t = document.getElementById('t');
function add(cls, txt){
  const d = document.createElement('div');
  d.className = 'm ' + cls; d.textContent = txt;
  log.appendChild(d); log.scrollTop = log.scrollHeight;
  return d;
}
log.scrollTop = log.scrollHeight;
f.onsubmit = async e => {
  e.preventDefault();
  const v = t.value.trim(); if(!v) return;
  t.value = ''; add('in', v);
  const wait = add('out', '…');
  try{
    const fd = new FormData(); fd.append('text', v);
    const r = await fetch('chat.php?a=send', {method:'POST', body:fd});
    const j = await r.json();
    wait.textContent = j.reply || '(bot চুপ — handoff চালু)';
    const tk = document.getElementById('tk');
    if (j.tickets) tk.textContent = j.tickets.filter(x=>x.status!=='closed'&&x.status!=='answered').length;
    const info = document.createElement('div');
    info.className='meta'; info.textContent = j.ms + ' ms — ' + (j.detail || '');
    log.appendChild(info);
  }catch(err){ wait.textContent = '⚠️ ' + err; }
  log.scrollTop = log.scrollHeight;
};
</script>
</html>
