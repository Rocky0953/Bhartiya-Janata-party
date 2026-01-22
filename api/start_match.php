<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        jsonResponse(['error' => 'Invalid JSON input'], 400);
    }

    // Validate required fields
    if (!isset($input['user_id']) || !isset($input['match_id'])) {
        jsonResponse(['error' => 'Missing required fields: user_id, match_id'], 400);
    }

    $userId = (int)$input['user_id'];
    $matchId = (int)$input['match_id'];

    $db = \TicTacToe\Database::getInstance();

    // Get match details
    $stmt = $db->prepare("SELECT * FROM matches WHERE id = ?");
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();

    if (!$match) {
        jsonResponse(['error' => 'Match not found'], 404);
    }

    if ($match['status'] !== 'pending') {
        jsonResponse(['error' => 'Match is not pending'], 400);
    }

    // Verify user is a player in this match
    $isPlayer1 = $match['player1_id'] == $userId && $match['player1_type'] === 'user';
    $isPlayer2 = $match['player2_id'] == $userId && $match['player2_type'] === 'user';

    if (!$isPlayer1 && !$isPlayer2) {
        jsonResponse(['error' => 'User is not a player in this match'], 403);
    }

    // Start the match
    $stmt = $db->prepare("
        UPDATE matches
        SET status = 'active', started_at = NOW(), last_move_time = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$matchId]);

    jsonResponse([
        'success' => true,
        'message' => 'Match started successfully',
        'match_id' => $matchId,
        'board_state' => $match['board_state'],
        'current_turn' => $match['current_turn'],
        'player1_type' => $match['player1_type'],
        'player2_type' => $match['player2_type']
    ]);

} catch (\Exception $e) {
    error_log('Start match error: ' . $e->getMessage());
    jsonResponse(['error' => 'Internal server error'], 500);
}