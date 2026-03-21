<?php
session_start();
require_once "config.php";

// Only admin access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: auth.php");
    exit;
}

// Get salon statistics
$stats = $conn->query("
    SELECT 
        COUNT(*) as total_salons,
        SUM(CASE WHEN request_status = 'pending' THEN 1 ELSE 0 END) as pending_salons,
        SUM(CASE WHEN request_status = 'approved' THEN 1 ELSE 0 END) as approved_salons,
        SUM(CASE WHEN request_status = 'rejected' THEN 1 ELSE 0 END) as rejected_salons
    FROM owner_profiles1
")->fetch_assoc();

// Get booking statistics
$booking_stats = $conn->query("
    SELECT 
        COUNT(*) as total_bookings,
        SUM(CASE WHEN status = 'booked' THEN 1 ELSE 0 END) as pending_bookings,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_bookings,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_bookings,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_bookings,
        SUM(total_price) as total_revenue
    FROM bookings
    WHERE booking_date >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)
")->fetch_assoc();

// Fetch pending salon requests with owner details
$result = $conn->query("
    SELECT op.*, u.name, u.email, u.created_at as registration_date,
           (SELECT COUNT(*) FROM staff_services ss JOIN salon_staff sf ON sf.id = ss.staff_id WHERE sf.owner_id = op.owner_id) as service_count
    FROM owner_profiles1 op
    JOIN users u ON op.owner_id = u.id
    WHERE op.request_status = 'pending'
    ORDER BY u.created_at DESC
");

function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - Hair Booking System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f7f8fb; }
        .sidebar {
            width: 260px;
            min-height: 100vh;
            background: #fff;
            position: fixed;
            top: 0;
            left: 0;
            padding-top: 1rem;
            box-shadow: 2px 0 8px rgba(0,0,0,.05);
        }
        .main-content {
            margin-left: 260px;
            padding: 2rem;
        }
        .stat-card {
            border-radius: 16px;
            border: none;
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-4px);
        }
        .request-card {
            border-radius: 12px;
            border: none;
            transition: all 0.2s ease;
        }
        .request-card:hover {
            transform: translateY(-2px);
        }
    </style>
</head>
<body>

<div class="sidebar p-3">
    <div class="d-flex align-items-center mb-4 px-3">
        <span class="text-primary h4 mb-0">💈</span>
        <h4 class="mb-0 ms-2">Admin Panel</h4>
    </div>
    
    <div class="list-group list-group-flush">
        <a href="admin_dashboard.php" class="list-group-item list-group-item-action active">
            <i class="fas fa-dashboard me-2"></i> Dashboard
        </a>
        <a href="admin_records.php" class="list-group-item list-group-item-action">
            <i class="fas fa-list me-2"></i> All Records
        </a>
        <a href="logout.php" class="list-group-item list-group-item-action text-danger">
            <i class="fas fa-sign-out-alt me-2"></i> Logout
        </a>
    </div>
</div>

<div class="main-content">
    <div class="container-fluid">
        <!-- Statistics Row -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="card stat-card shadow-sm bg-primary text-white">
                    <div class="card-body">
                        <h6 class="text-white-50">Total Salons</h6>
                        <div class="d-flex justify-content-between align-items-end">
                            <h3 class="mb-0"><?= number_format($stats['total_salons']) ?></h3>
                            <small class="text-white-50"><?= number_format($stats['pending_salons']) ?> pending</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card shadow-sm bg-success text-white">
                    <div class="card-body">
                        <h6 class="text-white-50">Monthly Revenue</h6>
                        <div class="d-flex justify-content-between align-items-end">
                            <h3 class="mb-0">₹<?= number_format($booking_stats['total_revenue'], 2) ?></h3>
                            <small class="text-white-50">Last 30 days</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card shadow-sm bg-info text-white">
                    <div class="card-body">
                        <h6 class="text-white-50">Total Bookings</h6>
                        <div class="d-flex justify-content-between align-items-end">
                            <h3 class="mb-0"><?= number_format($booking_stats['total_bookings']) ?></h3>
                            <small class="text-white-50"><?= number_format($booking_stats['completed_bookings']) ?> completed</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card shadow-sm bg-warning">
                    <div class="card-body">
                        <h6 class="text-dark">Active Bookings</h6>
                        <div class="d-flex justify-content-between align-items-end">
                            <h3 class="mb-0 text-dark"><?= number_format($booking_stats['pending_bookings'] + $booking_stats['confirmed_bookings']) ?></h3>
                            <small class="text-dark"><?= number_format($booking_stats['pending_bookings']) ?> pending</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending Requests -->
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h5 class="mb-0">Pending Salon Requests</h5>
            </div>
            <div class="card-body">
                <?php if ($result->num_rows === 0): ?>
                    <div class="text-center text-muted py-4">
                        No pending requests at this time.
                    </div>
                <?php else: while($row = $result->fetch_assoc()): ?>
                    <div class="request-card bg-light p-4 mb-3">
                        <div class="row">
                            <div class="col-md-6">
                                <h5 class="mb-1"><?= esc($row['salon_name']) ?></h5>
                                <div class="text-muted mb-2"><?= esc($row['address']) ?></div>
                                <div class="mb-3">
                                    <span class="badge bg-info me-2"><?= ucfirst(esc($row['salon_type'])) ?></span>
                                    <span class="badge bg-secondary">
                                        <?= esc($row['open_time']) ?> - <?= esc($row['close_time']) ?>
                                    </span>
                                </div>
                                <div class="small text-muted">
                                    <?= $row['staff_male'] ?> Male Staff • 
                                    <?= $row['staff_female'] ?> Female Staff •
                                    <?= $row['service_count'] ?> Services
                                </div>
                            </div>
                            <div class="col-md-4">
                                <h6>Owner Details</h6>
                                <div class="mb-1"><?= esc($row['name']) ?></div>
                                <div class="text-muted mb-2"><?= esc($row['email']) ?></div>
                                <div class="small text-muted">
                                    Registered: <?= date('F j, Y', strtotime($row['registration_date'])) ?>
                                </div>
                            </div>
                            <div class="col-md-2 text-end">
                                <div class="btn-group-vertical w-100">
                                    <a href="approve_request.php?id=<?= $row['owner_id'] ?>" 
                                       class="btn btn-success mb-2" 
                                       onclick="return confirm('Approve this salon?')">
                                        <i class="fas fa-check me-1"></i> Approve
                                    </a>
                                    <a href="reject_request.php?id=<?= $row['owner_id'] ?>" 
                                       class="btn btn-danger"
                                       onclick="return confirm('Reject this salon?')">
                                        <i class="fas fa-times me-1"></i> Reject
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endwhile; endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://kit.fontawesome.com/7b2c2b7d6c.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
