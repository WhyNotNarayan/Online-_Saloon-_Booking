<?php
session_start();
require_once "config.php";

// Only admin access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: auth.php");
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$reason = trim($_POST['reason'] ?? '');

if ($id <= 0) {
    die("Invalid request");
}

try {
    $conn->begin_transaction();

    // Verify salon exists and is in pending state
    $stmt = $conn->prepare("
        SELECT op.*, u.email, u.name 
        FROM owner_profiles1 op
        JOIN users u ON u.id = op.owner_id
        WHERE op.owner_id = ? AND op.request_status = 'pending'
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $salon = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$salon) {
        throw new Exception("Salon request not found or already processed");
    }

    // Update salon status with rejection reason
    $stmt = $conn->prepare("
        UPDATE owner_profiles1 
        SET request_status = 'rejected',
            rejection_reason = ?,
            rejected_at = NOW(),
            rejected_by = ?
        WHERE owner_id = ?
    ");
    $stmt->bind_param("sii", $reason, $_SESSION['id'], $id);
    if (!$stmt->execute()) {
        throw new Exception("Failed to reject salon");
    }
    $stmt->close();

    // Send email notification
    $to = $salon['email'];
    $subject = "Salon Registration Update";
    $message = "Dear " . htmlspecialchars($salon['name']) . ",\n\n"
             . "We regret to inform you that your salon registration for '" . htmlspecialchars($salon['salon_name']) . "' could not be approved at this time.\n\n";
    
    if ($reason) {
        $message .= "Reason: " . $reason . "\n\n";
    }
    
    $message .= "You can update your salon profile and submit for review again.\n\n"
              . "Best regards,\nHair Booking System";
    $headers = "From: noreply@hairbooking.com";

    mail($to, $subject, $message, $headers);

    $conn->commit();
    header("Location: admin_dashboard.php?success=rejected");

} catch (Exception $e) {
    $conn->rollback();
    die("Error: " . $e->getMessage());
}
?>
