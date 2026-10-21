-- Ana platform admin (admin@trendmaxtr.com) sifre guncelleme — 2026-10-15 (bcrypt cost 12)

UPDATE `admin_users`
SET `password_hash` = '$2y$12$/6IPnsJqiC4F2Ibp9F175udR4qjVIYtPf8Ih2PmGRVOwXrmcpVRy2',
    `updated_at` = NOW()
WHERE `email` = 'admin@trendmaxtr.com'
LIMIT 1;

UPDATE `users`
SET `password_hash` = '$2y$12$/6IPnsJqiC4F2Ibp9F175udR4qjVIYtPf8Ih2PmGRVOwXrmcpVRy2',
    `updated_at` = NOW()
WHERE `email` = 'admin@trendmaxtr.com'
LIMIT 1;
