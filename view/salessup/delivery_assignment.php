<?php
session_start();

// Include database
require_once '../../model/config/database.php';

// STRICT AUTHENTICATION GUARD (Preserved exactly as requested)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'salessup') {
    header("Location: ../../auth/login.php");
    exit;
}

$conn = getDBConnection();

// --- VERY BASIC BACKEND LOGIC ---

// 1. Handle the Assignment Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_driver'])) {
    $orderID = $conn->real_escape_string($_POST['orderID']);
    $driverID = $conn->real_escape_string($_POST['driverID']);

    // Basic security check: Make sure a driver was actually selected
    if (!empty($driverID)) {
        // Update the order with the selected driver
        $sql = "UPDATE Order_tbl SET driverID='$driverID' WHERE orderID='$orderID'";
        $conn->query($sql);
        
        // Refresh page to show the updated lists
        header("Location: delivery_assignment.php");
        exit();
    }
}

// 2. Fetch Available Drivers
// A driver is available if they DO NOT have any active (undelivered & uncancelled) orders assigned to them.
$availableDrivers = [];
$driverSql = "SELECT driverID, drivername FROM Driver_tbl 
              WHERE driverID NOT IN (
                  SELECT driverID FROM Order_tbl 
                  WHERE driverID IS NOT NULL AND delivered = 0 AND cancelled = 0
              )";
$driverResult = $conn->query($driverSql);
if ($driverResult && $driverResult->num_rows > 0) {
    while ($row = $driverResult->fetch_assoc()) {
        $availableDrivers[] = $row;
    }
}

// 3. Fetch Dispatched Orders Needing a Driver
// Orders where batch is dispatched, but no driver is assigned yet
$unassignedOrders = [];
$unassignedSql = "SELECT DISTINCT o.orderID, o.date, c.companyname, c.address 
                  FROM Order_tbl o
                  JOIN Customer_tbl c ON o.customerID = c.customerID
                  JOIN Order_Batch_tbl ob ON o.orderID = ob.orderID
                  JOIN ProductionBatch_tbl pb ON ob.batchID = pb.batchID
                  WHERE pb.dispatched = 1 AND o.driverID IS NULL AND o.cancelled = 0 AND o.delivered = 0
                  ORDER BY o.orderID ASC";
$unassignedResult = $conn->query($unassignedSql);
if ($unassignedResult && $unassignedResult->num_rows > 0) {
    while ($row = $unassignedResult->fetch_assoc()) {
        $unassignedOrders[] = $row;
    }
}

// 4. Fetch Orders Currently In Transit (Already assigned to a driver)
$inTransitOrders = [];
$transitSql = "SELECT o.orderID, c.companyname, c.address, d.drivername 
               FROM Order_tbl o
               JOIN Customer_tbl c ON o.customerID = c.customerID
               JOIN Driver_tbl d ON o.driverID = d.driverID
               WHERE o.delivered = 0 AND o.cancelled = 0 AND o.driverID IS NOT NULL
               ORDER BY o.orderID DESC";
$transitResult = $conn->query($transitSql);
if ($transitResult && $transitResult->num_rows > 0) {
    while ($row = $transitResult->fetch_assoc()) {
        $inTransitOrders[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Assignment - Tharu Systems</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

    <style>
        /* Reused specific styles for the light dashboard theme */
        .content-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 1.5rem;
            border: 1px solid #eef2f0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.02);
            margin-bottom: 25px;
        }

        .custom-table th {
            background-color: #f1f5f3;
            color: #475569;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
            padding: 1rem;
            border-bottom: none;
        }
        
        .custom-table td {
            padding: 1rem;
            vertical-align: middle;
            font-size: 0.92rem;
            border-bottom: 1px solid #eef2f0;
            color: #334155;
        }

        .btn-forest {
            background: #2e7d32;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        .btn-forest:hover {
            background: #1b5e20;
            color: #ffffff;
        }

        /* Status Badges */
        .badge-transit { background-color: #0dcaf0; color: white; padding: 5px 10px; border-radius: 6px; font-size: 0.8rem; }
        .badge-driver-free { background-color: #198754; color: white; padding: 5px 10px; border-radius: 6px; font-size: 0.8rem; }

        /* Simple Custom Modal Overlay */
        .custom-modal-overlay {
            display: none; 
            position: fixed; 
            top: 0; left: 0; 
            width: 100%; height: 100%; 
            background: rgba(0, 0, 0, 0.5); 
            backdrop-filter: blur(3px);
            z-index: 2000;
            align-items: center; 
            justify-content: center;
        }
        .custom-modal-box {
            width: 100%;
            max-width: 450px;
            background: #ffffff;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>

<div class="dashboard-wrapper">
    
    <!-- INCLUDE THE REUSABLE SIDEBAR -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="main-content">
        <div class="mb-4">
            <h3 class="fw-bold text-dark mb-1">Delivery Assignment</h3>
            <span class="text-muted small">Allocate dispatched orders to available drivers in the logistics fleet</span>
        </div>

        <div class="row g-4">
            <!-- Left Column: Orders Tables -->
            <div class="col-12 col-lg-8">
                
                <!-- UNASSIGNED ORDERS -->
                <div class="content-card">
                    <h6 class="fw-bold text-dark mb-3">Orders Awaiting Driver Assignment</h6>
                    <div class="table-responsive">
                        <table class="table custom-table mb-0">
                            <thead>
                                <tr>
                                    <th>ORDER ID</th>
                                    <th>CUSTOMER</th>
                                    <th>DESTINATION</th>
                                    <th>ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($unassignedOrders)): ?>
                                    <?php foreach($unassignedOrders as $order): ?>
                                    <tr>
                                        <td><span class="font-monospace text-muted fw-bold">#ORD-<?php echo $order['orderID']; ?></span></td>
                                        <td class="fw-bold text-dark"><?php echo htmlspecialchars($order['companyname']); ?></td>
                                        <td class="text-muted"><?php echo htmlspecialchars($order['address']); ?></td>
                                        <td>
                                            <!-- Button to trigger the assignment modal -->
                                            <button class="btn btn-sm btn-forest px-3" onclick="openAssignForm('<?php echo $order['orderID']; ?>', '<?php echo htmlspecialchars(addslashes($order['companyname'])); ?>')" <?php echo empty($availableDrivers) ? 'disabled title="No drivers available"' : ''; ?>>
                                                <i class="bi bi-truck"></i> Assign Delivery
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="text-center py-4 text-muted">No dispatched orders currently require assignment.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ORDERS IN TRANSIT -->
                <div class="content-card mt-4">
                    <h6 class="fw-bold text-dark mb-3">Active Deliveries In Transit</h6>
                    <div class="table-responsive">
                        <table class="table custom-table mb-0">
                            <thead>
                                <tr>
                                    <th>ORDER ID</th>
                                    <th>CUSTOMER</th>
                                    <th>ASSIGNED DRIVER</th>
                                    <th>STATUS</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($inTransitOrders)): ?>
                                    <?php foreach($inTransitOrders as $transit): ?>
                                    <tr>
                                        <td><span class="font-monospace text-muted fw-bold">#ORD-<?php echo $transit['orderID']; ?></span></td>
                                        <td class="fw-bold text-dark"><?php echo htmlspecialchars($transit['companyname']); ?></td>
                                        <td class="text-muted"><i class="bi bi-truck-flatbed"></i> <?php echo htmlspecialchars($transit['drivername']); ?></td>
                                        <td><span class="badge-transit">In Transit</span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="text-center py-4 text-muted">No active deliveries at the moment.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Right Column: Available Drivers Overview -->
            <div class="col-12 col-lg-4">
                <div class="content-card h-100 mb-0">
                    <h6 class="fw-bold text-dark mb-3">Driver Availability Fleet</h6>
                    <p class="text-muted small mb-4">Drivers are automatically marked unavailable while they have active assigned deliveries in transit.</p>
                    
                    <ul class="list-group list-group-flush border-0">
                        <?php if (!empty($availableDrivers)): ?>
                            <?php foreach($availableDrivers as $driver): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-3 border-bottom">
                                <div>
                                    <span class="fw-bold text-dark d-block"><?php echo htmlspecialchars($driver['drivername']); ?></span>
                                    <span class="text-muted small font-monospace">ID: #DRV-<?php echo $driver['driverID']; ?></span>
                                </div>
                                <span class="badge-driver-free">Available</span>
                            </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li class="list-group-item px-0 py-4 text-center text-muted border-0">
                                <i class="bi bi-bus-front"></i> All fleet drivers are currently occupied with deliveries.
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Custom Modal for Driver Assignment -->
<div id="assignDriverModal" class="custom-modal-overlay">
    <div class="custom-modal-box">
        <h4 class="text-dark mb-2 fw-bold">Assign Driver</h4>
        <p class="text-muted small mb-4">Select an available driver for <span id="display_customer_name" class="fw-bold text-dark"></span>.</p>
        
        <!-- Basic HTML Form -->
        <form method="POST" action="delivery_assignment.php">
            <input type="hidden" id="modal_orderID" name="orderID">
            
            <div class="mb-4">
                <label class="form-label text-muted small fw-bold">Available Drivers</label>
                <select name="driverID" class="form-select bg-light" required>
                    <option value="">-- Choose a Driver --</option>
                    <?php foreach($availableDrivers as $driver): ?>
                        <option value="<?php echo $driver['driverID']; ?>">
                            <?php echo htmlspecialchars($driver['drivername']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light border px-4" onclick="closeAssignForm()">Cancel</button>
                <button type="submit" name="assign_driver" class="btn btn-forest px-4">Confirm Assignment</button>
            </div>
        </form>
    </div>
</div>

<!-- Very Simple Javascript to handle the form popup -->
<script>
    function openAssignForm(orderID, customerName) {
        document.getElementById('modal_orderID').value = orderID;
        document.getElementById('display_customer_name').innerText = customerName;
        
        document.getElementById('assignDriverModal').style.display = 'flex';
    }

    function closeAssignForm() {
        document.getElementById('assignDriverModal').style.display = 'none';
    }
</script>

</body>
</html>
