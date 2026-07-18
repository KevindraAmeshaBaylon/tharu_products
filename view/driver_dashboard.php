<?php
session_start();

require_once '../model/config/database.php';

// STRICT AUTHENTICATION GUARD
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'driver') {
    header("Location: ../auth/login.php");
    exit;
}

$conn = getDBConnection();
$userID = $_SESSION['user_id'];
$message = "";
$error = "";

// 1. Fetch the specific Driver Profile linked to this User Account
$driverID = 0;
$driverName = "Driver";
$driverDOB = "";
$driverStmt = $conn->prepare("SELECT driverID, drivername, driverDOB FROM Driver_tbl WHERE userID = ?");
$driverStmt->bind_param("i", $userID);
$driverStmt->execute();
$driverRes = $driverStmt->get_result();

if ($row = $driverRes->fetch_assoc()) {
    $driverID = $row['driverID'];
    $driverName = $row['drivername'];
    $driverDOB = $row['driverDOB'];
} else {
    $error = "Driver profile not found. Please contact the administrator.";
}

// 2. Handle Profile Details Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $newName = $conn->real_escape_string($_POST['drivername']);
    $newDOB = $conn->real_escape_string($_POST['driverDOB']);
    
    $updateProfQuery = "UPDATE Driver_tbl SET drivername = ?, driverDOB = ? WHERE driverID = ?";
    $updateProfStmt = $conn->prepare($updateProfQuery);
    $updateProfStmt->bind_param("ssi", $newName, $newDOB, $driverID);
    
    if ($updateProfStmt->execute()) {
        $message = "Your profile details have been updated successfully.";
        $driverName = $newName; // Update live variable for immediate UI change
        $driverDOB = $newDOB;
    } else {
        $error = "Failed to update profile details. Please try again.";
    }
}

// 3. Handle 'Mark as Delivered' Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_delivered'])) {
    $orderID = intval($_POST['order_id']);
    
    $updateQuery = "UPDATE Order_tbl SET delivered = 1, pending = 0, processed = 0 WHERE orderID = ? AND driverID = ?";
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->bind_param("ii", $orderID, $driverID);
    
    if ($updateStmt->execute()) {
        $message = "Order #ORD-$orderID has been successfully marked as Delivered.";
    } else {
        $error = "Failed to update the delivery status. Please try again.";
    }
}

// 4. Fetch Active Deliveries Assigned to THIS Driver
$activeDeliveries = [];
$activeQuery = "SELECT o.orderID, o.date, c.companyname, c.address, c.customerNIC 
                FROM Order_tbl o 
                JOIN Customer_tbl c ON o.customerID = c.customerID 
                WHERE o.driverID = ? AND o.delivered = 0 AND o.cancelled = 0
                ORDER BY o.date ASC";
$activeStmt = $conn->prepare($activeQuery);
$activeStmt->bind_param("i", $driverID);
$activeStmt->execute();
$activeRes = $activeStmt->get_result();

if ($activeRes && $activeRes->num_rows > 0) {
    while ($row = $activeRes->fetch_assoc()) {
        $activeDeliveries[] = $row;
    }
}

// 5. Fetch Recently Completed Deliveries (History)
$completedDeliveries = [];
$compQuery = "SELECT o.orderID, o.date, c.companyname 
              FROM Order_tbl o 
              JOIN Customer_tbl c ON o.customerID = c.customerID 
              WHERE o.driverID = ? AND o.delivered = 1 
              ORDER BY o.orderID DESC LIMIT 5";
$compStmt = $conn->prepare($compQuery);
$compStmt->bind_param("i", $driverID);
$compStmt->execute();
$compRes = $compStmt->get_result();

if ($compRes && $compRes->num_rows > 0) {
    while ($row = $compRes->fetch_assoc()) {
        $completedDeliveries[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Dashboard - Tharu Systems</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        /* Base Canvas Background */
        body {
            background-color: #f8faf9;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }
        
        /* Exact green pulled from dashboard.php's stat-card-dark */
        .bg-theme-dark {
            background-color: #122919 !important; 
        }

        /* Specific card styles replicated from dashboard.php */
        .stat-card-light {
            background: #ffffff;
            border-radius: 20px;
            padding: 1.5rem;
            border: 1px solid #eef2f0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.02);
            transition: transform 0.2s ease, border-color 0.2s ease;
        }

        /* Subtle interactive lift for touch targets */
        .route-interactive:hover {
            transform: translateY(-3px);
            border-color: #c8e6c9;
        }

        .metric-value {
            font-size: 1.85rem;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .btn-forest {
            background-color: #2e7d32;
            color: white;
            border: none;
            border-radius: 12px;
            transition: all 0.2s ease;
        }
        
        .btn-forest:hover {
            background-color: #1b5e20;
            color: white;
        }

        .badge-pending { background-color: #ffecb3; color: #ff8f00; padding: 5px 10px; border-radius: 6px; font-size: 0.8rem; }
        .badge-delivered { background-color: #c8e6c9; color: #2e7d32; padding: 5px 10px; border-radius: 6px; font-size: 0.8rem; }
    </style>
</head>
<body>

<!-- Top Navigation Module (Using #122919 background) -->
<nav class="navbar navbar-expand-lg navbar-dark bg-theme-dark shadow-sm sticky-top py-3">
    <div class="container px-3">
        <a class="navbar-brand fw-bold d-flex align-items-center gap-2 font-monospace text-white" href="#">
            🚚 LOGISTICS PORTAL
        </a>
        <div class="ms-auto d-flex align-items-center gap-3">
            <span class="text-light small d-none d-md-inline">Driver: <strong><?= htmlspecialchars($driverName) ?></strong></span>
            <a class="btn btn-sm btn-outline-light rounded-pill px-3" href="../auth/logout.php">Log Out</a>
        </div>
    </div>
</nav>

<div class="container py-4">
    
    <!-- Status Alerts -->
    <?php if(!empty($message)): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm" style="border-radius: 12px;" role="alert">
            <strong>Success!</strong> <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if(!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm" style="border-radius: 12px;" role="alert">
            <?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        
        <!-- SIDE COLUMN: Profile & History -->
        <div class="col-12 col-lg-4 order-2 order-lg-1">
            <!-- Driver Profile Card (New Feature) -->
            <div class="stat-card-light mb-4 text-center">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <span class="badge bg-theme-dark rounded-pill px-3 py-2 fw-bold">ID: #DRV-<?= htmlspecialchars($driverID) ?></span>
                    <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#editProfileModal">✏️ Edit</button>
                </div>
                <div class="display-3 mb-2">👤</div>
                <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($driverName) ?></h5>
                <p class="text-muted small mb-0">DOB: <?= htmlspecialchars($driverDOB) ?></p>
            </div>

            <!-- Recent History Card -->
            <div class="stat-card-light p-0 overflow-hidden">
                <div class="p-4 border-bottom" style="background-color: #f1f5f3;">
                    <h6 class="fw-bold text-dark mb-0">Recent Deliveries Log</h6>
                </div>
                <ul class="list-group list-group-flush border-0">
                    <?php if (!empty($completedDeliveries)): ?>
                        <?php foreach ($completedDeliveries as $comp): ?>
                            <li class="list-group-item p-4 d-flex justify-content-between align-items-center border-bottom border-light">
                                <div>
                                    <h6 class="fw-bold text-dark mb-1"><?= htmlspecialchars($comp['companyname']) ?></h6>
                                    <span class="font-monospace text-muted fw-bold small">#ORD-<?= $comp['orderID'] ?></span>
                                </div>
                                <div class="text-end">
                                    <span class="badge-delivered d-block mb-1">Delivered</span>
                                    <span class="text-muted fw-bold" style="font-size: 0.75rem;"><?= htmlspecialchars($comp['date']) ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="list-group-item p-4 text-center text-muted small border-0">
                            No completed deliveries found in recent history.
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <!-- MAIN COLUMN: Active Routes -->
        <div class="col-12 col-lg-8 order-1 order-lg-2">
            <div class="d-flex justify-content-between align-items-end mb-4">
                <div>
                    <h3 class="fw-bold text-dark mb-1">My Active Routes</h3>
                    <p class="text-muted small mb-0">Orders currently assigned to your vehicle.</p>
                </div>
                <span class="badge bg-dark rounded-pill fs-6 px-3 py-2"><?= count($activeDeliveries) ?> Pending</span>
            </div>

            <?php if (!empty($activeDeliveries)): ?>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($activeDeliveries as $route): ?>
                        <div class="stat-card-light route-interactive p-4">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <span class="badge-pending fw-bold mb-2 d-inline-block">In Transit</span>
                                    <h4 class="fw-bold text-dark mb-0"><?= htmlspecialchars($route['companyname']) ?></h4>
                                    <span class="font-monospace text-muted fw-bold small">#ORD-<?= $route['orderID'] ?></span>
                                </div>
                                <span class="text-secondary small fw-bold"><?= htmlspecialchars($route['date']) ?></span>
                            </div>
                            
                            <div class="p-3 rounded-4 mb-4" style="background-color: #f1f5f3;">
                                <div class="small text-muted fw-bold text-uppercase mb-1" style="letter-spacing: 0.5px; font-size: 0.75rem;">Delivery Destination</div>
                                <div class="text-dark fw-medium fs-5">📍 <?= htmlspecialchars($route['address']) ?></div>
                            </div>
                            
                            <form method="POST" action="" onsubmit="return confirm('Confirm that this order has been successfully delivered to the customer?');">
                                <input type="hidden" name="order_id" value="<?= $route['orderID'] ?>">
                                <button type="submit" name="mark_delivered" class="btn btn-forest w-100 py-3 fw-bold shadow-sm fs-6">
                                    ✓ Confirm Delivery
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="stat-card-light p-5 text-center mt-2">
                    <h1 class="display-1 text-muted mb-4 opacity-50">🚐</h1>
                    <h4 class="fw-bold text-dark">No Active Deliveries</h4>
                    <p class="text-muted mb-0">You currently have no orders assigned to your route. Awaiting dispatch from the Sales Supervisor.</p>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- Modal: Update Driver Details -->
<div class="modal fade" id="editProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
            <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                <h5 class="modal-title fw-bold text-dark">Update My Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form method="POST" action="">
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold text-uppercase" style="letter-spacing: 0.5px;">Full Name</label>
                        <input type="text" class="form-control form-control-lg bg-light border-0" name="drivername" value="<?= htmlspecialchars($driverName) ?>" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label text-muted small fw-bold text-uppercase" style="letter-spacing: 0.5px;">Date of Birth</label>
                        <input type="date" class="form-control form-control-lg bg-light border-0" name="driverDOB" value="<?= htmlspecialchars($driverDOB) ?>" required>
                    </div>
                    <div class="d-grid gap-2 mt-4">
                        <button type="submit" name="update_profile" class="btn btn-forest py-3 fw-bold fs-6">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>