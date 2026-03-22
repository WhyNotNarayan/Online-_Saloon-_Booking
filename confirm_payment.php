<?php
// confirm_payment.php
session_start();
require_once "config.php";
header("Content-Type: application/json");

// Session validation
if(!isset($_SESSION['id'])){ 
    echo json_encode(['success'=>false,'message'=>'Not logged in']); 
    exit; 
}

// Input validation
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if($id<=0){ 
    echo json_encode(['success'=>false,'message'=>'Invalid booking ID']); 
    exit; 
}

try {
    $conn->begin_transaction();

    // Verify booking exists and belongs to user
    $stmt = $conn->prepare("
        SELECT status 
        FROM bookings 
        WHERE id=? AND user_id=? AND status='booked'
    ");
    $stmt->bind_param("ii", $id, $_SESSION['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if($result->num_rows === 0) {
        $stmt->close();
        throw new Exception('Booking not found or already confirmed');
    }
    $stmt->close();

    // Update booking status and payment time
    $stmt = $conn->prepare("
        UPDATE bookings 
        SET status='confirmed', 
            paid=1, 
            payment_date=NOW(),
            updated_at=NOW() 
        WHERE id=?
    ");
    $stmt->bind_param("i", $id);
    if(!$stmt->execute()) {
        throw new Exception('Database error while confirming payment');
    }
    $stmt->close();

    $conn->commit();
    echo json_encode(['success'=>true]);

} catch(Exception $e) {
    $conn->rollback();
    echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
}
