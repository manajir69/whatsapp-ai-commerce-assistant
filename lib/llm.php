<?php
/**
 * LLM স্তর — একাধিক প্রদানকারী সমর্থন করে।
 *
 *   চ্যাট      : gemini | anthropic | openai | qwen
 *   এমবেডিং   : gemini | openai | qwen
 *
 * config.php-এ শুধু provider বদলালেই পুরো সিস্টেম সেখানে চলে যাবে।
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/training.php';

/* ==========================================================
   প্রদানকারীর ঠিকানা
   ========================================================== */
function llmBase($provider, $which = 'llm') {
    /* config.php-এ base দেওয়া থাকলে সেটাই ব্যবহার হবে */
    $custom = ($which === 'embed') ? embeddingSetting('base', '') : ($provider === cfg('llm.provider') ? cfg('llm.base', '') : '');
    if ($custom) return rtrim($custom, '/');

    switch ($provider) {
        case 'openai': return 'https://api.openai.com/v1';
        case 'gemini': return 'https://generativelanguage.googleapis.com/v1beta/openai';
        // qwen: সিঙ্গাপুর (International)। চীনের অ্যাকাউন্ট হলে config-এ base বসান:
        //   https://dashscope.aliyuncs.com/compatible-mode/v1
        case 'qwen':   return 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1';
        default:       return '';
    }
}

function llmDefaultModel($provider) {
    switch ($provider) {
        case 'qwen':   return 'qwen-plus';
        case 'gemini': return 'gemini-3.6-flash';
        case 'openai': return 'gpt-4.1-mini';
        default:       return 'claude-sonnet-4-5';
    }
}

function embedDefaultModel($provider) {
    switch ($provider) {
        case 'qwen':   return 'text-embedding-v4';
        case 'gemini': return 'gemini-embedding-001';
        default:       return 'text-embedding-3-small';
    }
}

function httpJson($url, $headers, $payload, $timeout = 60) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new Exception("সংযোগ ব্যর্থ: $err");
    $json = json_decode($raw, true);
    if ($code >= 400) {
        $msg = $json['error']['message'] ?? ($json['message'] ?? substr($raw, 0, 300));
        throw new Exception("API ত্রুটি ($code): $msg");
    }
    return $json;
}

/** Keep a safe, server-side connection result. Never store keys or raw provider payloads. */
function llmHealthMessage($message) {
    $message=(string)$message;
    if (preg_match('/(?:API ত্রুটি \()?429\)?|quota|rate.?limit/i',$message)) return 'Quota exhausted or rate limit reached (HTTP 429).';
    if (preg_match('/(?:API ত্রুটি \()?(401|403)\)?|unauthori[sz]ed|forbidden|invalid api key/i',$message)) return 'API key or account authorization was rejected.';
    if (preg_match('/timed? out|timeout/i',$message)) return 'The provider connection timed out.';
    if (preg_match('/সংযোগ ব্যর্থ|connect|resolve host|dns/i',$message)) return 'Could not connect to the provider.';
    return 'The provider returned an API error.';
}
function llmHealthPut($kind,$provider,$model,$ok,$message='') {
    $safe=$ok?'Connection working.':llmHealthMessage($message);
    try {
        trainingPut('health_'.$kind.'_'.preg_replace('/[^a-z0-9_-]/i','',(string)$provider),json_encode([
            'ok'=>(bool)$ok,'provider'=>(string)$provider,'model'=>(string)$model,
            'message'=>$safe,'checked_at'=>nowStr()
        ],JSON_UNESCAPED_UNICODE));
    } catch (Throwable $ignored) { /* Health logging must never block a customer reply. */ }
    if(function_exists('connectionHealthPut'))connectionHealthPut($kind==='embed'?'embedding':'chat',$ok?'ok':'error',$safe,ucfirst((string)$provider).' · '.(string)$model);
}
function llmHealthGet($kind,$provider) {
    try {
        $state=json_decode(trainingGet('health_'.$kind.'_'.preg_replace('/[^a-z0-9_-]/i','',(string)$provider),'{}'),true);
        return is_array($state) && array_key_exists('ok',$state) ? $state : null;
    } catch (Throwable $e) { return null; }
}
function llmTrackedJson($kind,$provider,$model,$url,$headers,$payload,$timeout=60) {
    try {
        $res=httpJson($url,$headers,$payload,$timeout);
        llmUsageRecord($kind,$provider,$model,$res['usage']??[]);
        return $res;
    } catch (Throwable $e) {
        llmHealthPut($kind,$provider,$model,false,$e->getMessage());
        throw $e;
    }
}

function llmUsageEnsure(){
    static $done=false;if($done)return true;
    q("CREATE TABLE IF NOT EXISTS api_usage (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,kind VARCHAR(20) NOT NULL,provider VARCHAR(30) NOT NULL,model VARCHAR(150) NOT NULL,input_tokens INT UNSIGNED NOT NULL DEFAULT 0,cached_tokens INT UNSIGNED NOT NULL DEFAULT 0,output_tokens INT UNSIGNED NOT NULL DEFAULT 0,total_tokens INT UNSIGNED NOT NULL DEFAULT 0,estimated_usd DECIMAL(12,8) NOT NULL DEFAULT 0,created_at DATETIME NOT NULL,KEY idx_created(created_at),KEY idx_kind(kind)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");return $done=true;
}
function llmUsageRates($provider,$model,$kind){
    if($provider!=='openai')return [0,0,0];
    if($kind==='embed'&&strpos($model,'text-embedding-3-small')!==false)return [0.02,0,0];
    if(strpos($model,'gpt-4.1-mini')!==false)return [0.40,0.10,1.60];
    return [0,0,0];
}
function llmUsageRecord($kind,$provider,$model,$usage){
    try{if(!is_array($usage))$usage=[];$in=(int)($usage['prompt_tokens']??$usage['input_tokens']??0);$out=(int)($usage['completion_tokens']??$usage['output_tokens']??0);$cached=(int)($usage['prompt_tokens_details']['cached_tokens']??$usage['cache_read_input_tokens']??0);$total=(int)($usage['total_tokens']??($in+$out));list($ir,$cr,$or)=llmUsageRates($provider,$model,$kind);$usd=(max(0,$in-$cached)*$ir+$cached*$cr+$out*$or)/1000000;llmUsageEnsure();q('INSERT INTO api_usage(kind,provider,model,input_tokens,cached_tokens,output_tokens,total_tokens,estimated_usd,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[$kind,$provider,$model,$in,$cached,$out,$total,$usd,nowStr()]);}catch(Throwable $ignored){}
}
function llmUsageSummary($days=30){
    try{llmUsageEnsure();$from=(clone now())->modify('-'.(max(1,min(365,(int)$days))-1).' days')->format('Y-m-d 00:00:00');return one('SELECT COUNT(*) calls,COALESCE(SUM(input_tokens),0) input_tokens,COALESCE(SUM(output_tokens),0) output_tokens,COALESCE(SUM(total_tokens),0) total_tokens,COALESCE(SUM(estimated_usd),0) estimated_usd FROM api_usage WHERE created_at>=?',[$from]);}catch(Throwable $e){return ['calls'=>0,'input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0,'estimated_usd'=>0];}
}
function llmUsageDaily($days=14){
    try{llmUsageEnsure();$from=(clone now())->modify('-'.(max(1,(int)$days)-1).' days')->format('Y-m-d 00:00:00');return all('SELECT DATE(created_at) day,COUNT(*) calls,SUM(input_tokens) input_tokens,SUM(output_tokens) output_tokens,SUM(total_tokens) total_tokens,SUM(estimated_usd) estimated_usd FROM api_usage WHERE created_at>=? GROUP BY DATE(created_at) ORDER BY day DESC',[$from]);}catch(Throwable $e){return [];}
}

/* ==========================================================
   চ্যাট
   ========================================================== */

/**
 * @param string $system  সিস্টেম নির্দেশ
 * @param array  $msgs    [['role'=>'user','content'=>'…'], …]
 * @param array  $schema  null হলে সাধারণ লেখা; নাহলে JSON চাওয়া হবে
 */
function llmAsk($system, $msgs, $schema = null, $maxTokens = 1024) {
    $profile  = trainingActiveProfile();
    $provider = $profile['provider'];
    $key      = $profile['key'];
    $model    = $profile['model'];

    if (!$key) throw new Exception('config.php-এ llm.key বসানো হয়নি।');

    if ($schema) {
        $system .= "\n\nতুমি অবশ্যই শুধুমাত্র একটি বৈধ JSON object দিয়ে উত্তর দেবে, "
                 . "কোনো ব্যাখ্যা বা markdown ছাড়া। কাঠামো:\n"
                 . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    if ($provider === 'anthropic') {
        $usedModel=$model ?: 'claude-sonnet-4-5';
        $res = llmTrackedJson('chat',$provider,$usedModel,'https://api.anthropic.com/v1/messages', [
            'content-type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ], [
            'model'      => $usedModel,
            'max_tokens' => $maxTokens,
            'system'     => $system,
            'messages'   => $msgs,
        ]);
        $text = '';
        foreach (($res['content'] ?? []) as $blk) {
            if (($blk['type'] ?? '') === 'text') $text .= $blk['text'];
        }
    } else {
        // openai ও qwen — দুটোই OpenAI-সদৃশ গঠন
        $usedModel=$model ?: llmDefaultModel($provider);
        $payload = [
            'model'      => $usedModel,
            'max_tokens' => $maxTokens,
            'messages'   => array_merge([['role' => 'system', 'content' => $system]], $msgs),
        ];
        if ($schema) $payload['response_format'] = ['type' => 'json_object'];

        /* Gemini 3.x নিজে থেকেই "ভাবতে" গিয়ে max_tokens খেয়ে ফেলে,
           ফলে উত্তর মাঝপথে কেটে যায় ও দেরি হয়। ভাবনাটা কমিয়ে দিই। */
        if ($provider === 'gemini') {
            $eff = cfg('llm.reasoning', 'low');
            if ($eff !== '' && $eff !== 'default') $payload['reasoning_effort'] = $eff;
        }

        $res  = llmTrackedJson('chat',$provider,$usedModel,llmBase($provider, 'llm') . '/chat/completions', [
            'content-type: application/json',
            'authorization: Bearer ' . $key,
        ], $payload);
        $text = $res['choices'][0]['message']['content'] ?? '';
    }

    $text = trim($text);
    $usedModel=$model ?: llmDefaultModel($provider);
    if ($text==='') {
        llmHealthPut('chat',$provider,$usedModel,false,'Invalid empty provider response');
        throw new Exception('The chat provider returned an empty response.');
    }
    if (!$schema) { llmHealthPut('chat',$provider,$usedModel,true); return $text; }

    if (preg_match('/\{.*\}/s', $text, $m)) $text = $m[0];
    $data = json_decode($text, true);
    if (!is_array($data)) {
        llmHealthPut('chat',$provider,$usedModel,false,'Invalid JSON provider response');
        throw new Exception('বৈধ JSON আসেনি: ' . substr($text, 0, 200));
    }
    llmHealthPut('chat',$provider,$usedModel,true);
    return $data;
}

/* ==========================================================
   এমবেডিং
   ========================================================== */

function embedDims() {
    $d = (int)embeddingSetting('dims', 0);
    if ($d > 0) return $d;
    $p = embeddingSetting('provider', 'openai');
    // ছোট রাখলে জায়গা কম লাগে ও খোঁজা দ্রুত হয়; মান প্রায় একই থাকে
    return ($p === 'gemini' || $p === 'openai') ? 768 : 0;   // 0 = মডেলের নিজস্ব
}

function embedProviderTag() {
    return embeddingSetting('provider', 'openai') . ':'
         . (embeddingSetting('model') ?: embedDefaultModel(embeddingSetting('provider', 'openai')))
         . ':' . embedDims();
}

function embedCall(array $texts) {
    $provider = embeddingSetting('provider', 'openai');
    $key      = embeddingSetting('key');
    if (!$key) throw new Exception('config.php-এ llm.embed.key বসানো হয়নি।');

    $model = embeddingSetting('model') ?: embedDefaultModel($provider);

    $texts = array_values(array_map(function ($t) {
        return trim(mb_substr((string)$t, 0, 6000));
    }, $texts));

    $payload = ['model' => $model, 'input' => $texts];
    $dims = embedDims();
    if ($dims > 0) $payload['dimensions'] = $dims;

    $res = llmTrackedJson('embed',$provider,$model,llmBase($provider, 'embed') . '/embeddings', [
        'content-type: application/json',
        'authorization: Bearer ' . $key,
    ], $payload, 15);

    $out = [];
    try {
        foreach (($res['data']??[]) as $d) {
            if (!isset($d['index']) || !is_array($d['embedding']??null) || !$d['embedding']) throw new Exception('Invalid embedding response');
            foreach ($d['embedding'] as $value) if (!is_numeric($value) || !is_finite((float)$value)) throw new Exception('Invalid embedding value');
            $out[$d['index']] = $d['embedding'];
        }
        if (count($out)!==count($texts)) throw new Exception('Incomplete embedding response');
    } catch (Throwable $e) {
        llmHealthPut('embed',$provider,$model,false,$e->getMessage());
        throw $e;
    }
    ksort($out);
    llmHealthPut('embed',$provider,$model,true);
    return array_values($out);
}

function embed($text) {
    $text = trim((string)$text);
    if ($text === '') return null;
    $v = embedCall([$text]);
    return $v[0] ?? null;
}

/** Qwen একবারে ১০টির বেশি নেয় না — তাই ছোট গুচ্ছে ভাগ করা */
function embedBatch(array $texts) {
    $p   = embeddingSetting('provider', 'openai');
    $max = ($p === 'qwen') ? 10 : (($p === 'gemini') ? 20 : 64);
    $out = [];
    foreach (array_chunk($texts, $max) as $chunk) {
        foreach (embedCall($chunk) as $v) $out[] = $v;
    }
    return $out;
}

function cosine($a, $b) {
    if (!$a || !$b || count($a)!==count($b)) return 0.0;
    $dot = 0.0; $na = 0.0; $nb = 0.0;
    $n = min(count($a), count($b));
    for ($i = 0; $i < $n; $i++) {
        $dot += $a[$i] * $b[$i];
        $na  += $a[$i] * $a[$i];
        $nb  += $b[$i] * $b[$i];
    }
    if ($na == 0 || $nb == 0) return 0.0;
    return $dot / (sqrt($na) * sqrt($nb));
}

/* ==========================================================
   প্রদানকারী বদলালে টের পাওয়া
   ========================================================== */

function storedEmbedTag() {
    try {
        $r = one("SELECT v FROM settings WHERE k='embed_tag'");
        return $r ? $r['v'] : '';
    } catch (Exception $e) { return ''; }
}

function saveEmbedTag() {
    q("INSERT INTO settings (k,v) VALUES ('embed_tag',?) ON DUPLICATE KEY UPDATE v=VALUES(v)",
      [embedProviderTag()]);
}

/** সংরক্ষিত সংখ্যাগুলো এখনকার প্রদানকারীর সাথে মেলে কি না */
function embedMismatch() {
    $stored = storedEmbedTag();
    if ($stored === '') return false;              // এখনো কিছু বানানো হয়নি
    return $stored !== embedProviderTag();
}
