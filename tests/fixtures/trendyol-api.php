<?php
// Local-only contract fixture. Never use this router for a public server.
header('Content-Type: application/json');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (str_starts_with($path, '/receipts')) {
    $incomplete=str_starts_with($path,'/receipts-incomplete/');
    if (str_contains($path,'batch-requests/')) {
        if(str_ends_with($path,'/expired')){http_response_code(410);echo json_encode(['error'=>'Expired receipts must not be requested']);return;}
        echo json_encode(['status'=>'COMPLETED','items'=>[['requestItem'=>['barcode'=>str_ends_with($path,'/failed')?'OK':'ABSENT'],'status'=>str_ends_with($path,'/failed')?'FAILED':'SUCCESS','failureReasons'=>['Failed update']]]]);return;
    }
    $approved=str_ends_with($path,'/approved');
    $content=$approved ? [['id'=>'A','productMainId'=>'A','variants'=>[['barcode'=>'OK','stockCode'=>'OK']]],['id'=>'B','productMainId'=>'B','variants'=>[['barcode'=>'CONFLICT','stockCode'=>'OTHER']]]]
        : [['productMainId'=>'R','barcode'=>'REJECT','stockCode'=>'REJECT','status'=>'rejected','rejectReasonDetails'=>[['rejectReasonDetail'=>'Fixture rejection']]],['productMainId'=>'W','barcode'=>'WAIT','stockCode'=>'WAIT','status'=>'pendingApproval']];
    if ($approved) {
        if (($_GET['barcode']??'')==='TRANSIENT') $content=[['id'=>'NEW','productMainId'=>'TRANSIENT','barcode'=>'TRANSIENT','stockCode'=>'TRANSIENT']];
        else { $content[]=['id'=>'OLD','productMainId'=>'TRANSIENT','barcode'=>'TRANSIENT','stockCode'=>'TRANSIENT']; $content[]=['id'=>'NEW','productMainId'=>'TRANSIENT','barcode'=>'TRANSIENT','stockCode'=>'TRANSIENT']; }
    } elseif (($_GET['barcode']??'')==='TRANSIENT') $content=[];
    echo json_encode(['totalPages'=>1,'totalElements'=>count($content)+($incomplete?1:0),'content'=>$content]);return;
}
$xmlFlow=str_starts_with($path,'/xml-flow/');
if($xmlFlow)$path=substr($path,strlen('/xml-flow'));
if ($xmlFlow && in_array($path,['/integration/product/sellers/999/products/approved','/integration/product/sellers/999/products/unapproved'],true)) {
    $content=str_ends_with($path,'/approved')?array_map(fn($s)=>['productMainId'=>$s,'barcode'=>$s,'stockCode'=>$s],['XMLFLOW-A','XMLFLOW-B']):[];
    echo json_encode(['totalPages'=>1,'totalElements'=>count($content),'content'=>$content]);return;
}
if ($path === '/integration/product/sellers/999/products/batch-requests/local-stock-pending') {
    echo json_encode(['status'=>'IN_PROGRESS','items'=>[]]); return;
}
if ($path === '/integration/product/sellers/999/products/batch-requests/local-stock-only') {
    echo json_encode(['status'=>'COMPLETED','items'=>[['requestItem'=>['barcode'=>'STOCK-ONLY'],'status'=>'SUCCESS']]]); return;
}
if ($path === '/integration/order/sellers/999/v2/orders') {
    if (isset($_GET['status']) || ($_GET['orderByField'] ?? '') !== 'PackageLastModifiedDate') {
        http_response_code(400); echo json_encode(['error'=>'All statuses and last-modified ordering are required']); return;
    }
    $page=(int)($_GET['page']??0);
    echo json_encode(['totalPages'=>2,'content'=>[['id'=>100+$page,'orderNumber'=>'TEST-'.(100+$page),'status'=>$page===0?'Created':'Verified', 'orderDate'=>1792660000000,'lastModifiedDate'=>1792660100000,'totalPrice'=>215,'lines'=>[['id'=>10+$page,'merchantSku'=>'LOCAL','barcode'=>'LOCAL','productName'=>'Test product','quantity'=>1,'price'=>215,'amount'=>215]]]]]); return;
}
if ($path === '/integration/qna/sellers/999/questions/filter') {
    $page=(int)($_GET['page']??0);
    echo json_encode(['totalPages'=>2,'content'=>[['id'=>200+$page,'text'=>'Test question','status'=>$page===0?'WAITING_FOR_ANSWER':'ANSWERED']]]); return;
}
if ($path === '/integration/order/sellers/999/claims') {
    echo json_encode(['totalPages'=>1,'content'=>[['id'=>'claim-test','orderNumber'=>'TEST-100','claimItems'=>[]]]]); return;
}
if (in_array($path, ['/integration/product/sellers/999/products/approved','/integration/product/sellers/999/products/unapproved'], true)) {
    if (in_array($_GET['barcode'] ?? '', ['OLD-DUP','OLD-REJECT'], true)) {
        $barcode = $_GET['barcode'];
        $approved = str_ends_with($path, '/approved');
        $content = ($approved === ($barcode === 'OLD-DUP')) ? [['barcode'=>$barcode,'stockCode'=>'DUP-SKU','productMainId'=>'DUP-MODEL','status'=>'rejected','rejectReasonDetails'=>[['rejectReasonDetail'=>'Fixture content rejected']]]] : [];
        echo json_encode(['totalElements'=>count($content),'content'=>$content]); return;
    }
    if (($_GET['size'] ?? '') === '100') {
        echo json_encode(['totalPages'=>1,'totalElements'=>1,'content'=>[['id'=>123,'title'=>'Reconcile fixture','stockCode'=>'RECON80','barcode'=>'RAW80','salePrice'=>86,'listPrice'=>86,'quantity'=>498]]]); return;
    }
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
    if(($item['barcode']??'')==='STOCK-ONLY') {
        if($item!==['barcode'=>'STOCK-ONLY','quantity'=>0]){http_response_code(400);echo json_encode(['error'=>'Stock-only update changed price']);return;}
        echo json_encode(['batchRequestId'=>'local-stock-only']);return;
    }
    if(($item['barcode']??'')==='RAW80') {
        if (array_keys($item)===['barcode','quantity'] && $item['quantity']===0) {echo json_encode(['batchRequestId'=>'local-stock-pending']);return;}
        if (($item['salePrice']??0)!=166 || array_key_exists('quantity',$item)) {http_response_code(400);echo json_encode(['error'=>'Wrong reconciled shipping price']);return;}
        echo json_encode(['batchRequestId'=>'local-recon80']);return;
    }
    if(($item['barcode']??'')==='PRICE-ONLY') {
        if(array_key_exists('quantity',$item) || ($item['salePrice']??0)!=215 || ($item['listPrice']??0)!=215) {http_response_code(400);echo json_encode(['error'=>'Price-only update changed stock']);return;}
        echo json_encode(['batchRequestId'=>'local-price-only']);return;
    }
    if(($item['quantity']??null)!==19999 || ($item['salePrice']??null)!=165 || isset($item['title'])) {
        http_response_code(400);echo json_encode(['error'=>'Invalid listing update']);return;
    }
    echo json_encode(['batchRequestId'=>'local-listing-update']);
} else {http_response_code(404);echo json_encode(['error'=>'Unexpected fixture endpoint','path'=>$path]);}
