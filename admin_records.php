<?php
// admin_records.php
session_start();
require_once "config.php";

// only admin access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: auth.php");
    exit;
}

// fetch all salons with owner info
$sql = "SELECT o.id, o.salon_name, o.salon_type, o.staff_male, o.staff_female,
               o.open_time, o.close_time, o.request_status,
               u.name AS owner_name, u.email AS owner_email
        FROM owner_profiles1 o
        JOIN users u ON o.owner_id = u.id
        ORDER BY o.id DESC";
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Records - Hair System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://kit.fontawesome.com/7b2c2b7d6c.js" crossorigin="anonymous"></script>
<style>
  body {background:#f5f7fb;}
  .navbar {box-shadow: 0 4px 12px rgba(0,0,0,.1);}
  .sidebar {
    width: 240px; min-height: 100vh; background:#fff;
    position: fixed; top:0; left:0; padding-top:60px;
    box-shadow: 4px 0 12px rgba(0,0,0,.08);
  }
  .sidebar a {display:block; padding:14px 18px; color:#333; text-decoration:none;}
  .sidebar a:hover {background:#f0f0f0;}
  .content {margin-left:240px; padding:25px;}
  .card {border-radius:12px; box-shadow:0 6px 16px rgba(0,0,0,.08);}
</style>
</head>
<body>
<nav class="navbar navbar-light bg-white px-3 fixed-top">
  <a class="navbar-brand fw-bold"><i class="fa-solid fa-scissors me-2"></i>Hair System – Admin</a>
  <a href="logout.php" class="btn btn-sm btn-outline-danger">Logout</a>
</nav>

<div class="sidebar">
  <a href="admin_dashboard.php"><i class="fa-solid fa-bell me-2"></i> Pending Requests</a>
  <a href="admin_records.php"><i class="fa-solid fa-database me-2"></i> Records</a>
</div>

<div class="content">
  <h4 class="mb-4"><i class="fa-solid fa-database me-2"></i>Salon Records</h4>
  
  <?php while($row = $result->fetch_assoc()) { ?>
    <div class="card p-3 mb-3">
      <h5><?php echo htmlspecialchars($row['salon_name']); ?></h5>
      <p class="mb-1"><b>Owner:</b> <?php echo htmlspecialchars($row['owner_name']); ?> (<?php echo htmlspecialchars($row['owner_email']); ?>)</p>
      <p class="mb-1"><b>Type:</b> <?php echo ucfirst($row['salon_type']); ?></p>
      <p class="mb-1"><b>Staff:</b> Male <?php echo $row['staff_male']; ?>, Female <?php echo $row['staff_female']; ?></p>
      <p class="mb-1"><b>Timings:</b> <?php echo $row['open_time']." - ".$row['close_time']; ?></p>
      <span class="badge 
        <?php 
          if ($row['request_status']=='approved') echo 'bg-success';
          elseif ($row['request_status']=='rejected') echo 'bg-danger';
          else echo 'bg-warning text-dark';
        ?>">
        <?php echo ucfirst($row['request_status']); ?>
      </span>
    </div>
  <?php } ?>
</div>
</body>
</html>
