-- Empfohlene Composite-Indizes für filmdb
-- Einmalig ausführen: phpMyAdmin → filmdb → SQL

-- user_ratings: Beschleunigt Ranking- und Duel-Queries (JOIN auf user_id + movie_id)
ALTER TABLE user_ratings
    ADD INDEX IF NOT EXISTS idx_user_movie     (user_id, movie_id),
    ADD INDEX IF NOT EXISTS idx_user_comp      (user_id, comparisons),
    ADD INDEX IF NOT EXISTS idx_user_elo       (user_id, elo);

-- user_position_ranking: Beschleunigt Community-Rangliste (GROUP BY movie_id + AVG)
ALTER TABLE user_position_ranking
    ADD INDEX IF NOT EXISTS idx_movie_pos      (movie_id, position),
    ADD INDEX IF NOT EXISTS idx_user_pos_movie (user_id, position, movie_id);

-- comparisons: Beschleunigt Undo-Queries (ORDER BY id DESC)
ALTER TABLE comparisons
    ADD INDEX IF NOT EXISTS idx_user_winner_loser (user_id, winner_id, loser_id);

-- tournament_matches: Beschleunigt Runden-Fortschritt-Queries
ALTER TABLE tournament_matches
    ADD INDEX IF NOT EXISTS idx_tid_winner    (tournament_id, winner_id),
    ADD INDEX IF NOT EXISTS idx_tid_runde_win (tournament_id, runde, winner_id);

-- movies: Beschleunigt TMDB-Duplikat-Check
ALTER TABLE movies
    ADD INDEX IF NOT EXISTS idx_tmdb_id (tmdb_id);
