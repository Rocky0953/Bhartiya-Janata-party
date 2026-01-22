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

    // Validate Telegram WebApp data
    if (!isset($input['initData'])) {
        jsonResponse(['error' => 'Missing Telegram initData'], 400);
    }

    // Parse Telegram init data (simplified validation)
    parse_str($input['initData'], $telegramData);

    if (!isset($telegramData['user'])) {
        jsonResponse(['error' => 'Invalid Telegram user data'], 400);
    }

    $userData = json_decode($telegramData['user'], true);

    if (!$userData || !isset($userData['id'])) {
        jsonResponse(['error' => 'Invalid user data'], 400);
    }

    $telegramId = (int)$userData['id'];
    $username = $userData['username'] ?? null;
    $firstName = $userData['first_name'] ?? '';
    $lastName = $userData['last_name'] ?? '';

    // Check if user already exists
    $db = \TicTacToe\Database::getInstance();
    $stmt = $db->prepare("SELECT id, is_blocked FROM users WHERE telegram_id = ?");
    $stmt->execute([$telegramId]);
    $existingUser = $stmt->fetch();

    if ($existingUser) {
        if ($existingUser['is_blocked']) {
            jsonResponse(['error' => 'Account is blocked'], 403);
        }

        // Update last active
        $stmt = $db->prepare("UPDATE users SET last_active = NOW() WHERE id = ?");
        $stmt->execute([$existingUser['id']]);

        jsonResponse([
            'success' => true,
            'user_id' => $existingUser['id'],
            'message' => 'User already registered'
        ]);
    }

    // Generate referral code
    $referralCode = strtoupper(substr(md5(uniqid()), 0, 6));

    // Check for referral
    $referredBy = null;
    if (isset($input['referral_code']) && !empty($input['referral_code'])) {
        $stmt = $db->prepare("SELECT id FROM users WHERE referral_code = ?");
        $stmt->execute([strtoupper($input['referral_code'])]);
        $referrer = $stmt->fetch();
        if ($referrer) {
            $referredBy = $referrer['id'];
        }
    }

    // Register new user
    $stmt = $db->prepare("
        INSERT INTO users (telegram_id, username, first_name, last_name, referral_code, referred_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$telegramId, $username, $firstName, $lastName, $referralCode, $referredBy]);

    $userId = $db->lastInsertId();

    // Process referral bonus if applicable
    if ($referredBy) {
        $referralBonus = (float)getSetting('referral_bonus');
        if ($referralBonus > 0) {
            \TicTacToe\Wallet::credit($referredBy, $referralBonus, 'Referral bonus', 'referral_bonus');
            \TicTacToe\Wallet::credit($userId, $referralBonus, 'Welcome bonus', 'referral_bonus');

            // Log referral
            $stmt = $db->prepare("
                INSERT INTO referrals (referrer_id, referee_id, bonus_amount, status)
                VALUES (?, ?, ?, 'completed')
            ");
            $stmt->execute([$referredBy, $userId, $referralBonus]);
        }
    }

    jsonResponse([
        'success' => true,
        'user_id' => $userId,
        'referral_code' => $referralCode,
        'message' => 'User registered successfully'
    ]);

} catch (\Exception $e) {
    error_log('User registration error: ' . $e->getMessage());
    jsonResponse(['error' => 'Internal server error'], 500);
}