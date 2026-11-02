<?php
$root=sys_get_temp_dir().'/bi-media-'.bin2hex(random_bytes(5));mkdir($root);foreach(['db','wa'] as $f)file_put_contents($root.'/'.$f.'.php','<?php');copy(__DIR__.'/../lib/media.php',$root.'/media.php');require $root.'/media.php';
function cfg($k,$d=null){return ['woo.url'=>'https://shop.example.com','media.image_hosts'=>['cdn.shop.example.com']][$k]??$d;}
function one($sql,$p=[]){if(strpos($sql,'FROM messages')!==false)return ['body'=>'ei product ti ki ache?'];if(strpos($sql,'COUNT')!==false)return ['n'=>0];if(strpos($sql,'media_catalog_page')!==false)return ['v'=>'1'];return ['product_id'=>1];}
function all($sql,$p=[]){if(strpos($sql,'FROM messages')!==false)return [['body'=>'[unsupported]'],['body'=>'ei product ti ki ache?']];return [];}
function q(...$a){}function audit(...$a){}
function now(){return new DateTime('2026-09-14 22:00:00',new DateTimeZone('Asia/Dhaka'));}
if(!function_exists('mb_strlen')){function mb_strlen($s){return strlen($s);}}if(!function_exists('mb_strtolower')){function mb_strtolower($s){return strtolower($s);}}
function wooRequest(...$a){$GLOBALS['woo_calls']=($GLOBALS['woo_calls']??0)+1;return [['id'=>1]];}
function catalogStore($p){if(empty($GLOBALS['skip_product_embedding']))throw new Exception('embedding guard absent');$GLOBALS['catalog_rows'][]=$p;}
function check($ok,$label){if(!$ok)throw new Exception('FAIL '.$label);echo 'PASS '.$label."\n";}
check(mediaAllowedUrl('https://lookaside.fbsbx.com/file',true),'Meta signed media host accepted');
check(!mediaAllowedUrl('https://evilfacebook.com/file',true),'lookalike Meta host rejected');
check(!mediaAllowedUrl('http://shop.example.com/image.jpg',false),'unencrypted image URL rejected');
check(mediaAllowedUrl('https://cdn.shop.example.com/image.jpg',false),'configured store CDN accepted');
list($id,$question)=mediaExtractMarker('[[WA_MEDIA:abc-123]] Customer sent image');
check($id==='abc-123' && $question==='Customer sent image','ticket media marker round trip');
check(mediaHashDistance(str_repeat('0',64),str_repeat('0',63).'1')===1,'image hash distance exact');
check(mediaHashDistance('bad','hash')===65,'invalid image hashes never match');
check(mediaRecentQuestion(4)==='ei product ti ki ache?','question immediately before image is recovered');
check(mediaRecentLanguageSample(4)==='ei product ti ki ache?','media language ignores unsupported placeholders');
check(!mediaFallbackIsEnglish('ager image er product ti ki ace?') && mediaFallbackIsEnglish('Is this product available?'),'token-free fallback distinguishes common Banglish from English');
$warm=mediaWarmNextBatch(5);
check($GLOBALS['woo_calls']===1 && count($GLOBALS['catalog_rows'])===1,'dashboard warm-up queues a small WooCommerce batch');
check(empty($GLOBALS['skip_product_embedding']),'warm-up embedding guard is always cleared');
echo "All media tests passed.\n";
