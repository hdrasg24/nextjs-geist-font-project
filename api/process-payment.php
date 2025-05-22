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
    $required_fields = ['amount', 'payment_method'];
    foreach ($required_fields as $field) {
        if (!isset($input[$field])) {
            throw new Exception("Missing required field: {$field}");
        }
    }

    $amount = floatval($input['amount']);
    $payment_method = sanitize_input($input['payment_method']);

    // Validate amount
    if ($amount < MIN_DEPOSIT || $amount > MAX_DEPOSIT) {
        throw new Exception("Amount must be between " . format_currency(MIN_DEPOSIT) . " and " . format_currency(MAX_DEPOSIT));
    }

    // Start transaction
    $db->beginTransaction();

    // Generate unique reference ID
    $reference_id = 'DEP' . time() . rand(1000, 9999);

    // Create pending transaction
    $stmt = $db->prepare("
        INSERT INTO transactions (
            user_id,
            type,
            amount,
            payment_method,
            status,
            reference_id,
            payment_details,
            created_at
        )
        VALUES (?, 'deposit', ?, ?, 'pending', ?, ?, NOW())
    ");

    $payment_details = [];

    // Process payment based on method
    switch ($payment_method) {
        case 'qris':
            // Initialize QRIS payment
            $qris_data = [
                'reference_id' => $reference_id,
                'amount' => $amount,
                'merchant_id' => QRIS_MERCHANT_ID
            ];
            
            // Generate QRIS code (implement actual QRIS integration)
            $payment_details = [
                'qris_code' => 'SAMPLE_QRIS_CODE', // Replace with actual QRIS code
                'expires_at' => date('Y-m-d H:i:s', strtotime('+15 minutes'))
            ];
            break;

        case 'midtrans':
            // Initialize Midtrans payment
            $midtrans_data = [
                'transaction_details' => [
                    'order_id' => $reference_id,
                    'gross_amount' => $amount
                ],
                'customer_details' => [
                    'user_id' => $_SESSION['user_id']
                ]
            ];
            
            // Get Midtrans payment URL (implement actual Midtrans integration)
            $payment_details = [
                'redirect_url' => 'https://midtrans.com/payment', // Replace with actual URL
                'expires_at' => date('Y-m-d H:i:s', strtotime('+1 hour'))
            ];
            break;

        case 'xendit':
            // Initialize Xendit payment
            $xendit_data = [
                'external_id' => $reference_id,
                'amount' => $amount,
                'payer_email' => 'user@example.com', // Get actual user email
                'description' => 'Deposit to account'
            ];
            
            // Create Xendit invoice (implement actual Xendit integration)
            $payment_details = [
                'invoice_url' => 'https://xendit.com/invoice', // Replace with actual URL
                'expires_at' => date('Y-m-d H:i:s', strtotime('+24 hours'))
            ];
            break;

        case 'bank_transfer':
            // Get bank details from request
            if (!isset($input['bank_code'])) {
                throw new Exception('Bank code is required for bank transfer');
            }
            
            $bank_code = sanitize_input($input['bank_code']);
            
            // Generate virtual account number (implement actual VA generation)
            $payment_details = [
                'bank_code' => $bank_code,
                'account_number' => '8888' . rand(1000000, 9999999),
                'account_name' => 'TOGEL ONLINE',
                'expires_at' => date('Y-m-d H:i:s', strtotime('+24 hours'))
            ];
            break;

        case 'e_wallet':
            // Get e-wallet type from request
            if (!isset($input['wallet_type'])) {
                throw new Exception('Wallet type is required for e-wallet payment');
            }
            
            $wallet_type = sanitize_input($input['wallet_type']);
            
            switch ($wallet_type) {
                case 'gopay':
                    // Initialize GoPay payment (implement actual GoPay integration)
                    $payment_details = [
                        'qr_code' => 'GOPAY_QR_CODE', // Replace with actual QR code
                        'deep_link' => 'gojek://gopay/payment',
                        'expires_at' => date('Y-m-d H:i:s', strtotime('+15 minutes'))
                    ];
                    break;

                case 'ovo':
                    // Initialize OVO payment (implement actual OVO integration)
                    $payment_details = [
                        'payment_code' => rand(100000, 999999),
                        'phone_number' => $input['phone_number'] ?? '',
                        'expires_at' => date('Y-m-d H:i:s', strtotime('+15 minutes'))
                    ];
                    break;

                default:
                    throw new Exception('Unsupported e-wallet type');
            }
            break;

        default:
            throw new Exception('Unsupported payment method');
    }

    // Save transaction with payment details
    $stmt->execute([
        $_SESSION['user_id'],
        $amount,
        $payment_method,
        $reference_id,
        json_encode($payment_details)
    ]);

    // Create notification
    create_notification(
        $_SESSION['user_id'],
        'deposit_initiated',
        "Deposit initiated for " . format_currency($amount) . " via {$payment_method}"
    );

    // Commit transaction
    $db->commit();

    // Return success response with payment details
    echo json_encode([
        'success' => true,
        'message' => 'Payment initiated successfully',
        'data' => [
            'reference_id' => $reference_id,
            'amount' => $amount,
            'payment_method' => $payment_method,
            'payment_details' => $payment_details
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
    error_log("Payment processing error: " . $e->getMessage());
}
