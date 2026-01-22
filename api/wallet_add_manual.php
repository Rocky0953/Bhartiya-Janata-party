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
    // Handle file upload
    if (!isset($_FILES['screenshot']) || !isset($_POST['user_id']) || !isset($_POST['amount']) || !isset($_POST['upi_id']) || !isset($_POST['utr_number'])) {
        jsonResponse(['error' => 'Missing required fields'], 400);
    }

    $userId = (int)$_POST['user_id'];
    $amount = (float)$_POST['amount'];
    $upiId = sanitizeInput($_POST['upi_id']);
    $utrNumber = sanitizeInput($_POST['utr_number']);

    if ($amount <= 0) {
        jsonResponse(['error' => 'Invalid amount'], 400);
    }

    // Validate file
    $file = $_FILES['screenshot'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['error' => 'File upload error'], 400);
    }

    // Check file size
    if ($file['size'] > MAX_FILE_SIZE) {
        jsonResponse(['error' => 'File too large'], 400);
    }

    // Check file type
    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($fileInfo, $file['tmp_name']);
    finfo_close($fileInfo);

    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/jpg'])) {
        jsonResponse(['error' => 'Invalid file type'], 400);
    }

    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('payment_', true) . '.' . $extension;
    $filepath = UPLOAD_DIR . $filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        jsonResponse(['error' => 'Failed to save file'], 500);
    }

    $db = \TicTacToe\Database::getInstance();

    // Verify user exists
    $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND is_blocked = 0");
    $stmt->execute([$userId]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'User not found or blocked'], 404);
    }

    // Verify UPI ID exists and is active
    $stmt = $db->prepare("SELECT id FROM upi_accounts WHERE upi_id = ? AND is_active = 1");
    $stmt->execute([$upiId]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'Invalid UPI ID'], 400);
    }

    // Insert manual payment request
    $stmt = $db->prepare("
        INSERT INTO manual_payments (user_id, amount, upi_id, utr_number, screenshot_path, status)
        VALUES (?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->execute([$userId, $amount, $upiId, $utrNumber, $filename]);

    jsonResponse([
        'success' => true,
        'message' => 'Manual payment request submitted successfully',
        'payment_id' => $db->lastInsertId()
    ]);

} catch (\Exception $e) {
    error_log('Manual payment error: ' . $e->getMessage());
    jsonResponse(['error' => 'Internal server error'], 500);
}