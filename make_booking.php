<?php
session_start();
require_once "config.php";
header('Content-Type: application/json; charset=UTF-8');

function fail($msg){ echo json_encode(['success'=>false,'message'=>$msg]); exit; }
function ok($id){ echo json_encode(['success'=>true,'booking_id'=>$id]); exit; }

// Session check
$user_id = $_SESSION['id'] ?? 0;
if(!$user_id) fail('Not logged in');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) fail('Invalid input');

// Input validation
$salon_id = (int)($input['salon_id'] ?? 0);
$staff_id = (int)($input['staff_id'] ?? 0);
$date = $input['date'] ?? '';
$start_time = $input['start_time'] ?? '';
$service_ids = $input['service_ids'] ?? [];
$total_price = floatval($input['total_price'] ?? 0);
$total_minutes = (int)($input['total_minutes'] ?? 0);

if($salon_id <= 0 || $staff_id <= 0 || !$date || !$start_time || empty($service_ids)) {
    fail('Missing required booking information');
}

// validate service_ids are integers
$service_ids = array_map('intval', $service_ids);

if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    fail('Invalid date format');
}
if(strtotime($date) < strtotime('today')) {
    fail('Cannot book for past dates');
}

try {
    $conn->begin_transaction();

    // Get staff details (verify belongs to salon)
    $stmt = $conn->prepare("
        SELECT gender as staff_type, staff_code
        FROM salon_staff 
        WHERE id = ? AND owner_id = ? AND status = 'active'
    ");
    $stmt->bind_param("ii", $staff_id, $salon_id);
    $stmt->execute();
    $staff = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$staff) { $conn->rollback(); fail('Invalid staff member'); }

    // Verify each service_id exists for this staff (quick safe check)
    $in = implode(',', $service_ids); // safe because we've cast to int
    $sql = "SELECT COUNT(*) as cnt FROM staff_services WHERE staff_id = ? AND id IN ($in)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $staff_id);
    $stmt->execute();
    $cnt = $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
    if ($cnt !== count($service_ids)) {
        $conn->rollback();
        fail('One or more services are invalid for this staff member');
    }

    // Calculate end time
    $end_time = date('H:i:s', strtotime($start_time . " + {$total_minutes} minutes"));

    // Conflict check (same as before) - uses staff_type + staff_code to preserve existing logic
    $stmt = $conn->prepare("
        SELECT COUNT(*) as conflict_count
        FROM bookings 
        WHERE owner_id = ? AND booking_date = ? AND staff_type = ? AND staff_number = ?
        AND status IN ('booked', 'confirmed')
        AND (
            (start_time < ? AND end_time > ?) OR
            (start_time < ? AND end_time > ?) OR
            (start_time >= ? AND end_time <= ?)
        )
    ");
    $stmt->bind_param("isssssssss",
        $salon_id, $date, $staff['staff_type'], $staff['staff_code'],
        $end_time, $start_time,
        $start_time, $end_time,
        $start_time, $end_time
    );
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($result['conflict_count'] > 0) {
        $conn->rollback();
        fail('This time slot is no longer available');
    }

    // Map primary staff_service -> salon_service for booking.service_id
    $primary_staff_service_id = $service_ids[0];
    $stmt = $conn->prepare("
        SELECT ss.salon_service_id
        FROM staff_services ss
        WHERE ss.id = ? AND ss.staff_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $primary_staff_service_id, $staff_id);
    $stmt->execute();
    $map = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$map || empty($map['salon_service_id'])) {
        $conn->rollback();
        fail('Service not linked to salon service');
    }

    // Insert booking (now includes staff_id)
    $stmt = $conn->prepare("
        INSERT INTO bookings (
            owner_id, user_id, staff_id, staff_type, staff_number,
            service_id, booking_date, start_time, end_time,
            total_price, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'booked', NOW())
    ");
    // types: owner_id(i), user_id(i), staff_id(i), staff_type(s), staff_number(s), service_id(i),
    // booking_date(s), start_time(s), end_time(s), total_price(d)
    $stmt->bind_param("iiississsd",
        $salon_id, $user_id, $staff_id,
        $staff['staff_type'], $staff['staff_code'],
        (int)$map['salon_service_id'],
        $date, $start_time, $end_time, $total_price
    );
    if (!$stmt->execute()) { $conn->rollback(); fail('Failed to create booking'); }
    $booking_id = $stmt->insert_id;
    $stmt->close();

    // Insert booking_services: for each staff_service, insert mapped salon_service_id, name, price
    $insertStmt = $conn->prepare("
        INSERT INTO booking_services (booking_id, service_id, service_name, price)
        VALUES (?, ?, ?, ?)
    ");
    foreach ($service_ids as $sid) {
        // fetch staff service details and its salon_service_id
        $stmt = $conn->prepare("SELECT salon_service_id, service_name, price FROM staff_services WHERE id = ? AND staff_id = ?");
        $stmt->bind_param("ii", $sid, $staff_id);
        $stmt->execute();
        $ss = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$ss || empty($ss['salon_service_id'])) {
            $conn->rollback();
            fail('Failed to map staff service to salon service for id ' . intval($sid));
        }

        // bind and insert
        $insertStmt->bind_param("iisd", $booking_id, $ss['salon_service_id'], $ss['service_name'], $ss['price']);
        if (!$insertStmt->execute()) {
            $conn->rollback();
            fail('Failed to save booking service records');
        }
    }
    $insertStmt->close();

    $conn->commit();
    ok($booking_id);

} catch (Exception $e) {
    $conn->rollback();
    fail('Booking failed: ' . $e->getMessage());
}
?>
