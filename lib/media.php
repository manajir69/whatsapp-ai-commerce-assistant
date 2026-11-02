<?php
/** Token-free WhatsApp image understanding: local OCR and product image hashes. */
require_once __DIR__.'/db.php';
require_once __DIR__.'/wa.php';

function mediaEnsureTable() {
    static $ready=null;
    if ($ready!==null) return $ready;
    try {
        try { q('SELECT product_id FROM product_media LIMIT 1'); }
        catch (Throwable $e) {
            q("CREATE TABLE IF NOT EXISTS product_media (
                product_id BIGINT UNSIGNED NOT NULL, variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                image_url VARCHAR(1000) NOT NULL, image_hash CHAR(64) NOT NULL,
                updated_at DATETIME NOT NULL, PRIMARY KEY(product_id,variation_id), INDEX(image_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        return $ready=true;
    } catch (Throwable $e) { audit('product_media_error','Product image index unavailable'); return $ready=false; }
}

function mediaAllowedUrl($url,$meta=false) {
    $p=parse_url((string)$url); if (($p['scheme']??'')!=='https' || empty($p['host'])) return false;
    $host=strtolower($p['host']);
    if ($meta) foreach (['facebook.com','fbcdn.net','fbsbx.com','whatsapp.net'] as $suffix)
        if ($host===$suffix || substr($host,-strlen('.'.$suffix))==='.'. $suffix) return true;
    $store=strtolower((string)parse_url((string)cfg('woo.url',''),PHP_URL_HOST));
    if ($store!=='' && ($host===$store || substr($host,-strlen('.'.$store))==='.'. $store)) return true;
    foreach ((array)cfg('media.image_hosts',[]) as $allowed) {
        $allowed=strtolower(trim($allowed));
        if ($allowed!=='' && ($host===$allowed || substr($host,-strlen('.'.$allowed))==='.'. $allowed)) return true;
    }
    return false;
}

function mediaFetch($url,$auth=false,$maxBytes=8388608) {
    if (!mediaAllowedUrl($url,$auth)) throw new Exception('Untrusted media URL');
    $body=''; $ch=curl_init($url); $headers=[];
    if ($auth) $headers[]='Authorization: Bearer '.cfg('wa.token');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>function($ch,$chunk) use (&$body,$maxBytes) {
            if (strlen($body)+strlen($chunk)>$maxBytes) return 0; $body.=$chunk; return strlen($chunk);
        }]);
    curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);$err=curl_error($ch);curl_close($ch);
    if ($code<200 || $code>=300 || $body==='' || strlen($body)>$maxBytes) throw new Exception('Media download failed'.($err?' (transport)':''));
    return ['bytes'=>$body,'mime'=>strtolower(trim(explode(';',$type)[0]))];
}

function waDownloadImage($mediaId) {
    if (!preg_match('/^[A-Za-z0-9._:-]{4,250}$/',(string)$mediaId)) throw new Exception('Invalid media id');
    $meta=waGet((string)$mediaId);
    if (empty($meta['url']) || !mediaAllowedUrl($meta['url'],true)) throw new Exception('Invalid Meta media URL');
    $file=mediaFetch($meta['url'],true);
    $mime=strtolower((string)($meta['mime_type']??$file['mime']));
    if (!in_array($mime,['image/jpeg','image/png','image/webp'],true)) throw new Exception('Unsupported image type');
    if (function_exists('getimagesizefromstring') && @getimagesizefromstring($file['bytes'])===false) throw new Exception('Invalid image data');
    $file['mime']=$mime; return $file;
}

/** Keep customer images on this server for 30 days so the authenticated dashboard can show them. */
function mediaStorageDir() {
    $dir=dirname(__DIR__).'/storage/media';
    if (!is_dir($dir) && !@mkdir($dir,0700,true)) return '';
    if (!is_writable($dir)) return '';
    $deny=$dir.'/.htaccess';
    if (!is_file($deny)) @file_put_contents($deny,"<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",LOCK_EX);
    $index=$dir.'/index.html';if(!is_file($index))@file_put_contents($index,'',LOCK_EX);
    return $dir;
}
function mediaStorageKey($mediaId) {
    $secret=(string)cfg('wa.app_secret',(string)cfg('console.password','best-interior'));
    return hash_hmac('sha256',(string)$mediaId,$secret);
}
function mediaStoreFile($mediaId,array $file) {
    $dir=mediaStorageDir();if($dir==='' || empty($file['bytes']))return false;
    return @file_put_contents($dir.'/'.mediaStorageKey($mediaId).'.bin',$file['bytes'],LOCK_EX)===strlen($file['bytes']);
}
function mediaStoredFile($mediaId) {
    $dir=mediaStorageDir();if($dir==='')return null;$path=$dir.'/'.mediaStorageKey($mediaId).'.bin';
    if(!is_file($path) || filesize($path)>8388608)return null;$bytes=@file_get_contents($path);if($bytes===false || $bytes==='')return null;
    $mime='';if(function_exists('getimagesizefromstring')){$info=@getimagesizefromstring($bytes);$mime=(string)($info['mime']??'');}
    if(!in_array($mime,['image/jpeg','image/png','image/webp'],true))return null;
    return ['bytes'=>$bytes,'mime'=>$mime];
}
function mediaDashboardFile($mediaId) {
    $file=mediaStoredFile($mediaId);if($file)return $file;
    $file=waDownloadImage($mediaId);mediaStoreFile($mediaId,$file);return $file;
}
function mediaCleanup($days=30) {
    $dir=mediaStorageDir();if($dir==='')return 0;$cutoff=time()-max(1,(int)$days)*86400;$removed=0;
    foreach((array)glob($dir.'/*.bin') as $path)if(is_file($path) && filemtime($path)<$cutoff && @unlink($path))$removed++;
    return $removed;
}

function mediaDhash($bytes) {
    if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor')) return '';
    $src=@imagecreatefromstring($bytes); if (!$src) return '';
    $small=imagecreatetruecolor(9,8);
    imagecopyresampled($small,$src,0,0,0,0,9,8,imagesx($src),imagesy($src));
    $hash='';
    for($y=0;$y<8;$y++) for($x=0;$x<8;$x++) {
        $a=imagecolorat($small,$x,$y);$b=imagecolorat($small,$x+1,$y);
        $ga=((($a>>16)&255)*30+(($a>>8)&255)*59+($a&255)*11);
        $gb=((($b>>16)&255)*30+(($b>>8)&255)*59+($b&255)*11);
        $hash.=$ga>$gb?'1':'0';
    }
    imagedestroy($small);imagedestroy($src);return $hash;
}
function mediaDhashRegion($src,$x,$y,$w,$h) {
    if ($w<8 || $h<8) return '';
    $small=imagecreatetruecolor(9,8);
    imagecopyresampled($small,$src,0,0,$x,$y,9,8,$w,$h);$hash='';
    for($row=0;$row<8;$row++) for($col=0;$col<8;$col++) {
        $a=imagecolorat($small,$col,$row);$b=imagecolorat($small,$col+1,$row);
        $ga=((($a>>16)&255)*30+(($a>>8)&255)*59+($a&255)*11);
        $gb=((($b>>16)&255)*30+(($b>>8)&255)*59+($b&255)*11);$hash.=$ga>$gb?'1':'0';
    }
    imagedestroy($small);return $hash;
}
/** Hash likely product-photo regions inside a screenshot, without a vision API. */
function mediaCandidateHashes($bytes) {
    if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor')) return [];
    $src=@imagecreatefromstring($bytes);if(!$src)return [];$w=imagesx($src);$h=imagesy($src);
    $regions=[[0,0,1,1],[0,0,1,.50],[0,0,1,.65],[0,0,.5,.5],[.5,0,.5,.5],
        [0,.15,1,.55],[0,.25,1,.5],[0,0,.67,.67],[.165,0,.67,.67],[.33,0,.67,.67]];
    $out=[];foreach($regions as $r){$hash=mediaDhashRegion($src,(int)($w*$r[0]),(int)($h*$r[1]),max(1,(int)($w*$r[2])),max(1,(int)($h*$r[3])));if($hash!=='')$out[$hash]=true;}
    imagedestroy($src);return array_keys($out);
}
function mediaHashDistance($a,$b) {
    if (strlen($a)!==64 || strlen($b)!==64) return 65;$n=0;
    for($i=0;$i<64;$i++) if($a[$i]!==$b[$i])$n++;return $n;
}

function mediaRememberProduct(array $product,array $variation=null) {
    if (!mediaEnsureTable()) return;
    $images=$variation['image']??null; $url=is_array($images)?($images['src']??''):'';
    if ($url==='') $url=(string)($product['images'][0]['src']??'');
    if ($url==='' || !mediaAllowedUrl($url,false)) return;
    $pid=(int)$product['id'];$vid=(int)($variation['id']??0);
    $old=one('SELECT image_url FROM product_media WHERE product_id=? AND variation_id=?',[$pid,$vid]);
    if ($old && $old['image_url']===$url) return;
    q('INSERT INTO product_media(product_id,variation_id,image_url,image_hash,updated_at) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE image_url=VALUES(image_url),image_hash=VALUES(image_hash),updated_at=VALUES(updated_at)',[$pid,$vid,$url,'',nowStr()]);
}
function mediaIndexPending($limit=5) {
    if (!mediaEnsureTable() || !function_exists('imagecreatefromstring')) return 0;
    $limit=max(1,min(500,(int)$limit));$done=0;
    foreach(all("SELECT product_id,variation_id,image_url FROM product_media WHERE image_hash='' ORDER BY updated_at ASC LIMIT ".$limit) as $row) {
        try {
            $file=mediaFetch($row['image_url'],false,6291456);$hash=mediaDhash($file['bytes']);
            if ($hash==='') throw new Exception('Image decoding unavailable');
            q('UPDATE product_media SET image_hash=?,updated_at=? WHERE product_id=? AND variation_id=?',[$hash,nowStr(),$row['product_id'],$row['variation_id']]);$done++;
        } catch(Throwable $e) { q('UPDATE product_media SET updated_at=? WHERE product_id=? AND variation_id=?',[nowStr(),$row['product_id'],$row['variation_id']]); }
    }
    return $done;
}
function mediaWarmNextBatch($limit=5) {
    $limit=max(1,min(10,(int)$limit));
    if (!mediaEnsureTable()) return ['queued'=>0,'indexed'=>0];
    $pending=(int)(one("SELECT COUNT(*) n FROM product_media WHERE image_hash='' ")['n']??0);$queued=0;
    if ($pending<$limit && function_exists('wooRequest') && function_exists('catalogStore')) {
        $setting=one("SELECT v FROM settings WHERE k='media_catalog_page'");$page=max(1,(int)($setting['v']??1));
        try {
            $products=wooRequest('GET','products',null,['page'=>$page,'per_page'=>10,'status'=>'publish','orderby'=>'id','order'=>'asc']);
            if (!$products && $page>1) { $page=1;$products=wooRequest('GET','products',null,['page'=>1,'per_page'=>10,'status'=>'publish','orderby'=>'id','order'=>'asc']); }
            $GLOBALS['skip_product_embedding']=true;
            try { foreach($products as $product){catalogStore($product);$queued++;} }
            finally { unset($GLOBALS['skip_product_embedding']); }
            q("INSERT INTO settings(k,v) VALUES('media_catalog_page',?) ON DUPLICATE KEY UPDATE v=VALUES(v)",[(string)($page+1)]);
        } catch(Throwable $e) { unset($GLOBALS['skip_product_embedding']);audit('media_catalog_error','Could not queue WooCommerce images'); }
    }
    return ['queued'=>$queued,'indexed'=>mediaIndexPending($limit)];
}

function mediaMatchProduct($bytes) {
    $hashes=mediaCandidateHashes($bytes);if(!$hashes || !mediaEnsureTable())return null;
    $best=null;$distance=65;$second=65;
    foreach(all("SELECT m.image_hash,p.* FROM product_media m JOIN products p ON p.id=m.product_id AND p.variation_id=m.variation_id WHERE m.image_hash<>'' ORDER BY m.updated_at DESC LIMIT 2000") as $r) {
        $d=65;foreach($hashes as $hash)$d=min($d,mediaHashDistance($hash,$r['image_hash']));
        if($d<$distance){$second=$distance;$distance=$d;$best=$r;}elseif($d<$second)$second=$d;
    }
    return $best && $distance<=8 && $second>=$distance+3 ? $best : null;
}

function mediaTesseractPath() {
    foreach(['/usr/bin/tesseract','/usr/local/bin/tesseract','/opt/homebrew/bin/tesseract'] as $p) if(is_executable($p))return $p;
    return '';
}
function mediaOcr($bytes,$mime) {
    $bin=mediaTesseractPath();
    if ($bin==='' || !function_exists('exec')) return '';
    $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??'img';
    $path=tempnam(sys_get_temp_dir(),'bi-img-');$named=$path.'.'.$ext;
    if (!@rename($path,$named) || file_put_contents($named,$bytes,LOCK_EX)!==strlen($bytes)) { @unlink($path);@unlink($named);return ''; }
    try {
        $out=[];$code=1;
        exec(escapeshellarg($bin).' '.escapeshellarg($named).' stdout -l eng+ben 2>/dev/null',$out,$code);
        if ($code!==0) { $out=[];exec(escapeshellarg($bin).' '.escapeshellarg($named).' stdout -l eng 2>/dev/null',$out,$code); }
        $text=preg_replace('/\s+/u',' ',trim(implode("\n",$out)));
        return mb_substr($text,0,3000);
    } finally { @unlink($named); }
}

function mediaTicketMarker($mediaId) { return '[[WA_MEDIA:'.$mediaId.']]'; }
function mediaExtractMarker($text) {
    $id='';if(preg_match('/\[\[WA_MEDIA:([A-Za-z0-9._:-]{4,250})\]\]/',(string)$text,$m))$id=$m[1];
    return [$id,trim(preg_replace('/\[\[WA_MEDIA:[A-Za-z0-9._:-]{4,250}\]\]/','',(string)$text))];
}

function mediaRecentQuestion($convId) {
    $cutoff=(clone now())->modify('-15 minutes')->format('Y-m-d H:i:s');
    foreach(all("SELECT body FROM messages WHERE conv_id=? AND direction='in' AND created_at>=? ORDER BY id DESC LIMIT 8",[(int)$convId,$cutoff]) as $row){
        $text=trim((string)($row['body']??''));
        if($text==='' || preg_match('/^\[[^\]]+\]$/u',$text) || mb_strlen($text)>1000)continue;
        $low=mb_strtolower($text);$greeting=false;foreach(['hi','hello','salam','সালাম','আসসালাম'] as $g)if($low===$g){$greeting=true;break;}
        if(!$greeting)return $text;
    }
    return '';
}
function mediaRecentLanguageSample($convId){
    foreach(all("SELECT body FROM messages WHERE conv_id=? AND direction='in' ORDER BY id DESC LIMIT 12",[(int)$convId]) as $row){
        $text=trim((string)($row['body']??''));if($text!=='' && !preg_match('/^\[[^\]]+\]$/u',$text))return $text;
    }
    return '';
}
function mediaFallbackIsEnglish($text) {
    if(preg_match('/[\x{0980}-\x{09FF}]/u',(string)$text))return false;
    $low=' '.preg_replace('/[^a-z]+/',' ',strtolower((string)$text)).' ';
    foreach([' ami ',' apni ',' tumi ',' ki ',' keno ',' kivabe ',' kibhabe ',' ache ',' ace ',' ase ',' ager ',' eta ',' etar ',' oi ',' chai ',' koto ',' dam ',' pabo ',' den ',' dekhun ',' bolen '] as $word)
        if(strpos($low,$word)!==false)return false;
    return trim((string)$text)!=='';
}

function mediaUnderstand($mediaId,$caption='') {
    $file=waDownloadImage($mediaId);mediaStoreFile($mediaId,$file);
    $ocr=mediaOcr($file['bytes'],$file['mime']);$product=mediaMatchProduct($file['bytes']);
    $parts=[];if(trim($caption)!=='')$parts[]='Customer question attached to this image: '.trim($caption);
    if($product)$parts[]='Matched product SKU: '.$product['sku'].' URL: '.$product['permalink'];
    if($ocr!=='')$parts[]='Text read from image: '.$ocr;
    return ['text'=>implode("\n",$parts),'product'=>$product,'ocr'=>$ocr,'recognized'=>(bool)$product || $ocr!==''];
}

function mediaCapabilities() {
    return ['download'=>function_exists('curl_init'),'ocr'=>mediaTesseractPath()!=='' && function_exists('exec'),'hash'=>function_exists('imagecreatefromstring'),'storage'=>mediaStorageDir()!==''];
}

/** Authenticated dashboard video download. */
function mediaDashboardVideo($mediaId){
    if(!preg_match('/^[A-Za-z0-9._:-]{4,250}$/',(string)$mediaId))throw new Exception('Invalid media id');
    $dir=mediaStorageDir();$path=$dir!==''?$dir.'/'.mediaStorageKey($mediaId).'.video.bin':'';
    if($path!=='' && is_file($path) && filesize($path)<=16777216){
        $bytes=file_get_contents($path);$mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if(in_array($mime,['video/mp4','video/3gpp'],true))return ['bytes'=>$bytes,'mime'=>$mime];
    }
    $meta=waGet((string)$mediaId);$mime=(string)($meta['mime_type']??'');
    if(!in_array($mime,['video/mp4','video/3gpp'],true))throw new Exception('Unsupported video type');
    $file=mediaFetch((string)($meta['url']??''),true,16777216);$file['mime']=$mime;
    if($path!=='')file_put_contents($path,$file['bytes'],LOCK_EX);
    return $file;
}
