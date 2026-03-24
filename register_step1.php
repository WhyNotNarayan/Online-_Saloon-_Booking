<?php
session_start();
require_once "config.php";

if (!isset($_SESSION['id']) || $_SESSION['role'] !== 'owner') {
    header("Location: auth.php");
    exit;
}

$owner_id = (int)$_SESSION['id'];

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $salon_name = trim($_POST['salon_name'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $salon_type = $_POST['salon_type'] ?? 'both';
    $open_time = $_POST['open_time'] ?? '09:00';
    $close_time = $_POST['close_time'] ?? '18:00';
    $staff_count = (int)($_POST['staff_count'] ?? 0);

    if ($salon_name === '') $errors[] = "Salon name is required.";
    if ($mobile === '') $errors[] = "Mobile number required.";
    if ($address === '') $errors[] = "Address required.";
    if ($staff_count < 0 || $staff_count > 100) $errors[] = "Staff count must be between 0 and 100.";

    $staffs = $_POST['staffs'] ?? [];

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            $stmtUpd = $conn->prepare("UPDATE owner_profiles1 SET salon_name=?, address=?, mobile=?, salon_type=?, staff_male=NULL, staff_female=NULL, open_time=?, close_time=? WHERE owner_id=?");
            $stmtUpd->bind_param("ssssssi", $salon_name, $address, $mobile, $salon_type, $open_time, $close_time, $owner_id);
            $stmtUpd->execute();
            if ($stmtUpd->affected_rows === 0) {
              $stmtIns = $conn->prepare("INSERT INTO owner_profiles1 
              (owner_id, salon_name, address, mobile, salon_type, open_time, close_time, request_status) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
          $status = 'pending';
          $stmtIns->bind_param("isssssss", $owner_id, $salon_name, $address, $mobile, $salon_type, $open_time, $close_time, $status);
          $stmtIns->execute();
          $stmtIns->close();
          
            }
            $stmtUpd->close();

            if ($salon_type === 'male') {
                $stmt = $conn->prepare("UPDATE owner_profiles1 SET staff_male = ?, staff_female = 0 WHERE owner_id = ?");
                $stmt->bind_param("ii", $staff_count, $owner_id);
            } elseif ($salon_type === 'female') {
                $stmt = $conn->prepare("UPDATE owner_profiles1 SET staff_male = 0, staff_female = ? WHERE owner_id = ?");
                $stmt->bind_param("ii", $staff_count, $owner_id);
            } else {
                $stmt = $conn->prepare("UPDATE owner_profiles1 SET staff_male = ?, staff_female = ? WHERE owner_id = ?");
                $stmt->bind_param("iii", $staff_count, $staff_count, $owner_id);
            }
            $stmt->execute();
            $stmt->close();

            $delStmt = $conn->prepare("SELECT id FROM salon_staff WHERE owner_id = ?");
            $delStmt->bind_param("i", $owner_id);
            $delStmt->execute();
            $res = $delStmt->get_result();
            $old_ids = [];
            while ($r = $res->fetch_assoc()) $old_ids[] = (int)$r['id'];
            $delStmt->close();
            if (!empty($old_ids)) {
                $in = implode(',', array_fill(0, count($old_ids), '?'));
                $types = str_repeat('i', count($old_ids));
                $sqlServicesDel = "DELETE FROM staff_services WHERE staff_id IN ($in)";
                $p = $conn->prepare($sqlServicesDel);
                $p->bind_param($types, ...$old_ids);
                $p->execute();
                $p->close();

                $sqlStaffDel = "DELETE FROM salon_staff WHERE id IN ($in)";
                $p2 = $conn->prepare($sqlStaffDel);
                $p2->bind_param($types, ...$old_ids);
                $p2->execute();
                $p2->close();
            }

            $insertStaffStmt = $conn->prepare("INSERT INTO salon_staff (owner_id, staff_code, staff_name, gender, specialization) VALUES (?, ?, ?, ?, ?)");
            $insertServiceStmt = $conn->prepare("INSERT INTO staff_services (staff_id, service_name, price, duration_hours, duration_minutes) VALUES (?, ?, ?, ?, ?)");

            foreach ($staffs as $code => $staffData) {
                $staff_name = trim($staffData['staff_name'] ?? '');
                $gender = $staffData['gender'] ?? 'male';
                // specialization now might be comma-separated string from JS
                $specialization = $staffData['specialization'] ?? '';
                if ($staff_name === '') continue;

                $insertStaffStmt->bind_param("issss", $owner_id, $code, $staff_name, $gender, $specialization);
                $insertStaffStmt->execute();
                $staff_inserted_id = $insertStaffStmt->insert_id;

                $services = $staffData['services'] ?? [];
                foreach ($services as $sv) {
                    $sname = trim($sv['name'] ?? '');
                    $sprice = floatval($sv['price'] ?? 0);
                    $sh = (int)($sv['hours'] ?? 0);
                    $sm = (int)($sv['minutes'] ?? 0);
                    if ($sname === '') continue;
                    $insertServiceStmt->bind_param("isdii", $staff_inserted_id, $sname, $sprice, $sh, $sm);
                    $insertServiceStmt->execute();
                }
            }

            $insertStaffStmt->close();
            $insertServiceStmt->close();

            $conn->commit();
            $success = "Profile, staff and services saved successfully!";
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Save failed: " . $e->getMessage();
        }
    }
}

$profile = [];
$pref = $conn->prepare("SELECT * FROM owner_profiles1 WHERE owner_id = ?");
$pref->bind_param("i", $owner_id);
$pref->execute();
$prefRes = $pref->get_result();
if ($prefRes && $prefRes->num_rows) {
    $profile = $prefRes->fetch_assoc();
}
$pref->close();

function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Owner Onboarding — Salon Profile</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background: #f4f7fb; }
    .card-modern { border: none; border-radius: 16px; box-shadow: 0 8px 30px rgba(29, 31, 43, 0.08); }
    .section-title { font-size: 1.6rem; font-weight: 700; display:flex; align-items:center; gap:.6rem; }
    .icon-circle { width:44px; height:44px; border-radius:10px; display:inline-flex; align-items:center; justify-content:center; background:linear-gradient(135deg,#7b61ff,#5ac8fa); color:white; }
    .staff-block { border-radius: 12px; padding: 16px; background: #fff; transition: transform .22s ease, box-shadow .22s ease; }
    .staff-block:hover { transform: translateY(-6px); box-shadow: 0 20px 40px rgba(36, 37, 40, 0.06); }
    .service-row { background:#fbfdff; border-radius:8px; padding:10px; margin-bottom:8px; }
    .fade-in { animation: fadeInUp .36s ease both; }
    @keyframes fadeInUp { from { opacity:0; transform: translateY(8px); } to { opacity:1; transform: translateY(0); } }
    .small-muted { font-size:.85rem; color:#6b7280; }
    .btn-green { background:#198754; color:#fff; border:none; }
    .group-header { font-weight:600; color:#374151; margin-top:8px; margin-bottom:4px; }
  </style>
</head>
<body>
<div class="container py-5">
  <div class="card card-modern p-4">
    <div class="d-flex align-items-center justify-content-between mb-4">
      <div class="section-title">
        <span class="icon-circle"><i class="bi bi-shop"></i></span>
        <div>Salon Profile</div>
      </div>
      <div class="small-muted">Owner onboarding — fill staff & services</div>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger">
        <?php foreach ($errors as $err) echo "<div>".e($err)."</div>"; ?>
      </div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <form id="onboardForm" method="post" class="row g-3">
      <div class="col-12 col-lg-6">
        <label class="form-label">Salon Name</label>
        <input type="text" name="salon_name" class="form-control form-control-lg" value="<?= e($profile['salon_name'] ?? '') ?>" required>

        <label class="form-label mt-3">Mobile Number</label>
        <input type="tel" name="mobile" class="form-control" value="<?= e($profile['mobile'] ?? '') ?>" required>

        <label class="form-label mt-3">Address</label>
        <textarea name="address" class="form-control" rows="3" required><?= e($profile['address'] ?? '') ?></textarea>

        <div class="row g-2 mt-3">
          <div class="col-6">
            <label class="form-label">Salon Type</label>
            <select name="salon_type" id="salon_type" class="form-select">
              <option value="male" <?= (($profile['salon_type'] ?? '') === 'male' ? 'selected' : '') ?>>Male</option>
              <option value="female" <?= (($profile['salon_type'] ?? '') === 'female' ? 'selected' : '') ?>>Female</option>
              <option value="both" <?= (($profile['salon_type'] ?? '') === 'both' ? 'selected' : '') ?>>Both</option>
            </select>
          </div>
          <div class="col-3">
            <label class="form-label">Open Time</label>
            <input type="time" name="open_time" class="form-control" value="<?= e($profile['open_time'] ?? '09:00') ?>">
          </div>
          <div class="col-3">
            <label class="form-label">Close Time</label>
            <input type="time" name="close_time" class="form-control" value="<?= e($profile['close_time'] ?? '18:00') ?>">
          </div>
        </div>

        <label class="form-label mt-3">Number of staff</label>
        <input type="number" name="staff_count" id="staff_count" class="form-control" min="0" max="100" value="<?= e($profile['staff_male'] ?? 4) ?>">

        <div class="mt-4">
          <button type="button" id="generate_staff_btn" class="btn btn-primary"><i class="bi bi-people-fill"></i> Generate Staff Blocks</button>
          <button type="submit" class="btn btn-green ms-2">Save Profile & Staff</button>
        </div>
      </div>

      <div class="col-12 col-lg-6">
        <div class="section-title mb-3"><div>Staff Setup</div></div>
        <div id="staff_container" class="d-flex flex-column gap-3">
          <div class="small-muted">No staff blocks created yet. Click <strong>Generate Staff Blocks</strong> to create staff slots.</div>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- staff template with multiple specialization checkboxes -->
<template id="staff-template">
  <div class="staff-block fade-in border p-3" data-staff-code="">
    <div class="d-flex align-items-start justify-content-between mb-2">
      <div>
        <div class="fw-bold staff-title"></div>
        <div class="small-muted staff-subtitle"></div>
      </div>
      <div><button type="button" class="btn btn-outline-danger btn-sm remove-staff"><i class="bi bi-trash"></i></button></div>
    </div>
    <div class="row g-2 align-items-center">
      <div class="col-12">
        <label class="form-label">Staff Name</label>
        <input type="text" class="form-control staff-name" placeholder="e.g. Rahul">
      </div>

      <div class="col-12">
        <label class="form-label">Specialization (Select all that apply)</label>
        <div class="d-flex gap-3 flex-wrap specialization-checkboxes">
          <div class="form-check"><input class="form-check-input spec" type="checkbox" value="cutting"><label class="form-check-label small-muted">Cutting & Styling</label></div>
          <div class="form-check"><input class="form-check-input spec" type="checkbox" value="coloring"><label class="form-check-label small-muted">Coloring</label></div>
          <div class="form-check"><input class="form-check-input spec" type="checkbox" value="textured"><label class="form-check-label small-muted">Textured Hair Care</label></div>
          <div class="form-check"><input class="form-check-input spec" type="checkbox" value="extensions"><label class="form-check-label small-muted">Hair Extensions</label></div>
          <div class="form-check"><input class="form-check-input spec" type="checkbox" value="bridal"><label class="form-check-label small-muted">Bridal Styling</label></div>
        </div>
      </div>

      <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="small-muted">Services for this staff</div>
          <div><button type="button" class="btn btn-sm btn-outline-primary add-service"><i class="bi bi-plus"></i> Add service</button></div>
        </div>
        <div class="services-list"></div>
      </div>
    </div>
  </div>
</template>

<template id="service-template">
  <div class="service-row d-flex gap-2 align-items-center">
    <div class="flex-fill"><input type="text" class="form-control form-control-sm service-name" placeholder="Service name"></div>
    <div style="width:110px"><input type="number" min="0" step="0.01" class="form-control form-control-sm service-price" placeholder="Price"></div>
    <div style="width:120px" class="d-flex gap-1">
      <input type="number" min="0" class="form-control form-control-sm service-hours" placeholder="H">
      <input type="number" min="0" max="59" class="form-control form-control-sm service-mins" placeholder="M">
    </div>
    <div><button type="button" class="btn btn-sm btn-outline-danger remove-service"><i class="bi bi-x"></i></button></div>
  </div>
</template>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  const staffContainer = document.getElementById('staff_container');
  const staffTemplate = document.getElementById('staff-template').content;
  const serviceTemplate = document.getElementById('service-template').content;
  const generateBtn = document.getElementById('generate_staff_btn');
  const salonTypeSelect = document.getElementById('salon_type');
  const staffCountInput = document.getElementById('staff_count');
  const onboardForm = document.getElementById('onboardForm');

  // create a staff block element (returns the element ready to append)
  function createStaffBlock(code, gender) {
    const clone = staffTemplate.cloneNode(true);
    const wrapper = clone.querySelector('.staff-block');

    wrapper.dataset.staffCode = code;
    wrapper.querySelector('.staff-title').textContent = code.toUpperCase();
    wrapper.querySelector('.staff-subtitle').textContent = `Gender: ${gender}`;

    // remove staff
    wrapper.querySelector('.remove-staff').addEventListener('click', () => {
      wrapper.remove();
    });

    // add service -> append actual row into wrapper.services-list
    wrapper.querySelector('.add-service').addEventListener('click', () => {
      const sv = serviceTemplate.cloneNode(true);
      const svRow = sv.querySelector('.service-row');
      svRow.querySelector('.remove-service').addEventListener('click', () => svRow.remove());
      wrapper.querySelector('.services-list').appendChild(svRow);
    });

    return wrapper;
  }

  // Generate blocks based on salon type and staff count
  function generateStaffBlocks() {
    const type = salonTypeSelect.value;
    const count = parseInt(staffCountInput.value) || 0;
    staffContainer.innerHTML = '';
    if (count <= 0) {
      staffContainer.innerHTML = '<div class="small-muted">Enter a positive staff count to generate staff slots.</div>';
      return;
    }

    if (type === 'male') {
      for (let i=1; i<=count; i++) {
        staffContainer.appendChild(createStaffBlock('m' + i, 'Male'));
      }
    } else if (type === 'female') {
      for (let i=1; i<=count; i++) {
        staffContainer.appendChild(createStaffBlock('f' + i, 'Female'));
      }
    } else { // both
      // optional group header for clarity
      const mh = document.createElement('div');
      mh.className = 'group-header';
      mh.textContent = 'Male staff';
      staffContainer.appendChild(mh);
      for (let i=1; i<=count; i++) {
        staffContainer.appendChild(createStaffBlock('m' + i, 'Male'));
      }
      const fh = document.createElement('div');
      fh.className = 'group-header';
      fh.textContent = 'Female staff';
      staffContainer.appendChild(fh);
      for (let i=1; i<=count; i++) {
        staffContainer.appendChild(createStaffBlock('f' + i, 'Female'));
      }
    }
  }

  generateBtn.addEventListener('click', generateStaffBlocks);

  // Before submit, clear any existing hidden inputs we previously added, then build new ones
  onboardForm.addEventListener('submit', function(e){
    // remove previously inserted hidden inputs with name starting with "staffs["
    document.querySelectorAll('input[name^="staffs"]').forEach(n => n.remove());

    const staffBlocks = staffContainer.querySelectorAll('.staff-block');
    staffBlocks.forEach((block) => {
      const code = block.dataset.staffCode;
      if (!code) return;
      const staffName = block.querySelector('.staff-name').value.trim();
      const gender = block.querySelector('.staff-subtitle').textContent.toLowerCase().includes('female') ? 'female' : 'male';

      // collect specialization checkboxes as comma-separated string
      const specs = Array.from(block.querySelectorAll('.spec:checked')).map(c => c.value);
      const specValue = specs.join(',');

      // create hidden inputs
      const base = `staffs[${code}]`;
      const inputs = [
        {name: `${base}[staff_name]`, value: staffName},
        {name: `${base}[gender]`, value: gender},
        {name: `${base}[specialization]`, value: specValue}
      ];
      inputs.forEach(item => {
        const inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = item.name;
        inp.value = item.value;
        onboardForm.appendChild(inp);
      });

      // services
      const services = block.querySelectorAll('.service-row');
      services.forEach((sv, idx) => {
        const sName = sv.querySelector('.service-name').value.trim();
        const sPrice = sv.querySelector('.service-price').value.trim();
        const sH = sv.querySelector('.service-hours').value.trim();
        const sM = sv.querySelector('.service-mins').value.trim();

        const sInputs = [
          {name: `${base}[services][${idx}][name]`, value: sName},
          {name: `${base}[services][${idx}][price]`, value: sPrice},
          {name: `${base}[services][${idx}][hours]`, value: sH},
          {name: `${base}[services][${idx}][minutes]`, value: sM}
        ];
        sInputs.forEach(item => {
          const inp = document.createElement('input');
          inp.type = 'hidden';
          inp.name = item.name;
          inp.value = item.value;
          onboardForm.appendChild(inp);
        });
      });
    });
    // allow submit to continue
  });

  // Optional: auto-generate staff blocks if profile has staff counts already set (prefill)
  // We'll use profile.staff_male / staff_female if present on server via PHP rendered attributes.
  // If you later want prefill existing staff/services edit, that requires additional server JSON -> JS parsing.
})();
</script>
</body>
</html>
