<?php
/** Server-owned instructions and provider profiles. No credentials are sent to the browser. */
require_once __DIR__.'/db.php';
function trainingGet($key,$default='') {
    $row=one('SELECT v FROM settings WHERE k=?',[$key]);
    return $row ? (string)$row['v'] : $default;
}
function trainingPut($key,$value) {
    q('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)',[$key,$value]);
}
function trainingProviders() { return ['gemini'=>'Gemini','openai'=>'OpenAI','anthropic'=>'Claude','qwen'=>'Qwen']; }
function trainingPrompt() { return trainingGet('owner_prompt_enabled','1')==='1' ? trainingGet('owner_prompt') : ''; }
function trainingTemplateDefaults() {
    return [
        'stock_in'=>'{product} পণ্যটি স্টকে আছে।',
        'stock_price'=>'{product} পণ্যটি স্টকে আছে। মূল্য: {price}।',
        'stock_out'=>'দুঃখিত, {product} পণ্যটি বর্তমানে স্টকে নেই।',
        'stock_permanent'=>'{product} পণ্যটি স্থায়ীভাবে বন্ধ। ভবিষ্যতে আর স্টক হবে না।',
        'price'=>'{product} পণ্যটির মূল্য {price}।',
        'stock_off_hours'=>'এখন আমাদের অফিস সময়ের বাইরে, তাই এই মুহূর্তে পণ্যটির স্টক নিশ্চিত করা সম্ভব হচ্ছে না। {when} স্টকের নিশ্চিত তথ্য আপনাকে জানাব।',
        'stock_pending'=>'পণ্যটির স্টক যাচাই করা হচ্ছে। {when} নিশ্চিত তথ্য জানিয়ে দেব।',
        'stock_checking'=>'পণ্যটির স্টক যাচাই করা হচ্ছে। নিশ্চিত তথ্য পেলেই জানিয়ে দেব।',
    ];
}
function trainingTemplates() {
    $saved=json_decode(trainingGet('customer_reply_templates','{}'),true);
    return array_replace(trainingTemplateDefaults(),is_array($saved)?array_intersect_key($saved,trainingTemplateDefaults()):[]);
}
function trainingTemplate($key,$vars=[]) {
    $templates=trainingTemplates();$text=(string)($templates[$key]??'');$replace=[];
    foreach($vars as $name=>$value)$replace['{'.$name.'}']=(string)$value;
    return strtr($text,$replace);
}
function trainingSaveTemplates($templates) {
    $defaults=trainingTemplateDefaults();$required=[
        'stock_in'=>['product'],'stock_price'=>['product','price'],'stock_out'=>['product'],
        'stock_permanent'=>['product'],'price'=>['product','price'],
        'stock_off_hours'=>['when'],'stock_pending'=>['when'],'stock_checking'=>[],
    ];
    $clean=[];
    foreach($defaults as $key=>$fallback){
        $text=trim((string)($templates[$key]??''));if($text==='')$text=$fallback;
        if(mb_strlen($text)>600)throw new Exception('Each reply template can contain up to 600 characters.');
        foreach($required[$key] as $placeholder)if(strpos($text,'{'.$placeholder.'}')===false)throw new Exception($key.' must include {'.$placeholder.'}.');
        if(preg_match_all('/\{([^}]+)\}/',$text,$m))foreach($m[1] as $placeholder)if(!in_array($placeholder,['product','price','when'],true))throw new Exception('Unknown template placeholder: {'.$placeholder.'}.');
        $clean[$key]=$text;
    }
    trainingPut('customer_reply_templates',json_encode($clean,JSON_UNESCAPED_UNICODE));
    audit('reply_templates_saved','Customer wording updated');
}
function trainingSavePrompt($text,$enabled) {
    if (mb_strlen($text)>12000) throw new Exception('Instructions can contain up to 12,000 characters.');
    $history=json_decode(trainingGet('owner_prompt_history','[]'),true) ?: [];
    $old=trainingGet('owner_prompt');
    if ($old!==$text) { array_unshift($history,['text'=>$old,'saved_at'=>nowStr()]); }
    // Keep related settings atomic.
    db()->beginTransaction();
    try {
        $history=array_slice($history,0,20);
        // settings.v is TEXT on existing installations; keep complete versions within its byte limit.
        while (strlen(json_encode($history,JSON_UNESCAPED_UNICODE))>60000) array_pop($history);
        trainingPut('owner_prompt_history',json_encode($history,JSON_UNESCAPED_UNICODE));
        trainingPut('owner_prompt',$text); trainingPut('owner_prompt_enabled',$enabled?'1':'0');
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
    audit('owner_prompt_saved','Owner instructions updated');
}
function trainingCryptKey() {
    $secret=(string)cfg('console.password','');
    if ($secret==='') throw new Exception('A console password is required.');
    return hash('sha256','demo-commerce-provider-keys-v1:'.$secret,true);
}
function trainingEncrypt($plain) {
    if (!function_exists('openssl_encrypt')) throw new Exception('OpenSSL is required on the server.');
    $iv=random_bytes(12); $tag='';
    $cipher=openssl_encrypt($plain,'aes-256-gcm',trainingCryptKey(),OPENSSL_RAW_DATA,$iv,$tag);
    if ($cipher===false) throw new Exception('The API key could not be encrypted.');
    return base64_encode($iv.$tag.$cipher);
}
function trainingDecrypt($encoded) {
    if (!function_exists('openssl_decrypt')) throw new Exception('OpenSSL is required on the server.');
    $raw=base64_decode($encoded,true);
    if ($raw===false || strlen($raw)<29) throw new Exception('Please save the API key again.');
    $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',trainingCryptKey(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
    if ($plain===false) throw new Exception('Save the API key again after changing the console password.');
    return $plain;
}
function trainingProfile($provider,$withKey=true) {
    if (!isset(trainingProviders()[$provider])) throw new Exception('This LLM provider is not supported.');
    $saved=json_decode(trainingGet('llm_profile_'.$provider,'{}'),true) ?: [];
    $legacy=cfg('llm.provider')===$provider;
    $hasKey=!empty($saved['key']) || ($legacy && (bool)cfg('llm.key'));
    return ['provider'=>$provider,'model'=>$saved['model'] ?? ($legacy ? (string)cfg('llm.model','') : ''),
        'has_key'=>$hasKey,'key'=>$withKey ? (!empty($saved['key']) ? trainingDecrypt($saved['key']) : ($legacy ? cfg('llm.key','') : '')) : ''];
}
function trainingActiveProfile() {
    $provider=trainingGet('llm_active_provider',(string)cfg('llm.provider','gemini'));
    return trainingProfile($provider);
}
function embeddingSetting($field,$default=null) {
    $profile=json_decode(trainingGet('embedding_profile','{}'),true) ?: [];
    if ($field==='key') {
        if (trainingGet('embedding_enabled','1')!=='1') return '';
        return !empty($profile['key']) ? trainingDecrypt($profile['key']) : ((!isset($profile['provider']) || $profile['provider']===cfg('llm.embed.provider','openai')) ? cfg('llm.embed.key',$default) : '');
    }
    return $profile[$field] ?? cfg('llm.embed.'.$field,$default);
}
function trainingSaveEmbedding($provider,$model,$key,$dims,$enabled) {
    if (!in_array($provider,['openai','gemini','qwen'],true) || !preg_match('/^[a-zA-Z0-9._:\/-]{1,150}$/',$model)) throw new Exception('Enter a supported embedding provider and model.');
    if (!in_array($dims,[0,768],true) || strlen($key)>4096 || preg_match('/[\r\n]/',$key)) throw new Exception('Invalid embedding settings.');
    $old=json_decode(trainingGet('embedding_profile','{}'),true) ?: [];
    $same=($old['provider']??cfg('llm.embed.provider','openai'))===$provider;
    $encrypted=$key!=='' ? trainingEncrypt($key) : ($same ? ($old['key']??'') : '');
    if ($enabled && $encrypted==='' && !($provider===cfg('llm.embed.provider','openai') && cfg('llm.embed.key'))) throw new Exception('Enter an embedding API key for the selected provider.');
    $profile=['provider'=>$provider,'model'=>$model,'dims'=>$dims,'key'=>$encrypted];
    // Preserve the existing custom region endpoint only for the same provider.
    $profile['base']=$same ? ($old['base']??cfg('llm.embed.base','')) : '';
    db()->beginTransaction();
    try { trainingPut('embedding_profile',json_encode($profile)); trainingPut('embedding_enabled',$enabled?'1':'0'); db()->commit(); }
    catch (Throwable $e) { db()->rollBack(); throw $e; }
    audit('embedding_settings_saved',$provider.'; enabled='.($enabled?'yes':'no'));
    if(function_exists('connectionHealthPut'))connectionHealthPut('embedding',$enabled?'warning':'error',$enabled?'Embedding settings changed; run a connection test.':'Semantic retrieval is disabled.',ucfirst($provider).' · '.$model);
}
function trainingSaveProvider($provider,$model,$key,$activate) {
    if (!isset(trainingProviders()[$provider])) throw new Exception('Invalid provider.');
    if ($model==='' || !preg_match('/^[a-zA-Z0-9._:\/-]{1,150}$/',$model)) throw new Exception('Enter a valid model ID for this provider.');
    if (strlen($key)>4096 || preg_match('/[\r\n]/',$key)) throw new Exception('Invalid API key.');
    $saved=json_decode(trainingGet('llm_profile_'.$provider,'{}'),true) ?: [];
    $saved['model']=$model;
    if ($key!=='') $saved['key']=trainingEncrypt($key);
    if (empty($saved['key']) && !(cfg('llm.provider')===$provider && cfg('llm.key'))) throw new Exception('Enter an API key for this provider.');
    db()->beginTransaction();
    try {
        trainingPut('llm_profile_'.$provider,json_encode($saved));
        if ($activate) trainingPut('llm_active_provider',$provider);
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
    audit('llm_profile_saved',$provider.'; activated='.($activate?'yes':'no'));
    if($activate && function_exists('connectionHealthPut'))connectionHealthPut('chat','warning','Chat provider settings changed; run a connection test.',ucfirst($provider).' · '.$model);
}
