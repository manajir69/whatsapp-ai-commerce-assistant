<?php
/** ডেটাবেস সংযোগ ও ছোট সহায়ক ফাংশন */

function cfg($path = null, $default = null) {
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/../config.php';
        if (!file_exists($file)) {
            die('config.php পাওয়া যায়নি। config.example.php কপি করে config.php বানান।');
        }
        $cfg = require $file;
    }
    if ($path === null) return $cfg;
    $cur = $cfg;
    foreach (explode('.', $path) as $part) {
        if (!is_array($cur) || !array_key_exists($part, $cur)) return $default;
        $cur = $cur[$part];
    }
    return $cur;
}

function db() {
    static $pdo = null;
    if ($pdo === null) {
        $d = cfg('db');
        $dsn = "mysql:host={$d['host']};dbname={$d['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function q($sql, $params = []) {
    bisQueryCount(true);
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function one($sql, $params = []) { $r = q($sql, $params)->fetch(); return $r === false ? null : $r; }
function all($sql, $params = []) { return q($sql, $params)->fetchAll(); }

function now() {
    static $tz = null;
    if ($tz === null) $tz = new DateTimeZone(cfg('rules.timezone', 'Asia/Dhaka'));
    return new DateTime('now', $tz);
}

function nowStr() { return now()->format('Y-m-d H:i:s'); }

function audit($action, $detail = null, $before = '', $after = '', $dry = false) {
    q("INSERT INTO audit_log (action, detail, before_val, after_val, dry_run, created_at)
       VALUES (?,?,?,?,?,?)",
       [$action, is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE),
        (string)$before, (string)$after, $dry ? 1 : 0, nowStr()]);
}

/** এখন কি অফিস টাইম? (১১টা–৬টা, শুক্রবার বাদ) */
function isOfficeHours(DateTime $t = null) {
    $t = $t ?: now();
    $weekend = cfg('rules.weekend', [5]);
    if (in_array((int)$t->format('w'), $weekend, true)) return false;
    $hm = $t->format('H:i');
    return $hm >= cfg('rules.office_start', '11:00')
        && $hm <  cfg('rules.office_end', '18:00');
}

/** পরবর্তী অফিস টাইম শুরুর মুহূর্ত */
function nextOfficeOpen(DateTime $t = null) {
    $t = clone ($t ?: now());
    $weekend = cfg('rules.weekend', [5]);
    list($h, $m) = explode(':', cfg('rules.office_start', '11:00'));
    for ($i = 0; $i < 14; $i++) {
        $c = clone $t;
        if ($i > 0) { $c->modify("+$i day"); }
        $c->setTime((int)$h, (int)$m, 0);
        if ($c <= $t) continue;
        if (in_array((int)$c->format('w'), $weekend, true)) continue;
        return $c;
    }
    return $t;
}


/* ==========================================================
   সময় মাপা — কোথায় কত সময় যাচ্ছে
   ========================================================== */
function bisMark($label = null) {
    static $marks = [], $last = null;
    $now = microtime(true);
    if ($label === null) { $out = $marks; $marks = []; $last = null; return $out; }
    if ($last === null) { $last = $now; return null; }
    $marks[] = [$label, (int)round(($now - $last) * 1000)];
    $last = $now;
    return null;
}

/** কতগুলো SQL চালানো হলো */
function bisQueryCount($add = false) {
    static $n = 0;
    if ($add) $n++;
    return $n;
}
