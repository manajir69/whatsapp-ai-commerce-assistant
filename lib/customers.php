<?php
/** Customer database / lightweight CRM. */
require_once __DIR__.'/db.php';

function customerDbEnsure() {
    static $ready=false;if($ready)return true;
    $marker=one("SELECT v FROM settings WHERE k='customer_db_schema_v37'");
    if(!$marker){
    q("CREATE TABLE IF NOT EXISTS customer_profiles (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        conv_id INT UNSIGNED NOT NULL,
        wa_id VARCHAR(30) NOT NULL,
        name VARCHAR(200) NOT NULL DEFAULT '',
        location_text VARCHAR(1000) NOT NULL DEFAULT '',
        location_lat VARCHAR(40) NOT NULL DEFAULT '',
        location_lng VARCHAR(40) NOT NULL DEFAULT '',
        location_source VARCHAR(30) NOT NULL DEFAULT '',
        product_interest TEXT NULL,
        last_query TEXT NULL,
        first_seen DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        message_count INT UNSIGNED NOT NULL DEFAULT 0,
        marketing_status ENUM('unknown','opted_in','opted_out') NOT NULL DEFAULT 'unknown',
        consent_at DATETIME NULL,
        tags VARCHAR(500) NOT NULL DEFAULT '',
        notes TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uq_customer_conv (conv_id),
        UNIQUE KEY uq_customer_wa (wa_id),
        KEY idx_customer_last (last_seen),
        KEY idx_customer_marketing (marketing_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    q("CREATE TABLE IF NOT EXISTS customer_product_queries (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id BIGINT UNSIGNED NOT NULL,
        message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        product_name VARCHAR(500) NOT NULL DEFAULT '',
        sku VARCHAR(100) NOT NULL DEFAULT '',
        query_text TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_customer_query_message (customer_id,message_id),
        KEY idx_customer_query (customer_id,created_at),
        KEY idx_customer_product (product_id,variation_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    q("INSERT INTO settings(k,v) VALUES('customer_db_schema_v37',?) ON DUPLICATE KEY UPDATE v=VALUES(v)",[nowStr()]);
    }

    /* v40: one-time consent request evidence and campaign delivery history. */
    $v40=one("SELECT v FROM settings WHERE k='customer_marketing_schema_v40'");
    if(!$v40){
        $columns=[
            'consent_request_sent_at'=>"ALTER TABLE customer_profiles ADD COLUMN consent_request_sent_at DATETIME NULL AFTER consent_at",
            'consent_request_message_id'=>"ALTER TABLE customer_profiles ADD COLUMN consent_request_message_id VARCHAR(120) NOT NULL DEFAULT '' AFTER consent_request_sent_at",
            'consent_response_at'=>"ALTER TABLE customer_profiles ADD COLUMN consent_response_at DATETIME NULL AFTER consent_request_message_id",
            'consent_response'=>"ALTER TABLE customer_profiles ADD COLUMN consent_response VARCHAR(30) NOT NULL DEFAULT '' AFTER consent_response_at",
            'consent_source'=>"ALTER TABLE customer_profiles ADD COLUMN consent_source VARCHAR(40) NOT NULL DEFAULT '' AFTER consent_response",
        ];
        foreach($columns as $name=>$sql){
            $exists=one("SELECT 1 found FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_profiles' AND COLUMN_NAME=?",[$name]);
            if(!$exists)q($sql);
        }
        q("CREATE TABLE IF NOT EXISTS marketing_campaign_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id BIGINT UNSIGNED NOT NULL,
            wa_id VARCHAR(30) NOT NULL,
            delivery_mode VARCHAR(30) NOT NULL,
            message_text TEXT NOT NULL,
            template_name VARCHAR(200) NOT NULL DEFAULT '',
            wa_msg_id VARCHAR(120) NOT NULL DEFAULT '',
            status VARCHAR(30) NOT NULL,
            detail VARCHAR(500) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            KEY idx_campaign_customer (customer_id,created_at),
            KEY idx_campaign_status (status,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        q("CREATE TABLE IF NOT EXISTS marketing_campaign_queue (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            batch_key VARCHAR(40) NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            delivery_mode VARCHAR(30) NOT NULL,
            message_text TEXT NOT NULL,
            template_name VARCHAR(200) NOT NULL DEFAULT '',
            template_language VARCHAR(20) NOT NULL DEFAULT '',
            status VARCHAR(30) NOT NULL DEFAULT 'queued',
            created_at DATETIME NOT NULL,
            processed_at DATETIME NULL,
            KEY idx_campaign_queue (status,id),
            KEY idx_campaign_batch (batch_key,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        q("INSERT INTO settings(k,v) VALUES('customer_marketing_schema_v40',?) ON DUPLICATE KEY UPDATE v=VALUES(v)",[nowStr()]);
    }
    $ready=true;return true;
}

function customerExplicitLocation($body,$meta=[]) {
    if(is_array($meta) && ($meta['type']??'')==='location'){
        $lat=trim((string)($meta['latitude']??''));$lng=trim((string)($meta['longitude']??''));
        $label=trim((string)($meta['location_name']??'').' '.(string)($meta['address']??''));
        if($label==='')$label=trim($lat.($lat!==''&&$lng!==''?', ':'').$lng);
        return [$label,$lat,$lng,'whatsapp_location'];
    }
    $text=trim((string)$body);
    if(preg_match('/(?:^|\n)\s*(?:address|delivery address|location|ঠিকানা|ডেলিভারি ঠিকানা|লোকেশন)\s*[:：\-]\s*(.+)$/isu',$text,$m))
        return [mb_substr(trim($m[1]),0,1000),'','','chat'];
    $hasAddressWord=preg_match('/\b(?:road|rd\.?|street|lane|tower|floor|sadar|thana|upazila|district|dhaka|khulna|chattogram|sylhet|rajshahi|barishal|rangpur|mymensingh)\b|(?:রোড|সড়ক|সড়ক|জেলা|থানা|উপজেলা|ঢাকা|খুলনা|চট্টগ্রাম|সিলেট|রাজশাহী|বরিশাল|রংপুর|ময়মনসিংহ)/iu',$text);
    if($hasAddressWord && (substr_count($text,"\n")>=1 || preg_match('/(?:\+?88)?01[3-9]\d{8}/',$text)))
        return [mb_substr(preg_replace('/\s+/u',' ', $text),0,1000),'','','chat'];
    return ['','','',''];
}

function customerProfileCapture($convId,$messageId,$body,$meta,$createdAt) {
    try{
        customerDbEnsure();
        if(is_array($meta) && ($meta['type']??'')==='reaction')return;
        $conv=one('SELECT id,wa_id,name,is_owner,created_at,last_at FROM conversations WHERE id=?',[(int)$convId]);
        if(!$conv || !empty($conv['is_owner']))return;
        list($location,$lat,$lng,$source)=customerExplicitLocation($body,is_array($meta)?$meta:[]);
        q("INSERT INTO customer_profiles
          (conv_id,wa_id,name,location_text,location_lat,location_lng,location_source,last_query,first_seen,last_seen,message_count,created_at,updated_at)
          VALUES (?,?,?,?,?,?,?,?,?,?,1,?,?)
          ON DUPLICATE KEY UPDATE
          name=IF(VALUES(name)<>'',VALUES(name),name),
          location_text=IF(VALUES(location_text)<>'',VALUES(location_text),location_text),
          location_lat=IF(VALUES(location_lat)<>'',VALUES(location_lat),location_lat),
          location_lng=IF(VALUES(location_lng)<>'',VALUES(location_lng),location_lng),
          location_source=IF(VALUES(location_source)<>'',VALUES(location_source),location_source),
          last_query=VALUES(last_query),last_seen=VALUES(last_seen),message_count=message_count+1,updated_at=VALUES(updated_at)",
          [$conv['id'],$conv['wa_id'],$conv['name'],$location,$lat,$lng,$source,mb_substr((string)$body,0,3000),$conv['created_at']?:$createdAt,$createdAt,$createdAt,$createdAt]);
    }catch(Throwable $e){if(function_exists('audit'))audit('customer_db_error',mb_substr($e->getMessage(),0,200));}
}

function customerProfileRefresh($convId) {
    try{
        customerDbEnsure();
        $conv=one('SELECT * FROM conversations WHERE id=?',[(int)$convId]);if(!$conv || !empty($conv['is_owner']))return;
        $profile=one('SELECT * FROM customer_profiles WHERE conv_id=?',[(int)$convId]);if(!$profile)return;
        $latest=one("SELECT id,body,created_at FROM messages WHERE conv_id=? AND direction='in' ORDER BY id DESC LIMIT 1",[(int)$convId]);if(!$latest)return;
        $ticket=one("SELECT product_id,variation_id,question,created_at FROM tickets WHERE conv_id=? ORDER BY id DESC LIMIT 1",[(int)$convId]);
        $pid=(int)($conv['last_product']??0);$vid=(int)($conv['last_variation']??0);
        if(!$pid){$pid=(int)($ticket['product_id']??0);$vid=(int)($ticket['variation_id']??0);}
        if(!$pid)return;
        $product=one('SELECT name,sku FROM products WHERE id=? AND variation_id=?',[$pid,$vid]);
        $interest=$product?trim((string)$product['name'].((string)$product['sku']!==''?' · '.$product['sku']:'')):'Product #'.$pid;
        q('UPDATE customer_profiles SET product_interest=?,updated_at=? WHERE id=?',[$interest,nowStr(),$profile['id']]);
        $recentTicket=$ticket && abs(strtotime((string)$ticket['created_at'])-strtotime((string)$latest['created_at']))<=180;
        $query=trim((string)($recentTicket?$ticket['question']:$latest['body']));
        q("INSERT INTO customer_product_queries
          (customer_id,message_id,product_id,variation_id,product_name,sku,query_text,created_at)
          VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE product_id=VALUES(product_id),variation_id=VALUES(variation_id),product_name=VALUES(product_name),sku=VALUES(sku),query_text=VALUES(query_text)",
          [$profile['id'],$latest['id'],$pid,$vid,$product['name']??'',$product['sku']??'',mb_substr($query,0,3000),$latest['created_at']]);
    }catch(Throwable $e){if(function_exists('audit'))audit('customer_db_error',mb_substr($e->getMessage(),0,200));}
}

function customerDbBackfill() {
    customerDbEnsure();
    q("INSERT INTO customer_profiles
      (conv_id,wa_id,name,first_seen,last_seen,message_count,created_at,updated_at)
      SELECT c.id,c.wa_id,c.name,c.created_at,c.last_at,
      (SELECT COUNT(*) FROM messages m WHERE m.conv_id=c.id AND m.direction='in'),NOW(),NOW()
      FROM conversations c WHERE c.is_owner=0
      ON DUPLICATE KEY UPDATE name=IF(VALUES(name)<>'',VALUES(name),name),last_seen=VALUES(last_seen),
      message_count=GREATEST(message_count,VALUES(message_count)),updated_at=VALUES(updated_at)");
    foreach(all('SELECT id,conv_id FROM customer_profiles ORDER BY id') as $profile){
        $latest=one("SELECT body FROM messages WHERE conv_id=? AND direction='in' ORDER BY id DESC LIMIT 1",[$profile['conv_id']]);
        if($latest)q('UPDATE customer_profiles SET last_query=? WHERE id=?',[mb_substr((string)$latest['body'],0,3000),$profile['id']]);
        foreach(all("SELECT body,meta FROM messages WHERE conv_id=? AND direction='in' ORDER BY id DESC LIMIT 40",[$profile['conv_id']]) as $message){
            $meta=json_decode((string)($message['meta']??''),true);list($location,$lat,$lng,$source)=customerExplicitLocation($message['body'],is_array($meta)?$meta:[]);
            if($location!==''){q('UPDATE customer_profiles SET location_text=?,location_lat=?,location_lng=?,location_source=? WHERE id=?',[$location,$lat,$lng,$source,$profile['id']]);break;}
        }
        customerProfileRefresh($profile['conv_id']);
    }
}

function customerMarketingLabel($status){
    return ['unknown'=>'Permission unknown','opted_in'=>'Opted in','opted_out'=>'Opted out'][$status]??'Permission unknown';
}

function customerConsentRequestText(){
    return 'Demo Commerce থেকে WhatsApp-এ নতুন পণ্য, প্রাসঙ্গিক অফার ও product update পেতে চান? যেকোনো সময় STOP লিখে বন্ধ করতে পারবেন।';
}

/** One request per customer for the lifetime of the CRM record. */
function customerConsentRequestDue($limit=10,$delayMinutes=60){
    customerDbEnsure();$limit=max(1,min(30,(int)$limit));$delay=max(15,(int)$delayMinutes);
    $before=(clone now())->modify('-'.$delay.' minute')->format('Y-m-d H:i:s');
    $after=(clone now())->modify('-23 hour')->format('Y-m-d H:i:s');
    return all("SELECT cp.*,c.handoff,
        (SELECT MAX(m.created_at) FROM messages m WHERE m.conv_id=cp.conv_id AND m.direction='in') last_customer_at
        FROM customer_profiles cp JOIN conversations c ON c.id=cp.conv_id
        WHERE cp.marketing_status='unknown' AND cp.consent_request_sent_at IS NULL
          AND c.is_owner=0 AND c.handoff=0 AND cp.wa_id NOT LIKE 'TEST-%'
          AND NOT EXISTS (SELECT 1 FROM tickets t WHERE t.conv_id=cp.conv_id AND t.status IN ('queued','sent_owner'))
        HAVING last_customer_at IS NOT NULL AND last_customer_at<=? AND last_customer_at>=?
        ORDER BY last_customer_at ASC LIMIT ".$limit,[$before,$after]);
}

function customerSendConsentRequest($customer){
    customerDbEnsure();$id=(int)($customer['id']??0);$wa=(string)($customer['wa_id']??'');
    if(!$id || $wa==='' || !function_exists('waSendButtons'))return false;
    if(function_exists('channelOwnerWa') && in_array(preg_replace('/\D/','',$wa),[channelOwnerWa(),channelBusinessWa()],true))return false;
    $reserved=nowStr();
    $st=q("UPDATE customer_profiles SET consent_request_sent_at=?,consent_response='awaiting',consent_source='whatsapp_buttons',updated_at=? WHERE id=? AND marketing_status='unknown' AND consent_request_sent_at IS NULL",[$reserved,$reserved,$id]);
    if($st->rowCount()!==1)return false;
    $text=customerConsentRequestText();
    $mid=waSendButtons($wa,$text,[
        ['id'=>'mkt_consent:yes','title'=>'হ্যাঁ, চাই'],
        ['id'=>'mkt_consent:no','title'=>'না, চাই না'],
        ['id'=>'mkt_consent:later','title'=>'পরে জানাব'],
    ]);
    if(!$mid){
        q("UPDATE customer_profiles SET consent_request_sent_at=NULL,consent_response='',consent_source='',updated_at=? WHERE id=? AND consent_request_message_id=''",[nowStr(),$id]);
        audit('marketing_consent_request_failed','#'.$id);return false;
    }
    q('UPDATE customer_profiles SET consent_request_message_id=?,updated_at=? WHERE id=?',[$mid,nowStr(),$id]);
    if(function_exists('logMsg')){
        $logged=logMsg((int)$customer['conv_id'],'out',$text,['type'=>'marketing_consent_request','transport'=>'whatsapp','accepted'=>true,'one_time'=>true]);
        q('UPDATE messages SET wa_msg_id=? WHERE id=?',[$mid,$logged]);
    }
    audit('marketing_consent_request_sent','#'.$id);return true;
}

function customerConsentIntent($text,$buttonId=''){
    $button=(string)$buttonId;
    if($button==='mkt_consent:yes')return 'yes';
    if($button==='mkt_consent:no')return 'no';
    if($button==='mkt_consent:later')return 'later';
    $plain=mb_strtolower(trim(preg_replace('/\s+/u',' ',(string)$text)));
    if(in_array($plain,['stop','unsubscribe','বন্ধ','বন্ধ করুন','আর পাঠাবেন না','মেসেজ বন্ধ করুন'],true))return 'stop';
    return '';
}

/** Returns true when the inbound message was consumed as a consent response. */
function customerHandleConsentInbound($waId,$name,$text,$buttonId,$messageId=''){
    $intent=customerConsentIntent($text,$buttonId);customerDbEnsure();
    if($intent===''){
        $existing=one('SELECT cp.* FROM customer_profiles cp JOIN conversations c ON c.id=cp.conv_id WHERE c.wa_id=?',[$waId]);
        if(!$existing || ($existing['consent_response']??'')!=='awaiting')return false;
        $plain=mb_strtolower(trim(preg_replace('/\s+/u',' ',(string)$text)));
        if(in_array($plain,['হ্যাঁ','হ্যাঁ, চাই','হ্যা','yes','yes, i want'],true))$intent='yes';
        elseif(in_array($plain,['না','না, চাই না','no'],true))$intent='no';
        elseif(in_array($plain,['পরে','পরে জানাব','later'],true))$intent='later';
        else return false;
    }
    $conv=getConv($waId,$name);
    $logged=logMsg($conv['id'],'in',$text,['type'=>'marketing_consent_response','button_id'=>(string)$buttonId,'response'=>$intent]);
    if($messageId!=='')q('UPDATE messages SET wa_msg_id=? WHERE id=?',[$messageId,$logged]);
    $profile=one('SELECT * FROM customer_profiles WHERE conv_id=?',[$conv['id']]);if(!$profile)return true;
    $status=$intent==='yes'?'opted_in':(in_array($intent,['no','stop'],true)?'opted_out':'unknown');
    $consentAt=$status==='opted_in'?nowStr():null;
    q('UPDATE customer_profiles SET marketing_status=?,consent_at=?,consent_response_at=?,consent_response=?,consent_source=?,updated_at=? WHERE id=?',[$status,$consentAt,nowStr(),$intent,'whatsapp',nowStr(),$profile['id']]);
    $reply=$status==='opted_in'
        ? 'ধন্যবাদ। Demo Commerce-এর নতুন পণ্য ও প্রাসঙ্গিক অফারের WhatsApp update আপনি পাবেন। বন্ধ করতে যেকোনো সময় STOP লিখুন।'
        : ($status==='opted_out'?'ঠিক আছে। Demo Commerce-এর marketing message আর পাঠানো হবে না।':'ঠিক আছে। পরে চাইলে আমাদের জানাতে পারবেন।');
    $mid=waSendText($waId,$reply);$out=logMsg($conv['id'],'out',$reply,['type'=>'marketing_consent_confirmation','transport'=>'whatsapp','accepted'=>(bool)$mid]);
    if($mid)q('UPDATE messages SET wa_msg_id=? WHERE id=?',[$mid,$out]);
    audit('marketing_consent_response','#'.$profile['id'].' '.$intent);return true;
}

function customerCampaignLog($customer,$mode,$message,$template,$mid,$status,$detail=''){
    $customerId=(int)($customer['customer_id']??$customer['id']??0);
    q('INSERT INTO marketing_campaign_log(customer_id,wa_id,delivery_mode,message_text,template_name,wa_msg_id,status,detail,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[$customerId,(string)$customer['wa_id'],$mode,$message,$template,(string)$mid,$status,mb_substr((string)$detail,0,500),nowStr()]);
}

function customerQueueCampaign($audience,$targetId,$mode,$message,$templateName='',$templateLanguage='bn'){
    customerDbEnsure();
    if(!in_array($audience,['all','target'],true))throw new Exception('Choose all opted-in customers or one target customer.');
    if(!in_array($mode,['active_text','approved_template'],true))throw new Exception('Choose a valid delivery method.');
    $message=trim((string)$message);if($message==='' || mb_strlen($message)>1000)throw new Exception('Write a message between 1 and 1,000 characters.');
    if($mode==='approved_template'){
        if(!preg_match('/^[a-z0-9_]+$/',(string)$templateName))throw new Exception('Enter the approved Meta template name using lowercase letters, numbers and underscores.');
        if(!preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/',(string)$templateLanguage))throw new Exception('Enter a template language such as bn or en_US.');
    }
    $params=[];$where="cp.marketing_status='opted_in'";
    if($audience==='target'){$where.=' AND cp.id=?';$params[]=(int)$targetId;}
    $rows=all("SELECT cp.* FROM customer_profiles cp WHERE {$where} ORDER BY cp.id ASC",$params);
    if(!$rows)throw new Exception('No opted-in customer was found for this audience.');
    $batch=bin2hex(random_bytes(10));$queued=0;
    foreach($rows as $row){
        q('INSERT INTO marketing_campaign_queue(batch_key,customer_id,delivery_mode,message_text,template_name,template_language,status,created_at) VALUES(?,?,?,?,?,?,\'queued\',?)',[$batch,$row['id'],$mode,$message,(string)$templateName,(string)$templateLanguage,nowStr()]);$queued++;
    }
    audit('marketing_campaign_queued',$batch.' '.$mode.' '.$queued);return [$batch,$queued];
}

function customerProcessCampaignQueue($limit=10){
    customerDbEnsure();$limit=max(1,min(30,(int)$limit));$done=0;$failed=0;$skipped=0;
    foreach(all("SELECT q.*,cp.wa_id,cp.conv_id,cp.name,cp.marketing_status FROM marketing_campaign_queue q JOIN customer_profiles cp ON cp.id=q.customer_id WHERE q.status='queued' ORDER BY q.id ASC LIMIT ".$limit) as $item){
        $claim=q("UPDATE marketing_campaign_queue SET status='processing' WHERE id=? AND status='queued'",[$item['id']]);
        if($claim->rowCount()!==1)continue;
        $status='failed';$detail='';$mid='';
        if($item['marketing_status']!=='opted_in'){$status='skipped';$detail='Customer is no longer opted in.';$skipped++;}
        elseif($item['delivery_mode']==='active_text'){
            $last=one("SELECT created_at FROM messages WHERE conv_id=? AND direction='in' ORDER BY id DESC LIMIT 1",[$item['conv_id']]);
            $cutoff=(clone now())->modify('-23 hour')->format('Y-m-d H:i:s');
            if(!$last || $last['created_at']<$cutoff){$status='skipped';$detail='The free-text 24-hour service window is closed.';$skipped++;}
            else{
                $body=(string)$item['message_text'];
                if(mb_stripos($body,'STOP')===false && mb_stripos($body,'বন্ধ')===false)$body.="\n\nবন্ধ করতে STOP লিখুন।";
                $mid=waSendText($item['wa_id'],$body);$status=$mid?'accepted':'failed';
                if($mid){$logged=logMsg($item['conv_id'],'out',$body,['type'=>'marketing_campaign','transport'=>'whatsapp','accepted'=>true,'batch_key'=>$item['batch_key']]);q('UPDATE messages SET wa_msg_id=? WHERE id=?',[$mid,$logged]);$done++;}else{$detail='WhatsApp did not accept the message.';$failed++;}
            }
        }else{
            $mid=waSendMarketingTemplate($item['wa_id'],$item['template_name'],$item['template_language'],$item['message_text']);$status=$mid?'accepted':'failed';
            if($mid){$logged=logMsg($item['conv_id'],'out',$item['message_text'],['type'=>'marketing_template','transport'=>'whatsapp','accepted'=>true,'batch_key'=>$item['batch_key'],'template_name'=>$item['template_name']]);q('UPDATE messages SET wa_msg_id=? WHERE id=?',[$mid,$logged]);$done++;}else{$detail='Meta did not accept the approved template message.';$failed++;}
        }
        q('UPDATE marketing_campaign_queue SET status=?,processed_at=? WHERE id=?',[$status,nowStr(),$item['id']]);
        customerCampaignLog($item,$item['delivery_mode'],$item['message_text'],$item['template_name'],$mid,$status,$detail);
    }
    return ['accepted'=>$done,'failed'=>$failed,'skipped'=>$skipped];
}
