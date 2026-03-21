<?php
session_start();
require_once "config.php";

// Initialize message variable to avoid undefined error
$msg = "";

// Hardcoded admin credentials
$adminEmail = "preetipednekar@gmail.com";
$adminPass  = "SHD1295_preeti";

// Handle form submit
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action']) && $_POST['action'] === "signup") {
        // --- Signup process ---
        $name = htmlspecialchars($_POST['name']);
        $email = htmlspecialchars($_POST['email']);
        $password = $_POST['password'];
        $role = $_POST['role'];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = "Invalid email format!";
        } else {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email=?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                $msg = "Email already registered!";
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssss", $name, $email, $hash, $role);
                if ($stmt->execute()) {
                    $msg = "Signup successful! Please login.";
                } else {
                    $msg = "Error: " . $stmt->error;
                }
            }
            $stmt->close();
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === "login") {
        // --- Login process ---
        $email = htmlspecialchars($_POST['email']);
        $password = $_POST['password'];

        // Check admin
        if ($email === $adminEmail && $password === $adminPass) {
            $_SESSION['role'] = "admin";
            $_SESSION['name'] = "Admin";
            header("Location: admin_dashboard.php");
            exit;
        }

        // Check in database
        $stmt = $conn->prepare("SELECT id, name, password, role FROM users WHERE email=?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $stmt->bind_result($id, $name, $hash, $role);
            $stmt->fetch();

            if (password_verify($password, $hash)) {
                $_SESSION['id'] = $id;
                $_SESSION['name'] = $name;
                $_SESSION['role'] = $role;

                if ($role == "user") {
                    header("Location: user_dashboard.php");
                } elseif ($role == "owner") {
                    header("Location: owner_dashboard.php");
                }
                exit;
            } else {
                $msg = "Invalid credentials!";
            }
        } else {
            $msg = "No account found!";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Hair Booking - Auth</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://kit.fontawesome.com/yourkit.js" crossorigin="anonymous"></script>
  <style>
    body {
      margin:0; padding:0;
      display:flex; justify-content:center; align-items:center;
      height:100vh; background:linear-gradient(135deg,#ff9a9e,#fad0c4);
      font-family: 'Segoe UI', sans-serif;
    }
    .auth-container {
      width:900px; height:550px; background:#fff; border-radius:20px;
      box-shadow:0 8px 25px rgba(0,0,0,.2); overflow:hidden;
      display:flex; animation: fadeIn 1s ease-in-out;
    }
    @keyframes fadeIn {from{opacity:0; transform:scale(.9);} to{opacity:1; transform:scale(1);} }
    .left, .right {flex:1; padding:40px;}
    .left {background:#f8f9fa; display:flex; flex-direction:column; justify-content:center; align-items:center;}
    .left h2 {margin-bottom:20px;}
    .right {background:#fff; display:flex; justify-content:center; align-items:center;}
    .form-box {width:100%; max-width:350px;}
    .input-group-text {background:#fff;}
    .btn-custom {border-radius:30px;}
    .toggle-link {cursor:pointer; color:#007bff;}
  </style>
</head>
<body>

<div class="auth-container">
  <div class="left text-center">
    <h2 id="formTitle">Welcome Back!</h2>
    <p id="formDesc">To keep connected please login</p>
    <button class="btn btn-outline-primary btn-custom mt-3" onclick="toggleForm()">Switch</button>
  </div>
  <div class="right">
    <div class="form-box">
      <?php if($msg) echo "<div class='alert alert-info'>$msg</div>"; ?>

      <!-- Login Form -->
      <form method="POST" id="loginForm">
        <input type="hidden" name="action" value="login">
        <div class="mb-3 input-group">
          <span class="input-group-text"><i class="fas fa-envelope"></i></span>
          <input type="email" name="email" class="form-control" placeholder="Email" required autocomplete="off">        </div>
        <div class="mb-3 input-group">
          <span class="input-group-text"><i class="fas fa-lock"></i></span>
          <input type="password" name="password" id="loginPass" class="form-control" placeholder="Password" required autocomplete="new-password">
          <span class="input-group-text" onclick="togglePass('loginPass','eye1')"><i id="eye1" class="fas fa-eye"></i></span>
        </div>
        <button type="submit" class="btn btn-primary w-100 btn-custom">Login</button>
        <p class="text-center mt-3">Don't have an account? <span class="toggle-link" onclick="toggleForm()">Signup</span></p>
      </form>

      <!-- Signup Form -->
      <form method="POST" id="signupForm" style="display:none;">
        <input type="hidden" name="action" value="signup">
        <div class="mb-3 input-group">
          <span class="input-group-text"><i class="fas fa-user"></i></span>
          <input type="text" name="name" class="form-control" placeholder="Full Name" required autocomplete="off">
        </div>
        <div class="mb-3 input-group">
          <span class="input-group-text"><i class="fas fa-envelope"></i></span>
          <input type="email" name="email" class="form-control" placeholder="Email" required autocomplete="off">
        </div>
        <div class="mb-3 input-group">
          <span class="input-group-text"><i class="fas fa-lock"></i></span>
          <input type="password" name="password" id="signupPass" class="form-control" placeholder="Password" required >
          <span class="input-group-text" onclick="togglePass('signupPass','eye2')"><i id="eye2" class="fas fa-eye"></i></span>
        </div>
        <div class="mb-3">
          <select name="role" class="form-select" required>
            <option value="user">User</option>
            <option value="owner">Owner</option>
          </select>
        </div>
        <button type="submit" class="btn btn-success w-100 btn-custom">Signup</button>
        <p class="text-center mt-3">Already have an account? <span class="toggle-link" onclick="toggleForm()">Login</span></p>
      </form>
    </div>
  </div>
</div>

<script>
function togglePass(id, eyeId) {
  let input = document.getElementById(id);
  let eye = document.getElementById(eyeId);
  if (input.type === "password") {
    input.type = "text";
    eye.classList.replace("fa-eye","fa-eye-slash");
  } else {
    input.type = "password";
    eye.classList.replace("fa-eye-slash","fa-eye");
  }
}

function toggleForm() {
  let loginForm = document.getElementById("loginForm");
  let signupForm = document.getElementById("signupForm");
  let title = document.getElementById("formTitle");
  let desc = document.getElementById("formDesc");

  if (loginForm.style.display === "none") {
    loginForm.style.display = "block";
    signupForm.style.display = "none";
    title.innerText = "Welcome Back!";
    desc.innerText = "To keep connected please login";
  } else {
    loginForm.style.display = "none";
    signupForm.style.display = "block";
    title.innerText = "Hello, Friend!";
    desc.innerText = "Enter details and start your journey";
  }
}
</script>

</body>
</html>
