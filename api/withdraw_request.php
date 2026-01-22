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
    if (!isset($input['user_id']) || !isset($input['amount']) || !isset($input['upi_id'])) {
        jsonResponse(['error' => 'Missing required fields: user_id, amount, upi_id'], 400);
    }

    $userId = (int)$input['user_id'];
    $amount = (float)$input['amount'];
    $upiId = sanitizeInput($input['upi_id']);

    if ($amount <= 0) {
        jsonResponse(['error' => 'Invalid amount'], 400);
    }

    $db = \TicTacToe\Database::getInstance();

    // Get settings
    $minWithdrawal = (float)getSetting('min_withdrawal');
    $maxWithdrawal = (float)getSetting('max_withdrawal');

    if ($amount < $minWithdrawal || $amount > $maxWithdrawal) {
        jsonResponse(['error' => "Amount must be between ₹{$minWithdrawal} and ₹{$maxWithdrawal}"], 400);
    }

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

    // Check wallet balance
    $currentBalance = (float)$user['wallet_balance'];
    if ($currentBalance < $amount) {
        jsonResponse(['error' => 'Insufficient wallet balance'], 400);
    }

    // Check for pending withdrawal requests
    $stmt = $db->prepare("SELECT id FROM withdraw_requests WHERE user_id = ? AND status = 'pending'");
    $stmt->execute([$userId]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => 'You have a pending withdrawal request'], 400);
    }

    // Validate UPI ID format (basic validation)
    if (!preg_match('/^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+$/', $upiId)) {
        jsonResponse(['error' => 'Invalid UPI ID format'], 400);
    }

    // Create withdrawal request
    $stmt = $db->prepare("
        INSERT INTO withdraw_requests (user_id, amount, upi_id, status)
        VALUES (?, ?, ?, 'pending')
    ");
    $stmt->execute([$userId, $amount, $upiId]);

    $requestId = $db->lastInsertId();

    jsonResponse([
        'success' => true,
        'message' => 'Withdrawal request submitted successfully',
        'request_id' => $requestId,
        'amount' => $amount,
        'upi_id' => $upiId
    ]);

} catch (\Exception $e) {
    error_log('Withdraw request error: ' . $e->getMessage());
    jsonResponse(['error' => 'Internal server error'], 500);
}