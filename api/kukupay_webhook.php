<?php
require_once __DIR__ . '/../config/config.php';

// Kukupay webhook endpoint
// This should be called by Kukupay when payment status changes

header('Content-Type: application/json');

// Get webhook data
$input = file_get_contents('php://input');
$webhookData = json_decode($input, true);

if (!$webhookData) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid webhook data']);
    exit;
}

// Log webhook for debugging
error_log('Kukupay webhook received: ' . $input);

// Verify webhook signature (implement based on Kukupay documentation)
$signature = $_SERVER['HTTP_X_KUKUPAY_SIGNATURE'] ?? '';
$expectedSignature = hash_hmac('sha256', $input, KUKUPAY_API_KEY);

if (!hash_equals($expectedSignature, $signature)) {
    error_log('Invalid webhook signature');
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

// Process webhook
try {
    $transactionId = $webhookData['transaction_id'] ?? '';
    $status = $webhookData['status'] ?? '';
    $amount = (float)($webhookData['amount'] ?? 0);

    if (empty($transactionId) || empty($status)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        exit;
    }

    $db = \TicTacToe\Database::getInstance();

    // Find the transaction
    $stmt = $db->prepare("
        SELECT id, user_id, amount, status
        FROM wallet_transactions
        WHERE reference_id = ? AND payment_method = 'kukupay'
    ");
    $stmt->execute([$transactionId]);
    $transaction = $stmt->fetch();

    if (!$transaction) {
        error_log('Transaction not found: ' . $transactionId);
        http_response_code(404);
        echo json_encode(['error' => 'Transaction not found']);
        exit;
    }

    // Update transaction status
    $newStatus = 'failed'; // default
    switch ($status) {
        case 'completed':
        case 'success':
            $newStatus = 'completed';
            break;
        case 'pending':
            $newStatus = 'pending';
            break;
        case 'failed':
        case 'cancelled':
            $newStatus = 'failed';
            break;
    }

    // Only process if status changed
    if ($transaction['status'] === $newStatus) {
        echo json_encode(['success' => true, 'message' => 'Status unchanged']);
        exit;
    }

    $db->beginTransaction();

    try {
        // Update transaction status
        $stmt = $db->prepare("UPDATE wallet_transactions SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $transaction['id']]);

        // If payment completed, credit wallet
        if ($newStatus === 'completed' && $transaction['status'] !== 'completed') {
            $creditSuccess = \TicTacToe\Wallet::credit(
                $transaction['user_id'],
                (float)$transaction['amount'],
                'Kukupay payment completed',
                'kukupay',
                $transactionId
            );

            if (!$creditSuccess) {
                throw new \Exception('Failed to credit wallet');
            }
        }

        // If payment failed and was previously completed, debit wallet (refund scenario)
        if ($newStatus === 'failed' && $transaction['status'] === 'completed') {
            $debitSuccess = \TicTacToe\Wallet::debit(
                $transaction['user_id'],
                (float)$transaction['amount'],
                'Kukupay payment failed - refund',
                'kukupay',
                $transactionId
            );

            if (!$debitSuccess) {
                error_log('Failed to debit wallet for failed payment: ' . $transactionId);
                // Don't throw exception here as the payment failure is already logged
            }
        }

        $db->commit();

        // TODO: Send notification to user via Telegram bot

        echo json_encode(['success' => true, 'message' => 'Webhook processed successfully']);

    } catch (\Exception $e) {
        $db->rollBack();
        error_log('Webhook processing error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Processing error']);
    }

} catch (\Exception $e) {
    error_log('Webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}