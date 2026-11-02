<?php
/**
 * একবার চালানোর জন্য — টেবিল তৈরি করে ও সব সংযোগ যাচাই করে।
 * শুধু খুলুন: https://workspace.example.com/install.php
 * পাসওয়ার্ড চাইবে (config.php → console.password)
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
      <title>ইনস্টল</title>
      <body style="font:15px/1.8 system-ui,sans-serif;max-width:760px;margin:40px auto;padding:0 16px">';
echo '<h2>Demo Commerce Bot — ইনস্টল যাচাই</h2>';

function ok($s)   { echo "<div style='color:#15803d'>✅ $s</div>"; }
function bad($s)  { echo "<div style='color:#b91c1c'>❌ $s</div>"; }
function warn($s) { echo "<div style='color:#b45309'>⚠️ $s</div>"; }
function info_line($s) { echo "<div style='color:#555'>&nbsp;&nbsp;$s</div>"; }

/* ---------- ডেটাবেস ---------- */
try {
    db()->query('SELECT 1');
    ok('ডেটাবেস সংযোগ ঠিক আছে।');
} catch (Exception $e) {
    bad('ডেটাবেস: ' . htmlspecialchars($e->getMessage()));
    echo '<p>config.php-এর <code>db</code> ঘরগুলো আবার দেখুন।</p>';
    exit;
}

$sqlFile = __DIR__ . '/sql/schema.sql';
if (!file_exists($sqlFile)) {
    bad('sql/schema.sql ফাইলটি পাওয়া যায়নি।');
} else {
    $sql = file_get_contents($sqlFile);
    /* মন্তব্যের লাইনগুলো আগে সরিয়ে নিই, নাহলে বিবৃতি চেনা যায় না */
    $sql = preg_replace('/^[ \t]*--.*$/m', '', $sql);

    $errs = 0; $ran = 0;
    foreach (explode(';', $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        try { db()->exec($stmt); $ran++; }
        catch (Exception $e) { $errs++; bad(htmlspecialchars($e->getMessage())); }
    }

    /* সত্যিই কী কী আছে, গুনে দেখি */
    $want  = ['products','stock_state','knowledge','conversations','messages','tickets','audit_log','settings','stock_reply_queue','stock_sync_log'];
    $have  = [];
    foreach (db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $r) $have[] = $r[0];
    $miss  = array_values(array_diff($want, $have));

    if (!$miss) ok(count($want).'টি প্রয়োজনীয় টেবিলই আছে (' . $ran . ' টি বিবৃতি চালানো হলো)।');
    else {
        bad('এই টেবিলগুলো এখনো নেই: <b>' . htmlspecialchars(implode(', ', $miss)) . '</b>');
        info_line('আছে: ' . htmlspecialchars(implode(', ', $have) ?: '(কিছুই না)'));
    }
}

/* ---------- WooCommerce ---------- */
require_once __DIR__ . '/lib/woo.php';
if (cfg('woo.key')) {
    try {
        $r = wooRequest('GET', 'products', null, ['per_page' => 1]);
        ok('WooCommerce API কাজ করছে।');
    } catch (Exception $e) {
        bad('WooCommerce: ' . htmlspecialchars($e->getMessage()));
    }
} else {
    warn('WooCommerce key বসানো হয়নি — WP admin → WooCommerce → Settings → Advanced → REST API → Add key (Read/Write)।');
}

/* ---------- LLM ---------- */
require_once __DIR__ . '/lib/llm.php';
$prov = cfg('llm.provider', 'anthropic');
echo "<div style='color:#555'>চ্যাট প্রদানকারী: <b>$prov</b> · এমবেডিং: <b>"
   . htmlspecialchars(embedProviderTag()) . "</b></div>";

if (cfg('llm.key')) {
    try {
        $t = llmAsk('তুমি বাংলায় উত্তর দাও।',
             [['role' => 'user', 'content' => 'একটি বাক্যে লেখো: পর্দা কীভাবে পরিষ্কার করব?']], null, 200);
        ok("চ্যাট ($prov) কাজ করছে।<br><span style='color:#444;background:#f5f5f5;padding:6px 10px;"
         . "display:inline-block;border-radius:6px;margin-top:4px'>"
         . htmlspecialchars(mb_substr($t, 0, 300)) . '</span>');
    } catch (Exception $e) { bad("চ্যাট ($prov): " . htmlspecialchars($e->getMessage())); }
} else { warn('চ্যাটের API key বসানো হয়নি।'); }

if (cfg('llm.embed.key')) {
    try {
        $v = embed('পর্দা ধোয়া যাবে কি?');
        ok('এমবেডিং কাজ করছে (' . count($v) . ' মাত্রা)।');
        if (embedMismatch()) {
            warn('প্রদানকারী বদলেছে (আগে: <code>' . htmlspecialchars(storedEmbedTag())
               . '</code>)। <b>সিঙ্ক আবার চালাতে হবে</b> — নিচের লিংকে চাপলেই সব নতুন করে বানাবে।');
        }
    } catch (Exception $e) { bad('এমবেডিং: ' . htmlspecialchars($e->getMessage())); }
} else { warn('এমবেডিং key বসানো হয়নি — পণ্য খোঁজা দুর্বল হবে।'); }

/* ---------- verify token ---------- */
if (!cfg('wa.verify_token')) {
    $tok = bin2hex(random_bytes(16));
    warn('WhatsApp verify token এখনো বসানো হয়নি। এটি ব্যবহার করতে পারেন — '
       . "<code style='background:#eee;padding:2px 6px'>$tok</code>");
}

/* ---------- সময় ---------- */
echo '<hr>';
echo 'এখন (' . cfg('rules.timezone') . '): <b>' . nowStr() . '</b><br>';
echo 'অফিস টাইম চলছে? <b>' . (isOfficeHours() ? 'হ্যাঁ' : 'না') . '</b><br>';
echo 'পরবর্তী অফিস শুরু: <b>' . nextOfficeOpen()->format('Y-m-d H:i') . '</b><br>';
echo 'DRY RUN: <b>' . (cfg('rules.dry_run') ? 'চালু (ওয়েবসাইটে লিখবে না)' : 'বন্ধ') . '</b>';

$hasProducts = false;
try { $hasProducts = (int)one("SELECT COUNT(*) c FROM products")['c']; } catch (Exception $e) {}
echo '<hr><h3>পরের ধাপ</h3><ol>';
echo '<li><a href="sync_products.php">পণ্য সিঙ্ক করুন</a>'
   . ($hasProducts ? " — এখন ডেটাবেসে <b>$hasProducts</b> সারি আছে" : '') . '</li>';
echo '<li><a href="chat.php">টেস্ট কনসোল খুলুন</a></li>';
echo '</ol>';
echo '<p style="color:#b91c1c"><b>সব ঠিক হয়ে গেলে এই install.php ফাইলটি মুছে ফেলবেন।</b></p>';
echo '<p style="font-size:13px"><a href="?logout=1">লগআউট</a></p>';
