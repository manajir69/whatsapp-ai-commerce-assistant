<?php
/** Owner corrections to bot wording. Stored separately from factual knowledge. */
function feedbackEnsure(){
    static $done=false;if($done)return true;
    q("CREATE TABLE IF NOT EXISTS reply_feedback (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        conv_id INT UNSIGNED NOT NULL DEFAULT 0,
        customer_question TEXT NOT NULL,
        bot_reply TEXT NOT NULL,
        preferred_reply TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_conv (conv_id), KEY idx_message (message_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    return $done=true;
}
function feedbackWords($text){
    $text=mb_strtolower((string)$text);$text=preg_replace('/[^\p{L}\p{N}]+/u',' ',$text);
    $skip=['আমি','আমার','এই','ওই','একটা','একটি','কি','কী','এর','the','a','an','is','are','this','that','please'];
    $out=[];foreach(preg_split('/\s+/u',trim($text)) as $w)if(mb_strlen($w)>1&&!in_array($w,$skip,true))$out[$w]=1;
    return $out;
}
function feedbackAdd($messageId,$convId,$question,$botReply,$preferred){
    feedbackEnsure();
    q('INSERT INTO reply_feedback(message_id,conv_id,customer_question,bot_reply,preferred_reply,created_at) VALUES(?,?,?,?,?,?)',
      [(int)$messageId,(int)$convId,mb_substr(trim($question),0,2000),mb_substr(trim($botReply),0,3000),mb_substr(trim($preferred),0,3000),nowStr()]);
    audit('reply_feedback_saved','#'.(int)$messageId);
}
function feedbackExamples($question,$limit=3){
    try{feedbackEnsure();$want=feedbackWords($question);if(!$want)return [];$rank=[];
        foreach(all('SELECT customer_question,bot_reply,preferred_reply FROM reply_feedback ORDER BY id DESC LIMIT 120') as $row){
            $have=feedbackWords($row['customer_question']);$common=count(array_intersect_key($want,$have));
            $score=$common/max(1,sqrt(count($want)*max(1,count($have))));if($score>0)$rank[]=[$score,$row];
        }
        usort($rank,function($a,$b){return $b[0]<=>$a[0];});$out=[];
        foreach(array_slice($rank,0,max(1,(int)$limit)) as $item)$out[]=['question'=>$item[1]['customer_question'],'avoid'=>$item[1]['bot_reply'],'preferred_style'=>$item[1]['preferred_reply']];
        return $out;
    }catch(Throwable $e){return [];}
}
