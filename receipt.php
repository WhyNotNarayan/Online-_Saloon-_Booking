<?php
// receipt.php
session_start();
require_once "config.php";

// Session check
if (!isset($_SESSION['id'])) {
    header("Location: auth.php");
    exit;
}

// Input validation
$bid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if($bid <= 0) { 
    die("Invalid booking ID"); 
}

// Fetch booking details with proper joins
$stmt = $conn->prepare("
    SELECT 
        b.id, b.booking_date, b.start_time, b.end_time, b.status,
        b.total_price, b.payment_date, b.created_at,
        o.salon_name, o.address, o.mobile,
        u.name AS customer, u.email
    FROM bookings b
    JOIN owner_profiles1 o ON o.owner_id = b.owner_id
    JOIN users u ON u.id = b.user_id
    WHERE b.id = ? AND (b.user_id = ? OR o.owner_id = ?)
");
$stmt->bind_param("iii", $bid, $_SESSION['id'], $_SESSION['id']);
$stmt->execute();
$r = $stmt->get_result()->fetch_assoc();
$stmt->close();

if(!$r) { 
    die("Booking not found or access denied"); 
}

// Fetch services
$items = [];
$total = 0.0;
$total_mins = 0;
$stmt = $conn->prepare("
    SELECT ss.service_name, ss.price, ss.duration_minutes, sf.staff_name
    FROM booking_services bs
    JOIN staff_services ss ON ss.id = bs.service_id
    JOIN salon_staff sf ON sf.id = ss.staff_id
    WHERE bs.booking_id = ?
    ORDER BY ss.service_name
");
$stmt->bind_param("i", $bid);
$stmt->execute();
$res = $stmt->get_result();
while($row = $res->fetch_assoc()){
    $items[] = $row;
    $total += (float)$row['price'];
    $total_mins += (int)$row['duration_minutes'];
}
$stmt->close();

function fmtINR($n){ return number_format($n, 2, '.', ','); }
function esc($s){ return htmlspecialchars($s ?? '', ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Receipt #<?= esc($bid) ?> - <?= esc($r['salon_name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Booking receipt for <?= esc($r['salon_name']) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f7f8fb; }
        .receipt-card { 
            max-width: 800px; 
            margin: 2rem auto;
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.08);
        }
        .status-booked { background:#ffd43b; color:#000; }
        .status-confirmed { background:#40c057; color:#fff; }
        .status-completed { background:#228be6; color:#fff; }
        .status-cancelled { background:#fa5252; color:#fff; }
        .service-row:not(:last-child) { border-bottom: 1px solid #eee; }
    </style>
</head>
<body>
<div class="container my-4">
    <div class="receipt-card bg-white p-4">
        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <h4 class="mb-0">Booking Receipt</h4>
                <div class="text-muted small">Reference #<?= esc($bid) ?></div>
            </div>
            <span class="badge status-<?= strtolower($r['status']) ?> px-3 py-2">
                <?= esc(ucfirst($r['status'])) ?>
            </span>
        </div>

        <div class="row g-4">
            <div class="col-md-6">
                <h5 class="mb-3"><?= esc($r['salon_name']) ?></h5>
                <div class="text-muted mb-2"><?= esc($r['address']) ?></div>
                <div class="text-muted"><?= esc($r['mobile']) ?></div>
            </div>
            <div class="col-md-6">
                <div class="mb-2">
                    <strong>Customer:</strong> <?= esc($r['customer']) ?>
                    <div class="text-muted small"><?= esc($r['email']) ?></div>
                </div>
                <div class="mb-2">
                    <strong>Date:</strong> <?= date('F j, Y', strtotime($r['booking_date'])) ?>
                </div>
                <div class="mb-2">
                    <strong>Time:</strong> 
                    <?= date('h:i A', strtotime($r['start_time'])) ?> - 
                    <?= date('h:i A', strtotime($r['end_time'])) ?>
                </div>
                <?php if($r['payment_date']): ?>
                <div class="mb-2">
                    <strong>Paid On:</strong> 
                    <?= date('F j, Y h:i A', strtotime($r['payment_date'])) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="mt-4">
            <h6 class="mb-3">Services</h6>
            <?php foreach($items as $item): ?>
            <div class="service-row py-2">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div><?= esc($item['service_name']) ?></div>
                        <div class="text-muted small"><?= (int)$item['duration_minutes'] ?> minutes</div>
                        <div class="text-muted small">Staff: <?= esc($item['staff_name']) ?></div>
                    </div>
                    <div class="text-end">
                        ₹<?= fmtINR($item['price']) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="mt-3 pt-3 border-top">
                <div class="row">
                    <div class="col-6">
                        <div class="text-muted">Total Duration</div>
                        <div class="h5 mb-0"><?= $total_mins ?> minutes</div>
                    </div>
                    <div class="col-6 text-end">
                        <div class="text-muted">Total Amount</div>
                        <div class="h5 mb-0">₹<?= fmtINR($total) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <?php if($r['status'] === 'booked'): ?>
        <div class="mt-4 text-center">
            <button class="btn btn-success btn-lg" onclick="confirmPayment(<?= $bid ?>)">
                Pay & Confirm Booking
            </button>
            <div class="text-muted small mt-2">
                Secure payment processed with our payment gateway
            </div>
        </div>
        <?php endif; ?>

        <div class="mt-4 text-center">
            <a href="user_dashboard.php" class="btn btn-outline-primary">
                Return to Dashboard
            </a>
        </div>
    </div>
</div>

<script>
async function confirmPayment(id){
    if(!confirm("Proceed with payment?")) return;
    
    try {
        const res = await fetch("confirm_payment.php", {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: "id=" + id
        });
        const data = await res.json();
        
        if(data.success){
            alert("✅ Payment successful! Your appointment is confirmed.");
            window.location.reload();
        } else {
            alert("❌ " + (data.message || "Payment failed"));
        }
    } catch(err) {
        console.error(err);
        alert("❌ Request failed. Please try again.");
    }
}
</script>
</body>
</html>
