-- Datenbank-Schema für Gewinne4
-- Tabelle: gewinnspiele

CREATE TABLE IF NOT EXISTS `gewinnspiele` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `link_zur_webseite` VARCHAR(255) NOT NULL,
  `beschreibung` TEXT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'aktiv',
  `endet_am` DATETIME NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
