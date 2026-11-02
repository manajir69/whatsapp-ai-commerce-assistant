<?php
/** Deterministic system-event and pending-enquiry handling; no model calls. */
function requestIsDeletion(array $message){
    return in_array((string)($message['type']??''),['revoke','deleted','message_deleted'],true);
}
function requestRecordDeletion($convId,array $message){
    $type=(string)($message['type']??'');$event=$message[$type]??[];
    $target=(string)($event['message_id']??$event['id']??$message['message_id']??$message['id']??'');
    if($target==='')return;
    $row=one('SELECT id,meta FROM messages WHERE conv_id=? AND wa_msg_id=?',[(int)$convId,$target]);
    if($row){
        $meta=json_decode((string)$row['meta'],true);if(!is_array($meta))$meta=[];
        // Deleted content must not remain model context or be offered as a reply target.
        $meta=['type'=>'deleted','deleted'=>true,'transport'=>$meta['transport']??'whatsapp'];
        q('UPDATE messages SET body=?,meta=? WHERE id=?',['[Message deleted]',json_encode($meta),$row['id']]);
    }
    audit('wa_message_deleted','conv #'.(int)$convId.' target recorded');
}
function requestPlain($text){
    $text=preg_replace('/\[\[WA_MEDIA:[^]]+\]\]/u','',(string)$text);
    $text=preg_replace('/Customer sent an image that local OCR\/product matching could not identify\.?/i','',$text);
    $text=preg_replace('/Customer question:\s*/i','',$text);
    return trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',mb_strtolower($text)));
}
function requestShortFollowup($text){
    $plain=requestPlain($text);
    if(preg_match('/^(?:(?:is|will) (?:it|this|that) (?:in stock|available)(?: now)?|do you have (?:it|this|that)|(?:eta|etar|eita|oita)(?: ki)?(?: stock)? (?:ase|ace|ache|available)(?: ase|ace|ache)?|এটা কি পাওয়া যাবে|এটা কি পাওয়া যাবে)$/u',$plain))return true;
    return (bool)preg_match('/^(?:please )?(?:check|check please|please check|please confirm|confirm|any update|update|stock update|availability update|just for assurance|etar stock|eta ki available ase|eta available ase|eta ki available ache|দেখুন|একটু দেখুন|চেক করুন|জানাবেন|স্টক আছে|এটা কি আছে|নিশ্চিত করুন)$/u',requestPlain($text));
}
function requestIdentifiers($text){
    preg_match_all('/\b[a-z]*\d[\da-z]*(?:[-_][\da-z]+)*\b/iu',(string)$text,$found);
    return array_values(array_unique(array_map('strtolower',$found[0])));
}
function requestAvailability($text){
    return (bool)preg_match('/\b(?:stock|available|availability|ache|ace|ase|pabo)\b|স্টক|পাওয়া|পাওয়া|আছে/iu',(string)$text);
}
/** Conservative cross-kind matching: never collapse unrelated facts or identified products. */
function requestSameTicket(array $ticket,$kind,$question,$productId=0,$variationId=0){
    if(!in_array($ticket['status']??'',['queued','sent_owner'],true))return false;
    if(($ticket['kind']??'')==='handoff' || $kind==='handoff')return ($ticket['kind']??'')===$kind && requestPlain($ticket['question'])===requestPlain($question);
    $oldPid=(int)($ticket['product_id']??0);$oldVid=(int)($ticket['variation_id']??0);
    if($oldPid && $productId && ($oldPid!==(int)$productId || $oldVid!==(int)$variationId))return false;
    $a=requestPlain($ticket['question']);$b=requestPlain($question);
    $oldIds=requestIdentifiers($a);$newIds=requestIdentifiers($b);
    if($oldIds && $newIds && !array_intersect($oldIds,$newIds))return false;
    if($a!=='' && $a===$b)return true;
    if(requestShortFollowup($question))return true;
    if(($ticket['kind']??'')==='stock' && $kind==='stock' && $oldPid && $oldPid===(int)$productId)return true;
    // Image OCR failure and a stock request can represent the very same enquiry.
    if(requestAvailability($ticket['question']) && requestAvailability($question)){
        if(array_intersect($oldIds,$newIds))return true;
        if($oldPid && $oldPid===(int)$productId)return true;
    }
    return false;
}
function requestOpenTicket($convId,$kind,$question,$productId=0,$variationId=0){
    $cutoff=(clone now())->modify('-24 hour')->format('Y-m-d H:i:s');
    foreach(all("SELECT * FROM tickets WHERE conv_id=? AND status IN ('queued','sent_owner') AND created_at>=? ORDER BY id DESC LIMIT 20",[(int)$convId,$cutoff]) as $ticket){
        if(requestSameTicket($ticket,$kind,$question,$productId,$variationId))return $ticket;
    }
    return null;
}
function requestMergeTicket(array $ticket,$kind,$question,$productId=0,$variationId=0){
    $old=(string)$ticket['question'];$body=$old;
    if(requestPlain($question)!==requestPlain($old) && strpos($old,trim($question))===false){
        $extra="\nFollow-up: ".trim($question);
        // Keep media markers and the original enquiry, while bounding context growth.
        if(mb_strlen($body.$extra)<=6000)$body.=$extra;
    }
    $pid=(int)($ticket['product_id']??0);$vid=(int)($ticket['variation_id']??0);$newKind=$ticket['kind'];
    if(!$pid && $productId){$pid=(int)$productId;$vid=(int)$variationId;}
    // Stock controls require an identified catalog item; unresolved photos stay info tickets.
    if($pid && ($kind==='stock' || requestAvailability($old)))$newKind='stock';
    q('UPDATE tickets SET question=?,kind=?,product_id=?,variation_id=? WHERE id=? AND status IN (\'queued\',\'sent_owner\')',[$body,$newKind,$pid,$vid,$ticket['id']]);
    audit('ticket_followup','#'.$ticket['id']);return $ticket['id'];
}
function requestPendingReply($convId,$kind='stock',$prefix=''){
    $text=$kind==='stock'?stockWaitingMessage():'আমরা বিষয়টি যাচাই করছি। নিশ্চিত তথ্য পেলেই জানাব।';
    $language=$GLOBALS['bot_reply_languages'][$convId]??'';
    if($language==='en'){
        if($kind==='stock' && !stockOwnerContactOpen())$text='We are outside stock-checking hours. We will confirm on '.stockPromisedAt()->format('l, j M \a\t g:i A').' (Dhaka time).';
        else $text=$kind==='stock'?'We are checking the stock and will update you once it is confirmed.':'We are checking this and will update you once it is confirmed.';
    }
    $text=trim($prefix.' '.$text);logMsg($convId,'out',$text);return $text;
}

/** Reconcile only unsent duplicates; keep their audit records rather than deleting them. */
function requestReconcileQueued(){
    $rows=all("SELECT * FROM tickets WHERE status IN ('queued','sent_owner') ORDER BY id ASC LIMIT 200");$canonical=[];$merged=0;
    foreach($rows as $ticket){
        $key=(int)$ticket['conv_id'];$match=null;
        foreach($canonical[$key]??[] as $index=>$previous){
            if(abs(strtotime($ticket['created_at'])-strtotime($previous['created_at']))>86400)continue;
            if($ticket['status']!=='queued')continue;
            if(requestSameTicket($previous,$ticket['kind'],$ticket['question'],$ticket['product_id'],$ticket['variation_id'])){$match=$index;break;}
        }
        if($match===null){$canonical[$key][]=$ticket;continue;}
        if(function_exists('stockQueuedReply') && stockQueuedReply($ticket['id']))continue;
        $main=$canonical[$key][$match];
        requestMergeTicket($main,$ticket['kind'],$ticket['question'],$ticket['product_id'],$ticket['variation_id']);
        q("UPDATE tickets SET status='closed',answer=?,answered_at=? WHERE id=? AND status='queued'",['Merged into ticket #'.$main['id'],nowStr(),$ticket['id']]);
        $canonical[$key][$match]=one('SELECT * FROM tickets WHERE id=?',[$main['id']])?:$main;
        audit('ticket_duplicate_merged','#'.$ticket['id'].' into #'.$main['id']);$merged++;
    }
    return $merged;
}

function requestStoreVoiceRule(){
    return 'Speak as Demo Commerce using I/we and our store. For checking or follow-up, say "We are checking and will let you know." Never describe the owner, proprietor, manager, representative, another seller or team as a separate party to consult or contact; internal tickets and notifications remain internal. Preserve verified facts and promises; do not invent confirmation, prices or stock. If explicitly asked whether you are an AI, answer truthfully. This voice rule does not alter owner-facing operational messages or exact messages manually written by the operator.';
}
/** Repair common internal-attribution phrases without model calls or changing facts. */
function requestStoreVoice($text){
    $patterns=[
        '/\bI have also asked our representative\b/iu'=>'we are checking this',
        '/\b(?:I|we) (?:have |will )?(?:ask(?:ed)?|inform(?:ed)?|notif(?:y|ied)|consult(?:ed)?) (?:the |our |my )?(?:owner|representative|manager)\b/iu'=>'we are checking this',
        '/\b(?:our|the) (?:owner|representative|manager) is checking\b/iu'=>'we are checking',
        '/\b(?:our|the) (?:owner|representative|manager) (?:has|have) confirmed\b/iu'=>'we have confirmed',
        '/\b(?:our|the) (?:owner|representative|manager) will (?:reply|respond|answer)\b/iu'=>'we will reply',
        '/\b(?:check|confirm) (?:it |this |the availability )?with (?:the |our )?(?:owner|representative|team)\b/iu'=>'check',
        '/একজন প্রতিনিধিকে জানানো হয়েছে[।.]?\s*তিনি দেখে উত্তর দেবেন[।.]?/u'=>'আমরা দেখে জানাচ্ছি।',
        '/প্রশ্নটি একজন প্রতিনিধির কাছে যাচাই করা হচ্ছে/u'=>'আমরা বিষয়টি যাচাই করছি',
        '/(?:আমি\s*)?(?:আমাদের\s*)?(?:মালিক|ওনার|প্রতিনিধি)(?:কে|ের কাছে|র কাছে|ের কাছ থেকে|র কাছ থেকে)\s*(?:জিজ্ঞেস করে|শুনে|নিশ্চিত হয়ে|যাচাই করে|কথা বলে)\s*/u'=>'আমরা যাচাই করে ',
        '/(?:আমি\s*)?(?:আমাদের\s*)?(?:মালিক|ওনার|প্রতিনিধি)(?:কে|ের কাছে|র কাছে)\s*জিজ্ঞেস করছি/u'=>'আমরা যাচাই করছি',
        '/(?:আমাদের\s*)?(?:মালিক|ওনার|প্রতিনিধি)\s*(?:জানিয়েছেন|জানিয়েছেন|বলেছেন|নিশ্চিত করেছেন)/u'=>'আমরা নিশ্চিত করছি',
        '/একজন প্রতিনিধিকেও জানিয়েছি/u'=>'আমরা যাচাই করছি',
        '/একজন প্রতিনিধিকে জানানো হয়েছে/u'=>'আমরা বিষয়টি দেখছি',
    ];
    $result=preg_replace(array_keys($patterns),array_values($patterns),(string)$text);
    return $result===null?(string)$text:$result;
}
