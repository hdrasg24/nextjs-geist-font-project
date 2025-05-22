<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Check if user is logged in
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    // Validate required fields
    $required_fields = ['game_type', 'numbers', 'amount', 'draw_id'];
    foreach ($required_fields as $field) {
        if (!isset($input[$field])) {
            throw new Exception("Missing required field: {$field}");
        }
    }

    // Sanitize and validate input
    $game_type = sanitize_input($input['game_type']);
    $numbers = is_array($input['numbers']) ? $input['numbers'] : json_decode($input['numbers'], true);
    $amount = floatval($input['amount']);
    $draw_id = intval($input['draw_id']);

    // Start transaction
    $db->beginTransaction();

    // Get user's current balance
    $stmt = $db->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
    $stmt->execute([$_SESSION['user_id']]);
    $user_balance = $stmt->fetchColumn();

    // Check if user has sufficient balance
    if ($user_balance < $amount) {
        throw new Exception('Insufficient balance');
    }

    // Get game settings
    $stmt = $db->prepare("SELECT * FROM games WHERE type = ? AND status = 'active'");
    $stmt->execute([$game_type]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$game) {
        throw new Exception('Invalid game type or game is not active');
    }

    // Validate bet amount
    if ($amount < $game['min_bet'] || $amount > $game['max_bet']) {
        throw new Exception("Bet amount must be between {$game['min_bet']} and {$game['max_bet']}");
    }

    // Check if draw is still open
    $stmt = $db->prepare("
        SELECT * FROM draws 
        WHERE id = ? 
        AND status = 'pending' 
        AND draw_time > NOW()
    ");
    $stmt->execute([$draw_id]);
    $draw = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$draw) {
        throw new Exception('Draw is closed or invalid');
    }

    // Calculate potential win
    $potential_win = $amount * $game['prize_multiplier'];

    // Insert bet
    $stmt = $db->prepare("
        INSERT INTO bets (user_id, game_id, draw_id, numbers, amount, potential_win, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([
        $_SESSION['user_id'],
        $game['id'],
        $draw_id,
        json_encode($numbers),
        $amount,
        $potential_win
    ]);
    $bet_id = $db->lastInsertId();

    // Deduct amount from user's balance
    $stmt = $db->prepare("
        UPDATE users 
        SET balance = balance - ? 
        WHERE id = ?
    ");
    $stmt->execute([$amount, $_SESSION['user_id']]);

    // Record transaction
    $stmt = $db->prepare("
        INSERT INTO transactions (
            user_id, 
            type, 
            amount, 
            balance_before, 
            balance_after, 
            status, 
            reference_id,
            created_at
        )
        VALUES (?, 'bet', ?, ?, ?, 'completed', ?, NOW())
    ");
    $stmt->execute([
        $_SESSION['user_id'],
        $amount,
        $user_balance,
        $user_balance - $amount,
        "BET{$bet_id}"
    ]);

    // Create notification
    create_notification(
        $_SESSION['user_id'],
        'bet_placed',
        "Bet placed successfully for {$game_type}. Amount: " . format_currency($amount)
    );

    // Commit transaction
    $db->commit();

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Bet placed successfully',
        'data' => [
            'bet_id' => $bet_id,
            'new_balance' => $user_balance - $amount,
            'potential_win' => $potential_win
        ]
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);

    // Log error
    error_log("Bet placement error: " . $e->getMessage());
}
