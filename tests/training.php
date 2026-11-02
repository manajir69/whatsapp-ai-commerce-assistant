<?php
// Run only locally: this harness has no database/API connectivity.
$root=sys_get_temp_dir().'/bi-training-'.bin2hex(random_bytes(5));mkdir($root);file_put_contents($root.'/db.php','<?php');copy(__DIR__.'/../lib/training.php',$root.'/training.php');
$GLOBALS['settings']=[];
function cfg($k,$d=null){return ['console.password'=>'fixture-password','llm.provider'=>($GLOBALS['legacy_provider']??'gemini'),'llm.key'=>'fixture-legacy-key','llm.model'=>'fixture-model'][$k]??$d;}
function one($sql,$args=[]){return isset($GLOBALS['settings'][$args[0]])?['v'=>$GLOBALS['settings'][$args[0]]]:null;}
function q($sql,$args=[]){$GLOBALS['settings'][$args[0]]=$args[1];}
function db(){static $d;return $d??($d=new class{function beginTransaction(){} function commit(){} function rollBack(){}});}
function audit(...$a){}function nowStr(){return '2026-09-14 12:00:00';}
if(!function_exists('mb_strlen')){function mb_strlen($s){return preg_match_all('/./us',$s);}}
require $root.'/training.php';
function check($ok,$label){if(!$ok)throw new Exception('FAIL '.$label);echo 'PASS '.$label."\n";}
check(trainingActiveProfile()['key']==='fixture-legacy-key','legacy provider preserved');
check(isset(trainingProviders()['qwen']),'Qwen available in dashboard');
$GLOBALS['legacy_provider']='qwen';trainingSaveProvider('qwen','qwen-plus','',true);
check(trainingActiveProfile()['provider']==='qwen' && trainingActiveProfile()['model']==='qwen-plus','Qwen saved profile selected');
unset($GLOBALS['settings']['llm_active_provider']);$GLOBALS['legacy_provider']='gemini';
trainingSavePrompt('Speak politely',true);check(trainingPrompt()==='Speak politely','prompt saved and applied');
trainingSavePrompt('Answer briefly',false);check(trainingPrompt()==='','disabled prompt ignored');
$history=json_decode(trainingGet('owner_prompt_history'),true);check($history[0]['text']==='Speak politely','previous instruction retained');
$templates=trainingTemplates();$templates['stock_in']='{product} এখন পাওয়া যাচ্ছে।';$templates['stock_price']='{product} পাওয়া যাচ্ছে। দাম {price}।';
trainingSaveTemplates($templates);
check(trainingTemplate('stock_price',['product'=>'Panel','price'=>'400 টাকা'])==='Panel পাওয়া যাচ্ছে। দাম 400 টাকা।','customer reply template can be changed without code');
try{$bad=trainingTemplates();$bad['price']='মূল্য জানা আছে।';trainingSaveTemplates($bad);throw new Exception('missing placeholder accepted');}catch(Exception $e){check($e->getMessage()!=='missing placeholder accepted','verified fact placeholders cannot be removed');}
try{trainingSaveProvider('invalid','model','key',true);throw new Exception('invalid accepted');}catch(Exception $e){check($e->getMessage()!=='invalid accepted','unknown provider rejected');}
trainingSaveEmbedding('qwen','text-embedding-v4','',768,false);
check(embeddingSetting('key')==='' && embeddingSetting('provider')==='qwen','semantic retrieval can be disabled without chat changes');
try{trainingSaveEmbedding('anthropic','model','',768,true);throw new Exception('invalid accepted');}catch(Exception $e){check($e->getMessage()!=='invalid accepted','unsupported embedding provider rejected');}
for($i=0;$i<8;$i++)trainingSavePrompt(str_repeat('ক',11000).$i,true);
check(strlen(trainingGet('owner_prompt_history'))<=60000,'long instruction history stays within existing TEXT capacity');
if(function_exists('openssl_encrypt')){
 trainingSaveProvider('openai','fixture-model','fixture-new-key',true);
 check(strpos(trainingGet('llm_profile_openai'),'fixture-new-key')===false,'key encrypted at rest');
 check(trainingActiveProfile()['key']==='fixture-new-key','selected provider key decrypted');
 check(trainingProfile('openai',false)['key']==='','browser profile omits key');
 trainingSaveProvider('openai','new-model','',false);check(trainingActiveProfile()['key']==='fixture-new-key','blank key preserves saved credential');
}else{echo "SKIP encryption runtime: OpenSSL absent in WASM; server requires OpenSSL.\n";}
echo "All training tests passed.\n";
