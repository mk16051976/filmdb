-- ============================================================
--  filmdb – Vollständiges Datenbankschema
--  Einmalig ausführen: phpMyAdmin → SQL (oder via MySQL-CLI)
--  Erstellt alle Tabellen inkl. Composite-Indizes.
-- ============================================================

CREATE DATABASE IF NOT EXISTS filmdb
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE filmdb;

-- ── 1. users ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id             INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    username       VARCHAR(100)    NOT NULL,
    email          VARCHAR(255)    NOT NULL,
    password       VARCHAR(255)    NOT NULL,
    gender         VARCHAR(20)     NULL,
    nationality    VARCHAR(100)    NULL,
    birth_year     SMALLINT        NULL,
    favorite_genre VARCHAR(100)    NULL,
    role           VARCHAR(50)     NOT NULL DEFAULT 'Bewerter',
    blocked        TINYINT(1)      NOT NULL DEFAULT 0,
    created_at     TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email    (email),
    UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin-Rolle dem vorgesehenen Benutzer zuweisen (No-op wenn Benutzer noch nicht existiert)
-- Nach der ersten Registrierung von 'mk16051976' diesen Befehl erneut ausführen:
-- UPDATE users SET role = 'Admin' WHERE username = 'mk16051976';


-- ── 2. movies ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS movies (
    id             INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    title          VARCHAR(255)    NOT NULL,
    original_title VARCHAR(255)    NULL,
    year           SMALLINT        NULL,
    genre          VARCHAR(255)    NULL,
    tmdb_id        INT             NULL,
    poster_path    VARCHAR(255)    NULL,
    overview       TEXT            NULL,
    director       VARCHAR(255)    NULL,
    actors         TEXT            NULL,
    country        VARCHAR(255)    NULL,
    imdb_id        VARCHAR(20)     NULL,
    created_at     TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tmdb_id (tmdb_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 3. user_ratings ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_ratings (
    user_id     INT UNSIGNED    NOT NULL,
    movie_id    INT UNSIGNED    NOT NULL,
    elo         SMALLINT        NOT NULL DEFAULT 1500,
    wins        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    losses      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    comparisons SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, movie_id),
    INDEX idx_user_movie (user_id, movie_id),
    INDEX idx_user_comp  (user_id, comparisons),
    INDEX idx_user_elo   (user_id, elo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 4. comparisons ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS comparisons (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    winner_id       INT UNSIGNED    NOT NULL,
    loser_id        INT UNSIGNED    NOT NULL,
    prev_winner_elo SMALLINT        NULL,
    prev_loser_elo  SMALLINT        NULL,
    created_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_winner_loser (user_id, winner_id, loser_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 5. user_position_ranking ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_position_ranking (
    user_id    INT UNSIGNED    NOT NULL,
    movie_id   INT UNSIGNED    NOT NULL,
    position   INT UNSIGNED    NOT NULL,
    updated_at TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, movie_id),
    INDEX idx_user_pos       (user_id, position),
    INDEX idx_movie_pos      (movie_id, position),
    INDEX idx_user_pos_movie (user_id, position, movie_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 6. login_attempts ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    ip           VARCHAR(45)     NOT NULL,
    attempted_at TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 7. password_resets ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS password_resets (
    id         INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED    NOT NULL,
    token      VARCHAR(64)     NOT NULL,
    expires_at DATETIME        NOT NULL,
    created_at TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token  (token),
    INDEX      idx_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 8. user_tournaments ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_tournaments (
    id            INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED    NOT NULL,
    film_count    SMALLINT UNSIGNED NOT NULL,
    total_rounds  TINYINT UNSIGNED  NOT NULL,
    current_round TINYINT UNSIGNED  NOT NULL DEFAULT 1,
    status        ENUM('active','completed') NOT NULL DEFAULT 'active',
    created_at    TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 9. tournament_films ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tournament_films (
    tournament_id INT UNSIGNED    NOT NULL,
    movie_id      INT UNSIGNED    NOT NULL,
    seed          SMALLINT UNSIGNED NOT NULL,
    points        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    bye           TINYINT(1)      NOT NULL DEFAULT 0,
    PRIMARY KEY (tournament_id, movie_id),
    INDEX idx_seed (tournament_id, seed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 10. tournament_matches ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tournament_matches (
    id            INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    tournament_id INT UNSIGNED    NOT NULL,
    runde         TINYINT UNSIGNED  NOT NULL,
    match_number  SMALLINT UNSIGNED NOT NULL,
    movie_a_id    INT UNSIGNED    NOT NULL,
    movie_b_id    INT UNSIGNED    NOT NULL,
    winner_id     INT UNSIGNED    NULL,
    INDEX      idx_pending      (tournament_id, runde, winner_id),
    INDEX      idx_tid_winner   (tournament_id, winner_id),
    INDEX      idx_tid_runde_win(tournament_id, runde, winner_id),
    UNIQUE KEY uq_match         (tournament_id, runde, match_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 11. tournament_results ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tournament_results (
    id             INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    tournament_id  INT UNSIGNED    NOT NULL,
    user_id        INT UNSIGNED    NOT NULL,
    movie_id       INT UNSIGNED    NOT NULL,
    wins           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    matches_played SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    score          FLOAT           NOT NULL DEFAULT 0,
    created_at     TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    INDEX      idx_user       (user_id),
    INDEX      idx_tournament (tournament_id),
    UNIQUE KEY uq_tm          (tournament_id, movie_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 12. liga_sessions ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS liga_sessions (
    id         INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED    NOT NULL,
    film_count SMALLINT UNSIGNED NOT NULL,
    status     ENUM('active','completed') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 13. liga_matches ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS liga_matches (
    id         INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    liga_id    INT UNSIGNED    NOT NULL,
    movie_a_id INT UNSIGNED    NOT NULL,
    movie_b_id INT UNSIGNED    NOT NULL,
    winner_id  INT UNSIGNED    NULL,
    voted_at   TIMESTAMP       NULL,
    INDEX      idx_liga_pending (liga_id, winner_id),
    UNIQUE KEY uq_pair          (liga_id, movie_a_id, movie_b_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 14. forum_categories ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS forum_categories (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    sort_order  INT NOT NULL DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 15. forum_threads ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS forum_threads (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id  INT UNSIGNED NOT NULL,
    user_id      INT UNSIGNED NOT NULL,
    title        VARCHAR(200) NOT NULL,
    views        INT UNSIGNED NOT NULL DEFAULT 0,
    locked       TINYINT(1) NOT NULL DEFAULT 0,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_post_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cat  (category_id),
    INDEX idx_last (last_post_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 16. forum_posts ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS forum_posts (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thread_id  INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    body       TEXT NOT NULL,
    edited_at  DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_thread (thread_id),
    INDEX idx_user   (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  Nach der Installation:
--  1. Filme importieren: /filmdb/import.php
--  2. Nach Registrierung von 'mk16051976':
--     UPDATE users SET role = 'Admin' WHERE username = 'mk16051976';
-- ============================================================
