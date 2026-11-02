<?php
/** Server-side retrieval cache. No customer replies are reused by similarity. */
require_once __DIR__.'/llm.php';

function retrievalReady() {
    static $ready=null;
    if ($ready!==null) return $ready;
    try {
        try { q('SELECT cache_key FROM bot_cache LIMIT 1'); }
        catch (Throwable $e) { q("CREATE TABLE IF NOT EXISTS bot_cache (cache_key CHAR(64) PRIMARY KEY, payload MEDIUMTEXT NOT NULL, expires_at DATETIME NOT NULL, INDEX (expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }
        q('DELETE FROM bot_cache WHERE expires_at<? LIMIT 500',[gmdate('Y-m-d H:i:s')]);
        return $ready=true;
    } catch (Throwable $e) { audit('retrieval_cache_error','Cache unavailable; using uncached retrieval'); return $ready=false; }
}
function retrievalGet($key) {
    if (!retrievalReady()) return null;
    try {
        $r=one('SELECT payload FROM bot_cache WHERE cache_key=? AND expires_at>?', [hash('sha256',$key),gmdate('Y-m-d H:i:s')]);
        return $r ? json_decode($r['payload'],true) : null;
    } catch (Throwable $e) { return null; }
}
function retrievalPut($key,$value,$seconds=86400) {
    if (!retrievalReady()) return;
    try { q('INSERT INTO bot_cache (cache_key,payload,expires_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),expires_at=VALUES(expires_at)',
        [hash('sha256',$key),json_encode($value,JSON_UNESCAPED_UNICODE),gmdate('Y-m-d H:i:s',time()+$seconds)]);
    } catch (Throwable $e) { /* Optional cache writes must not prevent a reply. */ }
}
function retrievalVector($text) {
    if (!retrievalEnabled()) return null;
    $text=trim(mb_substr($text,0,6000));
    $key='vector:'.embedProviderTag().':'.$text;
    $cached=retrievalGet($key);
    if ($cached!==null) return $cached;
    try { $v=embed($text); if ($v) retrievalPut($key,$v,604800); return $v; }
    catch (Throwable $e) { audit('embedding_error','Embedding unavailable; lexical retrieval remains active'); return null; }
}
function retrievalEnabled() {
    try { return (bool)embeddingSetting('key'); }
    catch (Throwable $e) { return false; }
}
function retrievalDocument($table,$id,$variation,$text,$stored) {
    if (!in_array($table,['products','knowledge'],true)) throw new Exception('Invalid document table');
    $hash=hash('sha256',$text); $old=json_decode($stored ?: 'null',true);
    if (isset($old['tag'],$old['hash'],$old['vector']) && $old['tag']===embedProviderTag() && $old['hash']===$hash) return $old['vector'];
    static $newDocuments=0;
    if (PHP_SAPI!=='cli' && $newDocuments>=4) return null;
    $newDocuments++;
    // Only changed documents or a new embedding model need an API call.
    $v=retrievalVector($text);
    if ($v) {
        $payload=json_encode(['tag'=>embedProviderTag(),'hash'=>$hash,'vector'=>$v]);
        try {
            if ($table==='products') q('UPDATE products SET embedding=? WHERE id=? AND variation_id=?',[$payload,$id,$variation]);
            else q('UPDATE knowledge SET embedding=? WHERE id=?',[$payload,$id]);
        } catch (Throwable $e) { /* The fetched vector can still serve this request. */ }
    }
    return $v;
}
function retrievalProductText($p) {
    return implode(' | ',[$p['name'],$p['sku'],$p['categories'],$p['attributes']??'',mb_substr($p['description']??'',0,3000)]);
}
function retrievalLexical($question,$text) {
    $words=array_unique(preg_split('/[^\p{L}\p{N}]+/u',mb_strtolower($question),-1,PREG_SPLIT_NO_EMPTY));
    $hay=mb_strtolower($text); $hits=0; $count=0;
    foreach ($words as $w) { if (mb_strlen($w)<3) continue; $count++; if(mb_strpos($hay,$w)!==false)$hits++; }
    return $count ? $hits/$count : 0;
}
/** Retrieve candidates, never treat similarity as proof that a fact answers a question. */
function retrievalKnowledge($question,$product=null) {
    $ref=(string)($product['id']??0);
    $variant=!empty($product['variation_id']) ? $ref.':'.$product['variation_id'] : $ref;
    $rows=all("SELECT * FROM knowledge WHERE approved=1 AND (expires_at IS NULL OR expires_at>=CURDATE()) AND (scope='global' OR (scope='product' AND scope_ref IN (?,?)) OR scope='category') ORDER BY (question=?) DESC,id DESC LIMIT 500",[$ref,$variant,trim($question)]);
    $vec=null; $ranked=[]; $budget=3; $tag=embedProviderTag(); $enabled=retrievalEnabled();
    foreach ($rows as $r) {
        if ($r['scope']==='category' && (!$product || empty($r['scope_ref']) || mb_stripos($product['categories'],$r['scope_ref'])===false)) continue;
        $doc=$r['question'].' '.$r['answer'];
        $score=retrievalLexical($question,$doc);
        $stored=json_decode($r['embedding'] ?: 'null',true);
        $v=null;
        if (isset($stored['tag'],$stored['hash'],$stored['vector']) && $stored['tag']===$tag && $stored['hash']===hash('sha256',$doc)) $v=$stored['vector'];
        elseif ($budget>0 && $enabled) { $budget--; $v=retrievalDocument('knowledge',$r['id'],0,$doc,$r['embedding']); }
        if ($v) { if($vec===null)$vec=retrievalVector($question); $score=max($score,cosine($vec,$v)); }
        if (trim($question)===trim($r['question'])) $score=1;
        if ($score>=0.35) $ranked[]=['id'=>(int)$r['id'],'score'=>$score,'question'=>$r['question'],'answer'=>mb_substr($r['answer'],0,1800),'scope'=>$r['scope']];
    }
    usort($ranked,function($a,$b){return $b['score']<=>$a['score'];});
    return array_slice($ranked,0,4);
}
