<?php
$engine=file_get_contents(__DIR__.'/../lib/engine.php');
$start=strpos($engine,'function knowledgeFirstAnswer(');
$end=strpos($engine,"// ==========================================================\n// টিকিট",$start);
eval(substr($engine,$start,$end-$start));
$GLOBALS['matches']=[];$GLOBALS['writes']=[];
function retrievalKnowledge(...$args){return $GLOBALS['matches'];}
function composeAnswer($q,$p,$fact,$conv){return 'জি, '.$fact;}
function q($sql,$args=[]){$GLOBALS['writes'][]=[$sql,$args];}
function logMsg(...$args){$GLOBALS['logged']=$args;}
function audit(...$args){}
function cfg($key,$default=null){return $default;}
if(!function_exists('mb_strtolower')){function mb_strtolower($s){return strtolower($s);}}
if(!function_exists('mb_strpos')){function mb_strpos($s,$n){return strpos($s,$n);}}
function check($ok,$label){if(!$ok)throw new Exception('FAIL '.$label);echo 'PASS '.$label."\n";}
$conv=['id'=>7];
$GLOBALS['matches']=[['id'=>45,'score'=>0.82,'scope'=>'global','question'=>'office khole kokhon?','answer'=>'সকাল ১০টা থেকে রাত ৮টা পর্যন্ত খোলা']];
check(knowledgeFirstAnswer($conv,'ajke kokhon khulben office?')==='জি, সকাল ১০টা থেকে রাত ৮টা পর্যন্ত খোলা','lexical paraphrase uses saved general knowledge before planner');
check(($GLOBALS['writes'][0][1][0]??0)===45,'knowledge use counter updated');
$GLOBALS['matches'][0]['score']=0.75;
check(knowledgeFirstAnswer($conv,'fitting service ache?')===null,'weak similarity does not answer');
$GLOBALS['matches'][0]['score']=0.99;
check(knowledgeFirstAnswer($conv,'আমার CV পাঠালাম')===null,'unrelated high similarity cannot leak a saved answer');
$GLOBALS['matches'][0]['score']=1;
check(knowledgeFirstAnswer($conv,'product price koto?')===null,'product request never uses general shortcut');
echo "All knowledge-first tests passed.\n";
