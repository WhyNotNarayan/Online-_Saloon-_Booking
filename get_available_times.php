<?php
require_once "config.php";
header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

$staff_id = (int)($input['staff_id'] ?? 0);
$date = $input['date'] ?? '';
$service_ids = $input['services'] ?? [];

if (!$staff_id || !$date || empty($service_ids)) {
    echo json_encode(["success" => false, "message" => "Missing required parameters"]); 
    exit;
}

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(["success" => false, "message" => "Invalid date format"]); 
    exit;
}

try {
    // Get salon hours and verify approval status
    $stmt = $conn->prepare("
        SELECT op.open_time, op.close_time 
        FROM salon_staff sf
        JOIN owner_profiles1 op ON op.owner_id = sf.owner_id
        WHERE sf.id = ? AND sf.status = 'active' AND op.request_status = 'approved'
    ");
    $stmt->bind_param("i", $staff_id);
    $stmt->execute();
    $salon = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$salon) {
        echo json_encode(["success" => false, "message" => "Staff not found or salon not approved"]); 
        exit;
    }

    // Calculate total duration from staff services
    $placeholders = str_repeat('?,', count($service_ids) - 1) . '?';
    $stmt = $conn->prepare("
        SELECT SUM(duration_minutes) as total_duration
        FROM staff_services ss
        JOIN salon_staff sf ON sf.id = ss.staff_id
        WHERE ss.staff_id = ? AND ss.id IN ($placeholders)
    ");
    $params = array_merge([$staff_id], $service_ids);
    $types = str_repeat('i', count($params));
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $total_minutes = (int)($result['total_duration'] ?? 0);
    $stmt->close();

    if ($total_minutes <= 0) {
        echo json_encode(["success" => false, "message" => "Invalid services"]); 
        exit;
    }

    // Get staff details
    $stmt = $conn->prepare("SELECT gender as staff_type, staff_code FROM salon_staff WHERE id = ?");
    $stmt->bind_param("i", $staff_id);
    $stmt->execute();
    $staff = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Get existing bookings
    $stmt = $conn->prepare("
        SELECT start_time, end_time
        FROM bookings 
        WHERE booking_date = ? AND staff_type = ? AND staff_number = ?
        AND status IN ('booked', 'confirmed')
        ORDER BY start_time ASC
    ");
    $stmt->bind_param("sss", $date, $staff['staff_type'], $staff['staff_code']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $bookings = [];
    while ($row = $result->fetch_assoc()) {
        $bookings[] = [
            'start' => strtotime($row['start_time']),
            'end' => strtotime($row['end_time'])
        ];
    }
    $stmt->close();

    // Generate available time slots
    $slots = [];
    $start = strtotime($salon['open_time']);
    $end = strtotime($salon['close_time']);
    $interval = 30 * 60; // 30-minute intervals

    for ($time = $start; $time <= $end - ($total_minutes * 60); $time += $interval) {
        $slot_end = $time + ($total_minutes * 60);
        $available = true;

        // Check if slot overlaps with any existing booking
        foreach ($bookings as $booking) {
            if ($time < $booking['end'] && $slot_end > $booking['start']) {
                $available = false;
                break;
            }
        }

        if ($available) {
            $slots[] = date('H:i:s', $time);
        }
    }

    echo json_encode([
        'success' => true,
        'slots' => $slots
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch available slots'
    ]);
}
