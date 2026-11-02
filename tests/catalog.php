<?php
$root=sys_get_temp_dir().'/bi-catalog-'.bin2hex(random_bytes(5));mkdir($root);
foreach(['db','woo','retrieval','media'] as $f)file_put_contents($root.'/'.$f.'.php','<?php');copy(__DIR__.'/../lib/catalog.php',$root.'/catalog.php');require $root.'/catalog.php';
if(!function_exists('mb_strlen')){function mb_strlen($s){return strlen($s);}}if(!function_exists('mb_substr')){function mb_substr($s,$a,$b=null){return substr($s,$a,$b);}}if(!function_exists('mb_strtolower')){function mb_strtolower($s){return strtolower($s);}}
function check($ok,$label){if(!$ok)throw new Exception('FAIL '.$label);echo 'PASS '.$label."\n";}
function cfg($k,$d=null){return $k==='rules.timezone'?'Asia/Dhaka':$d;}
function all($s,$p=[]){return $GLOBALS['rows']??[];}
function one($s,$p=[]){return null;}function q(...$a){}function audit(...$a){}
function wooRequest(...$a){$GLOBALS['woo_calls']=($GLOBALS['woo_calls']??0)+1;if(!empty($GLOBALS['fail']))throw new Exception('offline');return ['status'=>'draft'];}
function retrievalVector($s){return null;}function retrievalGet($s){return null;}function retrievalPut(...$a){}
function mediaRememberProduct(...$a){}
$fresh=['id'=>1,'variation_id'=>0,'synced_at'=>(new DateTime('now',new DateTimeZone('Asia/Dhaka')))->format('Y-m-d H:i:s')];
check(catalogVerified($fresh,true)===$fresh && empty($GLOBALS['woo_calls']),'fresh cached stock avoids Woo request');
$old=$fresh;$old['synced_at']=(new DateTime('-10 minutes',new DateTimeZone('Asia/Dhaka')))->format('Y-m-d H:i:s');
check(!catalogFresh($old,5),'cache TTL respects configured timezone');
check(catalogVerified($old,false)===$old,'static facts retained within one day');
check(catalogVerified($old,true)===null,'unpublished product cannot be quoted');
$GLOBALS['fail']=true;check(catalogVerified($old,true)===null,'Woo failure does not return stale stock');
$GLOBALS['rows']=[$fresh];$calls=$GLOBALS['woo_calls'];
check(catalogSearchOnce('Panel')===[$fresh] && $GLOBALS['woo_calls']===$calls,'local name search before Woo');
check(in_array('ps',catalogKeywords('PS প্যানেল'),true),'short uppercase product acronym is searchable');
echo "All catalog tests passed.\n";
