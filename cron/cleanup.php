<?php
// Daily cleanup CRON job
// Run once per day to clean up old files and data

require_once __DIR__ . '/../config/config.php';

try {
    echo "Starting daily cleanup...\n";

    // Clean up old uploaded files (older than 30 days)
    cleanupOldFiles();

    // Clean up old temporary data
    cleanupOldData();

    // Optimize database tables
    optimizeDatabase();

    echo "Daily cleanup completed successfully\n";

} catch (\Exception $e) {
    error_log('Cleanup CRON error: ' . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
}

function cleanupOldFiles() {
    $uploadDir = UPLOAD_DIR;
    $retentionDays = 30; // Keep files for 30 days
    $deletedCount = 0;

    if (!is_dir($uploadDir)) {
        echo "Upload directory does not exist\n";
        return;
    }

    $files = scandir($uploadDir);
    $cutoffTime = time() - ($retentionDays * 24 * 60 * 60);

    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $filePath = $uploadDir . $file;

        if (is_file($filePath) && filemtime($filePath) < $cutoffTime) {
            if (unlink($filePath)) {
                $deletedCount++;
            } else {
                error_log("Failed to delete old file: {$filePath}");
            }
        }
    }

    echo "Cleaned up {$deletedCount} old uploaded files\n";
}

function cleanupOldData() {
    $db = \TicTacToe\Database::getInstance();

    try {
        // Clean up old wallet transactions (older than 1 year)
        $stmt = $db->prepare("
            DELETE FROM wallet_transactions
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)
            AND status IN ('completed', 'failed')
        ");
        $stmt->execute();
        $deletedTransactions = $stmt->rowCount();

        // Clean up old game moves (older than 6 months for completed matches)
        $stmt = $db->prepare("
            DELETE gm FROM game_moves gm
            INNER JOIN matches m ON gm.match_id = m.id
            WHERE m.completed_at < DATE_SUB(NOW(), INTERVAL 6 MONTH)
            AND m.status IN ('completed', 'draw', 'timeout')
        ");
        $stmt->execute();
        $deletedMoves = $stmt->rowCount();

        // Clean up old manual payments (approved/rejected older than 3 months)
        $stmt = $db->prepare("
            DELETE FROM manual_payments
            WHERE status IN ('approved', 'rejected')
            AND processed_at < DATE_SUB(NOW(), INTERVAL 3 MONTH)
        ");
        $stmt->execute();
        $deletedPayments = $stmt->rowCount();

        echo "Cleaned up {$deletedTransactions} old transactions, {$deletedMoves} old moves, {$deletedPayments} old payments\n";

    } catch (\Exception $e) {
        error_log('Data cleanup error: ' . $e->getMessage());
        echo "Data cleanup error: " . $e->getMessage() . "\n";
    }
}

function optimizeDatabase() {
    $db = \TicTacToe\Database::getInstance();

    $tables = [
        'users', 'wallet_transactions', 'tournaments', 'tournament_players',
        'matches', 'game_moves', 'withdraw_requests', 'manual_payments',
        'upi_accounts', 'admin_users', 'settings', 'referrals'
    ];

    foreach ($tables as $table) {
        try {
            $stmt = $db->prepare("OPTIMIZE TABLE {$table}");
            $stmt->execute();
        } catch (\Exception $e) {
            error_log("Failed to optimize table {$table}: " . $e->getMessage());
        }
    }

    echo "Database optimization completed\n";
}