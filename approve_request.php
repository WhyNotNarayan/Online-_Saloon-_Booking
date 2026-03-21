<?php
session_start();
require_once "config.php";

// Only admin access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: auth.php");
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
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

    // Validate required fields
    if (empty($salon['salon_name']) || empty($salon['address']) || 
        empty($salon['mobile']) || empty($salon['salon_type'])) {
        throw new Exception("Incomplete salon profile");
    }

    // Update salon status
    $stmt = $conn->prepare("
        UPDATE owner_profiles1 
        SET request_status = 'approved',
            approved_at = NOW(),
            approved_by = ?
        WHERE owner_id = ?
    ");
    $stmt->bind_param("ii", $_SESSION['id'], $id);
    if (!$stmt->execute()) {
        throw new Exception("Failed to approve salon");
    }
    $stmt->close();

    // Send email notification
    $to = $salon['email'];
    $subject = "Salon Registration Approved";
    $message = "Dear " . htmlspecialchars($salon['name']) . ",\n\n"
             . "Your salon registration for '" . htmlspecialchars($salon['salon_name']) . "' has been approved.\n"
             . "You can now start managing your salon profile and accepting bookings.\n\n"
             . "Best regards,\nHair Booking System";
    $headers = "From: noreply@hairbooking.com";

    mail($to, $subject, $message, $headers);

    $conn->commit();
    header("Location: admin_dashboard.php?success=approved");

} catch (Exception $e) {
    $conn->rollback();
    die("Error: " . $e->getMessage());
}
?>
