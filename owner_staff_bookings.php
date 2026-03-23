<?php
// owner_staff_bookings.php
session_start();
require_once "config.php";
header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors',0);

$owner_id = isset($_POST['owner_id']) ? (int)$_POST['owner_id'] : 0;
$staff_type = $_POST['staff_type'] ?? '';
$staff_number = isset($_POST['staff_number']) ? (int)$_POST['staff_number'] : 0;

if($owner_id<=0 || !$staff_type || $staff_number<=0){
  echo json_encode(['success'=>false,'message'=>'Missing parameters']); exit;
}

$stmt = $conn->prepare("
  SELECT b.id,b.booking_date,b.start_time,b.end_time,b.status,u.name AS customer,
         GROUP_CONCAT(s.service_name SEPARATOR ', ') AS services
  FROM bookings b
  LEFT JOIN booking_services bs ON bs.booking_id=b.id
  LEFT JOIN staff_services s ON s.id=bs.service_id
  LEFT JOIN users u ON u.id=b.user_id
  WHERE b.owner_id=? AND b.staff_type=? AND b.staff_number=?
  GROUP BY b.id
  ORDER BY b.booking_date DESC, b.start_time
");
$stmt->bind_param("iss",$owner_id,$staff_type,$staff_number);
$stmt->execute();
$res = $stmt->get_result();
$rows=[];
while($r=$res->fetch_assoc()){
  $rows[] = [
    'id'=>$r['id'],
    'booking_date'=>$r['booking_date'],
    'start_time'=>date('h:i A', strtotime($r['start_time'])),
    'end_time'=>date('h:i A', strtotime($r['end_time'])),
    'status'=>$r['status'],
    'customer'=>$r['customer'],
    'services'=>$r['services'] ?? ''
  ];
}
$stmt->close();
echo json_encode(['success'=>true,'bookings'=>$rows]);
