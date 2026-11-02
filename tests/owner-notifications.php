<?php
// Isolated routing and failure tests; no production DB, credentials or API calls.
$root=sys_get_temp_dir().'/bi-notify-'.bin2hex(random_bytes(4));mkdir($root);mkdir($root.'/lib');
foreach(['owner_flow.php','owner_notify.php'] as $file)copy(__DIR__.'/../lib/'.$file,$root.'/lib/'.$file);
foreach(['db.php','engine.php','wa.php'] as $file)file_put_contents($root.'/lib/'.$file,'<?php');
$GLOBALS['settings']=[];$GLOBALS['sent']=[];$GLOBALS['updates']=[];$GLOBALS['owner']='15555550103';$GLOBALS['accepted']=true;$GLOBALS['time']=1792468800;
function channelOwnerWa(){return $GLOBALS['owner'];}
function cfg($k,$d=null){return $d;}
function now(){return new DateTime('@'.$GLOBALS['time']);}
function nowStr(){return now()->format('Y-m-d H:i:s');}
function one($sql,$args=[]){
 if(strpos($sql,'FROM settings')!==false){$k=$args[0]??'owner_notification_window';return isset($GLOBALS['settings'][$k])?['v'=>$GLOBALS['settings'][$k]]:null;}
 if(strpos($sql,'FROM conversations')!==false)return ['id'=>1,'name'=>'Test Customer','wa_id'=>'15555550104'];
 if(strpos($sql,'FROM products')!==false)return ['name'=>'SAMPLE-01','sku'=>'SAMPLE-01'];
 return null;
}
function q($sql,$args=[]){
 if(strpos($sql,'INSERT INTO settings')!==false){if(strpos($sql,"'owner_notification_window'")!==false)$GLOBALS['settings']['owner_notification_window']=$args[0];else $GLOBALS['settings'][$args[0]]=$args[1];}
 if(strpos($sql,'UPDATE tickets')!==false)$GLOBALS['updates'][]=[$sql,$args];
}
function waSendOwnerTemplate($to,$subject,$body){$GLOBALS['sent'][]=['template',$to,$subject,$body];return $GLOBALS['accepted']?'template-id':'';}
function waSendText($to,$body){$GLOBALS['sent'][]=['text',$to,$body];return $GLOBALS['accepted']?'text-id':'';}
function waSendButtons($to,$body,$buttons){$GLOBALS['sent'][]=['buttons',$to,$body,$buttons];return $GLOBALS['accepted']?'buttons-id':'';}
function waSendImageId($to,$id,$body){$GLOBALS['sent'][]=['image',$to,$id,$body];return $GLOBALS['accepted']?'image-id':'';}
function catalogLabel($p){return $p['name'];}
function audit(...$args){}
function check($ok,$label){if(!$ok)throw new Exception('FAIL '.$label);echo 'PASS '.$label."\n";}
require $root.'/lib/owner_flow.php';
check(!ownerNotificationWindowOpen(),'unknown owner window uses template');
ownerNotificationRememberInbound('15555550104',$GLOBALS['time']);check(!$GLOBALS['settings'],'customer cannot open owner window');
ownerNotificationRememberInbound(channelOwnerWa(),$GLOBALS['time']);check(ownerNotificationWindowOpen(),'owner inbound opens window');
ownerNotificationRememberInbound(channelOwnerWa(),$GLOBALS['time']-100);check(json_decode($GLOBALS['settings']['owner_notification_window'],true)['at']===$GLOBALS['time'],'delayed webhook cannot move owner window backwards');
$GLOBALS['time']+=86100;check(!ownerNotificationWindowOpen(),'safety margin closes window before 24 hours');
$t=['id'=>123,'status'=>'queued','conv_id'=>1,'kind'=>'stock','product_id'=>10,'variation_id'=>0,'question'=>'Is SAMPLE-01 available?'];
check(ownerNotify($t),'closed-window stock ticket accepted via template');
check($GLOBALS['sent'][0][0]==='template' && strpos($GLOBALS['sent'][0][3],'SAMPLE-01')!==false,'template includes ticket and product context');
check($GLOBALS['updates'][0][1][1]==='template-id','template id retained for quoted owner answer');
$before=count($GLOBALS['sent']);check(!ownerNotify($t) && count($GLOBALS['sent'])===$before,'failed-status retry respects cooldown');
$GLOBALS['time']+=301;$GLOBALS['accepted']=false;$GLOBALS['updates']=[];
check(!ownerNotify($t) && !$GLOBALS['updates'],'rejected template leaves ticket queued');
ownerNotificationRememberInbound(channelOwnerWa(),$GLOBALS['time']);$GLOBALS['accepted']=true;
check(ownerNotify($t) && end($GLOBALS['sent'])[0]==='buttons','fresh owner message bypasses cooldown and restores buttons');
check(ownerSendOperational('Daily report','Report totals')==='text-id' && end($GLOBALS['sent'])[0]==='text','daily report uses text inside window');
$GLOBALS['time']+=86400;check(ownerSendOperational('Daily report','Report totals')==='template-id','daily report uses template outside window');
$before=count($GLOBALS['sent']);check(ownerSendOperational('Daily report','Report totals')==='' && count($GLOBALS['sent'])===$before,'report template retry is throttled');
$GLOBALS['owner']='15555550105';check(!ownerNotificationWindowOpen(),'changing owner number cannot reuse old service window');
echo "All owner notification tests passed.\n";
