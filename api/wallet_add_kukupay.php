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
    if (!isset($input['user_id']) || !isset($input['amount'])) {
        jsonResponse(['error' => 'Missing required fields: user_id, amount'], 400);
    }

    $userId = (int)$input['user_id'];
    $amount = (float)$input['amount'];

    if ($amount <= 0) {
        jsonResponse(['error' => 'Invalid amount'], 400);
    }

    // Minimum and maximum amounts
    $minAmount = 10; // ₹10 minimum
    $maxAmount = 10000; // ₹10,000 maximum

    if ($amount < $minAmount || $amount > $maxAmount) {
        jsonResponse(['error' => "Amount must be between ₹{$minAmount} and ₹{$maxAmount}"], 400);
    }

    $db = \TicTacToe\Database::getInstance();

    // Verify user exists
    $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND is_blocked = 0");
    $stmt->execute([$userId]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'User not found or blocked'], 404);
    }

    // Generate unique transaction ID
    $transactionId = 'KUKU_' . time() . '_' . $userId . '_' . rand(1000, 9999);

    // Kukupay API integration (simplified example)
    $kukupayData = [
        'api_key' => KUKUPAY_API_KEY,
        'amount' => $amount,
        'currency' => 'INR',
        'transaction_id' => $transactionId,
        'callback_url' => APP_URL . '/api/kukupay_webhook.php',
        'success_url' => TELEGRAM_WEBAPP_URL . '?action=payment_success',
        'failure_url' => TELEGRAM_WEBAPP_URL . '?action=payment_failed',
        'customer_email' => 'user' . $userId . '@temp.com', // Placeholder
        'description' => 'Wallet top-up for Tic-Tac-Toe Tournament'
    ];

    // Make API call to Kukupay (simulated)
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, KUKUPAY_API_URL . 'payments/create');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($kukupayData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . KUKUPAY_API_KEY
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log('Kukupay API error: ' . $response);
        jsonResponse(['error' => 'Payment gateway error'], 500);
    }

    $paymentResponse = json_decode($response, true);

    if (!$paymentResponse || !isset($paymentResponse['payment_url'])) {
        jsonResponse(['error' => 'Invalid payment gateway response'], 500);
    }

    // Store pending transaction
    $stmt = $db->prepare("
        INSERT INTO wallet_transactions
        (user_id, amount, type, description, reference_id, payment_method, status)
        VALUES (?, ?, 'credit', 'Kukupay wallet top-up', ?, 'kukupay', 'pending')
    ");
    $stmt->execute([$userId, $amount, $transactionId]);

    jsonResponse([
        'success' => true,
        'transaction_id' => $transactionId,
        'payment_url' => $paymentResponse['payment_url'],
        'amount' => $amount
    ]);

} catch (\Exception $e) {
    error_log('Kukupay payment error: ' . $e->getMessage());
    jsonResponse(['error' => 'Internal server error'], 500);
}