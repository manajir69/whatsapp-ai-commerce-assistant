<?php
/**
 * মালিকের সাথে WhatsApp-এ কথোপকথন — 🔔 পাঠানো ও উত্তর বোঝা।
 * owner.php (ওয়েব পাতা) আর এখানকার যুক্তি একই কাজ করে।
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/wa.php';
$channelFile=__DIR__.'/channel.php';if(file_exists($channelFile))require_once $channelFile;
$notifyFile=__DIR__.'/owner_notify.php';if(file_exists($notifyFile))require_once $notifyFile;

/* ==========================================================
   টিকিট মালিকের কাছে পাঠানো
   ========================================================== */
function ownerNotify($ticket) {
    $t = is_array($ticket) ? $ticket : one("SELECT * FROM tickets WHERE id=?", [(int)$ticket]);
    if (!$t || $t['status'] !== 'queued') return false;

    $to   = function_exists('channelOwnerWa')?channelOwnerWa():cfg('owner.wa');
    $conv = one("SELECT * FROM conversations WHERE id=?", [$t['conv_id']]);
    $p    = $t['product_id']
        ? one("SELECT * FROM products WHERE id=? AND variation_id=?", [$t['product_id'], $t['variation_id']])
        : null;

    $who  = $conv ? ($conv['name'] ?: $conv['wa_id']) : '';
    $head = "🔔 #{$t['id']} · " . $who;
    list($imageId,$cleanQuestion)=function_exists('mediaExtractMarker') ? mediaExtractMarker($t['question']) : ['', $t['question']];

    if(function_exists('ownerNotificationWindowOpen') && !ownerNotificationWindowOpen()){
        $retryKey='owner_ticket_attempt_'.(int)$t['id'];
        $attempt=one('SELECT v FROM settings WHERE k=?',[$retryKey]);
        if($attempt && now()->getTimestamp()-(int)$attempt['v']<300)return false;
        q('INSERT INTO settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)',[$retryKey,(string)now()->getTimestamp()]);
        $body=$head.' · '.$t['kind'].' · '.nowStr().' · '.($p?catalogLabel($p).' · ':'').mb_substr($cleanQuestion,0,350);
        if($t['kind']==='stock')$body.=' · Reply to this message: আছে / নেই / স্থায়ীভাবে শেষ';
        $mid=waSendOwnerTemplate($to,'Ticket #'.$t['id'].' - '.$t['kind'],$body);
        if(!$mid){audit('owner_notify_failed','#'.$t['id'].' template not accepted; ticket remains queued');return false;}
        q("UPDATE tickets SET status='sent_owner', sent_at=?, owner_msg_id=? WHERE id=?",[nowStr(),(string)$mid,$t['id']]);
        audit('owner_notify','#'.$t['id'].' '.$t['kind'].' template');
        return true;
    }

    if ($t['kind'] === 'stock' && $p) {
        $body = $head . "\n\n" . catalogLabel($p)
              . ($p['sku'] ? "\nকোড: {$p['sku']}" : '')
              . "\n\n“" . mb_substr($cleanQuestion, 0, 300) . "”"
              . "\n\nআছে: ৩ দিন। নেই: ৩ মাস। স্থায়ীভাবে বন্ধ হলে নিচের বাটন চাপুন।";
        $mid = waSendButtons($to, $body, [
            ['id' => "tk:{$t['id']}:in",   'title' => '✅ আছে'],
            ['id' => "tk:{$t['id']}:out",  'title' => '❌ নেই'],
            ['id' => "tk:{$t['id']}:permanent",   'title' => 'স্থায়ীভাবে শেষ'],
        ]);
    } elseif ($t['kind'] === 'handoff') {
        $body = $head . " · মানুষ চাইছে\n\n“" . mb_substr($cleanQuestion, 0, 400) . "”"
              . ($p ? "\n\n(" . catalogLabel($p) . ")" : '');
        $mid  = waSendButtons($to, $body, [
            ['id' => "tk:{$t['id']}:me",    'title' => '🤝 আমি দেখছি'],
            ['id' => "tk:{$t['id']}:close", 'title' => 'বাদ'],
        ]);
    } else {
        $body = $head . " · তথ্য চাইছে\n\n“" . mb_substr($cleanQuestion, 0, 400) . "”"
              . ($p ? "\n\n(" . catalogLabel($p) . ")" : '')
              . "\n\nউত্তরটা লিখে পাঠান — সংক্ষেপে লিখলেই হবে, আমি গুছিয়ে বলব।";
        $mid  = $imageId ? waSendImageId($to,$imageId,$body) : waSendText($to, $body);
    }

    if (!$mid) { audit('owner_notify_failed','#'.$t['id'].' '.$t['kind']); return false; }
    q("UPDATE tickets SET status='sent_owner', sent_at=?, owner_msg_id=? WHERE id=?",
      [nowStr(), (string)$mid, $t['id']]);
    audit('owner_notify', '#' . $t['id'] . ' ' . $t['kind']);
    return true;
}

/* ==========================================================
   মালিকের উত্তর প্রয়োগ করা
   ========================================================== */

/** স্টকের সিদ্ধান্ত — গ্রাহকদের জানানো ও Woo আপডেট */
function ownerStockCustomerNote($label,$state) {
    if(function_exists('trainingTemplate')){
        $key=$state==='in_stock'?'stock_in':($state==='permanent_out'?'stock_permanent':'stock_out');
        return trainingTemplate($key,['product'=>$label]);
    }
    return ($state === 'in_stock') ? "{$label} পণ্যটি স্টকে আছে।"
        : ($state==='permanent_out' ? "{$label} পণ্যটি স্থায়ীভাবে বন্ধ। ভবিষ্যতেও আর স্টক হবে না।" : "দুঃখিত, {$label} পণ্যটি বর্তমানে স্টকে নেই।");
}
function ownerApplyStock($t, $state) {
    $p = one("SELECT * FROM products WHERE id=? AND variation_id=?", [$t['product_id'], $t['variation_id']]);
    $label = $p ? catalogLabel($p) : 'পণ্যটি';
    if(function_exists('stockApplyDecision')){$decision=stockApplyDecision($t['product_id'],$t['variation_id'],$state,'owner');$woo=$decision['website']['message']??$decision['local'];}
    else $woo=setStock($t['product_id'], $t['variation_id'], $state, 'owner');

    $note = ownerStockCustomerNote($label,$state);

    $sent = 0;$scheduled=0;$deliverAt=stockPromisedAt();$hold=$deliverAt->getTimestamp()>now()->getTimestamp()+60;
    $rows = all("SELECT * FROM tickets WHERE product_id=? AND variation_id=? AND kind='stock'
                 AND status IN ('queued','sent_owner')", [$t['product_id'], $t['variation_id']]);
    foreach ($rows as $o) {
        if(stockQueuedReply($o['id']))continue;
        if($hold){
            if(stockQueueReply($o['id'],$state,$deliverAt))$scheduled++;
            // Never send an off-hours stock decision early when the queue table is unavailable.
            continue;
        }
        if (!customerSay($o['conv_id'], $note)) continue;
        q("UPDATE tickets SET status='answered', answer=?, answered_at=? WHERE id=?",
          [$state, nowStr(), $o['id']]);
        $sent++;
    }
    return [$label, $woo, $sent, $scheduled];
}

/** তথ্যের উত্তর — শেখা ও পাঠানো */
function ownerApplyInfo($t, $raw, $scope = null) {
    $p = $t['product_id']
        ? one("SELECT * FROM products WHERE id=? AND variation_id=?", [$t['product_id'], $t['variation_id']])
        : null;

    list($unusedMediaId,$cleanQuestion)=function_exists('mediaExtractMarker') ? mediaExtractMarker($t['question']) : ['', $t['question']];
    $scope = $scope ?: ($p ? 'product' : 'global');
    if (!in_array($scope, ['product', 'category', 'global'], true)) throw new Exception('তথ্যের ধরন সঠিক নয়।');
    if (!$p) $scope = 'global';
    $ref = $scope === 'product' ? (string)$p['id'] : ($scope === 'category' ? trim(explode(',', $p['categories'])[0]) : '');
    if ($scope==='product' && !empty($p['variation_id'])) $ref.=':'.$p['variation_id'];
    knowledgeAdd($cleanQuestion, $raw, $scope, $ref, 'owner');

    $nice = ''; $sent = 0;
    $rows = all("SELECT * FROM tickets WHERE kind='info' AND question=?
                 AND product_id=? AND variation_id=? AND status IN ('queued','sent_owner')", [$t['question'], $t['product_id'], $t['variation_id']]);
    foreach ($rows as $o) {
        $customerReply=composeAnswer($cleanQuestion,$p,$raw,$o['conv_id']);
        if (!is_string($customerReply) || trim($customerReply)==='') $customerReply=ownerAnswerFallback($raw);
        if (!customerSay($o['conv_id'],$customerReply,false)) continue;
        if($nice==='')$nice=$customerReply;
        q("UPDATE tickets SET status='answered', answer=?, answered_at=? WHERE id=?",
          [$raw, nowStr(), $o['id']]);
        $sent++;
    }
    if(strpos((string)$t['question'],'[[WA_MEDIA:')!==false){
        q("UPDATE tickets SET status='closed',answered_at=? WHERE conv_id=? AND kind='info' AND id<>? AND status IN ('queued','sent_owner') AND question LIKE '[[WA_MEDIA:%'",[nowStr(),$t['conv_id'],$t['id']]);
    }
    return [$nice!==''?$nice:ownerAnswerFallback($raw), $sent];
}

/** Dashboard conversation reply: polish it, optionally learn it, then send. */
function ownerDirectReply($convId,$raw,$learn=true,$replyMessageId=0) {
    $conv=one('SELECT * FROM conversations WHERE id=?',[(int)$convId]);
    if(!$conv)return ['',0,false];
    $target=$replyMessageId?one("SELECT id,body,wa_msg_id FROM messages WHERE id=? AND conv_id=? AND direction='in'",[(int)$replyMessageId,(int)$convId]):null;
    $latest=$target?:one("SELECT id,body,wa_msg_id FROM messages WHERE conv_id=? AND direction='in' ORDER BY id DESC LIMIT 1",[(int)$convId]);
    $question=trim((string)($latest['body']??''));
    if($learn){
        $ticket=one("SELECT * FROM tickets WHERE conv_id=? AND kind='info' AND status IN ('queued','sent_owner') ORDER BY id DESC LIMIT 1",[(int)$convId]);
        if($ticket && !$target){list($nice,$sent)=ownerApplyInfo($ticket,$raw);return [$nice,$sent,true];}
    }
    $nice=composeAnswer($question!==''?$question:'Customer message',null,$raw,(int)$convId);
    if(!is_string($nice) || trim($nice)==='')$nice=ownerAnswerFallback($raw);
    if(!customerSay((int)$convId,$nice,false,(string)($target['wa_msg_id']??''),$target?(string)$target['body']:''))return [$nice,0,false];
    $learned=false;
    if($learn && $question!==''){knowledgeAdd($question,$raw,'global','','owner');$learned=true;}
    return [$nice,1,$learned];
}

/** Dashboard conversation reply: send the owner's wording unchanged and always learn it. */
function ownerDirectExactReply($convId,$raw,$replyMessageId=0) {
    $conv=one('SELECT * FROM conversations WHERE id=?',[(int)$convId]);
    if(!$conv)return ['',0,false];
    $exact=trim((string)$raw);if($exact==='')return ['',0,false];
    $target=$replyMessageId?one("SELECT id,body,wa_msg_id FROM messages WHERE id=? AND conv_id=? AND direction='in'",[(int)$replyMessageId,(int)$convId]):null;
    $latest=$target?:one("SELECT id,body,wa_msg_id FROM messages WHERE conv_id=? AND direction='in' ORDER BY id DESC LIMIT 1",[(int)$convId]);
    $question=trim((string)($latest['body']??''));
    if(!customerSay((int)$convId,$exact,false,(string)($target['wa_msg_id']??''),$target?(string)$target['body']:''))return [$exact,0,false];
    $learned=false;
    if($question!==''){
        $ticket=!$target?one("SELECT * FROM tickets WHERE conv_id=? AND kind='info' AND status IN ('queued','sent_owner') ORDER BY id DESC LIMIT 1",[(int)$convId]):null;
        $scope='global';$ref='';$learningQuestion=$question;
        if($ticket){
            if(function_exists('mediaExtractMarker')){list($unusedMedia,$learningQuestion)=mediaExtractMarker((string)$ticket['question']);}
            else $learningQuestion=(string)$ticket['question'];
            if(!empty($ticket['product_id'])){$scope='product';$ref=(string)$ticket['product_id'];if(!empty($ticket['variation_id']))$ref.=':'.$ticket['variation_id'];}
        }
        knowledgeAdd(trim($learningQuestion),$exact,$scope,$ref,'owner');$learned=true;
        if($ticket)q("UPDATE tickets SET status='answered',answer=?,answered_at=? WHERE id=?",[$exact,nowStr(),$ticket['id']]);
    }
    audit('owner_direct_exact','#'.(int)$convId);return [$exact,1,$learned];
}

/** গ্রাহককে বার্তা — WhatsApp থাকলে পাঠায়, নাহলে শুধু নথিতে */
function customerSay($convId, $text, $format = true, $replyToWaId = '', $replyToText = '') {
    $c = one("SELECT * FROM conversations WHERE id=?", [$convId]);
    if (!$c) return false;
    if($format)$text = conversationFormat($convId, $text);
    $test = strpos($c['wa_id'], 'TEST-') === 0;
    $mid = $test ? '' : waSendText($c['wa_id'], $text, $replyToWaId);
    if (!$test && !$mid) return false;
    $meta=['transport' => $test ? 'test' : 'whatsapp', 'accepted' => true];
    if($replyToWaId!=='')$meta['reply_to']=['wa_msg_id'=>$replyToWaId,'role'=>'customer','text'=>mb_substr($replyToText,0,1600)];
    $loggedId=logMsg($convId, 'out', $text, $meta);
    if($mid!=='')q('UPDATE messages SET wa_msg_id=? WHERE id=?',[$mid,$loggedId]);
    return true;
}

/** সর্বশেষ যে টিকিটটা মালিকের কাছে পাঠানো হয়েছিল */
function ownerPendingTicket() {
    if (!stockEnsureReplyQueue()) return one("SELECT * FROM tickets WHERE status='sent_owner' ORDER BY sent_at DESC, id DESC LIMIT 1");
    return one("SELECT * FROM tickets t WHERE status='sent_owner' AND NOT EXISTS (SELECT 1 FROM stock_reply_queue q WHERE q.ticket_id=t.id) ORDER BY sent_at DESC, id DESC LIMIT 1");
}

/** মালিক WhatsApp-এ ছোট করে আজকের live হিসাব চাইলে database থেকে উত্তর। */
function ownerTodayStats() {
    $start=now()->format('Y-m-d 00:00:00');
    $end=now()->modify('+1 day')->format('Y-m-d 00:00:00');
    $ticketToday=one("SELECT COUNT(*) n FROM tickets WHERE created_at>=? AND created_at<?",[$start,$end]);
    $ticketOpen=one("SELECT COUNT(*) n FROM tickets WHERE status IN ('queued','sent_owner')");
    $customers=one("SELECT COUNT(DISTINCT conv_id) n FROM messages WHERE direction='in' AND created_at>=? AND created_at<?",[$start,$end]);
    $messages=one("SELECT COUNT(*) n FROM messages WHERE direction='in' AND created_at>=? AND created_at<?",[$start,$end]);
    return [
        'tickets'=>(int)($ticketToday['n']??0),
        'open'=>(int)($ticketOpen['n']??0),
        'customers'=>(int)($customers['n']??0),
        'messages'=>(int)($messages['n']??0),
    ];
}

function ownerStatsReply($mode='all') {
    $s=ownerTodayStats();
    if($mode==='tickets')return "আজ {$s['tickets']}টি নতুন টিকিট হয়েছে। এখন {$s['open']}টি আপনার উত্তরের অপেক্ষায় আছে।";
    if($mode==='customers')return "আজ {$s['customers']} জন customer মোট {$s['messages']}টি message দিয়েছেন।";
    return "📋 আজকের হিসাব\n\nকথোপকথন: {$s['customers']} জন\nমেসেজ: {$s['messages']}টি\nনতুন টিকিট: {$s['tickets']}টি\nঅপেক্ষমাণ টিকিট: {$s['open']}টি";
}

function ownerCustomerLabel($row) {
    $name=trim((string)($row['name']??''));$wa=trim((string)($row['wa_id']??''));
    return $name!==''?$name.' ('.$wa.')':$wa;
}

/** আজকের সাম্প্রতিক customer list; owner conversation বাদ। */
function ownerRecentCustomers($limit=8,$todayOnly=true) {
    $limit=max(1,min(20,(int)$limit));$params=[];$time='';
    if($todayOnly){$start=now()->format('Y-m-d 00:00:00');$end=now()->modify('+1 day')->format('Y-m-d 00:00:00');$time=' AND m.created_at>=? AND m.created_at<?';$params=[$start,$end];}
    return all("SELECT c.id,c.name,c.wa_id,c.last_at,
        (SELECT MAX(m2.created_at) FROM messages m2 WHERE m2.conv_id=c.id AND m2.direction='in') inbound_at
        FROM conversations c WHERE c.is_owner=0 AND EXISTS
        (SELECT 1 FROM messages m WHERE m.conv_id=c.id AND m.direction='in'{$time})
        ORDER BY inbound_at DESC LIMIT {$limit}",$params);
}

function ownerCustomerLastMessages($convId,$limit=3) {
    $limit=max(1,min(5,(int)$limit));
    $rows=all("SELECT body,created_at FROM messages WHERE conv_id=? AND direction='in'
        AND body NOT LIKE 'Reacted %' AND body<>'Reaction removed'
        ORDER BY created_at DESC,id DESC LIMIT {$limit}",[(int)$convId]);
    return array_reverse($rows);
}

function ownerCustomerMessageBlock($customer,$messageLimit=3,$includeTicket=false) {
    $lines=[ownerCustomerLabel($customer)];
    foreach(ownerCustomerLastMessages($customer['id'],$messageLimit) as $m){
        $body=trim(preg_replace('/\s+/u',' ',(string)($m['body']??'')));
        $stamp='';
        if(!empty($m['created_at'])){
            try{$at=new DateTime((string)$m['created_at'],new DateTimeZone((string)cfg('rules.timezone','Asia/Dhaka')));$stamp=$at->format('d M Y · g:i A');}
            catch(Throwable $ignored){$stamp=(string)$m['created_at'];}
        }
        if($body!=='')$lines[]='• '.($stamp!==''?'['.$stamp."]\n  ":'').mb_substr($body,0,220);
    }
    if($includeTicket){
        $ticket=one("SELECT question,kind FROM tickets WHERE conv_id=? AND status IN ('queued','sent_owner') ORDER BY id DESC LIMIT 1",[(int)$customer['id']]);
        if($ticket){$q=trim(preg_replace('/\s+/u',' ',(string)$ticket['question']));$lines[]='⏳ অপেক্ষমাণ: '.mb_substr($q,0,220);}
    }
    return implode("\n",$lines);
}

function ownerTodayCustomerNames() {
    $rows=ownerRecentCustomers(15,true);
    if(!$rows)return 'আজ এখনো কোনো customer message দেননি।';
    $out=['আজ message দিয়েছেন '.count($rows).' জন:'];$n=1;
    foreach($rows as $row)$out[]=$n++.'. '.ownerCustomerLabel($row);
    return implode("\n",$out);
}

function ownerRecentCustomerDigest($messageLimit=3,$includeTicket=false) {
    $rows=ownerRecentCustomers(5,true);
    if(!$rows)$rows=ownerRecentCustomers(5,false);
    if(!$rows)return 'এখনো কোনো customer message পাওয়া যায়নি।';
    $out=[$includeTicket?'সাম্প্রতিক customer-রা যা জানতে চাচ্ছেন:':'সাম্প্রতিক customer message:'];
    foreach($rows as $row)$out[]=ownerCustomerMessageBlock($row,$messageLimit,$includeTicket);
    return mb_substr(implode("\n\n",$out),0,3900);
}

function ownerFindCustomer($needle) {
    $needle=trim((string)$needle);if($needle==='')return null;
    $digits=preg_replace('/\D/','',$needle);
    if(strlen($digits)>=6)return one("SELECT id,name,wa_id,last_at FROM conversations WHERE is_owner=0 AND wa_id LIKE ? ORDER BY last_at DESC LIMIT 1",['%'.$digits.'%']);
    return one("SELECT id,name,wa_id,last_at FROM conversations WHERE is_owner=0 AND name LIKE ? ORDER BY last_at DESC LIMIT 1",['%'.$needle.'%']);
}

/** Pending ticket না থাকলে মালিকের control commands চিনে উত্তর দেয়। */
function ownerControlReply($text) {
    $low=mb_strtolower(trim((string)$text));
    $plain=preg_replace('/[^\p{L}\p{N}]+/u',' ',$low);
    $ticketWord=preg_match('/(?:ticket|tikit|টিকিট)/u',$plain);
    $countWord=preg_match('/(?:koita|koyta|koto|কয়টা|কয়টা|কত(?:টি|টা)?)/u',$plain);
    $customerWord=preg_match('/(?:koto\s*jon|koijon|কত\s*জন|customer|গ্রাহক)/u',$plain);
    $messageWord=preg_match('/(?:msg|message|মেসেজ|বার্তা)/u',$plain);
    /* যেমন: “Avery er last 3 msg” / “017... এর শেষ ২ মেসেজ” */
    if(preg_match('/^(.+?)\s*(?:er|এর)\s*(?:last|শেষ)\s*([23])?\s*(?:ta|টি|টা)?\s*(?:msg|message|মেসেজ|বার্তা)/u',$low,$m)){
        $customer=ownerFindCustomer(trim($m[1]));
        if(!$customer)return 'এই নাম বা নম্বরের customer খুঁজে পাইনি।';
        return ownerCustomerMessageBlock($customer,(int)($m[2]?:3),true);
    }
    if(preg_match('/(?:(?:client|customer|গ্রাহক)(?:রা)?\s*(?:ki|কী|কি)\s*(?:chay|chai|chasce|চায়|চাইছে|চাচ্ছে)|(?:ki|কী|কি)\s*(?:chay|chasce|চাইছে|চাচ্ছে))/u',$plain))return ownerRecentCustomerDigest(2,true);
    if(preg_match('/(?:(?:tader|customer(?:der)?|client(?:der)?|তাদের|গ্রাহকদের?)\s*(?:name|nam|নাম)|(?:ajke|আজ)\s*(?:kara|কারা|কে কে)\s*(?:msg|message|মেসেজ))/u',$plain))return ownerTodayCustomerNames();
    if(preg_match('/(?:(?:last|শেষ|sesh)\s*([23])?\s*(?:ta|টি|টা)?\s*(?:msg|message|মেসেজ|বার্তা)|(?:tader|তাদের)\s*(?:last|শেষ)\s*(?:msg|message|মেসেজ))/u',$plain,$m))return ownerRecentCustomerDigest((int)(($m[1]??'')?:3),false);
    if($ticketWord && ($countWord || preg_match('/(?:ache|আছে|open|খোলা)/u',$plain)))return ownerStatsReply('tickets');
    if(($customerWord && $messageWord) || ($customerWord && $countWord))return ownerStatsReply('customers');
    if(preg_match('/(?:ajker\s*(?:hisab|report|status)|today.?s?\s*(?:report|status)|আজকের\s*(?:হিসাব|রিপোর্ট|স্ট্যাটাস)|দিনের\s*(?:হিসাব|রিপোর্ট))/u',$plain))return ownerStatsReply('all');
    if(preg_match('/^(?:hi|hello|hey|হাই|হ্যালো|আসসালামু\s*আলাইকুম|সালাম)$/u',trim($plain))){
        $s=ownerTodayStats();
        return "আসসালামু আলাইকুম। আজ {$s['customers']} জন customer message দিয়েছেন; {$s['open']}টি ticket অপেক্ষায় আছে। বিস্তারিত জানতে লিখুন: আজকের হিসাব";
    }
    return null;
}

/**
 * মালিকের বার্তা সামলানো।
 * @param string $text     লেখা
 * @param string $btnId    বাটনের id (থাকলে)
 * @param string $replyTo  যে বার্তার উত্তর দিচ্ছেন তার WhatsApp id
 */
function handleOwnerMessage($text, $btnId = '', $replyTo = '') {
    $text = trim($text);

    /* ---------- বাটন ---------- */
    if ($btnId && preg_match('/^tk:(\d+):(\w+)$/', $btnId, $m)) {
        $t = one("SELECT * FROM tickets WHERE id=?", [(int)$m[1]]);
        if (!$t) return 'টিকিটটা আর নেই।';
        return ownerAct($t, $m[2]);
    }

    /* ---------- কোন টিকিট? ---------- */
    $t = null;$explicitTicket=false;
    if ($replyTo) {$t = one("SELECT * FROM tickets WHERE owner_msg_id=?", [$replyTo]);$explicitTicket=(bool)$t;}
    if (!$t && preg_match('/#(\d+)/', $text, $m)) {
        $t = one("SELECT * FROM tickets WHERE id=?", [(int)$m[1]]);
        $explicitTicket=(bool)$t;
        $text = trim(preg_replace('/#\d+/', '', $text, 1));
    }
    if (!$t) $t = ownerPendingTicket();

    /* ---------- সরাসরি শেখানো: "শেখো: প্রশ্ন = উত্তর" ---------- */
    if (preg_match('/^(শেখো|shekho|teach)\s*[:：]?\s*(.+?)\s*=\s*(.+)$/isu', $text, $m)) {
        knowledgeAdd(trim($m[2]), trim($m[3]), 'global', '', 'owner');
        return '✅ শিখে রাখলাম।';
    }

    /* কোনো ticket-কে explicit reply না করলে control প্রশ্ন সবসময় আগে ধরা হবে। */
    if(!$explicitTicket){$control=ownerControlReply($text);if($control!==null)return $control;}

    if (!$t) {
        return 'এখন কোনো প্রশ্ন অপেক্ষায় নেই। আজকের হিসাব জানতে লিখুন “আজকের হিসাব”; কিছু শেখাতে লিখুন — শেখো: প্রশ্ন = উত্তর';
    }

    /* ---------- ছোট উত্তর মানে হ্যাঁ/না ---------- */
    $low = mb_strtolower($text);
    $yes = ['আছে', 'ache', 'ace', 'ase', 'hae', 'হ্যাঁ', 'হা', 'yes', 'y', 'stock ache', 'আছে ✅'];
    $no  = ['নাই', 'নেই', 'nai', 'nei', 'no', 'n', 'out', 'শেষ', 'ses', 'sesh','stock ses','stock shesh','stock out','স্টক শেষ'];

    if ($t['kind'] === 'stock') {
        if (preg_match('/^(permanent(?:ly)?(?: stock)? out|permanent|parmanent|স্থায়ীভাবে শেষ|স্থায়ীভাবে শেষ|আর আসবে না|আর স্টক হবে না)$/iu',$text)) return ownerAct($t,'permanent');
        foreach ($yes as $w) if ($low === $w) return ownerAct($t, 'in');
        foreach ($no  as $w) if ($low === $w) return ownerAct($t, 'out');
    }

    if ($text === '') return 'কিছু লিখুন, নাহলে বাটনে চাপ দিন।';

    /* ---------- বাকি সব তথ্যের উত্তর ---------- */
    list($nice, $sent) = ownerApplyInfo($t, $text);
    if (!$sent) return "তথ্য শেখা হয়েছে, কিন্তু কোনো গ্রাহককে পাঠানো যায়নি। ড্যাশবোর্ড দেখুন।";
    return "✅ পাঠানো হলো" . ($sent > 1 ? " ($sent জনকে)" : '')
         . " ও শিখে রাখলাম।\n\nগ্রাহক পেয়েছে:\n“" . mb_substr($nice, 0, 300) . "”";
}

/** বাটন/সিদ্ধান্ত প্রয়োগ */
function ownerAct($t, $act) {
    if (in_array($act,['in','out','permanent'],true)) {
        $state = $act==='permanent'?'permanent_out':(($act === 'in') ? 'in_stock' : 'out_of_stock');
        list($label, $woo, $sent, $scheduled) = ownerApplyStock($t, $state);
        if (!$sent && !$scheduled) return "স্টক সংরক্ষিত, কিন্তু গ্রাহককে পাঠানো যায়নি। টিকিট খোলা আছে।";
        if($scheduled)return "✅ {$label} → সিদ্ধান্ত সংরক্ষিত। নির্ধারিত সময়ে গ্রাহককে জানানো হবে".($scheduled>1?" ({$scheduled} জনকে)":'').'.';
        return "✅ {$label} → " . ($act === 'in' ? 'আছে' : 'নেই')
             . ($sent > 1 ? " · {$sent} জনকে জানানো হলো" : ' · গ্রাহককে জানানো হলো')
             . ($woo ? "\n({$woo})" : '');
    }
    if ($act === 'me') {
        q("UPDATE conversations SET handoff=1 WHERE id=?", [$t['conv_id']]);
        q("UPDATE tickets SET status='closed', answered_at=? WHERE id=?", [nowStr(), $t['id']]);
        $c = one("SELECT * FROM conversations WHERE id=?", [$t['conv_id']]);
        return '🤝 ঠিক আছে, এই গ্রাহকের সাথে আমি আর কথা বলব না।'
             . ($c ? "\nনম্বর: " . $c['wa_id'] : '');
    }
    if ($act === 'close') {
        q("UPDATE tickets SET status='closed', answered_at=? WHERE id=?", [nowStr(), $t['id']]);
        return 'বাদ দেওয়া হলো।';
    }
    return 'বুঝতে পারিনি।';
}
