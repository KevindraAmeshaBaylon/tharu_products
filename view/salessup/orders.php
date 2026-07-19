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
// when someone clicks to update the status of an order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    
    // 1. Get safe data
    $orderID = $conn->real_escape_string($_POST['orderID']);
    $newStatus = $_POST['new_status'];

    // 2. Update logic based ONLY on Delivered or Cancelled
    // updating the db depending on what status they picked
    if ($newStatus == 'Delivered') {
        $conn->query("UPDATE Order_tbl SET delivered=1, cancelled=0 WHERE orderID='$orderID'");
    } elseif ($newStatus == 'Cancelled') {
        $conn->query("UPDATE Order_tbl SET cancelled=1, delivered=0 WHERE orderID='$orderID'");
    }
    
    // Refresh the page
    header("Location: orders.php");
    exit();
}

// 3. Simple SELECT query with JOINs to get Product, Quantity, and Bill Amount
// grabbing all the orders and tying them to their products and batches
$sql = "SELECT 
            o.orderID, 
            o.date, 
            o.totamt, 
            o.delivered, 
            o.cancelled,
            p.name AS product_name, 
            pb.outputqty AS quantity,
            pb.inproduction,
            pb.dispatched,
            pb.batchID
        FROM Order_tbl o
        LEFT JOIN Order_Batch_tbl ob ON o.orderID = ob.orderID
        LEFT JOIN ProductionBatch_tbl pb ON ob.batchID = pb.batchID
        LEFT JOIN ProductPerBatch_tbl ppb ON pb.batchID = ppb.batchID
        LEFT JOIN Product_tbl p ON ppb.ProductID = p.ProductID
        ORDER BY o.orderID DESC";

$result = $conn->query($sql);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Orders - Tharu Systems</title>
    
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

        /* Status Badge Colors */
        .badge-accepted { background-color: #6c757d; color: white; padding: 5px 10px; border-radius: 6px; font-size: 0.8rem; }
        .badge-processing { background-color: #0dcaf0; color: white; padding: 5px 10px; border-radius: 6px; font-size: 0.8rem; }
        .badge-dispatched { background-color: #fd7e14; color: white; padding: 5px 10px; border-radius: 6px; font-size: 0.8rem; }
        .badge-delivered { background-color: #198754; color: white; padding: 5px 10px; border-radius: 6px; font-size: 0.8rem; }
        .badge-cancelled { background-color: #dc3545; color: white; padding: 5px 10px; border-radius: 6px; font-size: 0.8rem; }

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
            max-width: 400px;
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
            <h3 class="fw-bold text-dark mb-1">Order Management</h3>
            <span class="text-muted small">View product orders and update operational status</span>
        </div>

        <!-- White Card Container -->
        <div class="content-card">
            <div class="table-responsive">
                <table class="table custom-table mb-0">
                    <thead>
                        <tr>
                            <th>ORDER ID</th>
                            <th>PRODUCT</th>
                            <th>QTY</th>
                            <th>BILL AMOUNT</th>
                            <th>STATUS</th>
                            <th>ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if ($result && $result->num_rows > 0) {
                            while($row = $result->fetch_assoc()) { 
                                
                                // Determine Status for UI
                                // figure out what status label to show based on the flags in the db
                                $currentStatus = "Accepted"; // Default
                                $badgeClass = "badge-accepted";

                                if ($row['cancelled'] == 1) {
                                    $currentStatus = "Cancelled";
                                    $badgeClass = "badge-cancelled";
                                } elseif ($row['delivered'] == 1) {
                                    $currentStatus = "Delivered";
                                    $badgeClass = "badge-delivered";
                                } elseif ($row['dispatched'] == 1) {
                                    $currentStatus = "Dispatched";
                                    $badgeClass = "badge-dispatched";
                                } elseif ($row['inproduction'] == 1) {
                                    $currentStatus = "Processing";
                                    $badgeClass = "badge-processing";
                                }

                                // Handle empty product names if batch isn't linked
                                $productName = $row['product_name'] ? $row['product_name'] : "N/A";
                                $quantity = $row['quantity'] ? $row['quantity'] : "0";
                        ?>
                        <tr>
                            <td><span class="font-monospace text-muted fw-bold">#ORD-<?php echo $row['orderID']; ?></span></td>
                            <td class="fw-bold text-dark"><?php echo htmlspecialchars($productName); ?></td>
                            <td><?php echo htmlspecialchars($quantity); ?> units</td>
                            <td class="text-success fw-bold">LKR <?php echo number_format($row['totamt'], 2); ?></td>
                            <td><span class="<?php echo $badgeClass; ?>"><?php echo $currentStatus; ?></span></td>
                            <td>
                                <!-- Simple Javascript trigger for the edit form -->
                                <?php if($currentStatus != "Delivered" && $currentStatus != "Cancelled"): ?>
                                    <button class="btn btn-sm btn-forest px-3" onclick="openStatusForm(
                                        '<?php echo $row['orderID']; ?>', 
                                        '<?php echo $currentStatus; ?>'
                                    )"><i class="bi bi-arrow-repeat"></i> Update Status</button>
                                <?php else: ?>
                                    <span class="text-muted small">Locked</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php 
                            }
                        } else {
                            echo "<tr><td colspan='6' class='text-center py-4 text-muted'>No orders found in database.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Simple Custom Modal for Updating Status -->
<div id="updateStatusModal" class="custom-modal-overlay">
    <div class="custom-modal-box">
        <h4 class="text-dark mb-4 fw-bold">Update Order Status</h4>
        
        <!-- Basic HTML Form -->
        <form method="POST" action="orders.php">
            <input type="hidden" id="form_orderID" name="orderID">
            
            <div class="mb-4">
                <label class="form-label text-muted small fw-bold">Select New Status</label>
                <select class="form-select bg-light" id="form_status" name="new_status" required>
                    <!-- Only Delivered and Cancelled remain -->
                    <option value="Delivered">Delivered</option>
                    <option value="Cancelled">Cancelled</option>
                </select>
            </div>
            
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light border px-4" onclick="closeStatusForm()">Cancel</button>
                <button type="submit" name="update_status" class="btn btn-forest px-4">Update</button>
            </div>
        </form>
    </div>
</div>

<!-- Very Simple Javascript to handle the form popup -->
<script>
    // opens the status update modal
    function openStatusForm(orderID, currentStatus) {
        document.getElementById('form_orderID').value = orderID;
        
        // If the current status isn't Delivered or Cancelled, it defaults to the first option, 
        // but you could add logic here if you want it to pre-select based on something else.
        // For now, it will just default to "Delivered".
        
        document.getElementById('updateStatusModal').style.display = 'flex';
    }

    function closeStatusForm() {
        document.getElementById('updateStatusModal').style.display = 'none';
    }
</script>

</body>
</html>