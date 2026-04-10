-- ============================================================
-- reset-testdaten.sql
-- Löscht ALLE Testdaten aus der MKFB-Datenbank.
--
-- ERHALTEN (nicht gelöscht):
--   movies, news_posts, project_slides  – Filmdaten & Inhalte
--   team_members                        – Team-Seite (Admin-Inhalt)
--   tournament_pool                     – Turnier-Pool (Admin-konfiguriert)
--
-- GELÖSCHT: alle User-Daten inkl. ID=1, Sessions, Bewertungen, Duelle, Ranglisten
--
-- Verwendung: In phpMyAdmin importieren oder per MySQL-CLI ausführen.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE `login_attempts`;
TRUNCATE TABLE `password_resets`;
TRUNCATE TABLE `news_comments`;
TRUNCATE TABLE `fuenf_sessions`;
TRUNCATE TABLE `film_insert_sessions`;
TRUNCATE TABLE `duel_sessions`;
TRUNCATE TABLE `jgj_pool`;
TRUNCATE TABLE `jgj_results`;
TRUNCATE TABLE `sort_sessions`;
TRUNCATE TABLE `liga_matches`;
TRUNCATE TABLE `liga_sessions`;
TRUNCATE TABLE `tournament_results`;
TRUNCATE TABLE `tournament_matches`;
TRUNCATE TABLE `tournament_films`;
TRUNCATE TABLE `user_tournaments`;
TRUNCATE TABLE `user_position_ranking`;
TRUNCATE TABLE `user_ratings`;
TRUNCATE TABLE `comparisons`;
TRUNCATE TABLE `user_collection`;
TRUNCATE TABLE `users`;

SET FOREIGN_KEY_CHECKS = 1;
