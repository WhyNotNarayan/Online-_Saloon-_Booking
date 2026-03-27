<?php
session_start();
require_once "config.php";

if (!isset($_SESSION['id']) || $_SESSION['role'] !== 'owner') {
    header("Location: auth.php");
    exit;
}

$owner_id = (int)$_SESSION['id'];
$staff_id = isset($_GET['staff_id']) ? (int)$_GET['staff_id'] : 0;
if ($staff_id <= 0) {
    die("Invalid staff id.");
}

// verify staff belongs to this owner
$stmt = $conn->prepare("SELECT id, staff_name FROM salon_staff WHERE id = ? AND owner_id = ?");
$stmt->bind_param("ii", $staff_id, $owner_id);
$stmt->execute();
$staff = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$staff) die("Staff not found.");

// fetch bookings for this staff
$stmt = $conn->prepare("
    SELECT b.id, b.booking_date, b.start_time, b.end_time, b.status, b.total_price, u.name AS customer_name,
           GROUP_CONCAT(s.service_name SEPARATOR ', ') AS services
    FROM bookings b
    JOIN users u ON b.user_id = u.id
    JOIN booking_services bs ON bs.booking_id = b.id
    JOIN salon_services s ON bs.service_id = s.id
    WHERE b.staff_id = ?
    GROUP BY b.id
    ORDER BY b.booking_date DESC, b.start_time ASC
");
$stmt->bind_param("i", $staff_id);
$stmt->execute();
$bookings = $stmt->get_result();
$stmt->close();

function esc($s){ return htmlspecialchars($s ?? '', ENT_QUOTES); }
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Bookings for <?= esc($staff['staff_name']) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container py-4">
  <a href="owner_dashboard.php" class="btn btn-outline-secondary mb-3">← Back</a>
  <h4>Bookings for <?= esc($staff['staff_name']) ?></h4>
  <?php if ($bookings->num_rows === 0): ?>
    <div class="alert alert-info">No bookings found for this staff.</div>
  <?php else: while($b = $bookings->fetch_assoc()): ?>
    <div class="card mb-3 p-3">
      <div class="d-flex justify-content-between">
        <div>
          <strong><?= esc($b['customer_name']) ?></strong> <br>
          <?= date('l, F j, Y', strtotime($b['booking_date'])) ?> • <?= date('h:i A', strtotime($b['start_time'])) ?> - <?= date('h:i A', strtotime($b['end_time'])) ?>
          <div class="text-muted small">Services: <?= esc($b['services']) ?></div>
        </div>
        <div class="text-end">
          <div>₹<?= number_format($b['total_price'],2) ?></div>
          <div class="badge bg-<?= $b['status']==='booked'?'warning':($b['status']==='confirmed'?'success':'secondary') ?>">
            <?= esc($b['status']) ?>
          </div>
        </div>
      </div>
    </div>
  <?php endwhile; endif; ?>
</div>
</body>
</html>
