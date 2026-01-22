<?php
namespace TicTacToe;

class GameLogic {
    const BOARD_SIZE = 9;
    const WINNING_COMBINATIONS = [
        [0, 1, 2], [3, 4, 5], [6, 7, 8], // Rows
        [0, 3, 6], [1, 4, 7], [2, 5, 8], // Columns
        [0, 4, 8], [2, 4, 6]             // Diagonals
    ];

    public static function isValidMove(string $boardState, int $position): bool {
        if ($position < 0 || $position >= self::BOARD_SIZE) {
            return false;
        }

        return $boardState[$position] === '-';
    }

    public static function makeMove(string $boardState, int $position, string $symbol): string {
        if (!self::isValidMove($boardState, $position)) {
            throw new \Exception('Invalid move');
        }

        return substr_replace($boardState, $symbol, $position, 1);
    }

    public static function checkWinner(string $boardState): ?string {
        $board = str_split($boardState);

        foreach (self::WINNING_COMBINATIONS as $combination) {
            if ($board[$combination[0]] !== '-' &&
                $board[$combination[0]] === $board[$combination[1]] &&
                $board[$combination[1]] === $board[$combination[2]]) {
                return $board[$combination[0]];
            }
        }

        return null;
    }

    public static function isBoardFull(string $boardState): bool {
        return strpos($boardState, '-') === false;
    }

    public static function isGameOver(string $boardState): bool {
        return self::checkWinner($boardState) !== null || self::isBoardFull($boardState);
    }

    public static function getBotMove(string $boardState, string $difficulty = 'easy'): int {
        switch ($difficulty) {
            case 'medium':
                return self::getMediumBotMove($boardState);
            case 'easy':
            default:
                return self::getEasyBotMove($boardState);
        }
    }

    private static function getEasyBotMove(string $boardState): int {
        $availableMoves = [];
        for ($i = 0; $i < self::BOARD_SIZE; $i++) {
            if ($boardState[$i] === '-') {
                $availableMoves[] = $i;
            }
        }

        return $availableMoves[array_rand($availableMoves)];
    }

    private static function getMediumBotMove(string $boardState): int {
        $board = str_split($boardState);

        // Check if bot can win
        $winningMove = self::findWinningMove($board, 'O');
        if ($winningMove !== null) {
            return $winningMove;
        }

        // Check if bot needs to block opponent
        $blockingMove = self::findWinningMove($board, 'X');
        if ($blockingMove !== null) {
            return $blockingMove;
        }

        // Take center if available
        if ($board[4] === '-') {
            return 4;
        }

        // Take corners
        $corners = [0, 2, 6, 8];
        shuffle($corners);
        foreach ($corners as $corner) {
            if ($board[$corner] === '-') {
                return $corner;
            }
        }

        // Take any available move
        return self::getEasyBotMove($boardState);
    }

    private static function findWinningMove(array $board, string $symbol): ?int {
        foreach (self::WINNING_COMBINATIONS as $combination) {
            $count = 0;
            $emptyPos = null;

            foreach ($combination as $pos) {
                if ($board[$pos] === $symbol) {
                    $count++;
                } elseif ($board[$pos] === '-') {
                    $emptyPos = $pos;
                }
            }

            if ($count === 2 && $emptyPos !== null) {
                return $emptyPos;
            }
        }

        return null;
    }

    public static function getGameResult(string $boardState): array {
        $winner = self::checkWinner($boardState);
        $isDraw = self::isBoardFull($boardState) && $winner === null;

        return [
            'winner' => $winner,
            'is_draw' => $isDraw,
            'is_game_over' => $winner !== null || $isDraw
        ];
    }

    public static function validateBoardState(string $boardState): bool {
        if (strlen($boardState) !== self::BOARD_SIZE) {
            return false;
        }

        $xCount = substr_count($boardState, 'X');
        $oCount = substr_count($boardState, 'O');
        $dashCount = substr_count($boardState, '-');

        // X should have at most one more move than O
        if ($xCount > $oCount + 1 || $oCount > $xCount) {
            return false;
        }

        // Total should be 9
        if ($xCount + $oCount + $dashCount !== self::BOARD_SIZE) {
            return false;
        }

        return true;
    }
}