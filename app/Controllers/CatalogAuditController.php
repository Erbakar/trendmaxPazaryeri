<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Request;
use App\Services\Catalog\{CatalogAuditService,CatalogAuditRepository};

final class CatalogAuditController extends BaseController
{
    public function index(Request $request): void
    {
        $this->requireModule('products');
        $service=$this->container->get(CatalogAuditService::class);
        try {
            $mid=$service->marketplace();
            $status=(string)$request->query('status','');$search=trim((string)$request->query('q',''));
            $report=$this->container->get(CatalogAuditRepository::class)->report($mid,$status,max(1,$request->integer('page',1)),$search);
            $this->view('products/catalog_audit',['pageTitle'=>'Katalog Kontrolü','report'=>$report,'config'=>$service->configuration(),'status'=>$status,'search'=>$search]);
        }catch(\Throwable $e){$this->view('products/catalog_audit',['pageTitle'=>'Katalog Kontrolü','error'=>$e->getMessage()]);}
    }
    public function configure(Request $request): void
    {
        $this->requireModule('products');
        try {
            $service=$this->container->get(CatalogAuditService::class);$enabled=$request->post('enabled','0')==='1';
            $service->configure($enabled,$request->post('publish','0')==='1');
            if($enabled)$service->request();
            $this->redirectWithSuccess('/products/catalog-audit',$enabled?'Katalog kontrolü sunucuda sıraya alındı. Sayfayı kapatabilirsiniz.':'Otomatik katalog kontrolü duraklatıldı.');
        }catch(\Throwable $e){$this->redirectWithError('/products/catalog-audit',$e->getMessage());}
    }
    public function approve(Request $request): void
    {
        $this->requireModule('products');
        try {
            $this->container->get(CatalogAuditService::class)->approve((int)$request->post('item_id','0'),(string)$request->post('fingerprint',''),(int)$this->session->userId());
            $this->redirectWithSuccess('/products/catalog-audit','İçerik incelemesi kaydedildi. Teknik kontroller sonraki otomatik turda yenilenecek.');
        }catch(\Throwable $e){$this->redirectWithError('/products/catalog-audit',$e->getMessage());}
    }
}
