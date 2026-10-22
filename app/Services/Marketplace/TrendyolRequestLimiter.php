<?php
declare(strict_types=1);
namespace App\Services\Marketplace;

/** Shared pacing for the seller's product service groups (including concurrent requests). */
final class TrendyolRequestLimiter
{
    public function __construct(private readonly string $directory = BASE_PATH . '/storage/cache') {}
    public function wait(string $sellerId, string $method, string $url): void
    {
        $path=(string)parse_url($url,PHP_URL_PATH);
        if (str_contains($path,'/inventory/')) { $group='inventory'; $perMinute=315; }
        elseif (str_contains($path,'/product/')) { $group=$method==='GET'?'read':'write'; $perMinute=$method==='GET'?900:180; }
        else return;
        $file=$this->directory.'/ty-rate-'.hash('sha256',$sellerId.'|'.$group).'.lock';
        $handle=fopen($file,'c+');
        if (!$handle || !flock($handle,LOCK_EX)) throw new \RuntimeException('Trendyol istek sınırı kilidi alınamadı.');
        try {
            $now=microtime(true);
            $next=(float)stream_get_contents($handle);
            $scheduled=max($now,$next);
            rewind($handle);ftruncate($handle,0);fwrite($handle,(string)($scheduled+60/$perMinute));fflush($handle);
        } finally {flock($handle,LOCK_UN);fclose($handle);}
        $delay=$scheduled-microtime(true);
        if($delay>0)usleep((int)ceil($delay*1000000));
    }
}
