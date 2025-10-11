<?php
// API endpoint to submit customer feedback for delivery tracking
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../classes/Database.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();
    
    // Get input data
    $txn = isset($_POST['txn']) ? trim($_POST['txn']) : '';
    $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
    $comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';
    
    // Validate input
    if (empty($txn)) {
        throw new Exception('Transaction ID is required');
    }
    
    if ($rating < 1 || $rating > 5) {
        throw new Exception('Rating must be between 1 and 5');
    }
    
    // Find the delivery order
    $stmt = $pdo->prepare('SELECT d.id, d.status 
                           FROM delivery_orders d
                           INNER JOIN sales s ON s.id = d.sale_id
                           WHERE s.transaction_id = ?
                           LIMIT 1');
    $stmt->execute([$txn]);
    $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$delivery) {
        throw new Exception('Delivery order not found');
    }
    
    // Check if already delivered
    if ($delivery['status'] !== 'delivered') {
        throw new Exception('Feedback can only be submitted for delivered orders');
    }
    
    // Check if feedback already submitted
    $checkStmt = $pdo->prepare('SELECT customer_rating FROM delivery_orders WHERE id = ?');
    $checkStmt->execute([$delivery['id']]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!empty($existing['customer_rating'])) {
        throw new Exception('Feedback already submitted for this delivery');
    }
    
    // Insert feedback
    $updateStmt = $pdo->prepare('UPDATE delivery_orders 
                                 SET customer_rating = ?,
                                     customer_feedback = ?,
                                     feedback_submitted_at = NOW()
                                 WHERE id = ?');
    $updateStmt->execute([$rating, $comment, $delivery['id']]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Thank you for your feedback! 💚'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

