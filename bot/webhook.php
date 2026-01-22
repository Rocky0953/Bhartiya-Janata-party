<?php
// Telegram Bot Webhook Handler
// This handles all incoming messages and callback queries from Telegram

require_once __DIR__ . '/../config/config.php';

$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update) {
    http_response_code(400);
    exit('Invalid webhook data');
}

// Log webhook for debugging
error_log('Telegram webhook received: ' . $input);

try {
    if (isset($update['message'])) {
        handleMessage($update['message']);
    } elseif (isset($update['callback_query'])) {
        handleCallbackQuery($update['callback_query']);
    }

    // Respond with OK to Telegram
    echo 'OK';

} catch (\Exception $e) {
    error_log('Telegram webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Error';
}

function handleMessage($message) {
    $chatId = $message['chat']['id'];
    $text = $message['text'] ?? '';
    $userId = $message['from']['id'];

    // Register user if not exists
    registerTelegramUser($message['from']);

    switch (strtolower($text)) {
        case '/start':
            sendWelcomeMessage($chatId);
            break;

        case '/play':
        case 'play':
            sendWebAppLink($chatId);
            break;

        case '/wallet':
        case 'wallet':
            sendWalletInfo($chatId, $userId);
            break;

        case '/tournaments':
        case 'tournaments':
            sendTournamentsInfo($chatId);
            break;

        case '/help':
        case 'help':
            sendHelpMessage($chatId);
            break;

        default:
            // Check for referral code in /start command
            if (strpos($text, '/start ') === 0) {
                $parts = explode(' ', $text);
                if (isset($parts[1]) && strlen($parts[1]) === 6) {
                    // This is a referral link
                    sendWelcomeMessage($chatId, $parts[1]);
                } else {
                    sendWelcomeMessage($chatId);
                }
            } else {
                sendUnknownCommandMessage($chatId);
            }
            break;
    }
}

function handleCallbackQuery($callbackQuery) {
    $callbackId = $callbackQuery['id'];
    $chatId = $callbackQuery['message']['chat']['id'];
    $data = $callbackQuery['data'];
    $userId = $callbackQuery['from']['id'];

    // Answer callback query
    answerCallbackQuery($callbackId);

    switch ($data) {
        case 'play_game':
            sendWebAppLink($chatId);
            break;

        case 'wallet_info':
            sendWalletInfo($chatId, $userId);
            break;

        case 'tournaments':
            sendTournamentsInfo($chatId);
            break;

        case 'help':
            sendHelpMessage($chatId);
            break;

        default:
            // Handle tournament join callbacks
            if (strpos($data, 'join_tournament_') === 0) {
                $tournamentId = str_replace('join_tournament_', '', $data);
                handleTournamentJoin($chatId, $userId, $tournamentId);
            }
            break;
    }
}

function registerTelegramUser($userData) {
    $db = \TicTacToe\Database::getInstance();

    $telegramId = $userData['id'];
    $username = $userData['username'] ?? null;
    $firstName = $userData['first_name'] ?? '';
    $lastName = $userData['last_name'] ?? '';

    // Check if user exists
    $stmt = $db->prepare("SELECT id FROM users WHERE telegram_id = ?");
    $stmt->execute([$telegramId]);

    if (!$stmt->fetch()) {
        // Generate referral code
        $referralCode = strtoupper(substr(md5(uniqid()), 0, 6));

        // Register user
        $stmt = $db->prepare("
            INSERT INTO users (telegram_id, username, first_name, last_name, referral_code)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$telegramId, $username, $firstName, $lastName, $referralCode]);
    }
}

function sendWelcomeMessage($chatId, $referralCode = null) {
    $text = "🎮 Welcome to Tic-Tac-Toe Tournament!\n\n";
    $text .= "🏆 Play real-money tournaments\n";
    $text .= "💰 Win prizes in INR\n";
    $text .= "🤖 Play vs AI or real players\n\n";
    $text .= "Use /play to start playing!";

    if ($referralCode) {
        $text .= "\n\n📱 You were referred by: {$referralCode}";
    }

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🎮 Play Game', 'callback_data' => 'play_game'],
                ['text' => '💰 Wallet', 'callback_data' => 'wallet_info']
            ],
            [
                ['text' => '🏆 Tournaments', 'callback_data' => 'tournaments'],
                ['text' => '❓ Help', 'callback_data' => 'help']
            ]
        ]
    ];

    sendMessage($chatId, $text, $keyboard);
}

function sendWebAppLink($chatId) {
    $webAppUrl = TELEGRAM_WEBAPP_URL;

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🎮 Open Game', 'web_app' => ['url' => $webAppUrl]]
            ],
            [
                ['text' => '🏆 View Tournaments', 'callback_data' => 'tournaments'],
                ['text' => '💰 Check Wallet', 'callback_data' => 'wallet_info']
            ]
        ]
    ];

    $text = "🎮 Click below to open the Tic-Tac-Toe Tournament game!\n\n";
    $text .= "💡 Make sure you're on a mobile device for the best experience.";

    sendMessage($chatId, $text, $keyboard);
}

function sendWalletInfo($chatId, $userId) {
    $db = \TicTacToe\Database::getInstance();

    // Get user wallet balance
    $stmt = $db->prepare("SELECT wallet_balance FROM users WHERE telegram_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        sendMessage($chatId, "❌ User not found. Please use /start first.");
        return;
    }

    $balance = number_format($user['wallet_balance'], 2);

    $text = "💰 Your Wallet Balance: ₹{$balance}\n\n";
    $text .= "💳 Add Money:\n";
    $text .= "• Use Kukupay for instant deposits\n";
    $text .= "• Manual UPI payments (admin approval)\n\n";
    $text .= "📤 Withdrawals:\n";
    $text .= "• Minimum: ₹50\n";
    $text .= "• Processed within 24 hours";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🎮 Play Game', 'web_app' => ['url' => TELEGRAM_WEBAPP_URL]]
            ]
        ]
    ];

    sendMessage($chatId, $text, $keyboard);
}

function sendTournamentsInfo($chatId) {
    $db = \TicTacToe\Database::getInstance();

    // Get upcoming tournaments
    $stmt = $db->prepare("
        SELECT id, name, entry_fee, max_players, current_players, start_time
        FROM tournaments
        WHERE status = 'upcoming'
        ORDER BY start_time ASC
        LIMIT 5
    ");
    $stmt->execute();
    $tournaments = $stmt->fetchAll();

    if (empty($tournaments)) {
        $text = "🏆 No upcoming tournaments right now.\n\n";
        $text .= "Check back later or create your own tournament!";
    } else {
        $text = "🏆 Upcoming Tournaments:\n\n";

        $keyboard = ['inline_keyboard' => []];

        foreach ($tournaments as $tournament) {
            $spotsLeft = $tournament['max_players'] - $tournament['current_players'];
            $startTime = date('M j, H:i', strtotime($tournament['start_time']));

            $text .= "🎯 {$tournament['name']}\n";
            $text .= "💰 Entry: ₹{$tournament['entry_fee']}\n";
            $text .= "👥 Players: {$tournament['current_players']}/{$tournament['max_players']}\n";
            $text .= "⏰ Starts: {$startTime}\n\n";

            if ($spotsLeft > 0) {
                $keyboard['inline_keyboard'][] = [
                    ['text' => "Join: {$tournament['name']}", 'callback_data' => 'join_tournament_' . $tournament['id']]
                ];
            }
        }

        $keyboard['inline_keyboard'][] = [
            ['text' => '🎮 Open Game', 'web_app' => ['url' => TELEGRAM_WEBAPP_URL]]
        ];
    }

    sendMessage($chatId, $text, $keyboard);
}

function sendHelpMessage($chatId) {
    $text = "❓ Tic-Tac-Toe Tournament Help\n\n";
    $text .= "🎮 /play - Start playing games\n";
    $text .= "💰 /wallet - Check your balance\n";
    $text .= "🏆 /tournaments - View tournaments\n";
    $text .= "❓ /help - Show this help\n\n";
    $text .= "💡 How to play:\n";
    $text .= "• Join tournaments with entry fees\n";
    $text .= "• Win matches to advance\n";
    $text .= "• Winner takes the prize pool!\n\n";
    $text .= "💳 Payments:\n";
    $text .= "• Kukupay for instant deposits\n";
    $text .= "• Manual UPI with admin approval\n";
    $text .= "• Withdraw winnings anytime";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🎮 Play Now', 'web_app' => ['url' => TELEGRAM_WEBAPP_URL]]
            ]
        ]
    ];

    sendMessage($chatId, $text, $keyboard);
}

function sendUnknownCommandMessage($chatId) {
    $text = "❓ Unknown command.\n\nUse /help to see available commands.";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '❓ Help', 'callback_data' => 'help'],
                ['text' => '🎮 Play', 'callback_data' => 'play_game']
            ]
        ]
    ];

    sendMessage($chatId, $text, $keyboard);
}

function handleTournamentJoin($chatId, $telegramUserId, $tournamentId) {
    // Get user ID from telegram ID
    $db = \TicTacToe\Database::getInstance();
    $stmt = $db->prepare("SELECT id FROM users WHERE telegram_id = ?");
    $stmt->execute([$telegramUserId]);
    $user = $stmt->fetch();

    if (!$user) {
        sendMessage($chatId, "❌ Please use /start first to register.");
        return;
    }

    $userId = $user['id'];

    // Attempt to join tournament via API
    $apiData = [
        'user_id' => $userId,
        'tournament_id' => $tournamentId
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, APP_URL . '/api/join_tournament.php');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($apiData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);

    if ($httpCode === 200 && $result && $result['success']) {
        $message = "✅ Successfully joined tournament!\n\n";
        $message .= "💰 Entry fee deducted: ₹{$result['entry_fee_deducted']}\n";
        $message .= "🎮 Use /play to start playing!";
    } else {
        $error = $result['error'] ?? 'Unknown error';
        $message = "❌ Failed to join tournament: {$error}";
    }

    sendMessage($chatId, $message);
}

function sendMessage($chatId, $text, $keyboard = null) {
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];

    if ($keyboard) {
        $data['reply_markup'] = json_encode($keyboard);
    }

    makeTelegramApiCall('sendMessage', $data);
}

function answerCallbackQuery($callbackId, $text = '') {
    $data = [
        'callback_query_id' => $callbackId
    ];

    if ($text) {
        $data['text'] = $text;
    }

    makeTelegramApiCall('answerCallbackQuery', $data);
}

function makeTelegramApiCall($method, $data) {
    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/{$method}";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("Telegram API error ({$method}): HTTP {$httpCode} - {$response}");
    }

    return $response;
}