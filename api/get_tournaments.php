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
    $db = \TicTacToe\Database::getInstance();

    // Get query parameters
    $status = $_GET['status'] ?? 'upcoming';
    $limit = (int)($_GET['limit'] ?? 20);
    $offset = (int)($_GET['offset'] ?? 0);
    $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;

    // Validate status
    $validStatuses = ['upcoming', 'active', 'completed', 'cancelled'];
    if (!in_array($status, $validStatuses)) {
        jsonResponse(['error' => 'Invalid status'], 400);
    }

    // Validate limit
    if ($limit < 1 || $limit > 100) {
        jsonResponse(['error' => 'Limit must be between 1 and 100'], 400);
    }

    $query = "
        SELECT
            t.id,
            t.name,
            t.description,
            t.entry_fee,
            t.prize_pool,
            t.max_players,
            t.current_players,
            t.status,
            t.start_time,
            t.end_time,
            t.tournament_type,
            t.allow_bots,
            t.created_at,
            CASE WHEN tp.user_id IS NOT NULL THEN 1 ELSE 0 END as is_registered
        FROM tournaments t
        LEFT JOIN tournament_players tp ON t.id = tp.tournament_id AND tp.user_id = ?
        WHERE t.status = ?
        ORDER BY t.start_time ASC
        LIMIT ? OFFSET ?
    ";

    $stmt = $db->prepare($query);
    $stmt->execute([$userId, $status, $limit, $offset]);

    $tournaments = $stmt->fetchAll();

    // Format response
    $formattedTournaments = array_map(function($tournament) {
        return [
            'id' => (int)$tournament['id'],
            'name' => $tournament['name'],
            'description' => $tournament['description'],
            'entry_fee' => (float)$tournament['entry_fee'],
            'prize_pool' => (float)$tournament['prize_pool'],
            'max_players' => (int)$tournament['max_players'],
            'current_players' => (int)$tournament['current_players'],
            'status' => $tournament['status'],
            'start_time' => $tournament['start_time'],
            'end_time' => $tournament['end_time'],
            'tournament_type' => $tournament['tournament_type'],
            'allow_bots' => (bool)$tournament['allow_bots'],
            'is_registered' => (bool)$tournament['is_registered'],
            'spots_left' => (int)$tournament['max_players'] - (int)$tournament['current_players'],
            'created_at' => $tournament['created_at']
        ];
    }, $tournaments);

    jsonResponse([
        'success' => true,
        'tournaments' => $formattedTournaments,
        'total' => count($formattedTournaments),
        'limit' => $limit,
        'offset' => $offset
    ]);

} catch (\Exception $e) {
    error_log('Get tournaments error: ' . $e->getMessage());
    jsonResponse(['error' => 'Internal server error'], 500);
}