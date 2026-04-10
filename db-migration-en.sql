-- ============================================================
-- db-migration-en.sql
-- Fügt englische Filmfelder zur movies-Tabelle hinzu.
-- Einmalig in phpMyAdmin ausführen.
-- ============================================================

ALTER TABLE `movies`
    ADD COLUMN IF NOT EXISTS `overview_en`    TEXT          NULL DEFAULT NULL AFTER `overview`,
    ADD COLUMN IF NOT EXISTS `poster_path_en` VARCHAR(255)  NULL DEFAULT NULL AFTER `poster_path`,
    ADD COLUMN IF NOT EXISTS `en_fetched`     TINYINT(1)    NOT NULL DEFAULT 0 AFTER `poster_path_en`;

-- Index für Batch-Fetch (nur noch nicht geholte Filme)
CREATE INDEX IF NOT EXISTS `idx_en_fetched` ON `movies` (`en_fetched`);
