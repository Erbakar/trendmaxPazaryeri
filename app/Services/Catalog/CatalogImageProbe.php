<?php
declare(strict_types=1);
namespace App\Services\Catalog;
use App\Core\{TenantContext,Database};

/** Bounded image reads and tenant-isolated DB cache: no file per image on shared hosting. */
final class CatalogImageProbe
{
    private array $dns=[];
    public function __construct(private readonly TenantContext $tenant,private readonly Database $db){}
    public static function publicIp(string $ip): bool
    {
        return (bool)filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)
            &&!str_starts_with(strtolower($ip),'::ffff:');
    }
    public function inspect(string $url,int $maxAge=86400): array
    {
        $old=$this->cached($url);
        if($old&&($old['algorithm']??0)===2&&($old['at']??0)>time()-min(max(0,$maxAge),!empty($old['ok'])?86400:900))return $old;
        try{$result=$this->download($url);$result['ok']=true;}
        catch(\Throwable $e){$result=['ok'=>false,'error'=>$e->getMessage()];}
        $result['url']=$url;$result['at']=time();$result['algorithm']=2;
        $this->remember($url,$result);return $result;
    }
    private function cached(string $url): ?array
    {
        $row=$this->db->fetchOne('SELECT result_json FROM catalog_image_checks WHERE store_id=? AND url_hash=?',[$this->tenant->currentStoreId(),hash('sha256',$url)]);
        return $row?json_decode($row['result_json'],true,512,JSON_THROW_ON_ERROR):null;
    }
    private function remember(string $url,array $result): void
    {
        $this->db->query('INSERT INTO catalog_image_checks(store_id,url_hash,result_json,checked_at) VALUES(?,?,?,FROM_UNIXTIME(?)) ON DUPLICATE KEY UPDATE result_json=IF(VALUES(checked_at)>=checked_at,VALUES(result_json),result_json),checked_at=GREATEST(checked_at,VALUES(checked_at))',[$this->tenant->currentStoreId(),hash('sha256',$url),json_encode($result,JSON_THROW_ON_ERROR),$result['at']]);
    }
    private function download(string $url): array
    {
        for($redirect=0;$redirect<4;$redirect++){
            $u=parse_url($url);$scheme=strtolower($u['scheme']??'');$host=strtolower($u['host']??'');$port=(int)($u['port']??($scheme==='https'?443:80));
            if(!in_array($scheme,['http','https'],true)||$host===''||isset($u['user'])||isset($u['pass'])||!in_array($port,[80,443],true))throw new \RuntimeException('Görsel adresi herkese açık HTTP/HTTPS olmalı.');
            if(!isset($this->dns[$host])){
                $ips=filter_var(trim($host,'[]'),FILTER_VALIDATE_IP)?[trim($host,'[]')]:array_column(dns_get_record($host,DNS_A)?:[],'ip');
                if(!$ips||array_filter($ips,static fn($ip)=>!self::publicIp($ip)))throw new \RuntimeException('Görsel adresi güvenli bir genel IP’ye çözümlenemedi.');
                $this->dns[$host]=$ips[0];
            }
            $bytes='';$location='';$ch=curl_init($url);
            curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_RESOLVE=>[$host.':'.$port.':'.$this->dns[$host]],CURLOPT_USERAGENT=>'Trendmax-CatalogCheck/1.0',
                CURLOPT_WRITEFUNCTION=>static function($c,string $data)use(&$bytes):int{if(strlen($bytes)+strlen($data)>8*1024*1024)return 0;$bytes.=$data;return strlen($data);},
                CURLOPT_HEADERFUNCTION=>static function($c,string $line)use(&$location):int{if(strncasecmp($line,'Location:',9)===0)$location=trim(substr($line,9));return strlen($line);}]);
            $ok=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$mime=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);curl_close($ch);
            if($http>=300&&$http<400&&$location!==''){
                if(str_starts_with($location,'/'))$location=$scheme.'://'.$host.($port!==443&&$port!==80?':'.$port:'').$location;
                $url=$location;continue;
            }
            if($ok===false||$http!==200||!str_starts_with(strtolower($mime),'image/'))throw new \RuntimeException('Görsel indirilemedi veya resim içeriği değil (HTTP '.$http.').');
            return $this->signature($bytes);
        }
        throw new \RuntimeException('Görsel yönlendirme zinciri tamamlanamadı.');
    }
    private function signature(string $bytes): array
    {
            $size=@getimagesizefromstring($bytes);if(!$size||$size[0]<32||$size[1]<32||$size[0]*$size[1]>20000000)throw new \RuntimeException('Görsel okunamıyor veya boyutu desteklenmiyor.');
            $result=['algorithm'=>2,'sha256'=>hash('sha256',$bytes),'width'=>$size[0],'height'=>$size[1],'hash'=>null];
            if(function_exists('imagecreatefromstring')){
                $image=@imagecreatefromstring($bytes);if(!$image)throw new \RuntimeException('Görsel çözülürken hata oluştu.');
                $small=imagecreatetruecolor(17,16);imagecopyresampled($small,$image,0,0,0,0,17,16,imagesx($image),imagesy($image));$bits='';$color=[0,0,0];
                for($y=0;$y<16;$y++)for($x=0;$x<16;$x++){$a=imagecolorat($small,$x,$y);$b=imagecolorat($small,$x+1,$y);$gray=static fn($v)=>(($v>>16)&255)*.299+(($v>>8)&255)*.587+($v&255)*.114;$bits.=$gray($a)>$gray($b)?'1':'0';$color[0]+=($a>>16)&255;$color[1]+=($a>>8)&255;$color[2]+=$a&255;}
                $result['hash']=$bits;$result['color']=array_map(static fn($n)=>round($n/256,2),$color);
                // Trendyol commonly letterboxes square supplier photos into a white 2:3 canvas.
                // Compare the same framing instead of accepting arbitrary aspect-ratio differences.
                $frame=imagecreatetruecolor(192,288);imagefill($frame,0,0,imagecolorallocate($frame,255,255,255));
                $scale=min(192/imagesx($image),288/imagesy($image));$w=max(1,(int)round(imagesx($image)*$scale));$h=max(1,(int)round(imagesy($image)*$scale));
                imagecopyresampled($frame,$image,(int)((192-$w)/2),(int)((288-$h)/2),0,0,$w,$h,imagesx($image),imagesy($image));
                imagecopyresampled($small,$frame,0,0,0,0,17,16,192,288);$bits='';$color=[0,0,0];
                for($y=0;$y<16;$y++)for($x=0;$x<16;$x++){$a=imagecolorat($small,$x,$y);$b=imagecolorat($small,$x+1,$y);$bits.=$gray($a)>$gray($b)?'1':'0';$color[0]+=($a>>16)&255;$color[1]+=($a>>8)&255;$color[2]+=$a&255;}
                $result['fit_hash']=$bits;$result['fit_color']=array_map(static fn($n)=>round($n/256,2),$color);
                imagedestroy($frame);imagedestroy($image);imagedestroy($small);
            }
            return $result;
    }
    /** Warm independent images concurrently; redirects still pass through inspect's checked redirect path. */
    public function prefetch(array $urls): void
    {
        $jobs=[];
        foreach(array_slice(array_values(array_unique($urls)),0,40)as$url){
            $cached=$this->cached($url);
            if($cached&&($cached['algorithm']??0)===2&&($cached['at']??0)>time()-(!empty($cached['ok'])?86400:900))continue;
            $u=parse_url($url);$scheme=strtolower($u['scheme']??'');$host=strtolower($u['host']??'');$port=(int)($u['port']??($scheme==='https'?443:80));
            if(!in_array($scheme,['http','https'],true)||$host===''||isset($u['user'])||isset($u['pass'])||!in_array($port,[80,443],true))continue;
            if(!isset($this->dns[$host])){
                $ips=filter_var(trim($host,'[]'),FILTER_VALIDATE_IP)?[trim($host,'[]')]:array_column(dns_get_record($host,DNS_A)?:[],'ip');
                if(!$ips||array_filter($ips,static fn($ip)=>!self::publicIp($ip)))continue;
                $this->dns[$host]=$ips[0];
            }
            $jobs[]=['url'=>$url,'host'=>$host,'port'=>$port,'ip'=>$this->dns[$host]];
        }
        foreach(array_chunk($jobs,10)as$chunk){
            $multi=curl_multi_init();$handles=[];$buffers=[];
            foreach($chunk as$i=>$job){
                $buffers[$i]='';$ch=curl_init($job['url']);
                curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,
                    CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                    CURLOPT_RESOLVE=>[$job['host'].':'.$job['port'].':'.$job['ip']],CURLOPT_USERAGENT=>'Trendmax-CatalogCheck/1.0',
                    CURLOPT_WRITEFUNCTION=>static function($c,string $data)use(&$buffers,$i):int{if(strlen($buffers[$i])+strlen($data)>8*1024*1024)return 0;$buffers[$i].=$data;return strlen($data);}]);
                $handles[$i]=$ch;curl_multi_add_handle($multi,$ch);
            }
            do{$status=curl_multi_exec($multi,$running);if($running&&$status===CURLM_OK){if(curl_multi_select($multi,.2)===-1)usleep(10000);}}while($running&&$status===CURLM_OK);
            foreach($handles as$i=>$ch){
                try{
                    if(!curl_errno($ch)&&(int)curl_getinfo($ch,CURLINFO_HTTP_CODE)===200&&str_starts_with(strtolower((string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE)),'image/')){
                        $result=$this->signature($buffers[$i])+['ok'=>true,'url'=>$chunk[$i]['url'],'at'=>time()];
                        $this->remember($chunk[$i]['url'],$result);
                    }
                }catch(\Throwable){}finally{curl_multi_remove_handle($multi,$ch);curl_close($ch);}
            }
            curl_multi_close($multi);
        }
    }
    public static function similar(array $a,array $b): bool
    {
        if(empty($a['ok'])||empty($b['ok']))return false;
        if($a['sha256']===$b['sha256'])return true;
        if(empty($a['hash'])||empty($b['hash']))return false;
        if(abs(($a['width']/$a['height'])/($b['width']/$b['height'])-1)<=.03
            &&self::nearHash($a['hash'],$b['hash'],$a['color'],$b['color']))return true;
        return !empty($a['fit_hash'])&&!empty($b['fit_hash'])&&self::nearHash($a['fit_hash'],$b['fit_hash'],$a['fit_color'],$b['fit_color']);
    }
    private static function nearHash(string $a,string $b,array $colorA,array $colorB): bool
    {
        if(strlen($a)!==256||strlen($b)!==256)return false;
        foreach([$a,$b]as$hash){$ones=substr_count($hash,'1');if($ones<12||$ones>244)return false;}
        if(array_sum(array_map(static fn($x,$y)=>abs($x-$y),$colorA,$colorB))>18)return false;
        return count(array_filter(str_split($a^$b),static fn($c)=>ord($c)!==0))<=8;
    }

    public function compare(array $source,array $remote): bool
    {
        if(count($source)!==count($remote)||!$source)return false;$used=[];
        foreach($source as$url){$a=$this->inspect($url);$match=null;
            foreach($remote as$i=>$other)if(!isset($used[$i])&&self::similar($a,$this->inspect($other))){$match=$i;break;}
            if($match===null)return false;$used[$match]=true;
        }
        return true;
    }
}
