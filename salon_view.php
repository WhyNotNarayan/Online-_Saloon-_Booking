<?php
session_start();
require_once "config.php";
if (!isset($_SESSION['id'])) { header("Location: auth.php"); exit; }

$owner_id = isset($_GET['owner_id']) ? (int)$_GET['owner_id'] : 0;
if ($owner_id <= 0) { echo "Invalid salon."; exit; }

// fetch salon profile (owner_profiles1)
$stmt = $conn->prepare("SELECT salon_name,address,salon_type,open_time,close_time,request_status,COALESCE(staff_male,0) AS staff_male, COALESCE(staff_female,0) AS staff_female FROM owner_profiles1 WHERE owner_id=?");
$stmt->bind_param("i",$owner_id);
$stmt->execute();
$stmt->bind_result($sname,$saddr,$stype,$open_time,$close_time,$status,$staff_male,$staff_female);
$stmt->fetch();
$stmt->close();

if ($status !== 'approved') { echo "<div class='alert alert-warning'>Salon not approved yet.</div>"; exit; }

// fetch services
$services = [];
$stmt = $conn->prepare("
    SELECT ss.id, ss.service_name, ss.price, IFNULL(ss.duration_minutes,0) AS duration_minutes 
    FROM staff_services ss
    JOIN salon_staff sf ON sf.id = ss.staff_id
    WHERE sf.owner_id = ? AND sf.status = 'active'
    ORDER BY ss.service_name
");
$stmt->bind_param("i",$owner_id);
$stmt->execute();
$res = $stmt->get_result();
while($r = $res->fetch_assoc()) $services[] = $r;
$stmt->close();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title><?php echo htmlspecialchars($sname); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>.serviceBox{cursor:pointer;} .staffSelect{min-width:200px;}</style>
</head>
<body class="bg-light">
<div class="container my-4">
  <a href="user_dashboard.php" class="btn btn-sm btn-outline-secondary mb-3">← Back</a>

  <div class="card p-4">
    <h3><?php echo htmlspecialchars($sname); ?></h3>
    <div class="text-muted"><?php echo htmlspecialchars($saddr); ?></div>
    <div class="mt-2">
      <b>Type:</b> <?php echo ucfirst($stype); ?> &nbsp;
      <b>Timings:</b> <?php echo date("h:i A", strtotime($open_time)).' - '.date("h:i A", strtotime($close_time)); ?>
    </div>

    <hr>
    <h5>Choose staff</h5>
    <div class="mb-3">
      <label class="form-label small">Pick a specific staff (user must choose one)</label>
      <select id="staff_picker" class="form-select staffSelect">
        <option value="">-- Select staff (e.g. M1 / F1) --</option>
        <?php
        for($i=1;$i<=(int)$staff_male;$i++){
          $val = "male|$i";
          echo "<option value=\"$val\">M{$i}</option>";
        }
        for($i=1;$i<=(int)$staff_female;$i++){
          $val = "female|$i";
          echo "<option value=\"$val\">F{$i}</option>";
        }
        ?>
      </select>
    </div>

    <h5>Services (choose one or multiple)</h5>
    <?php if(empty($services)) echo "<div class='alert alert-info'>No services yet.</div>"; ?>
    <form id="bookForm">
      <input type="hidden" name="owner_id" value="<?php echo $owner_id; ?>">
      <input type="hidden" name="staff_type" id="staff_type">
      <input type="hidden" name="staff_number" id="staff_number">

      <div class="row g-2">
        <?php foreach($services as $svc): ?>
          <div class="col-md-6">
            <div class="form-check border rounded p-2">
              <input class="form-check-input serviceBox" type="checkbox" id="svc_<?php echo $svc['id']; ?>" name="services[]" value="<?php echo $svc['id']; ?>"
                data-duration="<?php echo (int)$svc['duration_minutes']; ?>" data-price="<?php echo (float)$svc['price']; ?>">
              <label class="form-check-label" for="svc_<?php echo $svc['id']; ?>">
                <b><?php echo htmlspecialchars($svc['service_name']); ?></b>
                <div class="small text-muted">₹<?php echo number_format($svc['price'],2); ?> • <?php echo (int)$svc['duration_minutes']; ?> min</div>
              </label>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="mt-3 summary-pill p-2 bg-light border">
        <b>Total Duration:</b> <span id="sumDuration">0</span> mins &nbsp; | &nbsp;
        <b>Total Price:</b> ₹<span id="sumPrice">0.00</span>
      </div>

      <div class="row g-2 align-items-end mt-3">
        <div class="col-md-4">
          <label class="form-label small">Date</label>
          <!-- name kept booking_date as some other endpoints may expect it; we will send both below -->
          <input type="date" id="booking_date" name="booking_date" class="form-control" required>
        </div>

        <div class="col-md-2">
          <button type="button" class="btn btn-warning w-100" id="btnShowSlots" onclick="showSlots()">Show Slots</button>
        </div>
      </div>

      <div id="slotsArea" class="mt-4"></div>
    </form>
  </div>
</div>
<script>
  document.addEventListener('DOMContentLoaded', function() {
  const today = new Date();
  const yyyy = today.getFullYear();
  const mm = String(today.getMonth() + 1).padStart(2, '0');
  const dd = String(today.getDate()).padStart(2, '0');
  const minDate = `${yyyy}-${mm}-${dd}`;
  document.getElementById('booking_date').setAttribute('min', minDate);
});
function computeTotals(){
  let dur = 0, price = 0;
  document.querySelectorAll('.serviceBox:checked').forEach(cb=>{
    let d = parseInt(cb.dataset.duration || 0, 10);
    let p = parseFloat(cb.dataset.price || 0);
    if (isNaN(d)) d = 0;
    if (isNaN(p)) p = 0;
    dur += d;
    price += p;
  });
  document.getElementById('sumDuration').textContent = dur;
  document.getElementById('sumPrice').textContent = price.toFixed(2);
}
document.querySelectorAll('.serviceBox').forEach(cb=>cb.addEventListener('change', computeTotals));
computeTotals();

// staff picker -> fill hidden inputs
document.getElementById('staff_picker').addEventListener('change', function(){
  const v = this.value;
  if(!v){ document.getElementById('staff_type').value=''; document.getElementById('staff_number').value=''; return; }
  const parts = v.split('|');
  document.getElementById('staff_type').value = parts[0];
  document.getElementById('staff_number').value = parts[1];
});

async function showSlots(){
  const ownerId = <?php echo $owner_id; ?>;
  const staff_type = document.getElementById('staff_type').value;
  const staff_number = document.getElementById('staff_number').value;
  const date = document.getElementById('booking_date').value;
  const services = Array.from(document.querySelectorAll('.serviceBox:checked')).map(el=>el.value);

  if(!staff_type || !staff_number){ alert('Please choose a staff (e.g. M1 / F1).'); return; }
  if(services.length===0){ alert('Please choose at least one service.'); return; }
  if(!date){ alert('Please choose a date.'); return; }

  // build formdata and send 'services' (not 'services[]') so PHP receives $_POST['services'] as array
  const fd = new FormData();
  fd.append('owner_id', ownerId);
  fd.append('date', date);
  fd.append('booking_date', date); // duplicate name for compatibility
  fd.append('staff_type', staff_type);
  fd.append('staff_number', staff_number);
  services.forEach(s => fd.append('services', s)); // <-- important

  // DEBUG: show what we are sending (will appear in browser console)
  const debugObj = { owner_id: ownerId, date, staff_type, staff_number, services };
  console.log('showSlots - payload:', debugObj);

  const area = document.getElementById('slotsArea');
  area.innerHTML = '<div class="alert alert-secondary">Loading slots…</div>';

  try {
    const res = await fetch('get_bookings.php', { method:'POST', body:fd });
    const txt = await res.text();
    let data;
    try { data = JSON.parse(txt); }
    catch(e){ 
      area.innerHTML = '<div class="alert alert-danger">Server error (invalid response)</div>'; 
      console.error('invalid response from get_bookings.php:', txt);
      return; 
    }

    if(!data.success){ area.innerHTML = '<div class="alert alert-danger">'+(data.message||'Server error')+'</div>'; console.error('server message', data); return; }

    // render booked slots
    let html = '<h5>Booked slots for '+data.date_human+'</h5>';
    if(data.bookings.length===0){
      html += '<div class="alert alert-info">No bookings for this staff on the selected date.</div>';
    } else {
      html += '<ul class="list-group mb-3">';
      data.bookings.forEach(b=>{
        html += `<li class="list-group-item"><b>${b.start_time} - ${b.end_time}</b> • ${b.services} • by ${b.customer}</li>`;
      });
      html += '</ul>';
    }

    // available start times
    html += '<h5>Available start times (for chosen staff)</h5>';
    if(!data.available || data.available.length===0){
      html += '<div class="alert alert-warning">No available slots for selected staff & services on this date.</div>';
    }else{
      html += `<div class="mb-3">
                <label class="form-label small">Select Start Time</label>
                <select id="start_time" name="start_time" class="form-select">`;
      data.available.forEach(t=> html += `<option value="${t}">${t}</option>`);
      html += `</select>
               <div class="mt-2"><button type="button" class="btn btn-success" onclick="confirmBooking()">Confirm Booking</button></div>
               </div>`;
    }

    area.innerHTML = html;
  } catch(err){
    area.innerHTML = '<div class="alert alert-danger">Request failed. Try again.</div>';
    console.error(err);
  }
}

async function confirmBooking(){
  const startSel = document.getElementById('start_time');
  if(!startSel || !startSel.value){ alert('Select a start time'); return; }

  const form = document.getElementById('bookForm');
  const fd = new FormData(form);
  fd.append('start_time', startSel.value);

  const btn = document.querySelector('button[onclick="confirmBooking()"]');
  if(btn){ btn.disabled = true; btn.textContent = 'Booking…'; }

  try {
    const res = await fetch('make_booking.php', { method:'POST', body:fd });
    const txt = await res.text();
    let data;
    try { data = JSON.parse(txt); } catch(e){ alert('Server error.'); console.error(txt); if(btn){btn.disabled=false;btn.textContent='Confirm Booking';} return; }

    if(data.success){
      window.location = 'receipt.php?id=' + encodeURIComponent(data.booking_id);
    } else {
      alert(data.message || 'Booking failed.');
      if(btn){ btn.disabled=false; btn.textContent='Confirm Booking'; }
    }
  } catch(err){
    alert('Request failed. Try again.');
    if(btn){ btn.disabled=false; btn.textContent='Confirm Booking'; }
    console.error(err);
  }
}
</script>
</body>
</html>
