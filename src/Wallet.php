<?php
namespace TicTacToe;

class Wallet {
    public static function getBalance(int $userId): float {
        $db = Database::getInstance();

        $stmt = $db->prepare("SELECT wallet_balance FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        return $user ? (float)$user['wallet_balance'] : 0.0;
    }

    public static function credit(int $userId, float $amount, string $description, string $paymentMethod = null, string $referenceId = null): bool {
        $db = Database::getInstance();

        try {
            $db->beginTransaction();

            // Update user balance
            $stmt = $db->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
            $stmt->execute([$amount, $userId]);

            // Log transaction
            $stmt = $db->prepare("
                INSERT INTO wallet_transactions (user_id, amount, type, description, payment_method, reference_id, status)
                VALUES (?, ?, 'credit', ?, ?, ?, 'completed')
            ");
            $stmt->execute([$userId, $amount, $description, $paymentMethod, $referenceId]);

            $db->commit();
            return true;
        } catch (\Exception $e) {
            $db->rollBack();
            error_log('Wallet credit failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function debit(int $userId, float $amount, string $description, string $paymentMethod = null, string $referenceId = null): bool {
        $db = Database::getInstance();

        try {
            $db->beginTransaction();

            // Check balance
            $currentBalance = self::getBalance($userId);
            if ($currentBalance < $amount) {
                throw new \Exception('Insufficient balance');
            }

            // Update user balance
            $stmt = $db->prepare("UPDATE users SET wallet_balance = wallet_balance - ? WHERE id = ?");
            $stmt->execute([$amount, $userId]);

            // Log transaction
            $stmt = $db->prepare("
                INSERT INTO wallet_transactions (user_id, amount, type, description, payment_method, reference_id, status)
                VALUES (?, ?, 'debit', ?, ?, ?, 'completed')
            ");
            $stmt->execute([$userId, $amount, $description, $paymentMethod, $referenceId]);

            $db->commit();
            return true;
        } catch (\Exception $e) {
            $db->rollBack();
            error_log('Wallet debit failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function transfer(int $fromUserId, int $toUserId, float $amount, string $description): bool {
        $db = Database::getInstance();

        try {
            $db->beginTransaction();

            // Check sender balance
            $senderBalance = self::getBalance($fromUserId);
            if ($senderBalance < $amount) {
                throw new \Exception('Insufficient balance');
            }

            // Debit from sender
            $stmt = $db->prepare("UPDATE users SET wallet_balance = wallet_balance - ? WHERE id = ?");
            $stmt->execute([$amount, $fromUserId]);

            // Credit to receiver
            $stmt = $db->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
            $stmt->execute([$amount, $toUserId]);

            // Log transactions
            $stmt = $db->prepare("
                INSERT INTO wallet_transactions (user_id, amount, type, description, status)
                VALUES (?, ?, 'debit', ?, 'completed'), (?, ?, 'credit', ?, 'completed')
            ");
            $stmt->execute([
                $fromUserId, $amount, $description,
                $toUserId, $amount, $description
            ]);

            $db->commit();
            return true;
        } catch (\Exception $e) {
            $db->rollBack();
            error_log('Wallet transfer failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function getTransactionHistory(int $userId, int $limit = 50, int $offset = 0): array {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            SELECT id, amount, type, description, payment_method, reference_id, status, created_at
            FROM wallet_transactions
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$userId, $limit, $offset]);

        return $stmt->fetchAll();
    }

    public static function getTotalCredits(int $userId): float {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            SELECT COALESCE(SUM(amount), 0) as total
            FROM wallet_transactions
            WHERE user_id = ? AND type = 'credit' AND status = 'completed'
        ");
        $stmt->execute([$userId]);

        return (float)$stmt->fetch()['total'];
    }

    public static function getTotalDebits(int $userId): float {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            SELECT COALESCE(SUM(amount), 0) as total
            FROM wallet_transactions
            WHERE user_id = ? AND type = 'debit' AND status = 'completed'
        ");
        $stmt->execute([$userId]);

        return (float)$stmt->fetch()['total'];
    }
}