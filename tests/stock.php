<?php
$root=sys_get_temp_dir().'/bi-stock-'.bin2hex(random_bytes(5));mkdir($root);file_put_contents($root.'/db.php','<?php');copy(__DIR__.'/../lib/stock.php',$root.'/stock.php');require $root.'/stock.php';
function cfg($k,$d=null){return $k==='rules.timezone'?'Asia/Dhaka':$d;}
function now(){return new DateTime($GLOBALS['clock']??'2026-09-14 12:00:00',new DateTimeZone('Asia/Dhaka'));}
function nowStr(){return now()->format('Y-m-d H:i:s');}
function one(...$a){return $GLOBALS['row']??null;}
function q($s,$p){$GLOBALS['row']=['state'=>$p[2],'checked_at'=>$p[3],'valid_until'=>$p[4],'note'=>$p[5],'source'=>$p[6]];}
function audit(...$a){}
function check($v,$m){if(!$v)throw new Exception('FAIL '.$m);echo 'PASS '.$m."\n";}
foreach([
    ['2026-09-14 09:59:00',false,'before 10 AM is closed'],
    ['2026-09-14 10:00:00',true,'10 AM opens stock checks'],
    ['2026-09-14 17:59:00',true,'before 6 PM remains open'],
    ['2026-09-14 18:00:00',false,'6 PM closes stock checks'],
    ['2026-09-18 12:00:00',false,'Friday is closed'],
] as $case){$GLOBALS['clock']=$case[0];check(stockOwnerContactOpen()===$case[1],$case[2]);}
$GLOBALS['clock']='2026-09-14 19:00:00';
check(stockWaitingMessage()==='এখন আমাদের অফিস সময়ের বাইরে, তাই এই মুহূর্তে পণ্যটির স্টক নিশ্চিত করা সম্ভব হচ্ছে না। আগামীকাল সকাল ১১টায় স্টকের নিশ্চিত তথ্য আপনাকে জানাব।','next-day off-hours wording is clear');
$GLOBALS['clock']='2026-09-17 20:00:00';
check(stockWaitingMessage()==='এখন আমাদের অফিস সময়ের বাইরে, তাই এই মুহূর্তে পণ্যটির স্টক নিশ্চিত করা সম্ভব হচ্ছে না। শনিবার সকাল ১১টায় স্টকের নিশ্চিত তথ্য আপনাকে জানাব।','Thursday night names Saturday instead of tomorrow');
$GLOBALS['clock']='2026-09-14 09:00:00';
check(stockWaitingMessage()==='এখন আমাদের অফিস সময়ের বাইরে, তাই এই মুহূর্তে পণ্যটির স্টক নিশ্চিত করা সম্ভব হচ্ছে না। আজ সকাল ১১টায় স্টকের নিশ্চিত তথ্য আপনাকে জানাব।','morning before opening promises today');
$GLOBALS['clock']='2026-09-17 20:00:00';
check(stockPromisedAt()->format('Y-m-d H:i:s')==='2026-09-19 11:00:00','Thursday night skips closed Friday and promises Saturday 11 AM');
$GLOBALS['clock']='2026-10-18 17:47:00';check(stockOwnerContactOpen() && stockPromisedAt()->format('Y-m-d')==='2026-10-18','reported Sunday 5:47 PM is working time and never deferred to tomorrow');
$GLOBALS['clock']='2026-10-19 13:38:00';check(stockOwnerContactOpen() && stockPromisedAt()->format('Y-m-d')==='2026-10-19','reported Monday 1:38 PM is working time');
$GLOBALS['clock']='2026-09-14 12:00:00';
check(stockOf(1)['state']==='unknown','no owner confirmation means unknown');
setStock(1,0,'in_stock');check(stockOf(1)['valid_until']==='2026-09-17 12:00:00','available cached for three days');
$GLOBALS['clock']='2026-09-17 12:00:00';check(stockOf(1)['state']==='expired','available expires at exact boundary');
$GLOBALS['clock']='2026-01-31 12:00:00';setStock(1,0,'out_of_stock');check(stockOf(1)['valid_until']==='2026-04-30 12:00:00','three calendar months clamp month-end');
$GLOBALS['clock']='2026-04-30 12:00:00';check(stockOf(1)['state']==='expired','temporary out expires');
setStock(1,0,'permanent_out');$GLOBALS['clock']='2036-04-30 12:00:00';check(stockOf(1)['permanent'] && stockOf(1)['state']==='out_of_stock','permanent out never expires');
setStock(1,0,'in_stock');check(!stockOf(1)['permanent'] && stockOf(1)['state']==='in_stock','new owner decision can override permanent out');
$GLOBALS['row']['source']='woocommerce';check(stockOf(1)['state']==='unknown','website source never establishes availability');
$GLOBALS['row']=['state'=>'out_of_stock','source'=>'owner','note'=>'','checked_at'=>'2026-01-01 00:00:00','valid_until'=>null];check(stockOf(1)['state']==='expired','legacy indefinite out is not treated as permanent');
echo "All stock tests passed.\n";
