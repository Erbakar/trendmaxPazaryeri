INSERT INTO `settings` (`key`, `value`, `type`, `group`, `label`)
VALUES ('panel_logo_path', '', 'string', 'general', 'Panel Logosu')
ON DUPLICATE KEY UPDATE
    `group` = VALUES(`group`),
    `label` = VALUES(`label`);
