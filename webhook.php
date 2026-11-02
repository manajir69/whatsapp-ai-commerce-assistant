<?php
/**
 * WhatsApp Cloud API — webhook।
 *
 * Meta app → Configure Webhooks:
 *   Callback URL : https://workspace.example.com/webhook.php
 *   Verify token : config.php-এর wa.verify_token
 *   Subscribe    : messages
 */

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/engine.php';
require_once __DIR__ . '/lib/wa.php';
require_once __DIR__ . '/lib/owner_flow.php';
require_once __DIR__ . '/lib/media.php';

/* ==========================================================
   ১. Meta-র প্রথম যাচাই (GET)
   ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode  = $_GET['hub_mode']         ?? '';
    $token = $_GET['hub_verify_token'] ?? '';
    $chal  = $_GET['hub_challenge']    ?? '';
    if ($mode === 'subscribe' && $token !== '' && $token === cfg('wa.verify_token')) {
        if(function_exists('connectionHealthPut'))connectionHealthPut('webhook','ok','Meta webhook verification token accepted.');
        header('Content-Type: text/plain');
        echo $chal;
    } else {
        http_response_code(403);
        echo 'no';
    }
    exit;
}

/* ==========================================================
   ২. বার্তা আসা (POST)
   ========================================================== */
$raw = file_get_contents('php://input');

/* Meta সত্যিই ডাকছে কি না — নিশ্চিত হওয়ার জন্য প্রতিটা ডাক নথিভুক্ত */
audit('wa_hook', mb_substr($raw, 0, 500));

if (!waVerifySignature($raw)) {
    if(function_exists('connectionHealthPut'))connectionHealthPut('webhook','error','Meta webhook signature verification failed.');
    audit('wa_bad_signature', substr($raw, 0, 200));
    http_response_code(403);
    exit('bad signature');
}
if(function_exists('connectionHealthPut'))connectionHealthPut('webhook','ok','Live Meta webhook signature verified.');

/* Meta-কে সাথে সাথে হ্যাঁ বলে দিই, নাহলে সে বারবার একই বার্তা পাঠাবে */
http_response_code(200);
echo 'ok';
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
else { @ob_end_flush(); @flush(); }

@ignore_user_abort(true);
@set_time_limit(120);

$data = json_decode($raw, true);
if (!is_array($data)) exit;

foreach ($data['entry'] ?? [] as $entry) {
    foreach ($entry['changes'] ?? [] as $change) {
        $value = $change['value'] ?? [];

        /* Coexistence: WhatsApp Business phone app থেকে পাঠানো বার্তার mirror।
           এগুলো inbound messages নয়; customer হচ্ছে `to`, এবং bot কোনো উত্তর দেবে না। */
        
        $echoes=$value['message_echoes']??$value['smb_message_echoes']??[];
        if(($change['field']??'')==='smb_message_echoes' && !$echoes)$echoes=$value['messages']??[];
        foreach($echoes as $echo){
            try{
                if(requestIsDeletion($echo)){$deleteTo=(string)($echo['to']??'');if($deleteTo!==''){$deleteConv=getConv($deleteTo);requestRecordDeletion($deleteConv['id'],$echo);}continue;}
                $to=preg_replace('/\D/','',(string)($echo['to']??''));
                $echoId=(string)($echo['id']??'');
                if($to==='' || ($echoId!=='' && one('SELECT id FROM messages WHERE wa_msg_id=?',[$echoId])))continue;
                $type=(string)($echo['type']??'unknown');$body='['.$type.']';$meta=['type'=>$type,'transport'=>'whatsapp_business_app','echo'=>true,'accepted'=>true];
                if($type==='text')$body=(string)($echo['text']['body']??'');
                elseif($type==='image'){$body=trim((string)($echo['image']['caption']??''));if($body==='')$body='[ছবি]';$meta['media_id']=(string)($echo['image']['id']??'');}
                elseif($type==='document'){$body=trim((string)($echo['document']['caption']??''));$filename=(string)($echo['document']['filename']??'ফাইল');if($body==='')$body='[File] '.$filename;$meta['media_id']=(string)($echo['document']['id']??'');$meta['filename']=$filename;}
                elseif($type==='video'){$body=trim((string)($echo['video']['caption']??''));if($body==='')$body='[ভিডিও]';$meta['media_id']=(string)($echo['video']['id']??'');}
                elseif($type==='audio'){$body='[অডিও]';$meta['media_id']=(string)($echo['audio']['id']??'');}
                elseif($type==='sticker'){$body='[স্টিকার]';$meta['media_id']=(string)($echo['sticker']['id']??'');}
                elseif($type==='location'){$lat=(string)($echo['location']['latitude']??'');$lng=(string)($echo['location']['longitude']??'');$body='[লোকেশন]'.($lat!==''&&$lng!==''?' https://maps.google.com/?q='.$lat.','.$lng:'');}
                elseif($type==='reaction'){
                    $emoji=(string)($echo['reaction']['emoji']??'');$targetWa=(string)($echo['reaction']['message_id']??'');$body=$emoji!==''?'Reacted '.$emoji:'Reaction removed';
                    $meta['emoji']=$emoji;$meta['target_wa_msg_id']=$targetWa;
                }
                $conv=getConv($to);
                $replyTo=(string)($echo['context']['id']??'');
                if($replyTo!==''){
                    $ref=one('SELECT direction,body FROM messages WHERE conv_id=? AND wa_msg_id=? LIMIT 1',[$conv['id'],$replyTo]);
                    if($ref)$meta['reply_to']=['wa_msg_id'=>$replyTo,'role'=>$ref['direction']==='in'?'customer':'assistant','text'=>mb_substr((string)$ref['body'],0,1600)];
                }
                if($type==='reaction' && !empty($meta['target_wa_msg_id'])){
                    $ref=one('SELECT direction,body FROM messages WHERE conv_id=? AND wa_msg_id=? LIMIT 1',[$conv['id'],$meta['target_wa_msg_id']]);
                    if($ref)$meta['reply_to']=['wa_msg_id'=>$meta['target_wa_msg_id'],'role'=>$ref['direction']==='in'?'customer':'assistant','text'=>mb_substr((string)$ref['body'],0,1600)];
                }
                $created=nowStr();$ts=(int)($echo['timestamp']??0);
                if($ts>0)$created=(new DateTime('@'.$ts))->setTimezone(new DateTimeZone((string)cfg('app.timezone','Asia/Dhaka')))->format('Y-m-d H:i:s');
                $GLOBALS['incoming_created_at']=$created;
                $logged=logMsg($conv['id'],'out',$body,$meta);
                unset($GLOBALS['incoming_created_at']);
                if($echoId!=='')q('UPDATE messages SET wa_msg_id=? WHERE id=?',[$echoId,$logged]);
                q("INSERT INTO settings(k,v) VALUES('coexistence_echo_last',?) ON DUPLICATE KEY UPDATE v=VALUES(v)",[$created]);
                audit('wa_app_echo',$to.' '.$type);
            }catch(Throwable $e){unset($GLOBALS['incoming_created_at']);audit('wa_app_echo_error',$e->getMessage());}
        }

        /* Meta accepted বলার পর আসল delivery/read/failure status আলাদা করে রাখি। */
        foreach($value['statuses']??[] as $statusRow){
            $state=(string)($statusRow['status']??'unknown');
            if(!in_array($state,['sent','delivered','read','failed'],true))$state='unknown';
            $statusId=(string)($statusRow['id']??'');$detail='';
            if($state==='failed'){
                $error=$statusRow['errors'][0]??[];$detail=trim((string)($error['code']??'').' '.(string)($error['title']??$error['message']??''));
                if(function_exists('connectionHealthRuntime'))connectionHealthRuntime('meta',false,$detail!==''?$detail:'WhatsApp delivery failed.');
            }
            audit('wa_status_'.$state,mb_substr($detail,0,300));
            if($statusId!==''){
                foreach(all('SELECT id,meta FROM messages WHERE wa_msg_id=? AND direction=\'out\'',[$statusId]) as $stored){
                    $meta=json_decode((string)$stored['meta'],true);if(!is_array($meta))$meta=[];
                    $rank=['unknown'=>0,'accepted'=>1,'sent'=>2,'delivered'=>3,'read'=>4,'failed'=>5];
                    if($state==='failed' || ($rank[$state]??0)>=($rank[$meta['delivery_status']??'unknown']??0)){
                        $meta['delivery_status']=$state;$meta['delivery_detail']=$detail;
                        q('UPDATE messages SET meta=? WHERE id=?',[json_encode($meta,JSON_UNESCAPED_UNICODE),$stored['id']]);
                    }
                }
                $digest=one("SELECT v FROM settings WHERE k='digest_message_id'");
                if($digest && hash_equals((string)$digest['v'],$statusId)){
                    $digestStatus=$state.' · '.nowStr().($detail!==''?' · '.$detail:'');
                    q("INSERT INTO settings(k,v) VALUES('digest_status',?) ON DUPLICATE KEY UPDATE v=VALUES(v)",[$digestStatus]);
                    if($state==='failed')q("DELETE FROM settings WHERE k='digest_day'");
                }
                if($state==='failed'){
                    $ticket=one("SELECT id FROM tickets WHERE owner_msg_id=? AND status='sent_owner'",[$statusId]);
                    if($ticket){q("UPDATE tickets SET status='queued',sent_at=NULL,owner_msg_id='' WHERE id=?",[$ticket['id']]);audit('owner_notify_failed','#'.$ticket['id'].' '.$detail);}
                }
            }
        }

        /* নাম জানা থাকলে রেখে দিই */
        $names = [];
        foreach ($value['contacts'] ?? [] as $c) {
            $names[$c['wa_id'] ?? ''] = $c['profile']['name'] ?? '';
        }

        foreach ((($change['field']??'')==='smb_message_echoes'?[]:($value['messages']??[])) as $m) {
            unset($GLOBALS['incoming_created_at'],$GLOBALS['incoming_reply_context'],$GLOBALS['incoming_message_meta'],$GLOBALS['incoming_customer_utterance']);
            $from  = (string)($m['from'] ?? '');
            $msgId = (string)($m['id'] ?? '');
            if ($from === '') continue;

            // Deletion is a protocol event, never an order cancellation or consent response.
            if(requestIsDeletion($m)){
                $deleteConv=getConv($from,$names[$from]??'');
                requestRecordDeletion($deleteConv['id'],$m);
                continue;
            }
            /* একই বার্তা দুবার এলে বাদ */
            if ($msgId && one("SELECT id FROM messages WHERE wa_msg_id=?", [$msgId])) continue;

            /* ---- লেখা বের করা ---- */
            $text   = '';
            $btnId  = '';
            $type   = $m['type'] ?? '';
            $replyTo = (string)($m['context']['id'] ?? '');
            $eventTimestamp=(int)($m['timestamp']??0);
            if($eventTimestamp>0)$GLOBALS['incoming_created_at']=(new DateTime('@'.$eventTimestamp))->setTimezone(new DateTimeZone((string)cfg('app.timezone','Asia/Dhaka')))->format('Y-m-d H:i:s');
            if($replyTo!==''){
                $replyConv=getConv($from,$names[$from]??'');
                $referenced=one('SELECT direction,body FROM messages WHERE conv_id=? AND wa_msg_id=? LIMIT 1',[$replyConv['id'],$replyTo]);
                if($referenced)$GLOBALS['incoming_reply_context']=['wa_msg_id'=>$replyTo,'role'=>$referenced['direction']==='in'?'customer':'assistant','text'=>mb_substr($referenced['body'],0,1600)];
            }
            $ignoreMessage=false;

            if ($type === 'text') {
                $text = $m['text']['body'] ?? '';
            } elseif ($type === 'interactive') {
                $i = $m['interactive'] ?? [];
                if (($i['type'] ?? '') === 'button_reply') {
                    $btnId = $i['button_reply']['id'] ?? '';
                    $text  = $i['button_reply']['title'] ?? '';
                } elseif (($i['type'] ?? '') === 'list_reply') {
                    $btnId = $i['list_reply']['id'] ?? '';
                    $text  = $i['list_reply']['title'] ?? '';
                }
            } elseif ($type === 'button') {
                $text = $m['button']['text'] ?? '';
            } elseif ($type === 'image') {
                $text = trim((string)($m['image']['caption'] ?? ''));
                $mediaId=(string)($m['image']['id']??'');
                if (!waIsOwner($from)) {
                    $conv=getConv($from,$names[$from]??'');
                    $customerText=$text!==''?$text:mediaRecentQuestion($conv['id']);
                    try {
                        $seen=mediaUnderstand($mediaId,$customerText);$text=$seen['text'];
                        if (empty($seen['recognized'])) throw new Exception('No image text or product match');
                        if ($seen['product'] && $seen['ocr']==='' && $customerText==='') $text.="\nThe customer sent the product image without a question. Ask what they want to know.";
                        $GLOBALS['incoming_media_id']=$mediaId;
                        $GLOBALS['incoming_customer_utterance']=$customerText!==''?$customerText:'[Customer image]';
                        $GLOBALS['incoming_message_meta']=['type'=>'image','media_id'=>$mediaId,'recognized'=>true,'analysis'=>$seen['text']];
                        audit('image_understood',($seen['product']?'product_match ':'').($seen['ocr']!==''?'ocr':'image'));
                    } catch (Throwable $e) {
                        $question=mediaTicketMarker($mediaId)."\nCustomer sent an image that local OCR/product matching could not identify.";
                        if ($customerText!=='') $question.="\nCustomer question: ".mb_substr($customerText,0,500);
                        logMsg($conv['id'],'in',$customerText!==''?$customerText:'[Customer image]',['type'=>'image','media_id'=>$mediaId,'recognized'=>false]);
                        createTicket($conv['id'],'info',$question,0,0);
                        if(function_exists('customerProfileRefresh'))customerProfileRefresh($conv['id']);
                        $languageSample=$customerText!==''?$customerText:mediaRecentLanguageSample($conv['id']);
                        $english=mediaFallbackIsEnglish($languageSample);
                        $reply=$english?'I received the image, but could not identify the product. Please send its website link or SKU; we are checking it.':'ছবিটি পেয়েছি, তবে পণ্যটি শনাক্ত করতে পারিনি। ওয়েবসাইটের লিংক বা SKU পাঠান; আমরা যাচাই করছি।';
                        logMsg($conv['id'],'out',$reply);$sentId=waSendText($from,$reply);
                        if($sentId)q("UPDATE messages SET wa_msg_id=? WHERE conv_id=? AND direction='out' ORDER BY id DESC LIMIT 1",[$sentId,$conv['id']]);
                        if (isOfficeHours()) foreach(all("SELECT * FROM tickets WHERE conv_id=? AND status='queued'",[$conv['id']]) as $ticket) ownerNotify($ticket);
                        if ($msgId) q("UPDATE messages SET wa_msg_id=? WHERE conv_id=? AND direction='in' ORDER BY id DESC LIMIT 1",[$msgId,$conv['id']]);
                        continue;
                    }
                } elseif ($text==='') $text='[ছবি]';
            } elseif($type==='video') {
                $conv=getConv($from,$names[$from]??'');
                $body=trim((string)($m['video']['caption']??''));
                $logged=logMsg($conv['id'],'in',$body!==''?$body:'[Customer video]',['type'=>'video','media_id'=>(string)($m['video']['id']??'')]);
                if($msgId)q('UPDATE messages SET wa_msg_id=? WHERE id=?',[$msgId,$logged]);
                waMarkRead($msgId);continue;
            } elseif($type==='location') {
                $lat=(string)($m['location']['latitude']??'');$lng=(string)($m['location']['longitude']??'');
                $locationName=trim((string)($m['location']['name']??''));$address=trim((string)($m['location']['address']??''));
                $text=trim($locationName.($locationName!==''&&$address!==''?' · ':'').$address);
                if($text==='')$text='Location'.($lat!==''&&$lng!==''?': '.$lat.', '.$lng:'');
                $GLOBALS['incoming_message_meta']=['type'=>'location','latitude'=>$lat,'longitude'=>$lng,'location_name'=>$locationName,'address'=>$address];
            } elseif($type==='reaction') {
                $emoji=(string)($m['reaction']['emoji']??'');
                $targetWa=(string)($m['reaction']['message_id']??'');
                $text=$emoji!==''?'Reacted '.$emoji:'Reaction removed';
                $ignoreMessage=true;
                $GLOBALS['incoming_message_meta']=['type'=>'reaction','emoji'=>$emoji,'target_wa_msg_id'=>$targetWa];
            } else {
                $text = '[' . $type . ']';
            }

            waMarkRead($msgId);
            if($ignoreMessage){
                $conv=getConv($from,$names[$from]??'');
                $meta=is_array($GLOBALS['incoming_message_meta']??null)?$GLOBALS['incoming_message_meta']:['type'=>$type];
                $targetWa=(string)($meta['target_wa_msg_id']??'');
                if($targetWa!==''){
                    $referenced=one('SELECT direction,body FROM messages WHERE conv_id=? AND wa_msg_id=? LIMIT 1',[$conv['id'],$targetWa]);
                    if($referenced)$meta['reply_to']=['wa_msg_id'=>$targetWa,'role'=>$referenced['direction']==='in'?'customer':'assistant','text'=>mb_substr((string)$referenced['body'],0,1600)];
                }
                $loggedId=logMsg($conv['id'],'in',$text,$meta);
                if($msgId)q("UPDATE messages SET wa_msg_id=? WHERE id=?",[$msgId,$loggedId]);
                unset($GLOBALS['incoming_message_meta'],$GLOBALS['incoming_created_at']);
                continue;
            }

            try {
                /* ---------- মালিক ---------- */
                if (waIsOwner($from)) {
                    ownerNotificationRememberInbound($from,$eventTimestamp);
                    $out = handleOwnerMessage($text, $btnId, $replyTo);
                    if ($out) waSendText($from, $out);
                    audit('owner_msg', mb_substr($text, 0, 120));
                    continue;
                }

                /* ---------- গ্রাহক ---------- */
                $name  = $names[$from] ?? '';
                if(function_exists('customerHandleConsentInbound') && customerHandleConsentInbound($from,$name,$text,$btnId,$msgId)){
                    unset($GLOBALS['incoming_media_id'],$GLOBALS['incoming_customer_utterance'],$GLOBALS['incoming_message_meta'],$GLOBALS['incoming_reply_context'],$GLOBALS['incoming_created_at']);
                    continue;
                }
                if($replyTo!==''){
                    $replyConv=getConv($from,$name);$referenced=one('SELECT direction,body FROM messages WHERE conv_id=? AND wa_msg_id=? LIMIT 1',[$replyConv['id'],$replyTo]);
                    if($referenced)$GLOBALS['incoming_reply_context']=['wa_msg_id'=>$replyTo,'role'=>$referenced['direction']==='in'?'customer':'assistant','text'=>mb_substr($referenced['body'],0,1600)];
                }
                $reply = handleCustomerMessage($from, $text, $name);
                $profileConv=getConv($from,$name);
                if(function_exists('customerProfileRefresh'))customerProfileRefresh($profileConv['id']);
                unset($GLOBALS['incoming_media_id']);
                unset($GLOBALS['incoming_customer_utterance'],$GLOBALS['incoming_message_meta'],$GLOBALS['incoming_reply_context']);
                unset($GLOBALS['incoming_created_at']);

                /* আগত বার্তার id রেখে দিই — দ্বিতীয়বার এলে চিনতে পারি */
                if ($msgId) {
                    $conv = getConv($from);
                    q("UPDATE messages SET wa_msg_id=? WHERE conv_id=? AND direction='in'
                       ORDER BY id DESC LIMIT 1", [$msgId, $conv['id']]);
                }

                if ($reply !== null && $reply !== '') {
                    $sentId=waSendText($from,$reply);
                    if($sentId){$conv=getConv($from);q("UPDATE messages SET wa_msg_id=? WHERE conv_id=? AND direction='out' ORDER BY id DESC LIMIT 1",[$sentId,$conv['id']]);}
                }

                /* Stock ticket-এর নিজস্ব 10:00–19:00/Friday schedule; অন্য ticket সাধারণ office hours। */
                $conv = getConv($from);
                foreach (all("SELECT * FROM tickets WHERE conv_id=? AND status='queued'",[$conv['id']]) as $t) {
                    if(($t['kind']==='stock' && stockOwnerContactOpen()) || ($t['kind']!=='stock' && isOfficeHours()))ownerNotify($t);
                }
            } catch (Exception $e) {
                unset($GLOBALS['incoming_media_id']);
                unset($GLOBALS['incoming_customer_utterance'],$GLOBALS['incoming_message_meta'],$GLOBALS['incoming_reply_context']);
                unset($GLOBALS['incoming_created_at']);
                audit('webhook_error', $e->getMessage());
            }
        }
    }
}
