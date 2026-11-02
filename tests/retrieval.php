<?php
$root=sys_get_temp_dir().'/bi-retrieval-'.bin2hex(random_bytes(5));mkdir($root);
file_put_contents($root.'/llm.php','<?php');copy(__DIR__.'/../lib/retrieval.php',$root.'/retrieval.php');require $root.'/retrieval.php';
if(!function_exists('mb_substr')){function mb_substr($s,$a,$b=null){return substr($s,$a,$b);}}
function check($ok,$label){if(!$ok)throw new Exception('FAIL '.$label);echo 'PASS '.$label."\n";}
function embeddingSetting($k,$d=null){return $k==='key'?'fixture':$d;}
function embedProviderTag(){return $GLOBALS['tag']??'provider:model:3';}
function embed($text){$GLOBALS['api_calls']=($GLOBALS['api_calls']??0)+1;return [1,0,0];}
function audit(...$a){}
function one($sql,$p=[]){return isset($GLOBALS['cache'][$p[0]])?['payload'=>$GLOBALS['cache'][$p[0]]]:null;}
function q($sql,$p=[]){if(strpos($sql,'INSERT INTO bot_cache')===0)$GLOBALS['cache'][$p[0]]=$p[1];if(strpos($sql,'UPDATE products')===0)$GLOBALS['stored']=$p[0];}
retrievalVector('same query');retrievalVector('same query');
check($GLOBALS['api_calls']===1,'repeat query uses server vector cache');
$GLOBALS['tag']='different:model:3';retrievalVector('same query');
check($GLOBALS['api_calls']===2,'model switch isolates query vectors');
retrievalDocument('products',1,0,'document',null);$stored=$GLOBALS['stored'];$count=$GLOBALS['api_calls'];
retrievalDocument('products',1,0,'document',$stored);
check($GLOBALS['api_calls']===$count,'unchanged document reuses persisted vector');
retrievalDocument('products',1,0,'changed document',$stored);
check($GLOBALS['api_calls']===$count+1,'changed document reembedded');
$GLOBALS['tag']='third:model:3';retrievalDocument('products',1,0,'document',$stored);
check(json_decode($GLOBALS['stored'],true)['tag']===$GLOBALS['tag'],'document vector tag updated on model change');
echo "All retrieval tests passed.\n";
