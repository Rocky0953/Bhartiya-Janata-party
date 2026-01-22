<?php
// Unit tests for GameLogic class
// Run with: php tests/GameLogicTest.php

require_once __DIR__ . '/../config/config.php';

class GameLogicTest {
    private $testResults = [];

    public function run() {
        echo "Running GameLogic Tests...\n\n";

        $this->testValidMoves();
        $this->testInvalidMoves();
        $this->testWinnerDetection();
        $this->testDrawDetection();
        $this->testBotMoves();
        $this->testBoardValidation();

        $this->displayResults();
    }

    private function testValidMoves() {
        $board = 'X--------';

        // Test valid moves
        $this->assertTrue(\TicTacToe\GameLogic::isValidMove($board, 1), 'Valid move on empty position');
        $this->assertTrue(\TicTacToe\GameLogic::isValidMove($board, 2), 'Valid move on empty position');

        // Test move execution
        $newBoard = \TicTacToe\GameLogic::makeMove($board, 1, 'O');
        $this->assertEqual($newBoard, 'XO-------', 'Move execution works correctly');
    }

    private function testInvalidMoves() {
        $board = 'X--------';

        // Test invalid positions
        $this->assertFalse(\TicTacToe\GameLogic::isValidMove($board, -1), 'Invalid negative position');
        $this->assertFalse(\TicTacToe\GameLogic::isValidMove($board, 9), 'Invalid position > 8');

        // Test occupied position
        $this->assertFalse(\TicTacToe\GameLogic::isValidMove($board, 0), 'Invalid move on occupied position');

        // Test invalid move execution
        try {
            \TicTacToe\GameLogic::makeMove($board, 0, 'O');
            $this->assertTrue(false, 'Should throw exception for invalid move');
        } catch (\Exception $e) {
            $this->assertTrue(true, 'Exception thrown for invalid move');
        }
    }

    private function testWinnerDetection() {
        // Test horizontal wins
        $this->assertEqual(\TicTacToe\GameLogic::checkWinner('XXX------'), 'X', 'Horizontal win detection');
        $this->assertEqual(\TicTacToe\GameLogic::checkWinner('OOO------'), 'O', 'Horizontal win detection');

        // Test vertical wins
        $this->assertEqual(\TicTacToe\GameLogic::checkWinner('X-X-X----'), 'X', 'Vertical win detection');

        // Test diagonal wins
        $this->assertEqual(\TicTacToe\GameLogic::checkWinner('X--X--X--'), 'X', 'Diagonal win detection');
        $this->assertEqual(\TicTacToe\GameLogic::checkWinner('--X-X-X--'), 'X', 'Diagonal win detection');

        // Test no winner
        $this->assertNull(\TicTacToe\GameLogic::checkWinner('XOXOXOXOX'), 'No winner when board is full but no win');
    }

    private function testDrawDetection() {
        $drawBoard = 'XOXOXOXOX';
        $this->assertTrue(\TicTacToe\GameLogic::isBoardFull($drawBoard), 'Draw board is full');
        $this->assertNull(\TicTacToe\GameLogic::checkWinner($drawBoard), 'Draw board has no winner');
        $this->assertTrue(\TicTacToe\GameLogic::isGameOver($drawBoard), 'Draw board game is over');
    }

    private function testBotMoves() {
        // Test easy bot (random moves)
        $board = 'X--------';
        $move = \TicTacToe\GameLogic::getBotMove($board, 'easy');
        $this->assertTrue($move >= 1 && $move <= 8, 'Easy bot returns valid move');

        // Test medium bot
        $board = 'XX-------'; // Two X's, bot should block
        $move = \TicTacToe\GameLogic::getBotMove($board, 'medium');
        $this->assertTrue($move >= 0 && $move <= 8, 'Medium bot returns valid move');

        // Test winning move
        $board = 'OO-O-----'; // Bot can win
        $move = \TicTacToe\GameLogic::getBotMove($board, 'medium');
        $this->assertEqual($move, 6, 'Medium bot takes winning move');
    }

    private function testBoardValidation() {
        // Valid boards
        $this->assertTrue(\TicTacToe\GameLogic::validateBoardState('---------'), 'Empty board is valid');
        $this->assertTrue(\TicTacToe\GameLogic::validateBoardState('XOXOXOXOX'), 'Full board is valid');

        // Invalid boards
        $this->assertFalse(\TicTacToe\GameLogic::validateBoardState('----------'), 'Too long board is invalid');
        $this->assertFalse(\TicTacToe\GameLogic::validateBoardState('--------'), 'Too short board is invalid');
        $this->assertFalse(\TicTacToe\GameLogic::validateBoardState('XXXX-----'), 'Invalid X count is invalid');
    }

    private function assertTrue($condition, $message) {
        if ($condition) {
            $this->testResults[] = ['pass', $message];
        } else {
            $this->testResults[] = ['fail', $message];
        }
    }

    private function assertFalse($condition, $message) {
        $this->assertTrue(!$condition, $message);
    }

    private function assertEqual($actual, $expected, $message) {
        $this->assertTrue($actual === $expected, $message . " (expected: {$expected}, got: {$actual})");
    }

    private function assertNull($value, $message) {
        $this->assertTrue($value === null, $message);
    }

    private function displayResults() {
        $passed = 0;
        $failed = 0;

        foreach ($this->testResults as $result) {
            if ($result[0] === 'pass') {
                echo "✓ PASS: {$result[1]}\n";
                $passed++;
            } else {
                echo "✗ FAIL: {$result[1]}\n";
                $failed++;
            }
        }

        echo "\nTest Results: {$passed} passed, {$failed} failed\n";

        if ($failed === 0) {
            echo "All tests passed! 🎉\n";
        } else {
            echo "Some tests failed. Please review the implementation.\n";
        }
    }
}

// Run tests
$test = new GameLogicTest();
$test->run();