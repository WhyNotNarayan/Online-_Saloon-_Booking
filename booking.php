<?php
session_start();
require_once "config.php";

if (!isset($_SESSION['id'])) {
    header("Location: auth.php");
    exit;
}
$user_id = (int)$_SESSION['id'];

// CSRF Protection
$csrf_token = bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf_token;

// Helper functions
function json_out($arr){ header('Content-Type: application/json'); echo json_encode($arr); exit; }
function esc($s){ return htmlspecialchars($s ?? '', ENT_QUOTES); }

// Main page render
$salon_owner_id = (int)($_GET['salon_id'] ?? 0);
if (!$salon_owner_id) {
    die("Missing salon id.");
}

// Fetch salon info with status check
$stmt = $conn->prepare("
    SELECT salon_name, address, salon_type, open_time, close_time,
           staff_male, staff_female 
    FROM owner_profiles1 
    WHERE owner_id = ? AND request_status = 'approved'
");
$stmt->bind_param("i", $salon_owner_id);
$stmt->execute();
$salon = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$salon) {
    die("Salon not found or not approved.");
}

// Fetch services
$stmt = $conn->prepare("
    SELECT ss.id, ss.service_name, ss.price, ss.duration_hours, ss.duration_minutes,
           ss.staff_id, sf.staff_name, sf.staff_code
    FROM staff_services ss
    JOIN salon_staff sf ON sf.id = ss.staff_id
    WHERE sf.owner_id = ? AND sf.status = 'active'
    ORDER BY ss.service_name ASC
");
$stmt->bind_param("i", $salon_owner_id);
$stmt->execute();
$services = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// If no services are added yet
if (empty($services)) {
    die("This salon has not added any services yet.");
}

// Fetch staff list
$stmt = $conn->prepare("
    SELECT id, staff_name, gender
    FROM salon_staff 
    WHERE owner_id = ? AND status = 'active'
    ORDER BY staff_name
");
$stmt->bind_param("i", $salon_owner_id);
$stmt->execute();
$staff = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Book - <?= esc($salon['salon_name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f7f8fb; }
        .step { display: none; }
        .step.active { display: block; }
        .service-card {
            cursor: pointer;
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid #dee2e6;
            margin-bottom: 0.5rem;
            transition: all 0.2s;
        }
        .service-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .service-card.selected {
            background: #e7f5ff;
            border: 2px solid #339af0;
        }
        .staff-card {
            cursor: pointer;
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid #dee2e6;
            transition: all 0.2s;
        }
        .staff-card:hover {
            background: #f8f9fa;
        }
        .staff-card.selected {
            background: #e7f5ff;
            border: 2px solid #339af0;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <a href="user_dashboard.php" class="btn btn-outline-secondary mb-4">← Back to Dashboard</a>
                
                <!-- Salon Details -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <h4 class="card-title mb-2"><?= esc($salon['salon_name']) ?></h4>
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <span class="badge bg-primary">
                                <?= ucfirst($salon['salon_type']) === 'Both' ? 'Unisex' : ucfirst($salon['salon_type']) ?> Salon
                            </span>
                            <span class="text-muted">
                                <i class="fas fa-clock"></i> 
                                <?= date('h:i A', strtotime($salon['open_time'])) ?> - 
                                <?= date('h:i A', strtotime($salon['close_time'])) ?>
                            </span>
                        </div>
                        <p class="text-muted mb-0"><?= esc($salon['address']) ?></p>
                    </div>
                </div>

                <!-- Booking Steps -->
                <div class="card shadow-sm">
                    <div class="card-body">
                        <form id="bookingForm">
                            <input type="hidden" name="salon_id" value="<?= $salon_owner_id ?>">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

                            <!-- Step 1: Staff Selection -->
                            <div class="step active" id="step1">
                                <h5 class="mb-3">1. Select Staff Member</h5>
                                <div class="row g-3">
                                    <?php foreach($staff as $s): ?>
                                        <div class="col-md-6">
                                            <div class="staff-card" data-id="<?= $s['id'] ?>" data-gender="<?= $s['gender'] ?>">
                                                <div class="d-flex align-items-center">
                                                    <div>
                                                        <h6 class="mb-1"><?= esc($s['staff_name']) ?></h6>
                                                        <div class="text-muted small">
                                                            <?= ucfirst($s['gender']) ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Step 2: Service Selection -->
                            <div class="step" id="step2">
                                <h5 class="mb-3">2. Select Services</h5>
                                <div id="servicesList" class="mb-3"></div>
                                <div class="alert alert-info mb-0">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>Total Duration:</div>
                                        <div><span id="totalDuration">0</span> minutes</div>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>Total Amount:</div>
                                        <div>₹<span id="totalAmount">0.00</span></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Step 3: Date Selection -->
                            <div class="step" id="step3">
                                <h5 class="mb-3">3. Select Date</h5>
                                <div class="mb-3">
                                    <input type="date" class="form-control" name="date" required 
                                           min="<?= date('Y-m-d') ?>">
                                </div>
                                <button type="button" class="btn btn-primary" id="showSlotsBtn">
                                    Show Available Slots
                                </button>
                            </div>

                            <!-- Step 4: Time Slot Selection -->
                            <div class="step" id="step4">
                                <h5 class="mb-3">4. Select Time Slot</h5>
                                <div id="timeSlots" class="d-flex flex-wrap gap-2"></div>
                            </div>

                            <!-- Navigation -->
                            <div class="d-flex justify-content-between mt-4">
                                <button type="button" class="btn btn-outline-secondary" id="prevBtn" style="display:none">
                                    ← Previous
                                </button>
                                <button type="button" class="btn btn-primary" id="nextBtn" disabled>
                                    Next →
                                </button>
                                <button type="submit" class="btn btn-success" id="bookBtn" style="display:none">
                                    Confirm Booking
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentStep = 1;
        let selectedStaffId = null;
        let selectedServices = [];
        let selectedSlot = null;
        
        const form = document.getElementById('bookingForm');
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');
        const bookBtn = document.getElementById('bookBtn');
        
        // Staff selection
        document.querySelectorAll('.staff-card').forEach(card => {
            card.addEventListener('click', () => {
                document.querySelectorAll('.staff-card').forEach(c => c.classList.remove('selected'));
                card.classList.add('selected');
                selectedStaffId = card.dataset.id;
                loadStaffServices(selectedStaffId);
                nextBtn.disabled = false;
            });
        });

        // Navigation
        prevBtn.addEventListener('click', () => {
            if (currentStep > 1) {
                document.getElementById(`step${currentStep}`).classList.remove('active');
                currentStep--;
                document.getElementById(`step${currentStep}`).classList.add('active');
                updateNavigation();
            }
        });

        nextBtn.addEventListener('click', () => {
            if (validateCurrentStep()) {
                document.getElementById(`step${currentStep}`).classList.remove('active');
                currentStep++;
                document.getElementById(`step${currentStep}`).classList.add('active');
                updateNavigation();
            }
        });

        function updateNavigation() {
            prevBtn.style.display = currentStep > 1 ? 'block' : 'none';
            nextBtn.style.display = currentStep < 4 ? 'block' : 'none';
            bookBtn.style.display = currentStep === 4 ? 'block' : 'none';
            
            // Update button states
            nextBtn.disabled = !validateCurrentStep();
            bookBtn.disabled = !validateCurrentStep();
        }

        function validateCurrentStep() {
            switch(currentStep) {
                case 1:
                    return selectedStaffId !== null;
                case 2:
                    return selectedServices.length > 0;
                case 3:
                    return form.querySelector('[name="date"]').value !== '';
                case 4:
                    return selectedSlot !== null;
                default:
                    return false;
            }
        }

        async function loadStaffServices(staffId) {
            const servicesList = document.getElementById('servicesList');
            try {
                const response = await fetch(`get_staff_services.php?staff_id=${staffId}`);
                const services = await response.json();
                
                servicesList.innerHTML = services.map(service => `
                    <div class="service-card" data-id="${service.id}" 
                         data-price="${service.price}" data-duration="${service.duration_minutes}">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-bold">${service.service_name}</div>
                                <div class="text-muted small">
                                    Duration: ${service.duration_minutes} minutes
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="h5 mb-0">₹${service.price.toFixed(2)}</div>
                                <div class="text-success small d-none">Selected</div>
                            </div>
                        </div>
                    </div>
                `).join('');

                // Add click handlers for services
                document.querySelectorAll('.service-card').forEach(card => {
                    card.addEventListener('click', () => {
                        card.classList.toggle('selected');
                        const serviceId = parseInt(card.dataset.id);
                        const price = parseFloat(card.dataset.price);
                        const duration = parseInt(card.dataset.duration);
                        
                        if (card.classList.contains('selected')) {
                            selectedServices.push({ id: serviceId, price, duration });
                            card.querySelector('.text-success').classList.remove('d-none');
                        } else {
                            selectedServices = selectedServices.filter(s => s.id !== serviceId);
                            card.querySelector('.text-success').classList.add('d-none');
                        }
                        
                        updateTotals();
                        nextBtn.disabled = !validateCurrentStep();
                    });
                });
            } catch (error) {
                console.error('Error loading services:', error);
                servicesList.innerHTML = '<div class="alert alert-danger">Failed to load services</div>';
            }
        }

        function updateTotals() {
            const totalDuration = selectedServices.reduce((sum, s) => sum + s.duration, 0);
            const totalAmount = selectedServices.reduce((sum, s) => sum + s.price, 0);
            
            document.getElementById('totalDuration').textContent = totalDuration;
            document.getElementById('totalAmount').textContent = totalAmount.toFixed(2);
        }

        // Show available slots
        document.getElementById('showSlotsBtn').addEventListener('click', async () => {
            const date = form.querySelector('[name="date"]').value;
            if (!date) {
                alert('Please select a date');
                return;
            }

            const timeSlots = document.getElementById('timeSlots');
            timeSlots.innerHTML = '<div class="spinner-border text-primary" role="status"></div>';

            try {
                const response = await fetch('get_available_times.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        staff_id: selectedStaffId,
                        date: date,
                        services: selectedServices.map(s => s.id)
                    })
                });

                const data = await response.json();
                if (!data.success) {
                    timeSlots.innerHTML = `<div class="alert alert-danger">${data.message || 'Failed to load time slots'}</div>`;
                    return;
                }

                if (data.slots.length === 0) {
                    timeSlots.innerHTML = '<div class="alert alert-warning">No available slots for this date</div>';
                    return;
                }

                timeSlots.innerHTML = data.slots.map(slot => `
                    <button type="button" class="btn btn-outline-primary slot-btn" data-slot="${slot}">
                        ${formatTime(slot)}
                    </button>
                `).join('');

                // Show next step
                document.getElementById('step4').classList.add('active');
                nextBtn.style.display = 'none';
                bookBtn.style.display = 'block';

                // Add click handlers for slots
                document.querySelectorAll('.slot-btn').forEach(btn => {
                    btn.addEventListener('click', () => {
                        document.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('active'));
                        btn.classList.add('active');
                        selectedSlot = btn.dataset.slot;
                        bookBtn.disabled = false;
                    });
                });
            } catch (error) {
                console.error('Error:', error);
                timeSlots.innerHTML = '<div class="alert alert-danger">Failed to load time slots</div>';
            }
        });

        // Form submission
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const formData = {
                salon_id: <?= $salon_owner_id ?>,
                staff_id: selectedStaffId,
                date: form.querySelector('[name="date"]').value,
                start_time: selectedSlot,
                service_ids: selectedServices.map(s => s.id),
                total_price: selectedServices.reduce((sum, s) => sum + s.price, 0),
                total_minutes: selectedServices.reduce((sum, s) => sum + s.duration, 0),
                csrf_token: '<?= $csrf_token ?>'
            };

            try {
                const res = await fetch('make_booking.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(formData)
                });
                
                const data = await res.json();
                
                if (data.success) {
                    window.location.href = `receipt.php?id=${data.booking_id}`;
                } else {
                    alert(data.message || 'Booking failed');
                }
            } catch (err) {
                console.error(err);
                alert('Booking failed. Please try again.');
            }
        });

        function formatTime(time) {
            return new Date(`2000-01-01T${time}`).toLocaleTimeString([], {
                hour: '2-digit',
                minute: '2-digit'
            });
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
