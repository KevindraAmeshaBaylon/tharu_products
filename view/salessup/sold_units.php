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
// Simple SELECT query to get ONLY dispatched items as "Sold Units"
$sql = "SELECT 
            o.orderID,
            o.date AS order_date,
            p.name AS product_name,
            pb.outputqty AS sold_quantity,
            c.companyname AS customer_name
        FROM ProductionBatch_tbl pb
        JOIN Order_Batch_tbl ob ON pb.batchID = ob.batchID
        JOIN Order_tbl o ON ob.orderID = o.orderID
        JOIN ProductPerBatch_tbl ppb ON pb.batchID = ppb.batchID
        JOIN Product_tbl p ON ppb.ProductID = p.ProductID
        JOIN Customer_tbl c ON o.customerID = c.customerID
        WHERE pb.dispatched = 1 AND o.cancelled = 0
        ORDER BY o.orderID DESC";

$result = $conn->query($sql);

// Arrays to store chart data dynamically
$chartLabels = [];
$chartData = [];
$tableRows = [];
$totalSold = 0;

if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $tableRows[] = $row;
        $totalSold += $row['sold_quantity'];
        
        // Structure data for product summary chart distribution
        $pName = $row['product_name'];
        if (isset($chartData[$pName])) {
            $chartData[$pName] += $row['sold_quantity'];
        } else {
            $chartData[$pName] = $row['sold_quantity'];
        }
    }
}

// Convert PHP structure to clean array keys for the JavaScript chart
$chartLabels = array_keys($chartData);
$chartValues = array_values($chartData);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sold Units - Tharu Systems</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- Chart.js Engine CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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

        .badge-dispatched { 
            background-color: #fd7e14; 
            color: white; 
            padding: 5px 10px; 
            border-radius: 6px; 
            font-size: 0.8rem; 
        }

        .info-title {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            font-weight: 700;
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
            <h3 class="fw-bold text-dark mb-1">Sold Units Log</h3>
            <span class="text-muted small">View historical data of all successfully dispatched product units</span>
        </div>

        <!-- Top Overview Row with Diagram and Summary Info -->
        <div class="row g-4 mb-4">
            <!-- Pie Chart Diagram -->
            <div class="col-12 col-lg-7">
                <div class="content-card h-100 mb-0">
                    <h6 class="fw-bold text-dark mb-3">Product Sales Distribution Diagram</h6>
                    <div style="height: 250px; width: 100%; position: relative;">
                        <canvas id="productPieChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Relevant Highlights and Overview Info Info Box -->
            <div class="col-12 col-lg-5">
                <div class="content-card h-100 mb-0 d-flex flex-column justify-content-between">
                    <div>
                        <h6 class="fw-bold text-dark mb-3">Log Metrics Summary</h6>
                        <p class="text-muted small">Quick operational benchmarks matching active dispatched order states.</p>
                        
                        <div class="mt-4">
                            <div class="info-title">Total Dispatched Volumes</div>
                            <h2 class="fw-bold text-success font-monospace"><?php echo $totalSold; ?> <span class="fs-5 text-muted">Bags</span></h2>
                        </div>
                    </div>

                    <div class="p-3 rounded-3" style="background-color: var(--mint-light); border: 1px solid #e8f5e9;">
                        <span class="small text-success fw-bold d-block"><i class="bi bi-check2-circle"></i> Dispatch Verification Active</span>
                        <span class="small text-muted">Data points reflect warehouse output cycles verified by the logistics deck.</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Data Table Card -->
        <div class="content-card">
            <h6 class="fw-bold text-dark mb-3">Verified Sold Batches</h6>
            <div class="table-responsive">
                <table class="table custom-table mb-0">
                    <thead>
                        <tr>
                            <th>ORDER ID</th>
                            <th>DISPATCH DATE</th>
                            <th>CUSTOMER</th>
                            <th>PRODUCT ITEM</th>
                            <th>UNITS SOLD</th>
                            <th>STATUS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if (!empty($tableRows)) {
                            foreach($tableRows as $row) { 
                        ?>
                        <tr>
                            <td><span class="font-monospace text-muted fw-bold">#ORD-<?php echo $row['orderID']; ?></span></td>
                            <td><?php echo htmlspecialchars($row['order_date']); ?></td>
                            <td class="fw-bold text-dark"><?php echo htmlspecialchars($row['customer_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                            <td class="text-success fw-bold"><?php echo htmlspecialchars($row['sold_quantity']); ?> units</td>
                            <td><span class="badge-dispatched">Dispatched</span></td>
                        </tr>
                        <?php 
                            }
                        } else {
                            echo "<tr><td colspan='6' class='text-center py-4 text-muted'>No dispatched orders found in the database.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    // Grab processed variables from PHP logic engine safely
    const labelsArray = <?php echo json_encode($chartLabels); ?>;
    const valuesArray = <?php echo json_encode($chartValues); ?>;

    const ctx = document.getElementById('productPieChart').getContext('2d');
    
    new Chart(ctx, {
        type: 'pie',
        data: {
            labels: labelsArray,
            datasets: [{
                data: valuesArray,
                backgroundColor: [
                    '#2e7d32', // Forest main
                    '#1565c0', // Deep Blue
                    '#ad1457', // Muted Deep Pink
                    '#ef6c00', // Dark Orange
                    '#4527a0'  // Deep Purple
                ],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        boxWidth: 12,
                        font: { size: 11, weight: 500 }
                    }
                }
            }
        }
    });
</script>

</body>
</html>
