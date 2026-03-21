<?php
require_once "config.php";

if (!isset($_GET['id'])) {
    die("Missing owner ID");
}

$owner_id = (int)$_GET['id'];

// Update owner request status to approved
$stmt = $conn->prepare("UPDATE owner_profiles1 SET request_status='approved' WHERE owner_id = ?");
$stmt->bind_param("i", $owner_id);
if ($stmt->execute()) {
    $stmt->close();
    header("Location: admin_dashboard.php?msg=approved");
    exit;
} else {
    echo "Error updating record: " . $conn->error;
}
?>
