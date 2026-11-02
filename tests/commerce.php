<?php
$root=sys_get_temp_dir().'/bi-commerce-'.bin2hex(random_bytes(5));mkdir($root);file_put_contents($root.'/retrieval.php','<?php');copy(__DIR__.'/../lib/commerce.php',$root.'/commerce.php');require $root.'/commerce.php';
require __DIR__.'/../lib/request_flow.php';
if(!function_exists('mb_substr')){function mb_substr($s,$a,$b=null){return substr($s,$a,$b);}}if(!function_exists('mb_strtolower')){function mb_strtolower($s){return strtolower($s);}}if(!function_exists('mb_strpos')){function mb_strpos($s,$n){return strpos($s,$n);}}
function check($ok,$label){if(!$ok)throw new Exception('FAIL '.$label);echo 'PASS '.$label."\n";}
function trainingPrompt(){return 'Follow the latest customer language.';}
function conversationLanguageRule(){return 'Resolve the saved policy against the latest customer language.';}
function conversationLanguageRepair($s,$lang){return $s;}
function conversationHistory($id){return [['role'=>'customer','text'=>'Is this washable?']];}
function catalogSkuCandidates($t){return [];}
function catalogResolve($q,$c){return [$GLOBALS['product'],'search',[]];}
function catalogVerified($p,$live,$force=false){return !empty($GLOBALS['failed'])?null:$p;}
function catalogFresh($p,$minutes){return true;}
function catalogVariations($id){return [$GLOBALS['product']];}
function catalogLabel($p){return $p['name'];}
function retrievalKnowledge($q,$p){return [['question'=>'Available?','answer'=>'Website says in stock.']];}
function stockOf($id,$vid=0){return $GLOBALS['stock'];}
function stockOwnerContactOpen(){return $GLOBALS['stock_hours_open']??true;}
function stockWaitingMessage(){return stockOwnerContactOpen()?'পণ্যটির স্টক যাচাই করা হচ্ছে।':'এখন আমাদের অফিস সময়ের বাইরে, তাই এই মুহূর্তে পণ্যটির স্টক নিশ্চিত করা সম্ভব হচ্ছে না। আগামীকাল সকাল ১১টায় স্টকের নিশ্চিত তথ্য আপনাকে জানাব।';}
function llmAsk(...$a){$GLOBALS['calls'][]=$a;return ['reply'=>'Which option do you mean?','needs_owner'=>false];}
function logMsg(...$a){}function audit(...$a){}function q(...$a){}
function createTicket(...$a){$GLOBALS['tickets'][]=$a;}
function reply($c,$t){return $t;}
$GLOBALS['product']=['id'=>1,'variation_id'=>0,'name'=>'Panel','sku'=>'P100','permalink'=>'https://example.test/product/panel/','type'=>'simple','attributes'=>'{}','description'=>'Always available','synced_at'=>'2026-09-14 12:00:00','price'=>'100','woo_stock'=>'instock'];
$GLOBALS['stock']=['state'=>'unknown','valid_until'=>null];$GLOBALS['calls']=[];$GLOBALS['tickets']=[];
$conv=['id'=>2,'last_product'=>1,'last_variation'=>0];$plan=['route'=>'stock','needs_product'=>true,'query'=>'Panel'];
commerceHandle($conv,'In stock?',$plan);
check(count($GLOBALS['tickets'])===1 && $GLOBALS['tickets'][0][1]==='stock','unknown owner stock creates stock ticket despite website availability');
check(count($GLOBALS['calls'])===0,'unknown stock never reaches answer model for guessing');
check(commerceHandle($conv,'stock o dam koto?',$plan)==='Panel পণ্যটির মূল্য 100 টাকা। পণ্যটির স্টক যাচাই করা হচ্ছে।','combined unknown stock and verified price stays precise without model promises');
$GLOBALS['tickets']=array_slice($GLOBALS['tickets'],0,1);
$GLOBALS['stock_hours_open']=false;$out=commerceHandle($conv,'In stock?',$plan);
check($out==='এখন আমাদের অফিস সময়ের বাইরে, তাই এই মুহূর্তে পণ্যটির স্টক নিশ্চিত করা সম্ভব হচ্ছে না। আগামীকাল সকাল ১১টায় স্টকের নিশ্চিত তথ্য আপনাকে জানাব।','off-hours stock message stays exact without LLM');
$GLOBALS['tickets']=array_slice($GLOBALS['tickets'],0,1);
$GLOBALS['stock_hours_open']=true;
$GLOBALS['stock']=['state'=>'expired'];commerceHandle($conv,'In stock?',$plan);
check(count($GLOBALS['tickets'])===2,'expired stock requests owner confirmation again');
$GLOBALS['stock']=['state'=>'in_stock','permanent'=>false];$out=commerceHandle($conv,'In stock?',$plan);
check(count($GLOBALS['tickets'])===2 && strpos($out,'স্টকে আছে')!==false && strpos($out,'মালিকের')===false,'valid owner confirmation answers directly without exposing its source');
$out=commerceHandle($conv,'stock o dam koto?',$plan);
check($out==='Panel পণ্যটি স্টকে আছে। মূল্য: 100 টাকা।','combined stock and price answer is natural and concise');
$GLOBALS['stock']=['state'=>'out_of_stock','permanent'=>false];$out=commerceHandle($conv,'In stock?',$plan);
check(strpos($out,'বর্তমানে স্টকে নেই')!==false,'temporary out overrides website in-stock');
$GLOBALS['stock']=['state'=>'out_of_stock','permanent'=>true];$out=commerceHandle($conv,'In stock?',$plan);
check(strpos($out,'স্থায়ীভাবে বন্ধ')!==false && count($GLOBALS['tickets'])===2,'permanent out never requests recheck or promises return');
$GLOBALS['stock']=['state'=>'unknown','valid_until'=>null];$before=count($GLOBALS['tickets']);$out=commerceHandle($conv,'etar price koto?',$plan);
check($out==='Panel পণ্যটির মূল্য 100 টাকা।' && count($GLOBALS['tickets'])===$before,'price-only question uses concise Woo price without requiring owner stock');
$GLOBALS['stock']=['state'=>'out_of_stock','permanent'=>true];
$facts=commerceFacts($GLOBALS['product'],true);
check(!isset($facts['stock_status'],$facts['woo_stock']) && $facts['owner_stock']['permanent'],'Woo stock is excluded from all product facts');
$GLOBALS['product']['type']='variable';commerceHandle($conv,'In stock?',$plan);
check(count($GLOBALS['calls'])===1 && count($GLOBALS['tickets'])===2,'variable parent asks for option before stock decision');
$call=end($GLOBALS['calls']);check(strpos($call[0],'Only owner_stock establishes availability')!==false,'retrieved website stock cannot override owner policy');
$GLOBALS['product']['type']='simple';$plan['route']='info';commerceHandle($conv,'Is this washable?',$plan);
$facts=json_decode(end($GLOBALS['calls'])[1][0]['content'],true)['verified_product'];
check(!isset($facts['price']) && isset($facts['owner_stock']),'feature answer excludes old price and includes only owner stock');
echo "All commerce tests passed.\n";
