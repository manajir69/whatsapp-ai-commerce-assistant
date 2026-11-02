<?php
$root=sys_get_temp_dir().'/bi-customer-test-'.bin2hex(random_bytes(4));mkdir($root);mkdir($root.'/lib');
copy(__DIR__.'/../lib/customers.php',$root.'/lib/customers.php');file_put_contents($root.'/lib/db.php','<?php');
require $root.'/lib/customers.php';
function check($ok,$label){if(!$ok)throw new Exception('FAIL '.$label);echo "PASS $label\n";}
list($location,$lat,$lng,$source)=customerExplicitLocation('',['type'=>'location','latitude'=>'23.8103','longitude'=>'90.4125','location_name'=>'Dhaka showroom area','address'=>'Gulshan']);
check($location==='Dhaka showroom area Gulshan' && $lat==='23.8103' && $source==='whatsapp_location','WhatsApp location is stored exactly');
list($location,,,$source)=customerExplicitLocation("Name\n15555550199\nExample Supplies\nExample Tower, 2nd Floor, Khulna sadar.",[]);
check(strpos($location,'Khulna sadar')!==false && $source==='chat','multiline delivery address is detected');
list($location)=customerExplicitLocation('Dhaka delivery charge koto?',[]);
check($location==='','ordinary location-related question is not stored as an address');
echo "All customer database tests passed.\n";

