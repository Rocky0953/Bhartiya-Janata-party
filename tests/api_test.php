<?php
// API Testing Script
// Run with: php tests/api_test.php

echo "Tic-Tac-Toe Tournament API Tests\n";
echo "=================================\n\n";

// Test configuration
$baseUrl = 'http://localhost'; // Change to your actual URL
$testUserId = 1; // Use a test user ID from your database
$testTelegramId = 123456789; // Test telegram ID

// Test results
$tests = [];
$totalTests = 0;
$passedTests = 0;

// Helper function to make API calls
function makeApiCall($method, $endpoint, $data = null) {
    $url = $GLOBALS['baseUrl'] . $endpoint;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'User-Agent: API Test Script'
    ]);

    if ($method === 'POST' && $data) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'response' => $response,
        'http_code' => $httpCode,
        'data' => json_decode($response, true)
    ];
}

// Test functions
function testEndpoint($name, $method, $endpoint, $data = null, $expectedCode = 200) {
    global $tests, $totalTests, $passedTests;

    $totalTests++;
    echo "Testing: {$name}... ";

    $result = makeApiCall($method, $endpoint, $data);

    if ($result['http_code'] === $expectedCode) {
        echo "✓ PASS\n";
        $passedTests++;
        $tests[] = ['pass', $name, $result];
    } else {
        echo "✗ FAIL (HTTP {$result['http_code']})\n";
        $tests[] = ['fail', $name, $result];
    }

    return $result;
}

// Run tests
echo "Running API endpoint tests...\n\n";

// Test user registration
testEndpoint(
    'User Registration',
    'POST',
    '/api/register_user.php',
    [
        'initData' => 'user=' . urlencode(json_encode([
            'id' => $testTelegramId,
            'username' => 'testuser',
            'first_name' => 'Test',
            'last_name' => 'User'
        ]))
    ]
);

// Test getting tournaments
testEndpoint(
    'Get Tournaments',
    'GET',
    '/api/get_tournaments.php'
);

// Test wallet balance check (assuming user exists)
testEndpoint(
    'Wallet Balance',
    'POST',
    '/api/wallet_add_kukupay.php',
    ['user_id' => $testUserId, 'amount' => 100],
    200 // This might fail if Kukupay is not configured, but tests the endpoint
);

// Test manual payment upload (will fail without file, but tests validation)
testEndpoint(
    'Manual Payment Validation',
    'POST',
    '/api/wallet_add_manual.php',
    ['user_id' => $testUserId, 'amount' => 100, 'upi_id' => 'test@upi', 'utr_number' => '1234567890'],
    400 // Expected to fail due to missing file
);

// Test withdrawal request
testEndpoint(
    'Withdrawal Request',
    'POST',
    '/api/withdraw_request.php',
    ['user_id' => $testUserId, 'amount' => 50, 'upi_id' => 'test@upi']
);

// Test Kukupay webhook (invalid signature expected)
testEndpoint(
    'Kukupay Webhook',
    'POST',
    '/api/kukupay_webhook.php',
    [
        'transaction_id' => 'test_txn_123',
        'status' => 'completed',
        'amount' => '100.00'
    ],
    401 // Expected signature verification failure
);

// Test bot move
testEndpoint(
    'Bot Move',
    'POST',
    '/api/bot_move.php',
    ['match_id' => 1, 'difficulty' => 'easy'],
    404 // Expected if match doesn't exist
);

// Test check winner
testEndpoint(
    'Check Winner',
    'GET',
    '/api/check_winner.php?match_id=1',
    null,
    404 // Expected if match doesn't exist
);

// Test start match
testEndpoint(
    'Start Match',
    'POST',
    '/api/start_match.php',
    ['user_id' => $testUserId, 'match_id' => 1],
    404 // Expected if match doesn't exist
);

// Test save move
testEndpoint(
    'Save Move',
    'POST',
    '/api/save_move.php',
    ['user_id' => $testUserId, 'match_id' => 1, 'position' => 0],
    404 // Expected if match doesn't exist
);

// Test join tournament
testEndpoint(
    'Join Tournament',
    'POST',
    '/api/join_tournament.php',
    ['user_id' => $testUserId, 'tournament_id' => 1],
    404 // Expected if tournament doesn't exist
);

// Display results
echo "\n\nTest Results Summary\n";
echo "===================\n";
echo "Total Tests: {$totalTests}\n";
echo "Passed: {$passedTests}\n";
echo "Failed: " . ($totalTests - $passedTests) . "\n";

if ($passedTests === $totalTests) {
    echo "\n🎉 All tests passed!\n";
} else {
    echo "\n⚠️  Some tests failed. Check the implementation and configuration.\n";
}

// Detailed failed tests
$failedTests = array_filter($tests, function($test) {
    return $test[0] === 'fail';
});

if (!empty($failedTests)) {
    echo "\nFailed Tests Details:\n";
    echo "====================\n";

    foreach ($failedTests as $test) {
        echo "\n❌ {$test[1]}\n";
        echo "   HTTP Code: {$test[2]['http_code']}\n";
        echo "   Response: " . substr($test[2]['response'], 0, 200) . "...\n";
    }
}

echo "\n\nSample cURL Commands for Manual Testing:\n";
echo "========================================\n";

// User registration
echo "# Register a new user\n";
echo "curl -X POST {$baseUrl}/api/register_user.php \\\n";
echo "  -H \"Content-Type: application/json\" \\\n";
echo "  -d '{\"initData\":\"user={\\\\"id\\\":123456789,\\\"username\\\":\\\"testuser\\\",\\\"first_name\\\":\\\"Test\\\",\\\"last_name\\\":\\\"User\\\"}\"}'\n\n";

// Get tournaments
echo "# Get list of tournaments\n";
echo "curl -X GET {$baseUrl}/api/get_tournaments.php\n\n";

// Kukupay payment
echo "# Initiate Kukupay payment\n";
echo "curl -X POST {$baseUrl}/api/wallet_add_kukupay.php \\\n";
echo "  -H \"Content-Type: application/json\" \\\n";
echo "  -d '{\"user_id\":1,\"amount\":100}'\n\n";

// Webhook test
echo "# Test Kukupay webhook (replace with actual signature)\n";
echo "curl -X POST {$baseUrl}/api/kukupay_webhook.php \\\n";
echo "  -H \"Content-Type: application/json\" \\\n";
echo "  -H \"X-Kukupay-Signature: test_signature\" \\\n";
echo "  -d '{\"transaction_id\":\"test_txn_123\",\"status\":\"completed\",\"amount\":\"100.00\"}'\n\n";

echo "\nNote: Update the baseUrl variable at the top of this script to match your actual server URL.\n";
echo "Some tests may fail if the database is not properly seeded or if external services are not configured.\n";