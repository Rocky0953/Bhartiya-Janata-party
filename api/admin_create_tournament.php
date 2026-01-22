<?php
require_once __DIR__ . '/../config/config.php';

// Check admin authentication
session_start();
if (!isset($_SESSION['admin_id'])) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

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
    $requiredFields = ['name', 'entry_fee', 'max_players', 'start_time'];
    foreach ($requiredFields as $field) {
        if (!isset($input[$field])) {
            jsonResponse(['error' => "Missing required field: {$field}"], 400);
        }
    }

    $name = sanitizeInput($input['name']);
    $description = isset($input['description']) ? sanitizeInput($input['description']) : '';
    $entryFee = (float)$input['entry_fee'];
    $maxPlayers = (int)$input['max_players'];
    $startTime = $input['start_time'];
    $tournamentType = $input['tournament_type'] ?? 'single_elimination';
    $allowBots = isset($input['allow_bots']) ? (bool)$input['allow_bots'] : true;
    $commissionPercentage = isset($input['commission_percentage']) ? (float)$input['commission_percentage'] : (float)getSetting('tournament_commission');

    // Validate data
    if (strlen($name) < 3 || strlen($name) > 255) {
        jsonResponse(['error' => 'Tournament name must be between 3 and 255 characters'], 400);
    }

    if ($entryFee < 0) {
        jsonResponse(['error' => 'Entry fee cannot be negative'], 400);
    }

    if ($maxPlayers < 2 || $maxPlayers > 64) {
        jsonResponse(['error' => 'Max players must be between 2 and 64'], 400);
    }

    if (!in_array($tournamentType, ['single_elimination', 'round_robin'])) {
        jsonResponse(['error' => 'Invalid tournament type'], 400);
    }

    // Validate start time
    $startDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $startTime);
    if (!$startDateTime) {
        jsonResponse(['error' => 'Invalid start time format'], 400);
    }

    if ($startDateTime < new DateTime()) {
        jsonResponse(['error' => 'Start time cannot be in the past'], 400);
    }

    // Calculate prize pool (entry fees minus commission)
    $commissionAmount = $entryFee * ($commissionPercentage / 100);
    $prizePool = ($entryFee - $commissionAmount) * $maxPlayers;

    $db = \TicTacToe\Database::getInstance();

    // Create tournament
    $stmt = $db->prepare("
        INSERT INTO tournaments (
            name, description, entry_fee, prize_pool, max_players, status,
            start_time, tournament_type, allow_bots, commission_percentage, created_by
        ) VALUES (?, ?, ?, ?, ?, 'upcoming', ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $name,
        $description,
        $entryFee,
        $prizePool,
        $maxPlayers,
        $startTime,
        $tournamentType,
        $allowBots,
        $commissionPercentage,
        $_SESSION['admin_id']
    ]);

    $tournamentId = $db->lastInsertId();

    // If bots are allowed, add bot players
    if ($allowBots) {
        $botsToAdd = min($maxPlayers - 1, 6); // Add up to 6 bots, leaving at least 1 spot for human

        for ($i = 1; $i <= $botsToAdd; $i++) {
            $stmt = $db->prepare("
                INSERT INTO tournament_players (tournament_id, bot_id, position, status)
                VALUES (?, ?, ?, 'registered')
            ");
            $stmt->execute([$tournamentId, 1000 + $i, $i, 'registered']);
        }

        // Update current players count
        $stmt = $db->prepare("UPDATE tournaments SET current_players = ? WHERE id = ?");
        $stmt->execute([$botsToAdd, $tournamentId]);
    }

    jsonResponse([
        'success' => true,
        'message' => 'Tournament created successfully',
        'tournament_id' => $tournamentId,
        'prize_pool' => $prizePool,
        'bots_added' => $allowBots ? $botsToAdd : 0
    ]);

} catch (\Exception $e) {
    error_log('Admin create tournament error: ' . $e->getMessage());
    jsonResponse(['error' => 'Internal server error'], 500);
}