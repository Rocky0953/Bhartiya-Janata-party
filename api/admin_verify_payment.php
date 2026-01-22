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
    if (!isset($input['payment_id']) || !isset($input['action'])) {
        jsonResponse(['error' => 'Missing required fields: payment_id, action'], 400);
    }

    $paymentId = (int)$input['payment_id'];
    $action = $input['action'];
    $reason = isset($input['reason']) ? sanitizeInput($input['reason']) : '';

    if (!in_array($action, ['approve', 'reject'])) {
        jsonResponse(['error' => 'Invalid action'], 400);
    }

    $db = \TicTacToe\Database::getInstance();

    // Get payment details
    $stmt = $db->prepare("
        SELECT mp.*, u.username, u.telegram_id
        FROM manual_payments mp
        JOIN users u ON mp.user_id = u.id
        WHERE mp.id = ?
    ");
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch();

    if (!$payment) {
        jsonResponse(['error' => 'Payment not found'], 404);
    }

    if ($payment['status'] !== 'pending') {
        jsonResponse(['error' => 'Payment already processed'], 400);
    }

    $adminId = $_SESSION['admin_id'];

    if ($action === 'approve') {
        // Start transaction
        $db->beginTransaction();

        try {
            // Credit user wallet
            $creditSuccess = \TicTacToe\Wallet::credit(
                $payment['user_id'],
                (float)$payment['amount'],
                'Manual payment approved',
                'manual_upi',
                "manual_{$paymentId}"
            );

            if (!$creditSuccess) {
                throw new \Exception('Failed to credit wallet');
            }

            // Update payment status
            $stmt = $db->prepare("
                UPDATE manual_payments
                SET status = 'approved', admin_id = ?, processed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$adminId, $paymentId]);

            $db->commit();

            // TODO: Send notification to user via Telegram bot

        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }

    } else { // reject
        if (empty($reason)) {
            jsonResponse(['error' => 'Rejection reason is required'], 400);
        }

        // Update payment status
        $stmt = $db->prepare("
            UPDATE manual_payments
            SET status = 'rejected', admin_id = ?, processed_at = NOW(), rejection_reason = ?
            WHERE id = ?
        ");
        $stmt->execute([$adminId, $reason, $paymentId]);

        // TODO: Send notification to user via Telegram bot
    }

    jsonResponse([
        'success' => true,
        'message' => "Payment {$action}d successfully",
        'payment_id' => $paymentId,
        'action' => $action
    ]);

} catch (\Exception $e) {
    error_log('Admin verify payment error: ' . $e->getMessage());
    jsonResponse(['error' => 'Internal server error'], 500);
}