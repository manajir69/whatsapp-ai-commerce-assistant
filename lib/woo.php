<?php
/** WooCommerce REST API ক্লায়েন্ট */

require_once __DIR__ . '/db.php';
$healthFile=__DIR__.'/health.php';if(file_exists($healthFile))require_once $healthFile;

function wooRequest($method, $path, $body = null, $query = []) {
    $base = rtrim(cfg('woo.url'), '/') . '/wp-json/wc/v3/' . ltrim($path, '/');
    if ($query) $base .= (strpos($base, '?') === false ? '?' : '&') . http_build_query($query);

    $ch = curl_init($base);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_USERPWD        => cfg('woo.key') . ':' . cfg('woo.secret'),
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) { if(function_exists('connectionHealthRuntime'))connectionHealthRuntime('woo',false,$err); throw new Exception("Woo সংযোগ ব্যর্থ: $err"); }
    $json = json_decode($raw, true);
    if ($code >= 400) {
        $msg = isset($json['message']) ? $json['message'] : substr($raw, 0, 300);
        if(function_exists('connectionHealthRuntime'))connectionHealthRuntime('woo',false,"HTTP $code $msg");
        throw new Exception("Woo API ত্রুটি ($code): $msg");
    }
    if(function_exists('connectionHealthRuntime'))connectionHealthRuntime('woo',true,'WooCommerce API is working.');
    return $json;
}

function wooGetProducts($page = 1, $per = 50) {
    return wooRequest('GET', 'products', null, [
        'page' => $page, 'per_page' => $per, 'status' => 'publish', 'orderby' => 'id', 'order' => 'asc',
    ]);
}

function wooGetVariations($productId) {
    $out = [];
    for ($p = 1; $p <= 20; $p++) {
        $rows = wooRequest('GET', "products/$productId/variations", null, ['page' => $p, 'per_page' => 100]);
        if (!$rows) break;
        $out = array_merge($out, $rows);
        if (count($rows) < 100) break;
    }
    return $out;
}

/**
 * Woo-তে stock status লেখা। dry_run হলে শুধু লগ হবে।
 * @return array [ok, message]
 */
function wooSetStock($productId, $variationId, $status /* instock|outofstock */, $force = false) {
    $dry = (bool)cfg('rules.dry_run', true) && !$force;
    $path = $variationId
        ? "products/$productId/variations/$variationId"
        : "products/$productId";

    $label = $variationId ? "$productId/$variationId" : "$productId";

    if ($dry) {
        audit('woo_stock_write', "DRY RUN — $path → $status", '', $status, true);
        return [true, "(dry-run) $label → $status"];
    }

    try {
        wooRequest('PUT', $path, ['stock_status' => $status]);
        audit('woo_stock_write', $path, '', $status, false);
        purgeLiteSpeed();
        return [true, "$label → $status"];
    } catch (Exception $e) {
        audit('woo_stock_error', $path . ' :: ' . $e->getMessage(), '', $status, false);
        return [false, $e->getMessage()];
    }
}

/** LiteSpeed cache purge — সাইটে একটা ছোট endpoint বসাতে হবে (README দেখুন) */
function purgeLiteSpeed() {
    $url = rtrim(cfg('woo.url'), '/') . '/?bis_purge=1';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    curl_exec($ch);
    curl_close($ch);
}
