<?php
// Isolated regression tests. No config, database or external API access.
$fixtureRoot = sys_get_temp_dir().'/bibot-regression-'.bin2hex(random_bytes(5));
mkdir($fixtureRoot); mkdir($fixtureRoot.'/lib');
copy(__DIR__.'/../lib/owner_flow.php',$fixtureRoot.'/lib/owner_flow.php');
foreach (['db.php','engine.php','wa.php'] as $f) file_put_contents($fixtureRoot.'/lib/'.$f,'<?php');
$GLOBALS['calls']=[]; $GLOBALS['send_ok']=false; $GLOBALS['learned']=null;
function q($sql,$args=[]) {$GLOBALS['calls'][]=[$sql,$args];}
function one($sql,$args=[]) { if(strpos($sql,'COUNT(DISTINCT conv_id)')!==false)return ['n'=>3]; if(strpos($sql,"COUNT(*) n FROM messages")!==false)return ['n'=>7]; if(strpos($sql,"COUNT(*) n FROM tickets")!==false)return ['n'=>strpos($sql,"status IN")!==false?2:4]; if(strpos($sql,"FROM tickets WHERE status='sent_owner'")!==false)return $GLOBALS['pending_ticket']??null; if(strpos($sql,'products')!==false) return ['id'=>10,'variation_id'=>0,'name'=>'Product A','price'=>'100','sku'=>'SKU10','categories'=>'Kitchen']; if(strpos($sql,'conversations')!==false) return ['id'=>1,'wa_id'=>'15555550106','name'=>'Fixture']; if(strpos($sql,"direction='in'")!==false)return !empty($GLOBALS['latest_question'])?['body'=>$GLOBALS['latest_question']]:null; if(strpos($sql,"kind='info'")!==false)return $GLOBALS['direct_ticket']??null; return null; }
function all($sql,$args=[]) { $GLOBALS['calls'][]=[$sql,$args]; if(strpos($sql,'SELECT c.id,c.name,c.wa_id')!==false)return [['id'=>21,'name'=>'Avery','wa_id'=>'15555550103','last_at'=>'2026-09-13 12:00:00'],['id'=>22,'name'=>'Robin','wa_id'=>'15555550104','last_at'=>'2026-09-13 11:00:00']]; if(strpos($sql,'SELECT body,created_at FROM messages')!==false)return [['body'=>'price koto?','created_at'=>'2026-09-13 11:59:00'],['body'=>'stock ache?','created_at'=>'2026-09-13 12:00:00']]; return [['id'=>3,'conv_id'=>1]]; }
function conversationFormat($id,$text){return $text;}
function cfg($key,$default=null){return $key==='owner.wa'?'15555550107':$default;}
function nowStr(){return '2026-09-13 12:00:00';}
function now(){return new DateTime($GLOBALS['clock']??'2026-09-13 12:00:00',new DateTimeZone('Asia/Dhaka'));}
function stockPromisedAt(){return isset($GLOBALS['promise'])?new DateTime($GLOBALS['promise'],new DateTimeZone('Asia/Dhaka')):now();}
function stockEnsureReplyQueue(){return false;}
function stockQueuedReply($id){return null;}
function stockQueueReply(...$a){$GLOBALS['queued_reply']=$a;return $GLOBALS['queue_ok']??false;}
function audit(...$a){}
function waSendText(...$a){return $GLOBALS['send_ok']?'accepted-id':'';}
function waSendButtons(...$a){return waSendText(...$a);}
function waSendImageId($to,$id,$caption=''){$GLOBALS['image_relay']=[$to,$id,$caption];return waSendText($to,$caption);}
function mediaExtractMarker($text){$id='';if(preg_match('/\[\[WA_MEDIA:([^]]+)\]\]/',$text,$m))$id=$m[1];return [$id,trim(preg_replace('/\[\[WA_MEDIA:[^]]+\]\]/','',$text))];}
function catalogLabel($p){return $p['name'];}
function logMsg(...$a){$GLOBALS['calls'][]=['log',$a];}
function setStock(...$a){return 'dry run';}
function knowledgeAdd(...$a){$GLOBALS['learned']=$a;}
function ownerAnswerFallback($a){return trim($a).'.';}
function composeAnswer($q,$p,$a,$convId=0){$GLOBALS['composed_for'][]=$convId;return 'The verified answer is '.$a.'.';}
if(!function_exists('mb_substr')){function mb_substr($s,$a,$l=null){return substr($s,$a,$l);}}
require $fixtureRoot.'/lib/owner_flow.php';
function check($yes,$name){if(!$yes)throw new Exception('FAIL '.$name); echo 'PASS '.$name."\n";}
$t=['id'=>3,'conv_id'=>1,'kind'=>'info','product_id'=>10,'variation_id'=>0,'question'=>'Thickness?','status'=>'queued'];
check(ownerNotify($t)===false,'notification failure remains queued');
check(count($GLOBALS['calls'])===0,'no sent_owner write after failure');
list($nice,$sent)=ownerApplyInfo($t,'1 mm');
check($sent===0,'failed customer send not counted');
check($GLOBALS['learned'][2]==='product' && $GLOBALS['learned'][3]==='10','product knowledge scoped correctly');
$queries=$GLOBALS['calls'];
check(strpos($queries[0][0],'product_id=? AND variation_id=?')!==false && $queries[0][1]===['Thickness?',10,0],'fanout restricted to matching product and variation');
check(count($queries)===1,'failed customer send leaves ticket open');
$GLOBALS['calls']=[];$GLOBALS['send_ok']=true;
list($nice,$sent)=ownerApplyInfo($t,'1 mm');
check($sent===1,'successful API acceptance counted');
check($nice==='The verified answer is 1 mm.' && end($GLOBALS['composed_for'])===1,'owner shorthand is composed for the customer conversation');
check(count(array_filter($GLOBALS['calls'],fn($c)=>strpos($c[0],"status='answered'")!==false))===1,'successful ticket completed');
$t['product_id']=0;ownerApplyInfo($t,'Shop policy');check($GLOBALS['learned'][2]==='global','shop policy stays global');
$GLOBALS['calls']=[];$imageTicket=['id'=>9,'conv_id'=>1,'kind'=>'info','product_id'=>0,'variation_id'=>0,'question'=>'[[WA_MEDIA:media-123]] Customer image','status'=>'queued'];
check(ownerNotify($imageTicket)===true && $GLOBALS['image_relay'][1]==='media-123','unrecognized customer image relayed to owner');
check(strpos($GLOBALS['image_relay'][2],'WA_MEDIA')===false,'internal media marker hidden from owner');
$GLOBALS['clock']='2026-09-14 10:15:00';$GLOBALS['promise']='2026-09-14 11:00:00';$GLOBALS['queue_ok']=true;$GLOBALS['calls']=[];
$stockTicket=['id'=>12,'conv_id'=>1,'kind'=>'stock','product_id'=>10,'variation_id'=>0,'question'=>'Stock?','status'=>'sent_owner'];
list($label,$woo,$sent,$scheduled)=ownerApplyStock($stockTicket,'in_stock');
check($sent===0 && $scheduled===1 && $GLOBALS['queued_reply'][0]===3,'owner decision before promised time is queued for the customer');
check(count(array_filter($GLOBALS['calls'],fn($c)=>is_array($c) && isset($c[0]) && strpos($c[0],"status='answered'")!==false))===0,'queued stock ticket is not marked answered before delivery');
$GLOBALS['latest_question']='agamikal office kokhon khulbe?';$GLOBALS['direct_ticket']=null;$GLOBALS['learned']=null;$GLOBALS['send_ok']=true;
list($directNice,$directSent,$directLearned)=ownerDirectReply(1,'sokal 10 ta theke khola',true);
check($directSent===1 && $directLearned && $directNice==='The verified answer is sokal 10 ta theke khola.','conversation reply is composed instead of copied and can be learned');
check($GLOBALS['learned'][0]==='agamikal office kokhon khulbe?' && $GLOBALS['learned'][2]==='global','direct learned answer is linked to latest customer question');
$GLOBALS['learned']=null;
list($exactText,$exactSent,$exactLearned)=ownerDirectExactReply(1,'sokal 10 ta theke khola');
check($exactSent===1 && $exactLearned && $exactText==='sokal 10 ta theke khola','exact dashboard reply bypasses composition and is learned');
check($GLOBALS['learned'][1]==='sokal 10 ta theke khola','exact wording is stored unchanged');
$GLOBALS['pending_ticket']=$stockTicket;
check(handleOwnerMessage('Ajke koita ticket ace?')==='আজ 4টি নতুন টিকিট হয়েছে। এখন 2টি আপনার উত্তরের অপেক্ষায় আছে।','owner ticket count command bypasses pending ticket');
check(handleOwnerMessage('Koto jon msg diyece?')==='আজ 3 জন customer মোট 7টি message দিয়েছেন।','owner customer count command uses live database totals');
check(strpos(handleOwnerMessage('Hi'),'আজ 3 জন customer')!==false,'owner greeting returns useful live summary');
check(strpos(handleOwnerMessage('Tader name ki?'),'Avery (15555550103)')!==false,'owner can list today customer names');
check(strpos(handleOwnerMessage('Client ki chasce?'),'stock ache?')!==false,'owner can inspect what recent customers want');
check(strpos(handleOwnerMessage('last 3 msg'),'price koto?')!==false,'owner can inspect recent customer messages');
check(strpos(handleOwnerMessage('last 3 msg'),'13 Sep 2026 · 11:59 AM')!==false,'recent customer messages include Dhaka date and time');
echo "All regression tests passed.\n";
