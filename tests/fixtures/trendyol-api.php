<?php
// Local-only contract fixture. Never use this router for a public server.
header('Content-Type: application/json');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (in_array($path, ['/integration/product/sellers/999/products/approved','/integration/product/sellers/999/products/unapproved'], true)) {
    $status = $_GET['status'] ?? 'approved';
    $totals = ['approved'=>37,'onSale'=>31,'notOnSale'=>6,'pendingApproval'=>13,'rejected'=>2];
    $valid = str_ends_with($path, '/unapproved') ? ['pendingApproval','rejected'] : ['approved','onSale','notOnSale'];
    if (!in_array($status,$valid,true) || ($_GET['size'] ?? '') !== '1' || ($_GET['page'] ?? '') !== '0') {
        http_response_code(400); echo json_encode(['error'=>'Invalid status query']); return;
    }
    echo json_encode(['totalElements'=>$totals[$status],'content'=>[]]); return;
}
if($path==='/integration/product/sellers/999/v2/products' && $_SERVER['REQUEST_METHOD']==='POST' && str_starts_with((string)(json_decode(file_get_contents('php://input'),true)['items'][0]['barcode']??''),'XMLFLOW-')) {
    $items=json_decode(file_get_contents('php://input'),true)['items'];
    foreach($items as$item){$a=array_column($item['attributes'],null,'attributeId');$first=$item['barcode']==='XMLFLOW-A';
        if($item['categoryId']!=1017 || $item['salePrice']!=($first?215:430) || $item['quantity']!=($first?3:5) || ($a[14]['attributeValueId']??0)!==501 || ($a[1192]['attributeValueId']??0)!==100 || ($a[47]['customAttributeValue']??'')!==($first?'Siyah':'Mavi') || ($a[348]['attributeValueId']??0)!==($first?9001:9002) || count($item['images'])!==2 || $item['images'][1]['url']!=='https://example.com/xml-flow-3.jpg'){
            http_response_code(400);echo json_encode(['error'=>'XML flow lost category, price, stock, images or supplier attributes']);return;
        }
    }
    echo json_encode(['batchRequestId'=>'local-xml-flow']);
} elseif($path==='/integration/product/sellers/999/products/batch-requests/local-xml-flow') {
    echo json_encode(['status'=>'COMPLETED','items'=>array_map(fn($bc)=>['requestItem'=>['barcode'=>$bc],'status'=>'SUCCESS','failureReasons'=>[]],['XMLFLOW-A','XMLFLOW-B'])]);
} elseif($path==='/integration/product/sellers/999/products/batch-requests/local-xml-update') {
    echo json_encode(['status'=>'COMPLETED','items'=>[['requestItem'=>['barcode'=>'XMLFLOW-A'],'status'=>'SUCCESS','failureReasons'=>[]]]]);
} elseif($path==='/integration/inventory/sellers/999/products/price-and-inventory' && $_SERVER['REQUEST_METHOD']==='POST' && (json_decode(file_get_contents('php://input'),true)['items'][0]['barcode']??'')==='XMLFLOW-A') {
    $item=json_decode(file_get_contents('php://input'),true)['items'][0];
    if($item['salePrice']!=258 || $item['quantity']!==7 || isset($item['title']) || isset($item['images'])){http_response_code(400);echo json_encode(['error'=>'Invalid XML refresh price/stock update']);return;}
    echo json_encode(['batchRequestId'=>'local-xml-update']);
} elseif($path==='/integration/product/categories/429/attributes') {
    $counter=dirname(__DIR__,2).'/storage/local/trendyol-fixture-retries';
    $count=is_file($counter)?(int)file_get_contents($counter):0;
    file_put_contents($counter,(string)($count+1));
    if($count===0){http_response_code(429);header('Retry-After: 1');echo json_encode(['retry_after'=>0]);}
    else echo json_encode(['categoryAttributes'=>[['attribute'=>['id'=>47,'name'=>'Renk'],'required'=>true,'allowCustom'=>true]]]);
} elseif($path==='/integration/product/categories/1017/attributes') {
    echo json_encode(['categoryAttributes'=>[['attribute'=>['id'=>14,'name'=>'Materyal'],'required'=>true,'allowCustom'=>false,'allowMultipleAttributeValues'=>false]]]);
} elseif($path==='/integration/product/categories/1017/attributes/14/values') {
    $page=(int)($_GET['page']??0);
    echo json_encode(['totalPages'=>2,'content'=>[['attributeValueId'=>$page===0?501:502,'attributeValue'=>$page===0?'Plastik':'Ahşap']]]);
} elseif($path==='/integration/product/sellers/999/v2/products' && $_SERVER['REQUEST_METHOD']==='POST') {
    $payload=json_decode(file_get_contents('php://input'),true);
    $items=$payload['items']??[];
    foreach($items as $item) if(($item['attributes'][0]['attributeValueId']??null)!==501) {
        http_response_code(400);echo json_encode(['error'=>'Missing mapped Trendyol attribute']);return;
    }
    echo json_encode(['batchRequestId'=>'local-contract-'.count($items)]);
} elseif($path==='/integration/inventory/sellers/999/products/price-and-inventory' && $_SERVER['REQUEST_METHOD']==='POST') {
    $payload=json_decode(file_get_contents('php://input'),true);
    $item=$payload['items'][0]??[];
    if(($item['barcode']??'')==='PRICE-ONLY') {
        if(array_key_exists('quantity',$item) || ($item['salePrice']??0)!=215 || ($item['listPrice']??0)!=215) {http_response_code(400);echo json_encode(['error'=>'Price-only update changed stock']);return;}
        echo json_encode(['batchRequestId'=>'local-price-only']);return;
    }
    if(($item['quantity']??null)!==19999 || ($item['salePrice']??null)!=165 || isset($item['title'])) {
        http_response_code(400);echo json_encode(['error'=>'Invalid listing update']);return;
    }
    echo json_encode(['batchRequestId'=>'local-listing-update']);
} else {http_response_code(404);echo json_encode(['error'=>'Unexpected fixture endpoint','path'=>$path]);}
