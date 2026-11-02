<?php
/** Owner service window is separate from every customer's service window. */
function ownerNotificationRememberInbound($from, $timestamp = 0) {
    $owner=(string)channelOwnerWa();
    if ((string)$from!==$owner) return;
    $stamp=(int)$timestamp;
    if ($stamp<=0 || $stamp>now()->getTimestamp()) $stamp=now()->getTimestamp();
    $old=one("SELECT v FROM settings WHERE k='owner_notification_window'");
    $previous=$old?json_decode($old['v'],true):null;
    if(is_array($previous) && ($previous['to']??'')===$owner)$stamp=max($stamp,(int)($previous['at']??0));
    q("INSERT INTO settings(k,v) VALUES('owner_notification_window',?) ON DUPLICATE KEY UPDATE v=VALUES(v)",[json_encode(['to'=>$owner,'at'=>$stamp])]);
}

function ownerNotificationWindowOpen() {
    $row=one("SELECT v FROM settings WHERE k='owner_notification_window'");
    $state=$row?json_decode($row['v'],true):null;
    if(!is_array($state) || ($state['to']??'')!==(string)channelOwnerWa())return false;
    $age=now()->getTimestamp()-(int)($state['at']??0);
    // Leave a five-minute margin for queue/API latency.
    return $age>=0 && $age<86100;
}

function ownerSendOperational($subject,$body) {
    $to=channelOwnerWa();
    if(ownerNotificationWindowOpen())return waSendText($to,$body);
    $key='owner_alert_attempt_'.substr(hash('sha256',(string)$subject),0,24);
    $attempt=one('SELECT v FROM settings WHERE k=?',[$key]);
    if($attempt && now()->getTimestamp()-(int)$attempt['v']<300)return '';
    q('INSERT INTO settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)',[$key,(string)now()->getTimestamp()]);
    return waSendOwnerTemplate($to,$subject,$body);
}
