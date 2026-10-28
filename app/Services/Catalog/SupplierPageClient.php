<?php
declare(strict_types=1);
namespace App\Services\Catalog;

/** Fixed supplier hosts, pinned public DNS, TLS validation, and a provider-wide request interval. */
class SupplierPageClient
{
    public function get(string $url): string
    {
        if(!SupplierPage::allowedUrl($url))throw new \InvalidArgumentException('Desteklenmeyen tedarikçi adresi.');
        $dir=BASE_PATH.'/storage/catalog-audit';if(!is_dir($dir))mkdir($dir,0700,true);
        $fp=fopen($dir.'/supplier-http.lock','c');if(!$fp||!flock($fp,LOCK_EX|LOCK_NB))throw new \RuntimeException('Tedarikçi kontrolü başka bir işlemde çalışıyor.');
        try{
            $stateFile=$dir.'/supplier-http.json';$state=is_file($stateFile)?json_decode(file_get_contents($stateFile),true):[];
            $delay=max(0,(int)($state['next_at']??0)-time());if($delay>5)throw new \RuntimeException('Tedarikçi istek sınırı nedeniyle kontrol bekliyor.');if($delay)sleep($delay);
            $host=parse_url($url,PHP_URL_HOST);$ips=array_column(dns_get_record($host,DNS_A)?:[],'ip');
            if(!$ips||array_filter($ips,static fn($ip)=>!CatalogImageProbe::publicIp($ip)))throw new \RuntimeException('Tedarikçi adresi güvenli bir genel IP’ye çözümlenemedi.');
            $bytes='';$retry=900;$ch=curl_init($url);
            curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>10,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_RESOLVE=>[$host.':443:'.$ips[0]],CURLOPT_USERAGENT=>'Trendmax-SupplierDataCheck/1.0',
                CURLOPT_WRITEFUNCTION=>static function($c,string $b)use(&$bytes):int{if(strlen($bytes)+strlen($b)>3*1024*1024)return 0;$bytes.=$b;return strlen($b);},
                CURLOPT_HEADERFUNCTION=>static function($c,string $line)use(&$retry):int{if(preg_match('/^Retry-After:\s*(.+)/i',$line,$m))$retry=max(900,min(86400,ctype_digit(trim($m[1]))?(int)$m[1]:max(0,(int)strtotime(trim($m[1]))-time())));return strlen($line);}]);
            $ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$mime=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);curl_close($ch);
            file_put_contents($stateFile,json_encode(['next_at'=>time()+($code===429?$retry:5)]),LOCK_EX);chmod($stateFile,0600);
            if($ok===false||$code!==200||!str_starts_with(strtolower($mime),'text/html'))throw new \RuntimeException('Tedarikçi sayfası okunamadı (HTTP '.$code.').');
            return $bytes;
        }finally{flock($fp,LOCK_UN);fclose($fp);}
    }
}
