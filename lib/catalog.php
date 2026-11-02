<?php
/**
 * ক্যাটালগ — চাহিদা অনুযায়ী কাজ করে।
 *
 * আগে থেকে সব পণ্য জমিয়ে রাখা হয় না। গ্রাহক যে পণ্য নিয়ে জিজ্ঞেস করে,
 * সেটাই তখন WooCommerce থেকে টাটকা এনে স্থানীয় টেবিলে রেখে দেওয়া হয়।
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/woo.php';
require_once __DIR__ . '/retrieval.php';
require_once __DIR__ . '/media.php';

/* ==========================================================
   স্থানীয় টেবিলে জমা রাখা
   ========================================================== */

/**
 * Woo-র একটা পণ্য (ও চাইলে variation) স্থানীয় টেবিলে বসানো।
 * @return array  products টেবিলের সারি
 */
function catalogStore(array $p, array $v = null) {
    if (($p['status']??'publish')!=='publish' || ($v && ($v['status']??'publish')!=='publish')) throw new Exception('Product is not published');
    $cats = implode(', ', array_map(function ($c) { return $c['name']; }, $p['categories'] ?? []));
    $desc = trim(strip_tags(($p['short_description'] ?? '') . ' ' . ($p['description'] ?? '')));
    $desc = preg_replace('/\s+/u', ' ', mb_substr($desc, 0, 4000));

    $row = [
        'id'           => (int)$p['id'],
        'variation_id' => 0,
        'parent_id'    => 0,
        'type'         => (string)($p['type'] ?? 'simple'),
        'sku'          => (string)($p['sku'] ?? ''),
        'name'         => catalogClean($p['name']),
        'attributes'   => json_encode($p['attributes'] ?? [], JSON_UNESCAPED_UNICODE),
        'price'        => (string)($p['price'] ?? ''),
        'permalink'    => (string)($p['permalink'] ?? ''),
        'categories'   => $cats,
        'description'  => $desc,
        'woo_stock'    => (string)($p['stock_status'] ?? ''),
    ];

    if ($v) {
        $attr = [];
        foreach ($v['attributes'] ?? [] as $a) $attr[$a['name']] = $a['option'];
        $row['variation_id'] = (int)$v['id'];
        $row['parent_id']    = (int)$p['id'];
        $row['type']         = 'variation';
        $row['sku']          = (string)($v['sku'] ?? '');
        $row['name']         = catalogClean($p['name']) . ($attr ? ' — ' . implode(', ', $attr) : '');
        $row['attributes']   = json_encode($attr, JSON_UNESCAPED_UNICODE);
        $row['price']        = (string)($v['price'] ?? '');
        $row['description']  = trim(strip_tags($v['description'] ?? '')) ?: $desc;
        $row['woo_stock']    = (string)($v['stock_status'] ?? '');
    }

    q("INSERT INTO products
        (id,variation_id,parent_id,type,sku,name,attributes,price,permalink,categories,description,woo_stock,synced_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE
        parent_id=VALUES(parent_id), type=VALUES(type), sku=VALUES(sku), name=VALUES(name),
        attributes=VALUES(attributes), price=VALUES(price), permalink=VALUES(permalink),
        categories=VALUES(categories), description=VALUES(description),
        woo_stock=VALUES(woo_stock), synced_at=VALUES(synced_at)",
      [$row['id'], $row['variation_id'], $row['parent_id'], $row['type'], $row['sku'], $row['name'],
       $row['attributes'], $row['price'], $row['permalink'], $row['categories'],
       $row['description'], $row['woo_stock'], nowStr()]);

    q("INSERT IGNORE INTO stock_state (product_id,variation_id,state,updated_at) VALUES (?,?,'unknown',?)",
      [$row['id'], $row['variation_id'], nowStr()]);

    $saved=one("SELECT * FROM products WHERE id=? AND variation_id=?", [$row['id'], $row['variation_id']]);
    if ($saved && empty($GLOBALS['skip_product_embedding']) && retrievalEnabled()) retrievalDocument('products',$saved['id'],$saved['variation_id'],retrievalProductText($saved),$saved['embedding']??null);
    if ($saved) mediaRememberProduct($p,$v);
    return $saved;
}

/* ==========================================================
   খুঁজে বের করা
   ========================================================== */

/** স্থানীয় সারিটা কি এখনো টাটকা? (ডিফল্ট ১০ মিনিট) */
function catalogFresh($row, $minutes = null) {
    if (!$row || empty($row['synced_at'])) return false;
    $minutes = $minutes === null ? (int)cfg('rules.catalog_cache_min', 10) : $minutes;
    if ($minutes <= 0) return false;
    try { $stamp=new DateTimeImmutable($row['synced_at'],new DateTimeZone(cfg('rules.timezone','Asia/Dhaka'))); }
    catch (Throwable $e) { return false; }
    return ($stamp->getTimestamp() + $minutes * 60) > time();
}

/** বার্তায় দেখানোর জন্য ছোট নাম */
function catalogShortName($name) {
    $n = catalogClean($name);
    /* "Title | ব্যাখ্যা — মাপ" ধরনের নাম থেকে প্রথম অংশটুকু */
    foreach (['|', '—', '–'] as $sep) {
        $pos = mb_strpos($n, $sep);
        if ($pos !== false && $pos > 8) { $n = trim(mb_substr($n, 0, $pos)); break; }
    }
    if (mb_strlen($n) > 48) $n = trim(mb_substr($n, 0, 45)) . '…';
    return $n;
}

/** পণ্যের পুরো নাম + বাছাই করা অপশন (থাকলে) */
function catalogLabel($p) {
    $n = catalogShortName($p['name']);
    if (!empty($p['attributes'])) {
        $a = json_decode($p['attributes'], true);
        if ($a && !array_filter($a,'is_array')) $n .= ' (' . implode(', ', $a) . ')';
    }
    return $n;
}

/** বার্তা থেকে পণ্যের লিংক (slug) */
function catalogByLink($text) {
    if (!preg_match('~/product/([a-z0-9\-_%]+)~i', $text, $m)) return null;
    $slug = rawurldecode($m[1]);

    /* আগে নিজের ঘরে দেখি */
    $local = one("SELECT * FROM products WHERE variation_id=0 AND permalink LIKE ? LIMIT 1",
                 ['%/' . $slug . '/%']);
    if (catalogFresh($local,1440)) return $local;

    try {
        $rows = wooRequest('GET', 'products', null, ['slug' => $slug, 'per_page' => 1,'status'=>'publish']);
    } catch (Exception $e) { return null; }
    if (!$rows) return null;
    return catalogStore($rows[0]);
}

/** বার্তা থেকে সম্ভাব্য কোড (SKU) — কড়াভাবে, নাহলে সাধারণ শব্দও কোড ভেবে বসে */
function catalogSkuCandidates($text) {
    $out = [];

    /* "কোড: XXXX" ধরনের স্পষ্ট উল্লেখ — সবচেয়ে নির্ভরযোগ্য */
    if (preg_match('/(?:কোড|code|sku)\s*[:\-–]?\s*([A-Za-z0-9][A-Za-z0-9\-_\/]{3,31})/iu', $text, $m)) {
        $out[] = strtoupper($m[1]);
    }

    /* নাহলে: অক্ষর ও সংখ্যা — দুটোই আছে এমন শব্দই কেবল কোড হতে পারে */
    if (preg_match_all('/\b([A-Za-z0-9][A-Za-z0-9\-_\/]{3,31})\b/u', $text, $m)) {
        foreach ($m[1] as $c) {
            if (!preg_match('/[0-9]/', $c))  continue;   // সংখ্যা নেই → শব্দ
            if (!preg_match('/[A-Za-z]/', $c)) continue; // অক্ষর নেই → শুধু মাপ/সংখ্যা
            $out[] = strtoupper($c);
        }
    }

    $out = array_values(array_unique($out));
    return array_slice($out, 0, 3);   // বেশি হলে অযথা দেরি হয়
}

/** কোড দিয়ে পণ্য — parent ও variation দুটোতেই খোঁজে */
function catalogBySku($sku) {
    /* ০) আগে নিজের ঘরে — টাটকা থাকলে বাইরে যাওয়ার দরকার নেই */
    $local = one("SELECT * FROM products WHERE sku=? LIMIT 1", [$sku]);
    if (catalogFresh($local,1440)) return $local;

    // ১) মূল পণ্যের কোড
    try {
        $rows = wooRequest('GET', 'products', null, ['sku' => $sku, 'per_page' => 1,'status'=>'publish']);
        if ($rows) return catalogStore($rows[0]);
    } catch (Exception $e) { /* এগিয়ে যাই */ }

    // ২) আগে জমা থাকলে সেখান থেকে variation-এর হদিস
    if ($local && $local['variation_id']) {
        try {
            $p = wooRequest('GET', 'products/' . $local['id']);
            $v = wooRequest('GET', 'products/' . $local['id'] . '/variations/' . $local['variation_id']);
            return catalogStore($p, $v);
        } catch (Exception $e) { return $local; }
    }
    if ($local) return $local;

    // ৩) variation-এর কোড — Woo-র সাধারণ খোঁজায় মূল পণ্যটা পাওয়া যায় কি না
    try {
        $rows = wooRequest('GET', 'products', null, ['search' => $sku, 'per_page' => 5,'status'=>'publish']);
    } catch (Exception $e) { return null; }

    foreach ($rows as $p) {
        if (($p['type'] ?? '') !== 'variable') continue;
        try {
            foreach (wooGetVariations($p['id']) as $v) {
                if (strcasecmp((string)($v['sku'] ?? ''), $sku) === 0) {
                    return catalogStore($p, $v);
                }
            }
        } catch (Exception $e) { /* পরেরটা দেখি */ }
    }
    return null;
}

/** এক দফা খোঁজা */
function catalogSearchOnce($query, $limit = 5) {
    $query = trim(preg_replace('/\s+/u', ' ',
             preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $query)));
    if (mb_strlen($query) < 3) return [];
    $words=preg_split('/\s+/u',$query);
    $where=[]; $params=[];
    foreach ($words as $word) { $where[]="CONCAT(name,' ',sku,' ',categories) LIKE ?"; $params[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$word).'%'; }
    // All search words must match locally before falling back to WooCommerce.
    $where=array_map(function($s){return $s." ESCAPE '!'";},$where);
    $local=all('SELECT * FROM products WHERE variation_id=0 AND '.implode(' AND ',$where).' ORDER BY synced_at DESC LIMIT '.max(1,min(8,(int)$limit)),$params);
    if ($local) return $local;
    // Semantic candidates still need selection/verification by the answer controller.
    $vec=retrievalVector($query); $scored=[]; $tag=$vec ? embedProviderTag() : '';
    if ($vec) foreach (all('SELECT * FROM products WHERE variation_id=0 AND embedding IS NOT NULL ORDER BY synced_at DESC LIMIT 1000') as $r) {
        $e=json_decode($r['embedding'],true);
        if (!isset($e['tag'],$e['vector'],$e['hash']) || $e['tag']!==$tag || $e['hash']!==hash('sha256',retrievalProductText($r))) continue;
        $sim=cosine($vec,$e['vector']);
        if ($sim>=0.72) $scored[]=['score'=>$sim,'row'=>$r];
    }
    if ($scored) { usort($scored,function($a,$b){return $b['score']<=>$a['score'];}); return array_map(function($s){$s['row']['_semantic']=true;return $s['row'];},array_slice($scored,0,$limit)); }
    if (retrievalGet('catalog-miss:'.$query)!==null) return [];
    try {
        $rows = wooRequest('GET', 'products', null,
                 ['search' => $query, 'per_page' => $limit, 'status' => 'publish']);
    } catch (Exception $e) { return []; }
    $out = [];
    foreach ($rows as $p) $out[] = catalogStore($p);
    if (!$out) retrievalPut('catalog-miss:'.$query,true,120);
    return $out;
}

/**
 * শব্দ ধরে খোঁজা — লম্বা বাক্যে Woo কিছুই ফেরত দেয় না,
 * তাই ধাপে ধাপে শব্দ কমিয়ে চেষ্টা করা হয়।
 */
function catalogSearch($query, $limit = 5) {
    $words = is_array($query) ? $query : catalogKeywords($query);
    if (!$words) return [];

    /* লম্বা শব্দগুলোই বেশি স্বতন্ত্র */
    $ranked = $words;
    usort($ranked, function ($a, $b) { return mb_strlen($b) - mb_strlen($a); });

    $tries = [];
    if (count($words) <= 4) $tries[] = $words;
    else                    $tries[] = array_slice($ranked, 0, 4);
    $tries[] = array_slice($ranked, 0, 2);
    $tries[] = array_slice($ranked, 0, 1);
    foreach (array_slice($ranked,0,4) as $word) $tries[] = [$word];

    $seen = [];
    foreach ($tries as $t) {
        $key = implode(' ', $t);
        if ($key === '' || isset($seen[$key])) continue;
        $seen[$key] = true;
        $hits = catalogSearchOnce($key, $limit);
        if ($hits) return $hits;
    }
    return [];
}

/** নাম পরিষ্কার করা (&amp; ইত্যাদি) */
function catalogClean($s) {
    return trim(html_entity_decode((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/** খোঁজার অযোগ্য সাধারণ শব্দ — এগুলো বাদ দিয়ে আসল শব্দ বের করা */
function catalogKeywords($text) {
    $stop = ['etar','eta','ei','oi','ota','otar','seta','setar','er','ta','ti','tar',
             'stock','ace','ase','achhe','ki','kina','koto','kto','dam','daam','price',
             'ache','na','ar','o','ja','je','ki','vai','bhai','plz','please','janan','bolen',
             'এই','ওই','এটা','এটার','ওটা','সেটা','স্টক','আছে','কি','কিনা','দাম','কত','ভাই',
             'জানান','বলেন','আছে','কেমন','একটু','দয়া','করে'];
    $text = preg_replace('/https?:\/\/\S+/u', ' ', $text);
    $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
    $out = [];
    foreach (preg_split('/\s+/u', trim($text)) as $w) {
        $lw = mb_strtolower($w);
        if ($lw === '' || in_array($lw, $stop, true)) continue;
        $acronym=(bool)preg_match('/^[A-Z0-9]{2,5}$/',$w);
        if (mb_strlen($lw) < 3 && !$acronym) continue;
        $out[] = $lw;
    }
    return $out;
}


/** কোনো লেখা মিলিয়ে variation বেছে নেওয়া (রঙ/সাইজ/কোড) */
function catalogPickVariation($parentId, $text) {
    $t = mb_strtolower(catalogClean($text));
    $t = preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}\s\/]+/u', ' ', $t));
    if (trim($t) === '') return null;

    $vars = all("SELECT * FROM products WHERE parent_id=?", [$parentId]);
    if (!$vars) $vars = catalogVariations($parentId);
    if (!$vars) return null;

    $best = null; $bestLen = 0;
    foreach ($vars as $v) {
        $cands = [];
        if (!empty($v['sku'])) $cands[] = $v['sku'];
        foreach ((array)json_decode($v['attributes'], true) as $opt) $cands[] = $opt;

        foreach ($cands as $c) {
            $c = mb_strtolower(preg_replace('/\s+/u', ' ',
                 preg_replace('/[^\p{L}\p{N}\s\/]+/u', ' ', catalogClean($c))));
            $c = trim($c);
            if ($c === '' || mb_strlen($c) < 3) continue;
            if (mb_strpos($t, $c) !== false && mb_strlen($c) > $bestLen) {
                $best = $v; $bestLen = mb_strlen($c);
            }
        }
    }
    return $best;
}

/** ফলাফলটা সত্যিই প্রশ্নের সাথে মেলে কি না */
function catalogMatches(array $p, array $words) {
    $hay = mb_strtolower($p['name'] . ' ' . $p['sku'] . ' ' . $p['categories']);
    foreach ($words as $w) {
        if (mb_strlen($w) >= 4 && mb_strpos($hay, $w) !== false) return true;
    }
    return false;
}

/**
 * প্রধান প্রবেশপথ।
 * @return array [ $product|null, $how, $options[] ]
 *   how: link | sku | context | search | ambiguous | none
 */
function catalogResolve($text, $conv = null) {
    if ($p = catalogByLink($text))  return [$p, 'link', []];
    if (preg_match('~/product/[^\s]+~i',$text)) return [null,'none',[]];
    bisMark('  · লিংক দেখা');

    $skus = catalogSkuCandidates($text);
    foreach ($skus as $sku) {
        if ($p = catalogBySku($sku)) { bisMark('  · কোড ' . $sku); return [$p, 'sku', []]; }
    }
    if ($skus) bisMark('  · কোড খোঁজা (' . count($skus) . ' টি, মেলেনি)');
    if ($skus) return [null,'none',[]];

    $words = catalogKeywords($text);

    /* নতুন কোনো পণ্যের নাম নেই → আগের কথার পণ্যটাই বোঝাচ্ছে */
    $ctx = null;
    if ($conv && !empty($conv['last_product'])) {
        $ctx = one("SELECT * FROM products WHERE id=? AND variation_id=? LIMIT 1",
                   [$conv['last_product'], $conv['last_variation']]);
    }
    /* আগের পণ্যটি variable — গ্রাহক কি কোনো অপশন বেছে দিলেন? */
    if ($ctx) {
        $parent = $ctx['parent_id'] ? (int)$ctx['parent_id'] : (int)$ctx['id'];
        if ($ctx['type'] === 'variable' || $ctx['parent_id']) {
            if ($v = catalogPickVariation($parent, $text)) { bisMark('  · অপশন বাছাই'); return [$v, 'variation', []]; }
            bisMark('  · অপশন মেলেনি');
        }
    }

    if ($ctx && !$words) return [$ctx, 'context', []];

    /* শব্দ আছে, কিন্তু সেগুলো আগের পণ্যেরই কথা বলছে? */
    if ($ctx && catalogMatches($ctx, $words)) return [$ctx, 'context', []];

    if ($words) {
        $hits = [];
        bisMark('  · খোঁজা শুরু');
        foreach (catalogSearch($words) as $h) {
            $hits[] = $h;
        }
        if (count($hits) === 1 && empty($hits[0]['_semantic'])) return [$hits[0], 'search', []];
        if (count($hits) === 1) return [null,'ambiguous',$hits];
        if (count($hits) > 1)   return [null, 'ambiguous', $hits];
    }

    return [null, 'none', []];
}

/** Price/stock expire quickly; static facts may stay cached for one day. Fail closed. */
function catalogVerified($row,$live=false,$force=false) {
    if (!$row) return null;
    if (!$force && catalogFresh($row,$live ? 5 : 1440)) return $row;
    try {
        $p=wooRequest('GET','products/'.(int)$row['id']);
        if (($p['status']??'')!=='publish') return null;
        $v=null;
        if (!empty($row['variation_id'])) {
            $v=wooRequest('GET','products/'.(int)$row['id'].'/variations/'.(int)$row['variation_id']);
            if (($v['status']??'publish')!=='publish') return null;
        }
        return catalogStore($p,$v);
    } catch (Throwable $e) { audit('catalog_refresh_error','Could not verify product '.$row['id']); return null; }
}

/** কোনো variable পণ্যের অপশনগুলো (variation) */
function catalogVariations($productId) {
    $local=all('SELECT * FROM products WHERE parent_id=?',[$productId]);
    if ($local && !array_filter($local,function($r){return !catalogFresh($r,5);})) return $local;
    try {
        $p  = wooRequest('GET', 'products/' . $productId);
        $vs = wooGetVariations($productId);
    } catch (Exception $e) { return []; }
    $out = [];
    foreach ($vs as $v) if (($v['status']??'publish')==='publish' && ($p['status']??'publish')==='publish') $out[] = catalogStore($p, $v);
    return $out;
}
