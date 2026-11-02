<?php
require __DIR__.'/../lib/request_flow.php';
function check($ok,$name){if(!$ok)throw new Exception('FAIL '.$name);echo 'PASS '.$name."\n";}
check(requestStoreVoice('I have also asked our representative.')==='we are checking this.','image fallback attribution becomes store voice');
check(requestStoreVoice('Our representative is checking this. We will update you once it is confirmed.')==='we are checking this. We will update you once it is confirmed.','internal checking is expressed as we');
check(requestStoreVoice('একজন প্রতিনিধিকে জানানো হয়েছে। তিনি দেখে উত্তর দেবেন।')==='আমরা দেখে জানাচ্ছি।','Bangla handoff speaks as the store');
check(requestStoreVoice('মালিকের কাছ থেকে শুনে জানাব।')==='আমরা যাচাই করে জানাব।','Bangla owner attribution is repaired');
$fact='The owner has confirmed stock. Price 2,600 BDT. https://shop.example.com/product/example/';$out=requestStoreVoice($fact);
check(strpos($out,'owner')===false && strpos($out,'confirmed stock')!==false && strpos($out,'2,600 BDT')!==false && strpos($out,'https://shop.example.com/product/example/')!==false,'verified stock price and link are preserved');
check(requestStoreVoice('Stock is not confirmed. We will update you at 11 AM.')==='Stock is not confirmed. We will update you at 11 AM.','uncertainty and scheduled promise stay unchanged');
check(requestStoreVoice('I am the Demo Commerce AI assistant.')==='I am the Demo Commerce AI assistant.','truthful AI identity is retained');
check(requestStoreVoice('আমাদের শোরুমের ঠিকানা Example Road, Dhaka।')==='আমাদের শোরুমের ঠিকানা Example Road, Dhaka।','unrelated factual response remains unchanged');
echo "All store-voice tests passed.\n";
