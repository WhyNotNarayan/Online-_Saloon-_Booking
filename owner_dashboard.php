<?php
session_start();
require_once "config.php";

if (!isset($_SESSION['id']) || $_SESSION['role'] !== 'owner') {
    header("Location: auth.php");
    exit;
}

$owner_id = (int)$_SESSION['id'];
$owner_name = htmlspecialchars($_SESSION['name'] ?? 'Owner');
$date_filter = $_GET['date'] ?? date('Y-m-d');

// Fetch salon profile
$stmt = $conn->prepare("
    SELECT salon_name, salon_type, staff_male, staff_female, request_status,
           open_time, close_time, mobile, address
    FROM owner_profiles1 
    WHERE owner_id = ?
");
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$profile) {
    header("Location: register_step1.php");
    exit;
}

// Get booking statistics
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_bookings,
        SUM(CASE WHEN status = 'booked' THEN 1 ELSE 0 END) as pending_bookings,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_bookings,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_bookings,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_bookings,
        SUM(CASE WHEN DATE(booking_date) = CURDATE() THEN 1 ELSE 0 END) as today_bookings,
        SUM(total_price) as total_revenue,
        SUM(CASE WHEN paid = 1 THEN total_price ELSE 0 END) as paid_revenue
    FROM bookings 
    WHERE owner_id = ? AND booking_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Get today's bookings
$stmt = $conn->prepare("
    SELECT b.id, b.staff_type, b.staff_number, b.start_time, b.end_time,
           b.status, b.total_price, u.name as customer_name,
           GROUP_CONCAT(s.service_name SEPARATOR ', ') as services
    FROM bookings b
    JOIN users u ON b.user_id = u.id
    JOIN booking_services bs ON bs.booking_id = b.id
    JOIN salon_services s ON bs.service_id = s.id
    WHERE b.owner_id = ? AND DATE(b.booking_date) = ?
    GROUP BY b.id
    ORDER BY b.start_time ASC
");
$stmt->bind_param("is", $owner_id, $date_filter);
$stmt->execute();
$today_bookings = $stmt->get_result();
$stmt->close();

// Get staff list
$stmt = $conn->prepare("
    SELECT id, staff_code, staff_name, gender, specialization, status
    FROM salon_staff 
    WHERE owner_id = ?
    ORDER BY staff_code ASC
");
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$staff = $stmt->get_result();
$stmt->close();

function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Owner Dashboard - <?= esc($profile['salon_name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f7f8fb; }
        .stat-card {
            border-radius: 16px;
            border: none;
            transition: transform 0.2s ease;
        }
        .stat-card:hover {
            transform: translateY(-4px);
        }
        .booking-card {
            border-radius: 12px;
            border: none;
            transition: all 0.2s ease;
        }
        .booking-card:hover {
            transform: translateY(-2px);
        }
        .status-booked { background: #fff3bf !important; }
        .status-confirmed { background: #d3f9d8 !important; }
        .status-completed { background: #d0ebff !important; }
        .status-cancelled { background: #ffe3e3 !important; }
        .staff-card { 
            border-radius: 12px;
            border: none;
        }
        .staff-card.inactive {
            opacity: 0.7;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
    <div class="container">
        <span class="navbar-brand fw-bold">
            <span class="text-primary">💈</span> <?= esc($profile['salon_name']) ?>
        </span>
        <div class="d-flex gap-2">
            <a href="register_step1.php" class="btn btn-outline-primary btn-sm">Edit Profile</a>
            <a href="logout.php" class="btn btn-outline-danger btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="container py-4">
    <div class="row g-4">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <h4 class="mb-1"><?= esc($profile['salon_name']) ?></h4>
                            <div class="text-muted mb-2"><?= esc($profile['address']) ?></div>
                            <div>
                                <span class="badge bg-info"><?= ucfirst(esc($profile['salon_type'])) ?></span>
                                <span class="badge bg-secondary">
                                    <?= esc($profile['open_time']) ?> - <?= esc($profile['close_time']) ?>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <span class="badge bg-<?= $profile['request_status']==='approved'?'success':($profile['request_status']==='pending'?'warning':'danger') ?> p-2">
                                Status: <?= ucfirst(esc($profile['request_status'])) ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="col-md-3">
            <div class="card stat-card shadow-sm bg-primary text-white">
                <div class="card-body">
                    <h6 class="text-white-50">Today's Bookings</h6>
                    <h3 class="mb-0"><?= (int)$stats['today_bookings'] ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card shadow-sm bg-success text-white">
                <div class="card-body">
                    <h6 class="text-white-50">Total Revenue</h6>
                    <h3 class="mb-0">₹<?= number_format($stats['total_revenue'] ?? 0, 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card shadow-sm bg-info text-white">
                <div class="card-body">
                    <h6 class="text-white-50">Completed Bookings</h6>
                    <h3 class="mb-0"><?= (int)$stats['completed_bookings'] ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card shadow-sm bg-warning">
                <div class="card-body">
                    <h6 class="text-dark">Pending Bookings</h6>
                    <h3 class="mb-0 text-dark"><?= (int)$stats['pending_bookings'] ?></h3>
                </div>
            </div>
        </div>

        <!-- Staff Section -->
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Staff Members</h5>
                        <a href="register_step1.php" class="btn btn-primary btn-sm">Manage Staff</a>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if ($staff->num_rows === 0): ?>
                        <div class="p-4 text-center text-muted">
                            No staff members added yet.
                        </div>
                    <?php else: while($s = $staff->fetch_assoc()): ?>
                        <div class="staff-card p-3 <?= $s['status']==='inactive'?'inactive':'' ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h6 class="mb-1">
                                        <?= esc($s['staff_name']) ?>
                                        <span class="badge bg-secondary"><?= esc($s['staff_code']) ?></span>
                                    </h6>
                                    <div class="text-muted small">
                                        <?= ucfirst(esc($s['gender'])) ?> • <?= esc($s['specialization']) ?>
                                    </div>
                                </div>
                                <a href="staff_bookings.php?staff_id=<?= (int)$s['id'] ?>" 
   class="btn btn-outline-primary btn-sm">View Bookings</a>

                            </div>
                        </div>
                    <?php endwhile; endif; ?>
                </div>
            </div>
        </div>

        <!-- Today's Bookings -->
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Today's Appointments</h5>
                        <input type="date" class="form-control form-control-sm" style="width:auto" 
                               value="<?= esc($date_filter) ?>" 
                               onchange="window.location.href='?date='+this.value">
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($today_bookings->num_rows === 0): ?>
                        <div class="text-center text-muted">
                            No bookings for this date.
                        </div>
                    <?php else: while($b = $today_bookings->fetch_assoc()): ?>
                        <div class="booking-card mb-3 p-3 status-<?= strtolower($b['status']) ?>">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="fw-bold"><?= esc($b['customer_name']) ?></div>
                                    <div class="text-muted">
                                        <?= date('h:i A', strtotime($b['start_time'])) ?> - 
                                        <?= date('h:i A', strtotime($b['end_time'])) ?>
                                    </div>
                                </div>
                                <div class="col-md-5">
                                    <div class="text-muted small">Services</div>
                                    <?= esc($b['services']) ?>
                                </div>
                                <div class="col-md-3 text-md-end">
                                    <div class="mb-2">₹<?= number_format($b['total_price'], 2) ?></div>
                                    <a href="staff_bookings.php?staff_type=<?= $b['staff_type'] ?>&staff_number=<?= $b['staff_number'] ?>" 
                                       class="btn btn-sm btn-outline-primary">Manage</a>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
