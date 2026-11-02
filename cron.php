<?php
/**
 * প্রতি মিনিটে চলবে।
 *
 *
 * ব্রাউজার থেকেও চালানো যায়: /cron.php?key=<console password>
 */

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/engine.php';
require_once __DIR__ . '/lib/wa.php';
require_once __DIR__ . '/lib/owner_flow.php';
require_once __DIR__ . '/lib/media.php';
require_once __DIR__ . '/lib/customers.php';

$cli = (php_sapi_name() === 'cli');
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (($_GET['key'] ?? '') !== cfg('console.password') || cfg('console.password') === '') {
        http_response_code(403); exit('না।');
    }
}
@set_time_limit(110);

$log = [];
function say($s) { global $log; $log[] = $s; }

/* Owner আগে সিদ্ধান্ত দিলে নির্ধারিত ১১টার সময় customer-কে পাঠানো */
$queueReady=stockEnsureReplyQueue();
if($queueReady){
    $due=all("SELECT q.*,t.conv_id,t.product_id,t.variation_id FROM stock_reply_queue q JOIN tickets t ON t.id=q.ticket_id WHERE q.deliver_at<=? AND t.status IN ('queued','sent_owner') ORDER BY q.deliver_at ASC LIMIT 20",[nowStr()]);
    foreach($due as $scheduled){
        $p=one('SELECT * FROM products WHERE id=? AND variation_id=?',[$scheduled['product_id'],$scheduled['variation_id']]);
        $label=$p?catalogLabel($p):'পণ্যটি';$note=ownerStockCustomerNote($label,$scheduled['state']);
        if(!customerSay($scheduled['conv_id'],$note))continue;
        q("UPDATE tickets SET status='answered',answer=?,answered_at=? WHERE id=?",[$scheduled['state'],nowStr(),$scheduled['ticket_id']]);
        q('DELETE FROM stock_reply_queue WHERE ticket_id=?',[$scheduled['ticket_id']]);say("✅ #{$scheduled['ticket_id']} নির্ধারিত stock reply পাঠানো হলো");
    }
    q("DELETE q FROM stock_reply_queue q LEFT JOIN tickets t ON t.id=q.ticket_id WHERE t.id IS NULL OR t.status IN ('answered','closed')");
}

/* ==========================================================
   ১. অপেক্ষায় থাকা টিকিট মালিকের কাছে — শুধু অফিস টাইমে
   ========================================================== */
try{requestReconcileQueued();}catch(Throwable $e){audit('ticket_reconcile_error',mb_substr($e->getMessage(),0,200));}
$queued = all("SELECT * FROM tickets WHERE status='queued' ORDER BY id ASC LIMIT 10");
foreach ($queued as $t) {
    $allowed=$t['kind']==='stock'?stockOwnerContactOpen():isOfficeHours();
    if($allowed && ownerNotify($t))say("🔔 #{$t['id']} মালিকের কাছে পাঠানো হলো");
}
if(!$queued)say('অপেক্ষায় কিছু নেই');

/* ==========================================================
   Customer marketing permission — each customer only once.
   Send after 60 minutes of inactivity, while the 24h window is open.
   ========================================================== */
try{
    customerDbEnsure();$consentSent=0;
    foreach(customerConsentRequestDue(10,60) as $customer){
        if(customerSendConsentRequest($customer))$consentSent++;
    }
    if($consentSent)say("✅ {$consentSent} customer-কে একবারের consent request পাঠানো হলো");
}catch(Throwable $e){audit('marketing_consent_cron_error',mb_substr($e->getMessage(),0,300));say('⚠️ Consent request check failed');}

try{
    $campaign=customerProcessCampaignQueue(10);
    if(array_sum($campaign)>0)say('📣 Campaign: '.$campaign['accepted'].' accepted, '.$campaign['skipped'].' skipped, '.$campaign['failed'].' failed');
}catch(Throwable $e){audit('marketing_campaign_cron_error',mb_substr($e->getMessage(),0,300));say('⚠️ Marketing campaign queue failed');}

/* ==========================================================
   ২. মালিক উত্তর দিচ্ছেন না — একবার মনে করিয়ে দেওয়া
   ========================================================== */
if (isOfficeHours() || stockOwnerContactOpen()) {
    $wait  = (int)cfg('rules.owner_reply_wait', 30);
    $limit = (clone now())->modify("-$wait minute")->format('Y-m-d H:i:s');

    $queueFilter=$queueReady?' AND NOT EXISTS (SELECT 1 FROM stock_reply_queue q WHERE q.ticket_id=t.id)':'';
    $stuck = all("SELECT * FROM tickets t WHERE status='sent_owner'
                  AND nudged_at IS NULL AND sent_at IS NOT NULL AND sent_at < ?"
                  .$queueFilter." ORDER BY id ASC LIMIT 5", [$limit]);

    foreach ($stuck as $t) {
        if(($t['kind']==='stock' && !stockOwnerContactOpen()) || ($t['kind']!=='stock' && !isOfficeHours()))continue;
        $p = $t['product_id']
            ? one("SELECT * FROM products WHERE id=? AND variation_id=?", [$t['product_id'], $t['variation_id']])
            : null;
        $reminderId=ownerSendOperational('Ticket #'.$t['id'].' - reminder',
            "⏳ #{$t['id']} এখনো ঝুলে আছে ({$wait} মিনিট)\n"
          . ($p ? catalogLabel($p) . "\n" : '')
          . '“' . mb_substr($t['question'], 0, 160) . '”');
        if(!$reminderId)continue;
        q("UPDATE tickets SET nudged_at=? WHERE id=?", [nowStr(), $t['id']]);
        say("⏳ #{$t['id']} মনে করিয়ে দেওয়া হলো");

        /* একই কথোপকথনে পরে উত্তর চলে গেলে পুরোনো টিকিটের reminder আর পাঠাব না। */
        $newer=one("SELECT id FROM messages WHERE conv_id=? AND direction='out' AND created_at>? LIMIT 1",[$t['conv_id'],$t['sent_at']]);
        if(!$newer)customerSay($t['conv_id'], 'একটু সময় লাগছে, খোঁজ নিচ্ছি — জানতে পারলেই সাথে সাথে জানাব। 🙏');
    }
}

/* ==========================================================
   ৩. অফিস শেষে দিনের হিসাব (একবার)
   ========================================================== */
$digestTracking=one("SELECT v FROM settings WHERE k='digest_tracking_v34_initialized'");
if(!$digestTracking){
    $oldDigest=one("SELECT v FROM settings WHERE k='digest_day'");$oldMessage=one("SELECT v FROM settings WHERE k='digest_message_id'");
    if($oldDigest && $oldDigest['v']===now()->format('Y-m-d') && !$oldMessage)q("DELETE FROM settings WHERE k='digest_day'");
    q("INSERT INTO settings(k,v) VALUES('digest_tracking_v34_initialized',?) ON DUPLICATE KEY UPDATE v=VALUES(v)",[nowStr()]);
}
$endAt=now();list($endH,$endM)=array_map('intval',explode(':',cfg('rules.office_end','18:00')));$endAt->setTime($endH,$endM,0);
if (now() >= $endAt) {
    $today = now()->format('Y-m-d');
    $seen  = one("SELECT v FROM settings WHERE k='digest_day'");
    if (!$seen || $seen['v'] !== $today) {
        $conv = (int)one("SELECT COUNT(DISTINCT conv_id) c FROM messages
                          WHERE direction='in' AND DATE(created_at)=?", [$today])['c'];
        $ans  = (int)one("SELECT COUNT(*) c FROM tickets
                          WHERE status='answered' AND DATE(answered_at)=?", [$today])['c'];
        $open = (int)one("SELECT COUNT(*) c FROM tickets WHERE status IN ('queued','sent_owner')")['c'];
        $new  = (int)one("SELECT COUNT(*) c FROM knowledge WHERE DATE(created_at)=?", [$today])['c'];
        $oos  = (int)one("SELECT COUNT(*) c FROM stock_state WHERE state='out_of_stock'")['c'];

        $digestId=ownerSendOperational('Daily report - '.$today,
            "📋 আজকের হিসাব\n\n"
          . "কথোপকথন: {$conv}\n"
          . "মিটেছে: {$ans}\n"
          . "এখনো খোলা: {$open}\n"
          . "নতুন শেখা: {$new}\n"
          . "স্টক-আউট পণ্য: {$oos}");

        if($digestId){
            q("INSERT INTO settings (k,v) VALUES ('digest_day',?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [$today]);
            q("INSERT INTO settings (k,v) VALUES ('digest_message_id',?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [$digestId]);
            q("INSERT INTO settings (k,v) VALUES ('digest_status',?) ON DUPLICATE KEY UPDATE v=VALUES(v)", ['accepted · '.nowStr()]);
            audit('owner_digest_sent',$today);say('📋 দিনের হিসাব পাঠানো হলো');
        } else { audit('owner_digest_failed',$today);say('⚠️ দিনের হিসাব WhatsApp গ্রহণ করেনি; পরের cron-এ আবার চেষ্টা হবে'); }
    }
}

// Availability is rechecked when an expired product is requested by a customer.
// No weekly restock prompts for valid temporary or permanent stock-out decisions.

/* ==========================================================
   শেষ
   ========================================================== */
q("INSERT INTO settings (k,v) VALUES ('cron_last',?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [nowStr()]);
mediaCleanup(30);

if (!$cli) echo implode("\n", $log) . "\n";
