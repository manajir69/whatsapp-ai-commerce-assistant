<?php
/** Runtime WhatsApp channel settings. Secrets remain in config.php. */
require_once __DIR__.'/db.php';
function channelDigits($value){return preg_replace('/\D/','',(string)$value);}
function channelGet($key,$default=''){
    try{$row=one('SELECT v FROM settings WHERE k=?',['channel_'.$key]);return $row?(string)$row['v']:(string)$default;}
    catch(Throwable $e){return (string)$default;}
}
function channelPut($key,$value){q('INSERT INTO settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)',['channel_'.$key,(string)$value]);}
function channelBusinessWa(){return channelDigits(channelGet('business_wa',cfg('wa.business_number','')));}
function channelPhoneNumberId(){return channelDigits(channelGet('phone_number_id',cfg('wa.phone_number_id','')));}
function channelOwnerWa(){
    $saved=channelDigits(channelGet('owner_wa',''));if($saved!=='')return $saved;
    $configured=channelDigits(cfg('owner.wa',''));
    // One-time compatibility for the earlier build where the business number was placed in owner.wa.
    return $configured===channelBusinessWa()?'':$configured;
}
function channelSave($business,$phoneId,$owner){
    $business=channelDigits($business);$phoneId=channelDigits($phoneId);$owner=channelDigits($owner);
    if(strlen($business)<10 || strlen($business)>15)throw new Exception('Enter the business WhatsApp number with country code.');
    if(strlen($phoneId)<8 || strlen($phoneId)>30)throw new Exception('Enter the Meta Phone Number ID.');
    if(strlen($owner)<10 || strlen($owner)>15 || $owner===$business)throw new Exception('Enter a different valid owner WhatsApp number.');
    db()->beginTransaction();try{channelPut('business_wa',$business);channelPut('phone_number_id',$phoneId);channelPut('owner_wa',$owner);db()->commit();}
    catch(Throwable $e){db()->rollBack();throw $e;}
    audit('channel_settings','Business '.$business.'; Phone ID updated; owner '.$owner);
}
