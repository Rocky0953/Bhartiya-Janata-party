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
    if (!isset($input['user_id']) || !isset($input['match_id']) || !isset($input['position'])) {
        jsonResponse(['error' => 'Missing required fields: user_id, match_id, position'], 400);
    }

    $userId = (int)$input['user_id'];
    $matchId = (int)$input['match_id'];
    $position = (int)$input['position'];

    if ($position < 0 || $position > 8) {
        jsonResponse(['error' => 'Invalid position'], 400);
    }

    $db = \TicTacToe\Database::getInstance();

    // Get match details with lock
    $stmt = $db->prepare("SELECT * FROM matches WHERE id = ? FOR UPDATE");
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();

    if (!$match) {
        jsonResponse(['error' => 'Match not found'], 404);
    }

    if ($match['status'] !== 'active') {
        jsonResponse(['error' => 'Match is not active'], 400);
    }

    // Verify it's user's turn
    $isPlayer1 = $match['player1_id'] == $userId && $match['player1_type'] === 'user';
    $isPlayer2 = $match['player2_id'] == $userId && $match['player2_type'] === 'user';

    if (!$isPlayer1 && !$isPlayer2) {
        jsonResponse(['error' => 'User is not a player in this match'], 403);
    }

    $currentPlayer = $isPlayer1 ? 'player1' : 'player2';
    $opponentPlayer = $isPlayer1 ? 'player2' : 'player1';
    $playerSymbol = $isPlayer1 ? 'X' : 'O';

    if ($match['current_turn'] !== $currentPlayer) {
        jsonResponse(['error' => 'Not your turn'], 400);
    }

    // Validate move
    if (!\TicTacToe\GameLogic::isValidMove($match['board_state'], $position)) {
        jsonResponse(['error' => 'Invalid move'], 400);
    }

    // Make the move
    $newBoardState = \TicTacToe\GameLogic::makeMove($match['board_state'], $position, $playerSymbol);

    // Count moves for move number
    $stmt = $db->prepare("SELECT COUNT(*) as move_count FROM game_moves WHERE match_id = ?");
    $stmt->execute([$matchId]);
    $moveNumber = $stmt->fetch()['move_count'] + 1;

    // Save move
    $stmt = $db->prepare("
        INSERT INTO game_moves (match_id, player_id, player_type, position, move_number)
        VALUES (?, ?, 'user', ?, ?)
    ");
    $stmt->execute([$matchId, $userId, $position, $moveNumber]);

    // Check game result
    $gameResult = \TicTacToe\GameLogic::getGameResult($newBoardState);
    $winner = null;
    $winnerType = null;
    $status = 'active';

    if ($gameResult['is_game_over']) {
        $status = $gameResult['is_draw'] ? 'draw' : 'completed';
        if (!$gameResult['is_draw']) {
            if ($gameResult['winner'] === $playerSymbol) {
                $winner = $userId;
                $winnerType = 'user';
            } else {
                // Opponent won (shouldn't happen in normal play, but safety check)
                $winner = $match[$opponentPlayer . '_id'];
                $winnerType = $match[$opponentPlayer . '_type'];
            }
        }
    }

    // Update match
    $nextTurn = $status === 'active' ? $opponentPlayer : null;
    $stmt = $db->prepare("
        UPDATE matches
        SET board_state = ?, current_turn = ?, last_move_time = NOW(),
            winner_id = ?, winner_type = ?, status = ?, completed_at = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $newBoardState,
        $nextTurn,
        $winner,
        $winnerType,
        $status,
        $status === 'completed' || $status === 'draw' ? date('Y-m-d H:i:s') : null,
        $matchId
    ]);

    $response = [
        'success' => true,
        'board_state' => $newBoardState,
        'game_over' => $gameResult['is_game_over'],
        'winner' => $gameResult['winner'],
        'is_draw' => $gameResult['is_draw'],
        'status' => $status
    ];

    // If opponent is bot and game continues, make bot move
    if ($status === 'active' && $match[$opponentPlayer . '_type'] === 'bot') {
        $botMove = \TicTacToe\GameLogic::getBotMove($newBoardState, 'medium');
        $botBoardState = \TicTacToe\GameLogic::makeMove($newBoardState, $botMove, $opponentPlayer === 'player1' ? 'X' : 'O');

        // Save bot move
        $stmt = $db->prepare("
            INSERT INTO game_moves (match_id, player_id, player_type, position, move_number)
            VALUES (?, ?, 'bot', ?, ?)
        ");
        $stmt->execute([$matchId, $match[$opponentPlayer . '_id'], $botMove, $moveNumber + 1]);

        // Check bot move result
        $botGameResult = \TicTacToe\GameLogic::getGameResult($botBoardState);
        $botWinner = null;
        $botWinnerType = null;
        $botStatus = 'active';

        if ($botGameResult['is_game_over']) {
            $botStatus = $botGameResult['is_draw'] ? 'draw' : 'completed';
            if (!$botGameResult['is_draw']) {
                if ($botGameResult['winner'] === ($opponentPlayer === 'player1' ? 'X' : 'O')) {
                    $botWinner = $match[$opponentPlayer . '_id'];
                    $botWinnerType = 'bot';
                } else {
                    $botWinner = $userId;
                    $botWinnerType = 'user';
                }
            }
        }

        // Update match with bot move
        $stmt = $db->prepare("
            UPDATE matches
            SET board_state = ?, current_turn = ?, last_move_time = NOW(),
                winner_id = ?, winner_type = ?, status = ?, completed_at = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $botBoardState,
            $botStatus === 'active' ? $currentPlayer : null,
            $botWinner,
            $botWinnerType,
            $botStatus,
            $botStatus === 'completed' || $botStatus === 'draw' ? date('Y-m-d H:i:s') : null,
            $matchId
        ]);

        $response['bot_move'] = [
            'position' => $botMove,
            'board_state' => $botBoardState,
            'game_over' => $botGameResult['is_game_over'],
            'winner' => $botGameResult['winner'],
            'is_draw' => $botGameResult['is_draw'],
            'status' => $botStatus
        ];
    }

    jsonResponse($response);

} catch (\Exception $e) {
    error_log('Save move error: ' . $e->getMessage());
    jsonResponse(['error' => 'Internal server error'], 500);
}