<?php
session_start();
require_once "config.php";

// Basic auth: ensure user is logged in
if (!isset($_SESSION['id'])) {
    header("Location: auth.php");
    exit;
}
$user_id = (int)$_SESSION['id'];

// Filters and pagination
$items_per_page = 9;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $items_per_page;

// Filter inputs with validation
$type = isset($_GET['type']) ? trim($_GET['type']) : 'all';
$q = trim($_GET['q'] ?? '');
$rating = isset($_GET['rating']) ? (int)$_GET['rating'] : 0;
$price = isset($_GET['price']) ? trim($_GET['price']) : '';

// Validate type input
$valid_types = ['male', 'female', 'both', 'all'];
if (!in_array($type, $valid_types)) {
    $type = 'all';
}

// Get user's active bookings
$stmt = $conn->prepare("
    SELECT 
        b.id, b.booking_date, b.start_time, b.end_time, b.status,
        b.total_price, o.salon_name,
        GROUP_CONCAT(s.service_name SEPARATOR ', ') as services
    FROM bookings b
    JOIN owner_profiles1 o ON b.owner_id = o.owner_id
    JOIN booking_services bs ON bs.booking_id = b.id
    JOIN salon_services s ON bs.service_id = s.id
    WHERE b.user_id = ? AND b.status IN ('booked', 'confirmed')
    GROUP BY b.id
    ORDER BY b.booking_date ASC, b.start_time ASC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$active_bookings = $stmt->get_result();
$stmt->close();

// Build salon search query
$sql = "
    SELECT SQL_CALC_FOUND_ROWS 
        o.owner_id, o.salon_name, o.address, o.salon_type, 
        o.open_time, o.close_time,
        COUNT(DISTINCT s.id) as service_count,
        MIN(s.price) as min_price,
        MAX(s.price) as max_price
    FROM owner_profiles1 o
    LEFT JOIN salon_services s ON s.owner_id = o.owner_id
    WHERE o.request_status = 'approved'
";

$params = [];
$types = '';

if ($type !== 'all') {
    $sql .= " AND (o.salon_type = ? OR o.salon_type = 'both')";
    $params[] = $type;
    $types .= 's';
}

if ($q !== '') {
    $sql .= " AND (o.salon_name LIKE ? OR o.address LIKE ?)";
    $like = "%{$q}%";
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}

// Group and sort
$sql .= " GROUP BY o.owner_id";

if ($price === 'low') {
    $sql .= " ORDER BY min_price ASC";
} elseif ($price === 'high') {
    $sql .= " ORDER BY max_price DESC";
} else {
    $sql .= " ORDER BY o.salon_name ASC";
}

// Add pagination
$sql .= " LIMIT ? OFFSET ?";
$params[] = $items_per_page;
$params[] = $offset;
$types .= 'ii';

// Execute query
$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$salons = $stmt->get_result();
$stmt->close();

// Get total count for pagination
$total_results = $conn->query("SELECT FOUND_ROWS()")->fetch_row()[0];
$total_pages = ceil($total_results / $items_per_page);

function esc($s){ return htmlspecialchars($s ?? '', ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Hair Salon Booking</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Browse and book appointments at your favorite hair salons">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { 
            background: #f7f8fb; 
            min-height: 100vh;
        }
        .salon-card { 
            border-radius: 16px; 
            border: none;
            box-shadow: 0 8px 20px rgba(20,20,30,.04);
            transition: transform 0.2s;
        }
        .salon-card:hover {
            transform: translateY(-5px);
        }
        .booking-card {
            border-radius: 12px;
            border: none;
        }
        .status-booked { background: #fff3bf !important; }
        .status-confirmed { background: #d3f9d8 !important; }
        .status-completed { background: #d0ebff !important; }
        .status-cancelled { background: #ffe3e3 !important; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
    <div class="container">
        <span class="navbar-brand fw-bold">
            <span class="text-primary">💈</span> Hair Salon Booking
        </span>
        <div class="d-flex gap-2">
            <div class="dropdown">
                <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <?= esc($_SESSION['name']) ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="user_dashboard.php">Dashboard</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<div class="container py-4">
    <!-- Active Bookings -->
    <?php if ($active_bookings->num_rows > 0): ?>
    <div class="mb-4">
        <h5 class="mb-3">Your Active Bookings</h5>
        <div class="row g-3">
            <?php while($b = $active_bookings->fetch_assoc()): ?>
            <div class="col-md-6 col-lg-4">
                <div class="booking-card shadow-sm status-<?= strtolower($b['status']) ?> p-3">
                    <h6 class="mb-1"><?= esc($b['salon_name']) ?></h6>
                    <div class="mb-2">
                        <span class="badge bg-<?= $b['status']==='booked'?'warning':'success' ?>">
                            <?= ucfirst(esc($b['status'])) ?>
                        </span>
                    </div>
                    <div class="text-muted mb-2">
                        <?= date('l, F j, Y', strtotime($b['booking_date'])) ?><br>
                        <?= date('h:i A', strtotime($b['start_time'])) ?> - 
                        <?= date('h:i A', strtotime($b['end_time'])) ?>
                    </div>
                    <div class="mb-2 small">
                        <div class="text-muted">Services:</div>
                        <?= esc($b['services']) ?>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <div>₹<?= number_format($b['total_price'], 2) ?></div>
                        <a href="receipt.php?id=<?= $b['id'] ?>" class="btn btn-sm btn-outline-primary">
                            View Details
                        </a>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Search and Filters -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="get" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Search</label>
                    <input type="text" name="q" class="form-control" placeholder="Salon name or location" 
                           value="<?= esc($q) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Type</label>
                    <select name="type" class="form-select">
                        <option value="all" <?= $type==='all'?'selected':'' ?>>All Types</option>
                        <option value="male" <?= $type==='male'?'selected':'' ?>>Male</option>
                        <option value="female" <?= $type==='female'?'selected':'' ?>>Female</option>
                        <option value="both" <?= $type==='both'?'selected':'' ?>>Unisex</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Sort by Price</label>
                    <select name="price" class="form-select">
                        <option value="">Default</option>
                        <option value="low" <?= $price==='low'?'selected':'' ?>>Low to High</option>
                        <option value="high" <?= $price==='high'?'selected':'' ?>>High to Low</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">Search</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Salons Grid -->
    <div class="row g-4">
        <?php if ($salons->num_rows === 0): ?>
            <div class="col-12">
                <div class="alert alert-info">
                    No salons found matching your criteria.
                </div>
            </div>
        <?php else: while($s = $salons->fetch_assoc()): ?>
            <div class="col-md-6 col-lg-4">
                <div class="salon-card card h-100">
                    <div class="card-body">
                        <h5 class="card-title"><?= esc($s['salon_name']) ?></h5>
                        <div class="text-muted mb-2"><?= esc($s['address']) ?></div>
                        <div class="mb-3">
                            <span class="badge bg-info me-2"><?= ucfirst(esc($s['salon_type'])) ?></span>
                            <span class="badge bg-secondary">
                                <?= esc($s['open_time']) ?> - <?= esc($s['close_time']) ?>
                            </span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="text-muted small">
                                <?= (int)$s['service_count'] ?> services available
                            </div>
                            <div>
                                ₹<?= number_format($s['min_price'], 2) ?> - 
                                ₹<?= number_format($s['max_price'], 2) ?>
                            </div>
                        </div>
                        <a href="booking.php?salon_id=<?= (int)$s['owner_id'] ?>" 
                           class="btn btn-primary w-100">Book Appointment</a>
                    </div>
                </div>
            </div>
        <?php endwhile; endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <nav aria-label="Salon navigation" class="mt-4">
        <ul class="pagination justify-content-center">
            <?php if ($page > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?= ($page-1) ?>&type=<?= esc($type) ?>&q=<?= esc($q) ?>&price=<?= esc($price) ?>">
                        Previous
                    </a>
                </li>
            <?php endif; ?>

            <?php for($i = 1; $i <= $total_pages; $i++): ?>
                <li class="page-item <?= $i===$page?'active':'' ?>">
                    <a class="page-link" href="?page=<?= $i ?>&type=<?= esc($type) ?>&q=<?= esc($q) ?>&price=<?= esc($price) ?>">
                        <?= $i ?>
                    </a>
                </li>
            <?php endfor; ?>

            <?php if ($page < $total_pages): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?= ($page+1) ?>&type=<?= esc($type) ?>&q=<?= esc($q) ?>&price=<?= esc($price) ?>">
                        Next
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
