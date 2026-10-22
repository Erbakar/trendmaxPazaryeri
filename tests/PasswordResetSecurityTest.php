<?php
declare(strict_types=1);

use App\Core\{Container,Database};
use App\Services\PasswordResetService;
use PHPUnit\Framework\TestCase;

final class PasswordResetSecurityTest extends TestCase
{
    public function testMissingOrFailedMailNeverDisclosesRecoveryToken(): void
    {
        if (getenv('TRENDMAX_LOCAL_INTEGRATION') !== '1') $this->markTestSkipped('Local DB only.');
        $saved = $_ENV;
        foreach (file(BASE_PATH.'/.env', FILE_IGNORE_NEW_LINES) as $line) {
            if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) continue;
            [$k,$v] = explode('=', $line, 2); $_ENV[trim($k)] = trim($v, " \t\"'");
        }
        if (($_ENV['DB_NAME']??'') !== 'trendmax_local' || ($_ENV['DB_HOST']??'') !== '127.0.0.1') throw new RuntimeException('Local DB only.');
        $c = new Container([]); $db = $c->get(Database::class); $db->beginTransaction();
        try {
            $email = 'recovery-'.bin2hex(random_bytes(6)).'@example.com';
            $db->query('INSERT INTO stores(name,slug) VALUES(?,?)', ['Recovery test','recovery-'.bin2hex(random_bytes(6))]);
            $sid = (int)$db->lastInsertId();
            $db->query('INSERT INTO users(store_id,name,email,password_hash,role) VALUES(?,?,?,?,?)', [$sid,'Fixture',$email,password_hash('fixture-only-password',PASSWORD_DEFAULT),'admin']);
            $service = $c->get(PasswordResetService::class);
            $_ENV['SMTP_HOST'] = ''; $_ENV['SMTP_USER'] = '';
            $missing = $service->requestReset($email);
            self::assertSame($service->requestReset('not-present-'.$email), $missing);
            self::assertArrayNotHasKey('token', $missing);
            self::assertSame(0, (int)$db->fetchOne('SELECT COUNT(*) n FROM password_resets WHERE email=?', [$email])['n']);
            $_ENV['SMTP_HOST'] = '127.0.0.1'; $_ENV['SMTP_PORT'] = '1';
            $_ENV['SMTP_USER'] = 'fixture'; $_ENV['SMTP_PASS'] = 'fixture';
            $_ENV['SMTP_FROM_EMAIL'] = 'fixture@example.com'; $_ENV['SMTP_ENCRYPTION'] = '';
            $failed = $service->requestReset($email);
            self::assertSame($missing, $failed);
            self::assertSame(0, (int)$db->fetchOne('SELECT COUNT(*) n FROM password_resets WHERE email=?', [$email])['n']);
            $db->query('UPDATE users SET is_active=0 WHERE email=?', [$email]);
            self::assertSame($missing, $service->requestReset($email));
        } finally {
            $db->rollBack(); $_ENV = $saved;
        }
    }
}
