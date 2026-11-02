<?php
/** Read-only connection diagnostics. Results are safe summaries stored on this server. */
require_once __DIR__.'/db.php';
require_once __DIR__.'/channel.php';

function connectionHealthKey($service) {
    return 'health_connection_'.preg_replace('/[^a-z0-9_-]/i','',strtolower((string)$service));
}
function connectionHealthSafeError($message) {
    $text=trim(preg_replace('/\s+/u',' ',strip_tags((string)$message)));
    if (preg_match('/(?:\(|\b)429(?:\)|\b)|quota|rate.?limit/i',$text)) return 'Quota exhausted or rate limit reached (HTTP 429).';
    if (preg_match('/(?:\(|\b)(401|403)(?:\)|\b)|unauthori[sz]ed|forbidden|invalid (?:oauth |api )?(?:access )?token|invalid api key/i',$text)) return 'Credential or account authorization was rejected.';
    if (preg_match('/timed? out|timeout/i',$text)) return 'The connection timed out.';
    if (preg_match('/resolve host|could not connect|connection refused|\u09b8\u0982\u09af\u09cb\u0997 \u09ac\u09cd\u09af\u09b0\u09cd\u09a5|dns/i',$text)) return 'Could not connect to the remote service.';
    if (preg_match('/(?:\(|\b)([45]\d\d)(?:\)|\b)/',$text,$m)) return 'The service returned HTTP '.$m[1].'.';
    return $text!=='' ? mb_substr($text,0,220) : 'The connection test failed.';
}
function connectionHealthPut($service,$level,$message,$detail='') {
    if (!in_array($level,['ok','error','warning'],true)) $level='error';
    $payload=['level'=>$level,'message'=>(string)$message,'detail'=>(string)$detail,'checked_at'=>nowStr()];
    try { q('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)',[connectionHealthKey($service),json_encode($payload,JSON_UNESCAPED_UNICODE)]); }
    catch(Throwable $ignored) { /* Diagnostics must never stop normal bot work. */ }
    return $payload;
}
function connectionHealthGet($service) {
    try { $row=one('SELECT v FROM settings WHERE k=?',[connectionHealthKey($service)]);$state=$row?json_decode($row['v'],true):null;return is_array($state)&&isset($state['level'])?$state:null; }
    catch(Throwable $e){return null;}
}
function connectionHealthRuntime($service,$ok,$message='',$detail='') {
    return connectionHealthPut($service,$ok?'ok':'error',$ok?$message:connectionHealthSafeError($message),$detail);
}
function connectionMetaTest() {
    $token=(string)cfg('wa.token','');$phoneId=channelPhoneNumberId();
    if($token==='' || $phoneId==='')return connectionHealthPut('meta','error','WhatsApp token or Phone Number ID is missing.');
    $url='https://graph.facebook.com/v21.0/'.rawurlencode($phoneId).'?fields=id,display_phone_number,verified_name,quality_rating';
    $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token]]);
    $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
    if($raw===false)return connectionHealthPut('meta','error',connectionHealthSafeError($err));
    $json=json_decode((string)$raw,true);
    if($code<200 || $code>=300){$msg=$json['error']['message']??('HTTP '.$code);return connectionHealthPut('meta','error',connectionHealthSafeError('HTTP '.$code.' '.$msg));}
    $number=(string)($json['display_phone_number']??'');$name=(string)($json['verified_name']??'');$quality=(string)($json['quality_rating']??'');
    $detail=implode(' · ',array_filter([$name,$number,$quality]));
    if(channelDigits($number)!==channelBusinessWa())return connectionHealthPut('meta','warning','This Phone Number ID is connected, but it is not the selected live business number.',$detail.' · Expected +'.channelBusinessWa());
    return connectionHealthPut('meta','ok','Live Meta token and Phone Number ID are working.',$detail);
}
function connectionWebhookTest() {
    if(!cfg('wa.verify_token'))return connectionHealthPut('webhook','error','Webhook verify token is missing.');
    if(!cfg('wa.app_secret'))return connectionHealthPut('webhook','error','Meta App Secret is missing; incoming signatures cannot be verified.');
    return connectionHealthPut('webhook','warning','Configured locally; live Meta signatures are monitored when webhooks arrive.');
}
function connectionWooTest() {
    if(!cfg('woo.url') || !cfg('woo.key') || !cfg('woo.secret'))return connectionHealthPut('woo','error','WooCommerce URL, key or secret is missing.');
    try {
        $rows=wooRequest('GET','products',null,['per_page'=>1,'status'=>'publish']);
        return connectionHealthPut('woo','ok','WooCommerce API is working.',is_array($rows)?'Product catalog access confirmed.':'Connection confirmed.');
    } catch(Throwable $e){return connectionHealthPut('woo','error',connectionHealthSafeError($e->getMessage()));}
}
function connectionChatTest() {
    try {$profile=trainingActiveProfile();}
    catch(Throwable $e){return connectionHealthPut('chat','error',connectionHealthSafeError($e->getMessage()));}
    if(empty($profile['key']))return connectionHealthPut('chat','error','Chat API key is missing.');
    try {llmAsk('Return only OK.',[['role'=>'user','content'=>'Connection test']],null,32);return connectionHealthPut('chat','ok','Chat API is working.',ucfirst($profile['provider']).' · '.($profile['model']?:llmDefaultModel($profile['provider'])));}
    catch(Throwable $e){return connectionHealthPut('chat','error',connectionHealthSafeError($e->getMessage()),ucfirst($profile['provider']));}
}
function connectionEmbeddingTest() {
    try {$provider=embeddingSetting('provider','openai');$key=embeddingSetting('key');}
    catch(Throwable $e){return connectionHealthPut('embedding','error',connectionHealthSafeError($e->getMessage()));}
    if(!$key)return connectionHealthPut('embedding','error','Embedding API key is missing or semantic retrieval is disabled.');
    try {$vector=embed('Demo Commerce connection test');if(!$vector)throw new Exception('Empty embedding response');return connectionHealthPut('embedding','ok','Embedding API is working.',ucfirst($provider).' · '.count($vector).' dimensions');}
    catch(Throwable $e){return connectionHealthPut('embedding','error',connectionHealthSafeError($e->getMessage()),ucfirst($provider));}
}
function connectionDatabaseTest() {
    try {db()->query('SELECT 1');$tables=(int)one("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema=DATABASE()")['n'];return connectionHealthPut('database','ok','Database connection is working.',$tables.' tables available.');}
    catch(Throwable $e){return ['level'=>'error','message'=>connectionHealthSafeError($e->getMessage()),'detail'=>'','checked_at'=>nowStr()];}
}
function connectionRunAllTests() {
    return [
        'database'=>connectionDatabaseTest(),
        'meta'=>connectionMetaTest(),
        'webhook'=>connectionWebhookTest(),
        'woo'=>connectionWooTest(),
        'chat'=>connectionChatTest(),
        'embedding'=>connectionEmbeddingTest(),
    ];
}
