# Şifre Sıfırlama Güncelleme Talimatları

## Adım 1: SQL Çalıştır
`password_reset_update.sql` dosyasını phpMyAdmin'de **İçe Aktar (Import)** ile çalıştırın.
(Tablo zaten varsa hata vermez, `IF NOT EXISTS` kullanılmıştır.)

## Adım 2: Dosyaları FTP ile Yükle
Aşağıdaki dosyaları sunucuya **aynı dizin yapısıyla** yükleyin (üzerine yazın):

```
app/Controllers/AuthController.php   → (güncellendi)
app/Models/PasswordReset.php         → (yeni)
app/Services/PasswordResetService.php → (yeni)
app/routes.php                       → (güncellendi)
views/auth/forgot.php                → (yeni)
views/auth/reset.php                 → (yeni)
views/auth/login.php                 → (güncellendi)
```

## Adım 3: Test
1. `/login` sayfasına gidin
2. **Şifremi unuttum** linkine tıklayın
3. E-posta girin → Sıfırlama kodu ekranda görünecek
4. Kodu kullanarak yeni şifre belirleyin

## Not
- SMTP ayarlanmamışsa kod ekranda gösterilir
- SMTP ayarlıysa e-posta ile gönderilir
