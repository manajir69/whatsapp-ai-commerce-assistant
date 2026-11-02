<?php
/** Optional full cache warm-up via CLI; normal chat loads products on demand. */
require_once __DIR__.'/lib/catalog.php';
if (PHP_SAPI!=='cli') {
    require_once __DIR__.'/lib/auth.php'; requireLogin();
    http_response_code(405);
    exit('Full catalog indexing is a CLI task: php sync_products.php. Normal customer messages populate the cache automatically.');
}
@set_time_limit(0); $count=0;
try {
    for ($page=1;$page<=200;$page++) {
        $batch=wooRequest('GET','products',null,['page'=>$page,'per_page'=>25,'status'=>'publish']);
        foreach ($batch as $p) {
            catalogStore($p); $count++;
            if (($p['type']??'')==='variable') foreach (wooGetVariations($p['id']) as $v) {
                if (($v['status']??'publish')==='publish') { catalogStore($p,$v); $count++; }
            }
        }
        echo 'Imported page '.$page."\n";
        if (count($batch)<25) break;
    }
    foreach (all('SELECT * FROM knowledge WHERE approved=1 ORDER BY id DESC LIMIT 500') as $r)
        retrievalDocument('knowledge',$r['id'],0,$r['question'].' '.$r['answer'],$r['embedding']);
    $images=mediaIndexPending(500);
    audit('sync_products',$count.' rows refreshed');
    echo 'Saved '.$count.' rows and indexed '.$images." product images. Instructions and credentials preserved.\n";
} catch (Throwable $e) {
    audit('sync_error','Catalog import failed');
    fwrite(STDERR,"Import stopped. Check WooCommerce connectivity and database permissions; saved rows remain available.\n");
    exit(1);
}
