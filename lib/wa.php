<?php
/**
 * WhatsApp Cloud API — বার্তা পাঠানো ও গ্রহণ করার সরঞ্জাম।
 */

require_once __DIR__ . '/db.php';
$healthFile=__DIR__.'/health.php';if(file_exists($healthFile))require_once $healthFile;
require_once __DIR__.'/channel.php';

function waGraph() { return 'https://graph.facebook.com/v21.0'; }

function waApi($path, $payload) {
    $url = waGraph() . '/' . ltrim($path, '/');
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . cfg('wa.token'),
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT    => 25,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) { if(function_exists('connectionHealthRuntime'))connectionHealthRuntime('meta',false,$err); throw new Exception('WhatsApp সংযোগ ব্যর্থ: ' . $err); }
    $json = json_decode($raw, true);
    if ($code >= 400) {
        $m = $json['error']['message'] ?? substr($raw, 0, 300);
        if(function_exists('connectionHealthRuntime'))connectionHealthRuntime('meta',false,"HTTP $code $m");
        throw new Exception("WhatsApp ত্রুটি ($code): $m");
    }
    if(function_exists('connectionHealthRuntime'))connectionHealthRuntime('meta',true,'Meta messaging API is working.');
    return $json;
}

function waGet($path) {
    $ch=curl_init(waGraph().'/'.ltrim($path,'/'));
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.cfg('wa.token')],CURLOPT_TIMEOUT=>20]);
    $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    $json=json_decode((string)$raw,true);
    if ($raw===false || $code<200 || $code>=300 || !is_array($json)) { if(function_exists('connectionHealthRuntime'))connectionHealthRuntime('meta',false,'HTTP '.$code); throw new Exception('WhatsApp media metadata unavailable'); }
    if(function_exists('connectionHealthRuntime'))connectionHealthRuntime('meta',true,'Meta media API is working.');
    return $json;
}

/** Dashboard থেকে Meta-তে media upload করে media id ফেরত দেয়। */
function waUploadMedia($filePath, $mime, $filename) {
    if (!cfg('wa.token') || !channelPhoneNumberId()) throw new Exception('WhatsApp connection is not configured.');
    $ch=curl_init(waGraph().'/'.channelPhoneNumberId().'/media');
    $post=[
        'messaging_product'=>'whatsapp',
        'file'=>new CURLFile($filePath,$mime,$filename),
    ];
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.cfg('wa.token')],CURLOPT_POSTFIELDS=>$post,CURLOPT_TIMEOUT=>60]);
    $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
    $json=json_decode((string)$raw,true);
    if($raw===false || $code<200 || $code>=300 || empty($json['id'])){
        $message=$json['error']['message']??($err?:'Media upload failed.');
        if(function_exists('connectionHealthRuntime'))connectionHealthRuntime('meta',false,'HTTP '.$code.' '.$message);
        throw new Exception('WhatsApp file upload failed: '.$message);
    }
    if(function_exists('connectionHealthRuntime'))connectionHealthRuntime('meta',true,'Meta media upload is working.');
    return (string)$json['id'];
}

/** আগে upload করা image/document customer-কে পাঠায়। */
function waSendMediaId($to,$type,$mediaId,$filename='',$caption='') {
    if(!in_array($type,['image','document','video'],true))throw new Exception('Unsupported WhatsApp file type.');
    $media=['id'=>$mediaId];
    if($caption!=='')$media['caption']=mb_substr($caption,0,1024);
    if($type==='document' && $filename!=='')$media['filename']=mb_substr($filename,0,240);
    $r=waApi(channelPhoneNumberId().'/messages',['messaging_product'=>'whatsapp','to'=>$to,'type'=>$type,$type=>$media]);
    return $r['messages'][0]['id']??'';
}

function waSendImageId($to,$mediaId,$caption='') {
    try {
        $r=waApi(channelPhoneNumberId().'/messages',['messaging_product'=>'whatsapp','to'=>$to,'type'=>'image','image'=>['id'=>$mediaId,'caption'=>mb_substr($caption,0,1024)]]);
        return $r['messages'][0]['id']??'';
    } catch(Throwable $e) { audit('wa_image_send_error','Could not relay customer image');return ''; }
}

/** সাধারণ লেখা পাঠানো — ফেরত দেয় WhatsApp-এর message id */
function waSendText($to, $text, $replyToMessageId = '') {
    if (!cfg('wa.token') || !channelPhoneNumberId()) return '';
    try {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'text',
            'text'              => ['preview_url' => false, 'body' => mb_substr($text, 0, 4000)],
        ];
        if($replyToMessageId!=='')$payload['context']=['message_id'=>$replyToMessageId];
        $r = waApi(channelPhoneNumberId() . '/messages', $payload);
        return $r['messages'][0]['id'] ?? '';
    } catch (Exception $e) {
        audit('wa_send_error', $to . ' :: ' . $e->getMessage());
        return '';
    }
}

/**
 * বাটনসহ বার্তা (সর্বোচ্চ ৩টি)।
 * @param array $buttons  [['id'=>'tk:12:in','title'=>'✅ আছে'], …]
 */
function waSendButtons($to, $text, array $buttons) {
    if (!cfg('wa.token') || !channelPhoneNumberId()) return '';
    $rows = [];
    foreach (array_slice($buttons, 0, 3) as $b) {
        $rows[] = ['type' => 'reply', 'reply' => [
            'id'    => mb_substr($b['id'], 0, 250),
            'title' => mb_substr($b['title'], 0, 20),   // WhatsApp-এর সীমা
        ]];
    }
    try {
        $r = waApi(channelPhoneNumberId() . '/messages', [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'interactive',
            'interactive'       => [
                'type'   => 'button',
                'body'   => ['text' => mb_substr($text, 0, 1024)],
                'action' => ['buttons' => $rows],
            ],
        ]);
        return $r['messages'][0]['id'] ?? '';
    } catch (Exception $e) {
        /* বাটন না গেলে অন্তত লেখাটা যাক */
        audit('wa_button_error', $e->getMessage());
        return waSendText($to, $text);
    }
}

/** Approved Meta template whose body contains one {{1}} text variable. */
function waSendMarketingTemplate($to,$templateName,$language,$messageText){
    if(!cfg('wa.token') || !channelPhoneNumberId())return '';
    $name=trim((string)$templateName);$lang=trim((string)$language);
    if(!preg_match('/^[a-z0-9_]+$/',$name) || !preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/',$lang))return '';
    try{
        $r=waApi(channelPhoneNumberId().'/messages',[
            'messaging_product'=>'whatsapp','to'=>$to,'type'=>'template',
            'template'=>[
                'name'=>$name,'language'=>['code'=>$lang],
                'components'=>[['type'=>'body','parameters'=>[['type'=>'text','text'=>mb_substr((string)$messageText,0,1000)]]]],
            ],
        ]);
        return $r['messages'][0]['id']??'';
    }catch(Throwable $e){audit('wa_marketing_template_error',mb_substr($e->getMessage(),0,300));return '';}
}

/** Operational owner alerts use their own approved template and body parameters. */
function waSendOwnerTemplate($to,$subject,$body) {
    if(!cfg('wa.token') || !channelPhoneNumberId())return '';
    $name=(string)cfg('owner.notification_template','owner_workspace_alert');
    $language=(string)cfg('owner.notification_language','en');
    if(!preg_match('/^[a-z0-9_]+$/',$name) || !preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/',$language))return '';
    $clean=function($s,$length){return mb_substr(trim(preg_replace('/\s+/u',' ',(string)$s)),0,$length);};
    try {
        $r=waApi(channelPhoneNumberId().'/messages',[
            'messaging_product'=>'whatsapp','to'=>$to,'type'=>'template',
            'template'=>['name'=>$name,'language'=>['code'=>$language],
                'components'=>[['type'=>'body','parameters'=>[
                    ['type'=>'text','text'=>$clean($subject,120)],
                    ['type'=>'text','text'=>$clean($body,640)],
                ]]]],
        ]);
        return $r['messages'][0]['id']??'';
    }catch(Throwable $e){audit('owner_template_failed',mb_substr($e->getMessage(),0,220));return '';}
}

/** "পড়া হয়েছে" চিহ্ন */
function waMarkRead($msgId) {
    if (!$msgId || !cfg('wa.token')) return;
    try {
        waApi(channelPhoneNumberId() . '/messages', [
            'messaging_product' => 'whatsapp',
            'status'            => 'read',
            'message_id'        => $msgId,
        ]);
    } catch (Exception $e) { /* গুরুত্বপূর্ণ নয় */ }
}

/** Meta সত্যিই পাঠিয়েছে কি না — স্বাক্ষর যাচাই */
function waVerifySignature($rawBody) {
    $secret = cfg('wa.app_secret');
    if (!$secret) return true;                     // বসানো না থাকলে যাচাই বাদ
    $sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    if (strpos($sig, 'sha256=') !== 0) return false;
    $mine = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
    return hash_equals($mine, $sig);
}

/** মালিকের নম্বর কি না */
function waIsOwner($waId) {
    $o = channelOwnerWa();
    $x = preg_replace('/\D/', '', (string)$waId);
    return $o !== '' && $o === $x;
}
