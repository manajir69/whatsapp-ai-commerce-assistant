<?php
require_once __DIR__.'/db.php';
$wooFile=__DIR__.'/woo.php';if(file_exists($wooFile))require_once $wooFile;

function stockSyncEnsure(){
    static $ready=null;if($ready!==null)return $ready;
    try{q("CREATE TABLE IF NOT EXISTS stock_sync_log (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,product_id BIGINT UNSIGNED NOT NULL,variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,action VARCHAR(40) NOT NULL,system_state VARCHAR(30) NOT NULL DEFAULT '',website_state VARCHAR(30) NOT NULL DEFAULT '',status VARCHAR(20) NOT NULL,detail VARCHAR(500) NOT NULL DEFAULT '',created_at DATETIME NOT NULL,KEY idx_product(product_id,variation_id),KEY idx_created(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");return $ready=true;}
    catch(Throwable $e){if(function_exists('audit'))audit('stock_sync_table_error','Stock reconciliation history unavailable');return $ready=false;}
}
function stockSyncRecord($productId,$variationId,$action,$systemState,$websiteState,$status,$detail=''){
    if(!stockSyncEnsure())return;
    q('INSERT INTO stock_sync_log(product_id,variation_id,action,system_state,website_state,status,detail,created_at) VALUES(?,?,?,?,?,?,?,?)',[(int)$productId,(int)$variationId,$action,$systemState,$websiteState,$status,mb_substr((string)$detail,0,500),nowStr()]);
}
function stockSystemLabel($stock){
    if(!empty($stock['permanent']))return 'permanent_out';return (string)($stock['state']??'unknown');
}
function stockExpectedWoo($stock){
    $state=stockSystemLabel($stock);if($state==='in_stock')return 'instock';if(in_array($state,['out_of_stock','permanent_out'],true))return 'outofstock';return '';
}
function stockWebsiteRefresh($productId,$variationId=0,$record=true){
    $path=$variationId?"products/$productId/variations/$variationId":"products/$productId";
    try{$row=wooRequest('GET',$path);$status=(string)($row['stock_status']??'');if(!in_array($status,['instock','outofstock','onbackorder'],true))throw new Exception('WooCommerce returned no valid stock status.');q('UPDATE products SET woo_stock=?,synced_at=? WHERE id=? AND variation_id=?',[$status,nowStr(),(int)$productId,(int)$variationId]);if($record)stockSyncRecord($productId,$variationId,'check',stockSystemLabel(stockOf($productId,$variationId)),$status,'ok','Live WooCommerce status checked.');return ['ok'=>true,'state'=>$status,'message'=>'Website status refreshed.'];}
    catch(Throwable $e){$safe=function_exists('connectionHealthSafeError')?connectionHealthSafeError($e->getMessage()):mb_substr($e->getMessage(),0,220);if($record)stockSyncRecord($productId,$variationId,'check',stockSystemLabel(stockOf($productId,$variationId)),'','error',$safe);return ['ok'=>false,'state'=>'','message'=>$safe];}
}
function stockWebsiteWrite($productId,$variationId,$websiteState,$action='manual'){
    if(!in_array($websiteState,['instock','outofstock'],true))throw new Exception('Invalid website stock state.');
    list($ok,$message)=wooSetStock($productId,$variationId,$websiteState,true);
    if($ok)q('UPDATE products SET woo_stock=?,synced_at=? WHERE id=? AND variation_id=?',[$websiteState,nowStr(),(int)$productId,(int)$variationId]);
    stockSyncRecord($productId,$variationId,$action,stockSystemLabel(stockOf($productId,$variationId)),$websiteState,$ok?'ok':'error',$message);
    return ['ok'=>$ok,'state'=>$websiteState,'message'=>$ok?'Website stock updated to '.$websiteState.'.':$message];
}
function stockApplyDecision($productId,$variationId,$state,$source='owner'){
    $local=setStock($productId,$variationId,$state,$source);
    $website=null;
    if($state==='permanent_out')$website=stockWebsiteWrite($productId,$variationId,'outofstock','permanent_out');
    else {$cached=one('SELECT woo_stock FROM products WHERE id=? AND variation_id=?',[$productId,$variationId]);stockSyncRecord($productId,$variationId,'system_update',stockSystemLabel(stockOf($productId,$variationId)),(string)($cached['woo_stock']??''),'ok',$local);}
    return ['local'=>$local,'website'=>$website];
}
function stockExpiry($checked,$state) {
    $date=DateTimeImmutable::createFromInterface($checked);
    if ($state==='in_stock') return $date->modify('+3 days');
    $month=$date->modify('first day of this month')->modify('+3 months');
    return $month->setDate((int)$month->format('Y'),(int)$month->format('m'),min((int)$date->format('d'),(int)$month->format('t')));
}

/** Only an owner's decision can establish availability. */
function stockOf($productId,$variationId=0) {
    $s=one('SELECT * FROM stock_state WHERE product_id=? AND variation_id=?',[$productId,$variationId]);
    if (!$s || !in_array($s['state'],['in_stock','out_of_stock'],true)) return ['state'=>'unknown','valid_until'=>null];
    if (!in_array($s['source']??'', ['owner','owner_policy_v2'],true)) return ['state'=>'unknown','valid_until'=>null];
    $permanent=$s['state']==='out_of_stock' && ($s['note']??'')==='OWNER_PERMANENT_OUT';
    $s['permanent']=$permanent;
    if ($permanent) { $s['valid_until']=null; return $s; }
    if (empty($s['checked_at'])) { $s['state']='expired'; return $s; }
    $checked=new DateTimeImmutable($s['checked_at'],new DateTimeZone(cfg('rules.timezone','Asia/Dhaka')));
    $until=stockExpiry($checked,$s['state']);
    $s['valid_until']=$until->format('Y-m-d H:i:s');
    if ($until->getTimestamp()<=now()->getTimestamp()) $s['state']='expired';
    return $s;
}
function setStock($productId,$variationId,$state,$source='owner',$note='') {
    if (!in_array($state,['in_stock','out_of_stock','permanent_out'],true)) throw new Exception('Invalid stock decision');
    $permanent=$state==='permanent_out';
    if ($permanent) $state='out_of_stock';
    $until=$permanent ? null : stockExpiry(now(),$state)->format('Y-m-d H:i:s');
    $before=stockOf($productId,$variationId)['state'];
    q("INSERT INTO stock_state (product_id,variation_id,state,checked_at,valid_until,note,source,updated_at) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE state=VALUES(state),checked_at=VALUES(checked_at),valid_until=VALUES(valid_until),note=VALUES(note),source=VALUES(source),updated_at=VALUES(updated_at)",[$productId,$variationId,$state,nowStr(),$until,$permanent?'OWNER_PERMANENT_OUT':'','owner_policy_v2',nowStr()]);
    audit('stock_state',"$productId/$variationId",$before,$permanent?'permanent_out':$state);
    return $permanent?'Permanent stock-out saved':($state==='in_stock'?'Confirmed for 3 days':'Out of stock for 3 months');
}

function stockClock($hour,$minute,$ampm='') {
    $h=(int)$hour;$m=(int)$minute;$ap=strtolower((string)$ampm);
    if($ap==='pm' && $h<12)$h+=12;if($ap==='am' && $h===12)$h=0;
    return sprintf('%02d:%02d',max(0,min(23,$h)),max(0,min(59,$m)));
}
/** Stock checks: 10:00–18:00, Friday closed; promised result time 11:00. */
function stockSchedule() {
    return ['start'=>(string)cfg('rules.stock_office_start','10:00'),'end'=>(string)cfg('rules.stock_office_end',cfg('rules.office_end','18:00')),'reply'=>'11:00','weekend'=>(array)cfg('rules.weekend',[5])];
}
function stockWorkingDay(DateTime $t,array $schedule=null){$s=$schedule?:stockSchedule();return !in_array((int)$t->format('w'),$s['weekend'],true);}
function stockOwnerContactOpen(DateTime $t=null) {
    $t=$t?:now();$s=stockSchedule();$hm=$t->format('H:i');return stockWorkingDay($t,$s) && $hm>=$s['start'] && $hm<$s['end'];
}
function stockPromisedAt(DateTime $t=null) {
    $t=clone($t?:now());$s=stockSchedule();$hm=$t->format('H:i');
    if(stockWorkingDay($t,$s) && $hm>=$s['start'] && $hm>=$s['reply'] && $hm<$s['end'])return $t;
    for($i=0;$i<14;$i++){
        $c=clone $t;if($i)$c->modify("+$i day");list($h,$m)=array_map('intval',explode(':',$s['reply']));$c->setTime($h,$m,0);
        if(!stockWorkingDay($c,$s) || $c<=$t)continue;return $c;
    }
    return $t;
}
function stockWaitingMessage() {
    $at=stockPromisedAt();$seconds=$at->getTimestamp()-now()->getTimestamp();
    if($seconds<=60)return function_exists('trainingTemplate')?trainingTemplate('stock_checking'): 'পণ্যটির স্টক যাচাই করা হচ্ছে। নিশ্চিত তথ্য পেলেই জানিয়ে দেব।';
    $days=(int)now()->setTime(0,0)->diff((clone $at)->setTime(0,0))->format('%a');
    $when=$days===0?'আজ':($days===1?'আগামীকাল':['রবিবার','সোমবার','মঙ্গলবার','বুধবার','বৃহস্পতিবার','শুক্রবার','শনিবার'][(int)$at->format('w')]);
    $hour=(int)$at->format('g');$hourBn=strtr((string)$hour,['0'=>'০','1'=>'১','2'=>'২','3'=>'৩','4'=>'৪','5'=>'৫','6'=>'৬','7'=>'৭','8'=>'৮','9'=>'৯']);$part=(int)$at->format('G')<12?'সকাল':'বিকাল';
    $due=$when.' '.$part.' '.$hourBn.'টায়';
    if(!stockOwnerContactOpen())return function_exists('trainingTemplate')?trainingTemplate('stock_off_hours',['when'=>$due]):'এখন আমাদের অফিস সময়ের বাইরে, তাই এই মুহূর্তে পণ্যটির স্টক নিশ্চিত করা সম্ভব হচ্ছে না। '.$due.' স্টকের নিশ্চিত তথ্য আপনাকে জানাব।';
    $due=$when.' '.$part.' '.$hourBn.'টার মধ্যে';
    return function_exists('trainingTemplate')?trainingTemplate('stock_pending',['when'=>$due]):'পণ্যটির স্টক যাচাই করা হচ্ছে। '.$due.' নিশ্চিত তথ্য জানিয়ে দেব।';
}
function stockEnsureReplyQueue() {
    static $ok=null;if($ok!==null)return $ok;
    try{q("CREATE TABLE IF NOT EXISTS stock_reply_queue (ticket_id INT UNSIGNED PRIMARY KEY,state VARCHAR(30) NOT NULL,deliver_at DATETIME NOT NULL,created_at DATETIME NOT NULL,INDEX(deliver_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");return $ok=true;}
    catch(Throwable $e){audit('stock_queue_error','Scheduled stock replies unavailable');return $ok=false;}
}
function stockQueueReply($ticketId,$state,DateTime $at){if(!stockEnsureReplyQueue())return false;q('INSERT INTO stock_reply_queue(ticket_id,state,deliver_at,created_at) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE state=VALUES(state),deliver_at=VALUES(deliver_at)',[(int)$ticketId,$state,$at->format('Y-m-d H:i:s'),nowStr()]);return true;}
function stockQueuedReply($ticketId){if(!stockEnsureReplyQueue())return null;return one('SELECT * FROM stock_reply_queue WHERE ticket_id=?',[(int)$ticketId]);}
