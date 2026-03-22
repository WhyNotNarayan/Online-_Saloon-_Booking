<?php
session_start();
require_once "config.php";

header('Content-Type: application/json; charset=UTF-8');

// log errors to debug_log.txt so JSON output isn't broken by a PHP warning
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__.'/debug_log.txt');

// small helpers
function fail($msg){
    echo json_encode(['success'=>false,'message'=>$msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function t2m($t){
    if(!$t) return 0;
    $p = explode(':', substr($t,0,5));
    return intval($p[0])*60 + intval($p[1]);
}
function m2ampm($m){
    $h = floor($m/60); $min = $m%60;
    return date('h:i A', strtotime(sprintf('%02d:%02d:00',$h,$min)));
}

// --- debug: log incoming POST and raw body to debug_log.txt (useful for diagnosis)
file_put_contents(__DIR__.'/debug_log.txt', "=== ".date('r')." ===\n", FILE_APPEND);
file_put_contents(__DIR__.'/debug_log.txt', "POST:\n".print_r($_POST, true)."\n", FILE_APPEND);
$raw = file_get_contents('php://input');
if ($raw) file_put_contents(__DIR__.'/debug_log.txt', "RAW:\n".$raw."\n", FILE_APPEND);

// 1) read inputs robustly
$owner_id     = isset($_POST['owner_id']) ? (int)$_POST['owner_id'] : 0;
$date         = $_POST['date'] ?? $_POST['booking_date'] ?? '';
$staff_type   = $_POST['staff_type'] ?? '';
$staff_number = isset($_POST['staff_number']) ? (int)$_POST['staff_number'] : 0;

// collect services array robustly
$services = [];
if (isset($_POST['services']) && is_array($_POST['services'])) $services = $_POST['services'];
else {
    // catch keys like 'services[]' or 'services[0]'
    foreach ($_POST as $k=>$v){
        if ($k === 'services' || $k === 'services[]' || strpos($k,'services[')===0){
            if(is_array($v)) $services = array_merge($services, $v);
            else $services[] = $v;
        }
    }
}
// if still empty, try decoding JSON body
if (count($services) === 0 && $raw){
    $maybe = json_decode($raw,true);
    if (json_last_error()===JSON_ERROR_NONE && !empty($maybe['services'])) {
        $services = (array)$maybe['services'];
    }
}
$services = array_values(array_filter(array_map('intval', $services)));

if ($owner_id <= 0) fail('Missing owner id');
if (!$date) fail('Missing date');
if (!$staff_type) fail('Missing staff type');
if ($staff_number <= 0) fail('Missing staff number');
if (count($services) === 0) fail('Invalid services selection');

// 2) get salon hours
$stmt = $conn->prepare("SELECT open_time, close_time FROM owner_profiles1 WHERE owner_id = ? AND request_status = 'approved'");
$stmt->bind_param("i",$owner_id);
$stmt->execute();
$stmt->bind_result($open_time,$close_time);
if (!$stmt->fetch()){ $stmt->close(); fail('Salon not found or not approved'); }
$stmt->close();

$openM = t2m($open_time); $closeM = t2m($close_time);
if ($closeM <= $openM) fail('Invalid salon hours');

// 3) compute total duration from staff_services
$total_minutes = 0;
$svcStmt = $conn->prepare("
    SELECT COALESCE(duration_minutes,0) AS duration_minutes 
    FROM staff_services ss
    JOIN salon_staff sf ON sf.id = ss.staff_id
    WHERE ss.id = ? AND sf.owner_id = ?
");
foreach ($services as $sid) {
    $sid = (int)$sid;
    if ($sid <= 0) continue;
    $svcStmt->bind_param("ii", $sid, $owner_id);
    $svcStmt->execute();
    $svcStmt->bind_result($duration);
    if ($svcStmt->fetch()) {
        $total_minutes += max(0, (int)$duration);
    }
    $svcStmt->free_result();
}
$svcStmt->close();

if ($total_minutes <= 0) fail('Invalid services selection');

// 4) find bookings table name (support 'bookings' and fallback 'booking')
$bookings_table = 'bookings';
$check = $conn->query("SHOW TABLES LIKE 'bookings'");
if ($check === false || $check->num_rows === 0) {
    $check2 = $conn->query("SHOW TABLES LIKE 'booking'");
    if ($check2 && $check2->num_rows>0) $bookings_table = 'booking';
}

// 5) fetch existing bookings for this staff/date
$bookings = [];
$q = $conn->prepare("
    SELECT id, start_time, end_time, user_id
    FROM {$bookings_table}
    WHERE owner_id = ? AND booking_date = ? AND staff_type = ? AND staff_number = ? AND status IN ('booked','confirmed')
    ORDER BY start_time
");
$q->bind_param("issi",$owner_id,$date,$staff_type,$staff_number);
$q->execute();
$res = $q->get_result();
while ($r = $res->fetch_assoc()){
    $b = [
        'id' => $r['id'],
        'start_time' => date('h:i A', strtotime($r['start_time'])),
        'end_time'   => date('h:i A', strtotime($r['end_time'])),
        'customer' => '',
        'services' => ''
    ];
    // fetch user name
    if (!empty($r['user_id'])) {
        $u = $conn->prepare("SELECT name FROM users WHERE id = ?");
        $u->bind_param("i",$r['user_id']);
        $u->execute();
        $u->bind_result($uname);
        if ($u->fetch()) $b['customer'] = $uname;
        $u->close();
    }
    // fetch service names via booking_services if exists
    $svcNames = [];
    // check booking_services table variants
    $bs_table = null;
    $chk = $conn->query("SHOW TABLES LIKE 'booking_services'");
    if ($chk && $chk->num_rows>0) $bs_table = 'booking_services';
    else {
        $chk2 = $conn->query("SHOW TABLES LIKE 'booking_service'");
        if ($chk2 && $chk2->num_rows>0) $bs_table = 'booking_service';
    }
    if ($bs_table) {
        $bs = $conn->prepare("
            SELECT s.service_name 
            FROM {$bs_table} bs 
            JOIN staff_services s ON s.id = bs.service_id 
            WHERE bs.booking_id = ?
        ");
        $bs->bind_param("i", $r['id']);
        $bs->execute();
        $bres = $bs->get_result();
        while ($br = $bres->fetch_assoc()) $svcNames[] = $br['service_name'];
        $bs->close();
        $b['services'] = implode(', ', $svcNames);
    }
    $bookings[] = $b;
}
$q->close();

// 6) build busy minutes map and mark booked minutes inside salon hours
$busy = [];
for ($m=$openM; $m<$closeM; $m++) $busy[$m] = 0;

$q2 = $conn->prepare("SELECT start_time,end_time FROM {$bookings_table} WHERE owner_id=? AND booking_date=? AND staff_type=? AND staff_number=? AND status IN ('booked','confirmed')");
$q2->bind_param("issi",$owner_id,$date,$staff_type,$staff_number);
$q2->execute();
$res2 = $q2->get_result();
while ($r = $res2->fetch_assoc()){
    $s = t2m($r['start_time']); $e = t2m($r['end_time']);
    for ($mm = max($s,$openM); $mm < min($e,$closeM); $mm++){
        if (isset($busy[$mm])) $busy[$mm] += 1;
    }
}
$q2->close();

// 7) generate available start times (15 minute step)
$available = [];
$step = 15;
for ($start = $openM; $start + $total_minutes <= $closeM; $start += $step){
    $ok = true;
    for ($m = $start; $m < $start + $total_minutes; $m++){
        if (!isset($busy[$m]) || $busy[$m] > 0){ $ok = false; break; }
    }
    if ($ok) $available[] = m2ampm($start);
}

// 8) return
echo json_encode([
    'success' => true,
    'date' => $date,
    'date_human' => date('Y-m-d', strtotime($date)),
    'bookings' => $bookings,
    'available' => $available
], JSON_UNESCAPED_UNICODE);
exit;
