<?php
$r=sys_get_temp_dir().'/bi-conv-'.bin2hex(random_bytes(5));mkdir($r);file_put_contents($r.'/training.php','<?php');copy(__DIR__.'/../lib/conversation.php',$r.'/conversation.php');
$GLOBALS['instructions']='Always answer in English.';$GLOBALS['model_reply']=['route'=>'conversation','needs_product'=>false,'query'=>'','reply'=>'Yes, I am here.'];$GLOBALS['llm_calls']=[];
function trainingPrompt(){return $GLOBALS['instructions'];}
function all(...$args){if(strpos($args[0],'FROM tickets')!==false)return [];return $GLOBALS['history']??[['direction'=>'in','body'=>'Are you there?'],['direction'=>'out','body'=>'Product A was discussed earlier']];}
function audit(...$a){}function llmAsk(...$a){$GLOBALS['llm_calls'][]=$a;if(strpos($a[0],'Translate the supplied reply')===0 && isset($GLOBALS['translation']))return $GLOBALS['translation'];return $GLOBALS['model_reply'];}
if(!function_exists('mb_substr')){function mb_substr($s,$a,$b=null){return substr($s,$a,$b);}}if(!function_exists('mb_strlen')){function mb_strlen($s){return strlen($s);}}if(!function_exists('mb_strtolower')){function mb_strtolower($s){return strtolower($s);}}if(!function_exists('mb_strpos')){function mb_strpos($s,$n){return strpos($s,$n);}}
require $r.'/conversation.php';
require __DIR__.'/../lib/request_flow.php';
function now(){return new DateTime('2026-10-19 13:38:00',new DateTimeZone('Asia/Dhaka'));}
function nowStr(){return now()->format('Y-m-d H:i:s');}
function check($ok,$label){if(!$ok)throw new Exception('FAIL '.$label);echo 'PASS '.$label."\n";}
$engine=file_get_contents(__DIR__.'/../lib/engine.php');$start=strpos($engine,'function conversationEmploymentIntent(');$end=strpos($engine,'function isPriceOnly(',$start);eval(substr($engine,$start,$end-$start));
function bisMark(...$a){}function getConv(...$a){return ['id'=>2,'handoff'=>0,'last_product'=>4904,'last_variation'=>0];}function logMsg(...$a){}function reply($c,$t){return $t;}function catalogResolve(...$a){throw new Exception('PRODUCT_LOOKUP');}function catalogSkuCandidates($t){return [];}
function createTicket(...$a){$GLOBALS['ticket']=$a;}
function knowledgeFirstAnswer(...$a){return $GLOBALS['known_answer']??null;}
function commerceHandle($conv,$text,$plan){return catalogResolve($plan['query'],$conv);}
check(handleCustomerMessage('fixture','Are you there?')==='Yes, I am here.','model-directed conversation bypasses old product context');
$GLOBALS['ticket']=null;$job=handleCustomerMessage('fixture','আমার CV সংযুক্ত করলাম, salesman পদে আবেদন করতে চাই');
check(isset($GLOBALS['ticket']) && $GLOBALS['ticket'][1]==='info' && strpos($job,'চাকরির আবেদন')!==false,'employment request cannot become a product stock answer');
$GLOBALS['ticket']=null;$fixed=handleCustomerMessage('fixture','Price fixed?');
check(isset($GLOBALS['ticket']) && $GLOBALS['ticket'][1]==='info' && strpos($fixed,'যাচাই')!==false,'fixed-price follow-up asks the owner instead of treating it as confirmation');
$GLOBALS['history']=[['direction'=>'in','body'=>'etar price koto?'],['direction'=>'out','body'=>'এটি একটি PS প্যানেল। এটি আমাদের ওয়েবসাইটে আছে।']];
$fallback=conversationFallbackPlan(['id'=>2,'last_product'=>0],'etar price koto?');
check($fallback['route']==='stock' && $fallback['query']==='PS প্যানেল','failed planner recovers product hint for Woo search');unset($GLOBALS['history']);
check(strpos($GLOBALS['llm_calls'][0][0],'Always answer in English.')!==false,'saved policy used before routing');
check(strpos($GLOBALS['llm_calls'][0][1][0]['content'],'Product A')!==false,'recent context supplied');
$GLOBALS['instructions']='সবসময় বাংলা অক্ষরে উত্তর দেবে।';$GLOBALS['model_reply']['reply']='জি, আমি আছি।';
check(handleCustomerMessage('fixture','Are you there?')==='জি, আমি আছি।','changed policy applied on next call without code changes');
check(strpos($GLOBALS['llm_calls'][1][0],$GLOBALS['instructions'])!==false,'latest policy reloaded');
$GLOBALS['model_reply']=['route'=>'stock','needs_product'=>true,'query'=>'Product A','reply'=>''];
try{handleCustomerMessage('fixture','Is it in stock?');check(false,'lookup required');}catch(Exception $e){check($e->getMessage()==='PRODUCT_LOOKUP','catalog used only when requested by plan');}
$GLOBALS['model_reply']=['route'=>'conversation','needs_product'=>'false','reply'=>'hi'];
handleCustomerMessage('fixture','test');check(isset($GLOBALS['ticket']),'malformed plan falls back to ticket');
$GLOBALS['model_reply']='A faithful answer.';conversationFormat(2,'একটি উত্তর।');$last=end($GLOBALS['llm_calls']);
check(strpos($last[0],$GLOBALS['instructions'])!==false,'owner instructions used in final format');
check(strpos($last[0],'Required output language:')===false,'no hardcoded language override');
$GLOBALS['model_reply']='It costs 200.';check(conversationFormat(2,'Price 100.')==='Price 100.','changed factual number rejected');
$GLOBALS['instructions']='Follow the customer language. Romanized Bengali must use Bengali script.';
$GLOBALS['model_reply']=['route'=>'conversation','needs_product'=>false,'reply_language'=>'en','reply'=>'হ্যাঁ, আমি আছি।'];$GLOBALS['translation']='Yes, I am here.';
check(handleCustomerMessage('fixture','Are you there?')==='Yes, I am here.','Bengali draft repaired when resolved language is English');
$calls=$GLOBALS['llm_calls'];$planCall=$calls[count($calls)-2];
$latest=json_decode(end($planCall[1])['content'],true);
check(($latest['customer_utterance']??'')==='Are you there?','latest customer message has a separate final message position');
check(strpos($planCall[0],'it does not apply to an English sentence')!==false,'conditional Banglish policy is distinguished from English');
$GLOBALS['incoming_reply_context']=['role'=>'assistant','text'=>'Please send the product image.'];
$GLOBALS['model_reply']=['route'=>'conversation','needs_product'=>false,'reply_language'=>'bn','reply'=>'জি, বুঝেছি।'];conversationPlan(['id'=>2],'আগের কথাটি');
$replyCall=end($GLOBALS['llm_calls']);$replyPayload=json_decode(end($replyCall[1])['content'],true);
check(($replyPayload['replied_to_message']['text']??'')==='Please send the product image.','WhatsApp replied message supplied explicitly');unset($GLOBALS['incoming_reply_context']);
$GLOBALS['model_reply']=['route'=>'conversation','needs_product'=>false,'reply_language'=>'bn','reply'=>'Ji ami achi'];$GLOBALS['translation']='জি, আমি আছি।';
check(handleCustomerMessage('fixture','tumi acho?')==='জি, আমি আছি।','romanized Bengali draft repaired to Bengali script');
$GLOBALS['ticket']=null;$before=count($GLOBALS['llm_calls']);
check(handleCustomerMessage('fixture','[revoke]')===null && $GLOBALS['ticket']===null && count($GLOBALS['llm_calls'])===$before,'legacy deletion placeholders cannot reach model or ticket creation');
$GLOBALS['history']=[['direction'=>'in','body'=>'Worldmax 9088 stock?'],['direction'=>'in','body'=>'[revoke]'],['direction'=>'in','body'=>'[Message deleted]','meta'=>'{"type":"deleted"}']];
$history=conversationHistory(2);check(count($history)===1 && $history[0]['text']==='Worldmax 9088 stock?','deleted events are excluded from model history');
echo "All conversation tests passed.\n";
