<?php
// pay_placeholder.php
session_start();
require_once "config.php";
if (!isset($_SESSION['id'])) { header("Location: auth.php"); exit; }
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $conn->prepare("UPDATE bookings SET paid=1 WHERE id=?");
$stmt->bind_param("i",$id); $stmt->execute();
header("Location: receipt.php?id=".$id);
