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
    if (!isset($input['user_id']) || !isset($input['tournament_id'])) {
        jsonResponse(['error' => 'Missing required fields: user_id, tournament_id'], 400);
    }

    $userId = (int)$input['user_id'];
    $tournamentId = (int)$input['tournament_id'];

    $db = \TicTacToe\Database::getInstance();

    // Verify user exists and is not blocked
    $stmt = $db->prepare("SELECT id, wallet_balance, is_blocked FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(['error' => 'User not found'], 404);
    }

    if ($user['is_blocked']) {
        jsonResponse(['error' => 'Account is blocked'], 403);
    }

    // Get tournament details
    $stmt = $db->prepare("
        SELECT id, name, entry_fee, max_players, current_players, status, start_time
        FROM tournaments
        WHERE id = ?
    ");
    $stmt->execute([$tournamentId]);
    $tournament = $stmt->fetch();

    if (!$tournament) {
        jsonResponse(['error' => 'Tournament not found'], 404);
    }

    if ($tournament['status'] !== 'upcoming') {
        jsonResponse(['error' => 'Tournament is not accepting new players'], 400);
    }

    // Check if tournament is full
    $stmt = $db->prepare("SELECT COUNT(*) as player_count FROM tournament_players WHERE tournament_id = ? AND status IN ('registered', 'active')");
    $stmt->execute([$tournamentId]);
    $playerCount = $stmt->fetch()['player_count'];

    if ($playerCount >= $tournament['max_players']) {
        jsonResponse(['error' => 'Tournament is full'], 400);
    }

    // Check if user is already registered
    $stmt = $db->prepare("SELECT id FROM tournament_players WHERE tournament_id = ? AND user_id = ?");
    $stmt->execute([$tournamentId, $userId]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => 'Already registered for this tournament'], 400);
    }

    // Check wallet balance for entry fee
    $entryFee = (float)$tournament['entry_fee'];
    $userBalance = (float)$user['wallet_balance'];

    if ($userBalance < $entryFee) {
        jsonResponse(['error' => 'Insufficient wallet balance'], 400);
    }

    // Start transaction
    $db->beginTransaction();

    try {
        // Deduct entry fee
        if ($entryFee > 0) {
            $debitSuccess = \TicTacToe\Wallet::debit(
                $userId,
                $entryFee,
                "Tournament entry: {$tournament['name']}",
                'tournament_entry',
                "tournament_{$tournamentId}"
            );

            if (!$debitSuccess) {
                throw new \Exception('Failed to deduct entry fee');
            }
        }

        // Register player
        $stmt = $db->prepare("
            INSERT INTO tournament_players (tournament_id, user_id, position, status)
            VALUES (?, ?, ?, 'registered')
        ");
        $stmt->execute([$tournamentId, $userId, $playerCount + 1]);

        // Update tournament player count
        $stmt = $db->prepare("UPDATE tournaments SET current_players = current_players + 1 WHERE id = ?");
        $stmt->execute([$tournamentId]);

        $db->commit();

        jsonResponse([
            'success' => true,
            'message' => 'Successfully joined tournament',
            'tournament_id' => $tournamentId,
            'entry_fee_deducted' => $entryFee
        ]);

    } catch (\Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (\Exception $e) {
    error_log('Join tournament error: ' . $e->getMessage());
    jsonResponse(['error' => 'Internal server error'], 500);
}