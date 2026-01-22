<?php
// Tournament Processing CRON Job
// Run every 5 minutes to process tournament matches and timeouts

require_once __DIR__ . '/../config/config.php';

try {
    $db = \TicTacToe\Database::getInstance();

    // Process active tournaments
    $stmt = $db->prepare("
        SELECT id, name, status, start_time, max_players, current_players
        FROM tournaments
        WHERE status IN ('upcoming', 'active')
        ORDER BY start_time ASC
    ");
    $stmt->execute();
    $tournaments = $stmt->fetchAll();

    foreach ($tournaments as $tournament) {
        // Start tournament if time has come
        if ($tournament['status'] === 'upcoming' && strtotime($tournament['start_time']) <= time()) {
            startTournament($tournament['id']);
        }

        // Process active tournament matches
        if ($tournament['status'] === 'active') {
            processTournamentMatches($tournament['id']);
        }
    }

    echo "Tournament processing completed successfully\n";

} catch (\Exception $e) {
    error_log('Tournament processing CRON error: ' . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
}

function startTournament($tournamentId) {
    global $db;

    try {
        $db->beginTransaction();

        // Get tournament players
        $stmt = $db->prepare("
            SELECT tp.id, tp.user_id, tp.bot_id, tp.position, u.username
            FROM tournament_players tp
            LEFT JOIN users u ON tp.user_id = u.id
            WHERE tp.tournament_id = ? AND tp.status = 'registered'
            ORDER BY tp.position ASC
        ");
        $stmt->execute([$tournamentId]);
        $players = $stmt->fetchAll();

        if (count($players) < 2) {
            // Not enough players, cancel tournament
            $stmt = $db->prepare("UPDATE tournaments SET status = 'cancelled' WHERE id = ?");
            $stmt->execute([$tournamentId]);
            $db->commit();
            return;
        }

        // Create first round matches
        $round = 1;
        $matchNumber = 1;

        for ($i = 0; $i < count($players); $i += 2) {
            if (isset($players[$i + 1])) {
                $player1 = $players[$i];
                $player2 = $players[$i + 1];

                $player1Id = $player1['user_id'] ?: $player1['bot_id'];
                $player1Type = $player1['user_id'] ? 'user' : 'bot';
                $player2Id = $player2['user_id'] ?: $player2['bot_id'];
                $player2Type = $player2['user_id'] ? 'user' : 'bot';

                $stmt = $db->prepare("
                    INSERT INTO matches (
                        tournament_id, player1_id, player2_id, player1_type, player2_type,
                        round, match_number, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
                ");
                $stmt->execute([
                    $tournamentId, $player1Id, $player2Id, $player1Type, $player2Type,
                    $round, $matchNumber
                ]);

                $matchNumber++;
            }
        }

        // Update tournament status
        $stmt = $db->prepare("UPDATE tournaments SET status = 'active' WHERE id = ?");
        $stmt->execute([$tournamentId]);

        $db->commit();

        echo "Tournament {$tournamentId} started with " . count($players) . " players\n";

    } catch (\Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

function processTournamentMatches($tournamentId) {
    global $db;

    // Check for timeout matches
    $moveTimeout = (int)getSetting('move_timeout');
    $timeoutThreshold = date('Y-m-d H:i:s', time() - ($moveTimeout * 60));

    $stmt = $db->prepare("
        SELECT id, player1_id, player1_type, player2_id, player2_type, current_turn, last_move_time
        FROM matches
        WHERE tournament_id = ? AND status = 'active' AND last_move_time < ?
    ");
    $stmt->execute([$tournamentId, $timeoutThreshold]);
    $timeoutMatches = $stmt->fetchAll();

    foreach ($timeoutMatches as $match) {
        // Mark as timeout - current turn player loses
        $loserId = $match['current_turn'] === 'player1' ? $match['player1_id'] : $match['player2_id'];
        $loserType = $match['current_turn'] === 'player1' ? $match['player1_type'] : $match['player2_type'];
        $winnerId = $match['current_turn'] === 'player1' ? $match['player2_id'] : $match['player1_id'];
        $winnerType = $match['current_turn'] === 'player1' ? $match['player2_type'] : $match['player1_type'];

        $stmt = $db->prepare("
            UPDATE matches
            SET status = 'timeout', winner_id = ?, winner_type = ?, completed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$winnerId, $winnerType, $match['id']]);

        // Update player status
        updatePlayerStatus($tournamentId, $winnerId, $winnerType, 'winner');
        updatePlayerStatus($tournamentId, $loserId, $loserType, 'eliminated');

        echo "Match {$match['id']} timeout - winner: {$winnerId} ({$winnerType})\n";
    }

    // Check for completed matches and advance tournament
    $stmt = $db->prepare("
        SELECT id, winner_id, winner_type, round
        FROM matches
        WHERE tournament_id = ? AND status IN ('completed', 'draw', 'timeout')
        AND id NOT IN (
            SELECT DISTINCT match_id FROM game_moves WHERE tournament_id = ?
        )
    ");
    $stmt->execute([$tournamentId, $tournamentId]);
    $completedMatches = $stmt->fetchAll();

    if (!empty($completedMatches)) {
        advanceTournament($tournamentId, $completedMatches);
    }

    // Check if tournament is complete
    $stmt = $db->prepare("
        SELECT COUNT(*) as total_matches,
               COUNT(CASE WHEN status IN ('completed', 'draw', 'timeout') THEN 1 END) as completed_matches
        FROM matches WHERE tournament_id = ?
    ");
    $stmt->execute([$tournamentId]);
    $matchStats = $stmt->fetch();

    if ($matchStats['total_matches'] > 0 && $matchStats['total_matches'] === $matchStats['completed_matches']) {
        // Tournament complete - distribute prizes
        distributeTournamentPrizes($tournamentId);
    }
}

function updatePlayerStatus($tournamentId, $playerId, $playerType, $status) {
    global $db;

    if ($playerType === 'user') {
        $stmt = $db->prepare("
            UPDATE tournament_players
            SET status = ?
            WHERE tournament_id = ? AND user_id = ?
        ");
        $stmt->execute([$status, $tournamentId, $playerId]);
    } else {
        $stmt = $db->prepare("
            UPDATE tournament_players
            SET status = ?
            WHERE tournament_id = ? AND bot_id = ?
        ");
        $stmt->execute([$status, $tournamentId, $playerId]);
    }
}

function advanceTournament($tournamentId, $completedMatches) {
    global $db;

    // Get current round
    $currentRound = $completedMatches[0]['round'];

    // Get winners from completed matches
    $winners = [];
    foreach ($completedMatches as $match) {
        if ($match['winner_id']) {
            $winners[] = [
                'id' => $match['winner_id'],
                'type' => $match['winner_type']
            ];
        }
    }

    if (count($winners) < 2) {
        // Tournament complete
        return;
    }

    // Create next round matches
    $nextRound = $currentRound + 1;
    $matchNumber = 1;

    for ($i = 0; $i < count($winners); $i += 2) {
        if (isset($winners[$i + 1])) {
            $player1 = $winners[$i];
            $player2 = $winners[$i + 1];

            $stmt = $db->prepare("
                INSERT INTO matches (
                    tournament_id, player1_id, player2_id, player1_type, player2_type,
                    round, match_number, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([
                $tournamentId,
                $player1['id'], $player2['id'],
                $player1['type'], $player2['type'],
                $nextRound, $matchNumber
            ]);

            $matchNumber++;
        }
    }

    echo "Advanced tournament {$tournamentId} to round {$nextRound}\n";
}

function distributeTournamentPrizes($tournamentId) {
    global $db;

    try {
        $db->beginTransaction();

        // Get tournament details
        $stmt = $db->prepare("SELECT prize_pool, commission_percentage FROM tournaments WHERE id = ?");
        $stmt->execute([$tournamentId]);
        $tournament = $stmt->fetch();

        if (!$tournament) {
            return;
        }

        // Get tournament winner
        $stmt = $db->prepare("
            SELECT winner_id, winner_type
            FROM matches
            WHERE tournament_id = ? AND round = (
                SELECT MAX(round) FROM matches WHERE tournament_id = ?
            ) AND winner_id IS NOT NULL
            LIMIT 1
        ");
        $stmt->execute([$tournamentId, $tournamentId]);
        $finalMatch = $stmt->fetch();

        if ($finalMatch && $finalMatch['winner_type'] === 'user') {
            $winnerId = $finalMatch['winner_id'];
            $prizeAmount = (float)$tournament['prize_pool'];

            // Credit winner
            $creditSuccess = \TicTacToe\Wallet::credit(
                $winnerId,
                $prizeAmount,
                "Tournament prize - Tournament #{$tournamentId}",
                'tournament_win',
                "tournament_{$tournamentId}"
            );

            if ($creditSuccess) {
                // Update tournament status
                $stmt = $db->prepare("UPDATE tournaments SET status = 'completed', end_time = NOW() WHERE id = ?");
                $stmt->execute([$tournamentId]);

                echo "Tournament {$tournamentId} completed. Winner: {$winnerId}, Prize: ₹{$prizeAmount}\n";
            }
        }

        $db->commit();

    } catch (\Exception $e) {
        $db->rollBack();
        error_log('Prize distribution error: ' . $e->getMessage());
    }
}