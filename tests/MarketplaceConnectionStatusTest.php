<?php
declare(strict_types=1);
use App\Core\{Container,Database,TenantContext};
use App\Models\{Marketplace,SyncLog};
use PHPUnit\Framework\TestCase;
final class MarketplaceConnectionStatusTest extends TestCase
{
 public function testOnlyCompletedSuccessfulSyncOfCurrentStoreIsShown(): void
 {
  if(getenv('TRENDMAX_LOCAL_INTEGRATION')!=='1')$this->markTestSkipped('Local DB integration only.');
  $env=$_ENV;
  foreach(file(BASE_PATH.'/.env',FILE_IGNORE_NEW_LINES)as$line){if(!str_contains($line,'=')||str_starts_with(trim($line),'#'))continue;[$k,$v]=explode('=',$line,2);$_ENV[trim($k)]=trim($v," \t\"'");}
  if(($_ENV['DB_NAME']??'')!=='trendmax_local'||($_ENV['DB_HOST']??'')!=='127.0.0.1')throw new RuntimeException('Only local test DB.');
  $c=new Container([]);$db=$c->get(Database::class);$db->beginTransaction();
  try{
   $tenant=$c->get(TenantContext::class);$mp=$c->get(Marketplace::class);$logs=$c->get(SyncLog::class);
   $row=static function()use($mp){foreach($mp->getAllWithStatus()as$r)if((int)$r['id']===1)return$r;throw new RuntimeException('Missing marketplace');};
   $db->query('INSERT INTO stores(name,slug) VALUES(?,?)',['Connection test','connection-'.bin2hex(random_bytes(6))]);$sid=(int)$db->lastInsertId();$tenant->setStoreId($sid);
   self::assertNull($row()['last_sync_at']);
   $id=$logs->start(1,'orders');$logs->finish($id,'success',1,1);
   $db->query('UPDATE sync_logs SET finished_at=? WHERE id=?',['2026-01-01 10:00:00',$id]);
   self::assertSame('2026-01-01 10:00:00',$row()['last_sync_at']);
   $bad=$logs->start(1,'price');$logs->finish($bad,'success',1,0,1);
   $running=$logs->start(1,'stock');
   self::assertSame('2026-01-01 10:00:00',$row()['last_sync_at']);
   $db->query('INSERT INTO stores(name,slug) VALUES(?,?)',['Other connection test','connection-'.bin2hex(random_bytes(6))]);$tenant->setStoreId((int)$db->lastInsertId());
   self::assertNull($row()['last_sync_at']);
   $other=$logs->start(1,'orders');$logs->finish($other,'success',0,0);
   $tenant->setStoreId($sid);
   self::assertSame('2026-01-01 10:00:00',$row()['last_sync_at']);
  }finally{$db->rollBack();$_ENV=$env;}
 }
}
