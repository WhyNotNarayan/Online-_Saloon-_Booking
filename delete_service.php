<?php
require 'config.php';
session_start();
if (!isset($_SESSION['id']) || $_SESSION['role'] !== 'owner') exit;

$id = (int)($_GET['id'] ?? 0);
$owner_id = $_SESSION['id'];

if ($id > 0) {
    $stmt = $conn->prepare("DELETE FROM staff_services WHERE id=? AND staff_id IN (SELECT id FROM salon_staff WHERE owner_id=?)");
    $stmt->bind_param("ii", $id, $owner_id);
    $stmt->execute();
    $stmt->close();
}
?>
