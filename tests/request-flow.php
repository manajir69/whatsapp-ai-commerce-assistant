<?php
// Regression tests use an in-memory DB fixture. Production config is never loaded.
require __DIR__.'/../lib/request_flow.php';
$source=file_get_contents(__DIR__.'/../lib/engine.php');
$source=preg_replace('/^require_once[^;]*;/m','',$source);
$engine=sys_get_temp_dir().'/bi-request-engine-'.bin2hex(random_bytes(4)).'.php';file_put_contents($engine,$source);require $engine;
$GLOBALS['tickets']=[];$GLOBALS['messages']=[];$GLOBALS['last_id']=0;$GLOBALS['audits']=[];
function now(){return new DateTime('2026-10-19 21:57:00',new DateTimeZone('Asia/Dhaka'));}
function nowStr(){return now()->format('Y-m-d H:i:s');}
function db(){return new class{function lastInsertId(){return $GLOBALS['last_id'];}};}
function audit(...$args){$GLOBALS['audits'][]=$args;}
function one($sql,$args=[]){
    if(strpos($sql,'GET_LOCK')!==false)return ['acquired'=>1];
    if(strpos($sql,'FROM messages WHERE conv_id')!==false){foreach($GLOBALS['messages'] as $row)if($row['conv_id']==$args[0] && $row['wa_msg_id']==$args[1])return $row;return null;}
    if(strpos($sql,'SELECT * FROM tickets WHERE id=')!==false)return $GLOBALS['tickets'][$args[0]]??null;
    return null;
}
function all($sql,$args=[]){
    if(!$args)return array_values($GLOBALS['tickets']);
    return array_values(array_reverse(array_filter($GLOBALS['tickets'],function($t)use($args){return $t['conv_id']==$args[0] && in_array($t['status'],['queued','sent_owner'],true);}),true));
}
function q($sql,$args=[]){
    if(strpos($sql,'INSERT INTO tickets')!==false){$id=++$GLOBALS['last_id'];$GLOBALS['tickets'][$id]=['id'=>$id,'conv_id'=>$args[0],'kind'=>$args[1],'product_id'=>$args[2],'variation_id'=>$args[3],'question'=>$args[4],'status'=>'queued','created_at'=>nowStr()];}
    elseif(strpos($sql,'UPDATE tickets SET question=')!==false){$id=$args[4];foreach(['question','kind','product_id','variation_id'] as $i=>$k)$GLOBALS['tickets'][$id][$k]=$args[$i];}
    elseif(strpos($sql,"UPDATE tickets SET status='closed'")!==false){$GLOBALS['tickets'][$args[2]]['status']='closed';}
    elseif(strpos($sql,'UPDATE messages SET body=')!==false){$GLOBALS['messages'][$args[2]]['body']=$args[0];$GLOBALS['messages'][$args[2]]['meta']=$args[1];}
}
function check($yes,$label){if(!$yes)throw new Exception('FAIL '.$label);echo 'PASS '.$label."\n";}
$photo='[[WA_MEDIA:photo-9088]] Customer sent an image that local OCR/product matching could not identify. Customer question: Worldmax HPL Code 9088 is available now ??';
$id=createTicket(29,'info',$photo);
$again=createTicket(29,'stock','please check');
check($id===$again && count($GLOBALS['tickets'])===1,'OCR failure and please check reuse one ticket across types');
check(strpos($GLOBALS['tickets'][$id]['question'],'9088')!==false && strpos($GLOBALS['tickets'][$id]['question'],'please check')!==false && strpos($GLOBALS['tickets'][$id]['question'],'[[WA_MEDIA:')!==false,'merged ticket preserves product enquiry, photo and follow-up');
check($GLOBALS['tickets'][$id]['kind']==='info','unresolved photo keeps an answerable information ticket');
$again=createTicket(29,'stock','Worldmax HPL Code 9088 stock ache?',200,9088);
check($again===$id && $GLOBALS['tickets'][$id]['kind']==='stock','identified catalog product upgrades existing enquiry to stock');
check(createTicket(29,'stock','Is it available now?',200,9088)===$id,'English availability follow-up reuses pending stock enquiry');
$new=createTicket(29,'stock','Worldmax HPL Code 9089 stock ache?',200,9089);
check($new!==$id,'different design gets a separate ticket');
$newConv=createTicket(30,'stock','Worldmax HPL Code 9088 stock ache?',200,9088);
check($newConv!==$id,'different customer is never merged');
$newFeature=createTicket(29,'info','Is Worldmax HPL 9088 waterproof?',200,9088);
check($newFeature!==$id,'a different feature question is not swallowed by stock ticket');
$unresolved=createTicket(29,'stock','Vestiga 1234 available?');
check($unresolved!==$id,'unknown product id zero does not deduplicate unrelated products');
check(requestIsDeletion(['type'=>'revoke']) && !requestIsDeletion(['type'=>'text','text'=>['body'=>'cancel order']]),'protocol deletion is separate from customer cancellation text');
$GLOBALS['messages'][500]=['id'=>500,'conv_id'=>29,'wa_msg_id'=>'wamid.original','body'=>'original text','meta'=>json_encode(['type'=>'image','media_id'=>'secret-photo'])];
$before=count($GLOBALS['tickets']);requestRecordDeletion(29,['type'=>'revoke','id'=>'event-id','revoke'=>['message_id'=>'wamid.original']]);
check($GLOBALS['messages'][500]['body']==='[Message deleted]' && count($GLOBALS['tickets'])===$before,'deletion updates original message without cancelling or creating tickets');
check(!isset(json_decode($GLOBALS['messages'][500]['meta'],true)['media_id']),'deleted attachment is removed from model/dashboard context');
requestRecordDeletion(30,['type'=>'revoke','revoke'=>['message_id'=>'wamid.original']]);
check(count($GLOBALS['tickets'])===$before,'deletion is scoped to the customer conversation');
$GLOBALS['tickets']=[90=>['id'=>90,'conv_id'=>29,'kind'=>'info','product_id'=>0,'variation_id'=>0,'question'=>$photo,'status'=>'queued','created_at'=>'2026-10-19 21:56:51'],91=>['id'=>91,'conv_id'=>29,'kind'=>'stock','product_id'=>0,'variation_id'=>0,'question'=>'please check','status'=>'queued','created_at'=>'2026-10-19 21:57:14']];
check(requestReconcileQueued()===1 && $GLOBALS['tickets'][90]['status']==='queued' && $GLOBALS['tickets'][91]['status']==='closed','existing unsent #90/#91 duplicates reconcile without deleting audit records');
check(requestReconcileQueued()===0,'reconciliation is idempotent');
echo "All request-flow tests passed.\n";
