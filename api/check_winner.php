<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

try {
    // Validate required parameters
    if (!isset($_GET['match_id'])) {
        jsonResponse(['error' => 'Missing required parameter: match_id'], 400);
    }

    $matchId = (int)$_GET['match_id'];

    $db = \TicTacToe\Database::getInstance();

    // Get match details
    $stmt = $db->prepare("SELECT * FROM matches WHERE id = ?");
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();

    if (!$match) {
        jsonResponse(['error' => 'Match not found'], 404);
    }

    $gameResult = \TicTacToe\GameLogic::getGameResult($match['board_state']);

    // Check for timeout
    $moveTimeout = (int)getSetting('move_timeout');
    $lastMoveTime = strtotime($match['last_move_time']);
    $currentTime = time();
    $timeSinceLastMove = $currentTime - $lastMoveTime;

    $isTimeout = false;
    $timeoutWinner = null;
    $timeoutWinnerType = null;

    if ($match['status'] === 'active' && $timeSinceLastMove > ($moveTimeout * 60)) {
        $isTimeout = true;

        // Determine who should have moved (opposite of current turn)
        if ($match['current_turn'] === 'player1') {
            $timeoutWinner = $match['player2_id'];
            $timeoutWinnerType = $match['player2_type'];
        } else {
            $timeoutWinner = $match['player1_id'];
            $timeoutWinnerType = $match['player1_type'];
        }

        // Update match as timeout
        $stmt = $db->prepare("
            UPDATE matches
            SET status = 'timeout', winner_id = ?, winner_type = ?, completed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$timeoutWinner, $timeoutWinnerType, $matchId]);
    }

    $response = [
        'success' => true,
        'match_id' => $matchId,
        'board_state' => $match['board_state'],
        'status' => $isTimeout ? 'timeout' : $match['status'],
        'current_turn' => $match['current_turn'],
        'game_over' => $gameResult['is_game_over'] || $isTimeout,
        'winner' => $gameResult['winner'] ?: ($isTimeout ? 'timeout' : null),
        'winner_id' => $isTimeout ? $timeoutWinner : $match['winner_id'],
        'winner_type' => $isTimeout ? $timeoutWinnerType : $match['winner_type'],
        'is_draw' => $gameResult['is_draw'] && !$isTimeout,
        'is_timeout' => $isTimeout,
        'last_move_time' => $match['last_move_time'],
        'time_remaining' => $match['status'] === 'active' ? max(0, ($moveTimeout * 60) - $timeSinceLastMove) : 0
    ];

    jsonResponse($response);

} catch (\Exception $e) {
    error_log('Check winner error: ' . $e->getMessage());
    jsonResponse(['error' => 'Internal server error'], 500);
}