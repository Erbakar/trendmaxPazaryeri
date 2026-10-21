<?php
$pdo = new PDO('mysql:host=localhost;dbname=OzyConnect;charset=utf8mb4', 'root', '');
$pdo->exec("UPDATE marketplaces SET is_active = 1 WHERE slug = 'pttavm'");
echo "PttAVM aktif edildi\n";
$row = $pdo->query("SELECT id, slug, display_name, is_active FROM marketplaces WHERE slug = 'pttavm'")->fetch(PDO::FETCH_ASSOC);
echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
