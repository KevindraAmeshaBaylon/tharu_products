<?php
session_start();

require_once '../../model/config/database.php';

// STRICT AUTHENTICATION GUARD (Preserved exactly as requested)
// basically just making sure they are logged in and actually a sales supervisor
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'salessup') {
    header("Location: ../../auth/login.php");
    exit;
}


$conn = getDBConnection();

// setting up variables for our top boxes
$totalOrders = 0;
$pendingOrders = 0;
$processingOrders = 0;
$dispatchedOrders = 0;
$soldUnits = 0;
$monthlyIncome = 0;

// figure out how many orders are currently being made in the factory
// we have to join a few tables to find batches that are in production
$procQuery = "
    SELECT COUNT(DISTINCT o.orderID) 
    FROM order_tbl o 
    JOIN order_batch_tbl ob ON o.orderID = ob.orderID 
    JOIN productionbatch_tbl pb ON ob.batchID = pb.batchID 
    WHERE pb.inproduction = 1 AND o.cancelled = 0
";
if ($procResult = $conn->query($procQuery)) {
    $processingOrders = $procResult->fetch_row()[0];
}

// find out how many orders are sent out but not delivered yet
$dispQuery = "
    SELECT COUNT(DISTINCT o.orderID) 
    FROM order_tbl o 
    JOIN order_batch_tbl ob ON o.orderID = ob.orderID 
    JOIN productionbatch_tbl pb ON ob.batchID = pb.batchID 
    WHERE pb.dispatched = 1 AND o.delivered = 0 AND o.cancelled = 0
";
if ($dispResult = $conn->query($dispQuery)) {
    $dispatchedOrders = $dispResult->fetch_row()[0];
}

// total units we actually managed to sell and dispatch
$unitsQuery = "
    SELECT SUM(outputqty) 
    FROM productionbatch_tbl 
    WHERE dispatched = 1
";
if ($unitsResult = $conn->query($unitsQuery)) {
    $soldUnits = $unitsResult->fetch_row()[0] ?: 0;
}

// just a straight count of every order we have
$result = $conn->query("SELECT COUNT(*) FROM Order_tbl");
if ($result) {
    $totalOrders = $result->fetch_row()[0];
}

// how many orders are still waiting around (not delivered or cancelled)
$result = $conn->query("SELECT COUNT(*) FROM Order_tbl WHERE delivered = 0 AND cancelled = 0");
if ($result) {
    $pendingOrders = $result->fetch_row()[0];
}

// total cash we made this month
$result = $conn->query("
    SELECT SUM(amount) FROM Payment_tbl 
    WHERE DATE_FORMAT(date, '%Y-%m') = DATE_FORMAT(CURRENT_DATE, '%Y-%m')
");
if ($result && $row = $result->fetch_row()) {
    $monthlyIncome = $row[0] ?: 0;
}

// grab the last 5 orders to show in the little table at the bottom
$recentOrders = [];
$recentQuery = "SELECT o.orderID, o.date, o.totamt, c.companyname 
                FROM Order_tbl o 
                JOIN Customer_tbl c ON o.customerID = c.customerID 
                ORDER BY o.orderID DESC LIMIT 5";
$recentResult = $conn->query($recentQuery);
if ($recentResult && $recentResult->num_rows > 0) {
    while ($row = $recentResult->fetch_assoc()) {
        $recentOrders[] = $row;
    }
}

// --- START OF DYNAMIC CHART DATA ---
// getting the revenue numbers for the line chart
$chartMonths = [];
$chartSales = [];
$salesMap = [];

// 1. generate the names of the last 6 months (safely so it doesn't break on the 31st of the month)
for ($i = 5; $i >= 0; $i--) {
    $monthLabel = date('M', strtotime(date('Y-m-01') . " -$i months"));
    $chartMonths[] = $monthLabel;
    $salesMap[$monthLabel] = 0; // start at 0 so months with nothing don't crash the chart
}

// 2. actually pull the revenue grouped by month from the database
$trendQuery = "
    SELECT 
        DATE_FORMAT(date, '%b') AS month_label, 
        SUM(amount) AS total_revenue 
    FROM Payment_tbl 
    WHERE date >= DATE_FORMAT(DATE_SUB(CURRENT_DATE, INTERVAL 5 MONTH), '%Y-%m-01')
    GROUP BY YEAR(date), MONTH(date), month_label
";

$trendResult = $conn->query($trendQuery);
if ($trendResult && $trendResult->num_rows > 0) {
    while ($row = $trendResult->fetch_assoc()) {
        $label = $row['month_label'];
        if (isset($salesMap[$label])) {
            $salesMap[$label] = (float)$row['total_revenue'];
        }
    }
}

// 3. put the numbers into the array in the right order for the chart
foreach ($chartMonths as $month) {
    $chartSales[] = $salesMap[$month];
}
// --- END OF DYNAMIC CHART DATA ---

// we are done with the db for now, close it up
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Supervisor Dashboard - Tharu Systems</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        /* Specific card styles for the dashboard */
        .stat-card-dark {
            background-color: #122919;
            color: #ffffff;
            border-radius: 20px;
            padding: 1.5rem;
            border: none;
        }

        .stat-card-light {
            background: #ffffff;
            border-radius: 20px;
            padding: 1.5rem;
            border: 1px solid #eef2f0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.02);
        }

        .metric-value {
            font-size: 1.85rem;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .custom-table th {
            background-color: #f1f5f3;
            color: #475569;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0.8rem;
            border-bottom: none;
        }
        
        .custom-table td {
            padding: 0.8rem;
            vertical-align: middle;
            font-size: 0.85rem;
            border-bottom: 1px solid #eef2f0;
            color: #334155;
        }
    </style>
</head>
<body>

<div class="dashboard-wrapper">
    
    <!-- INCLUDE THE REUSABLE SIDEBAR -->
    <?php include 'sidebar.php'; ?>

    <!-- MAIN DASHBOARD CONTENT -->
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold text-dark mb-1">Sales Operations Overview</h3>
                <p class="text-muted small mb-0">Live metrics tracking for orders, units, and generated revenue.</p>
            </div>
        </div>

        <!-- Top Metrics Row -->
        <div class="row g-4 mb-4">
            <div class="col-12 col-md-4">
                <div class="stat-card-dark shadow-sm h-100">
                    <span class="text-white-50 small text-uppercase">Total Processed Orders</span>
                    <div class="metric-value mt-1"><?php echo htmlspecialchars($totalOrders); ?></div>
                    <span class="text-success small fw-bold"><i class="bi bi-caret-up-fill"></i> Live</span> <span class="text-white-50 small">database metric</span>
                </div>
            </div>
            
            <div class="col-12 col-md-4">
                <div class="stat-card-light shadow-sm h-100">
                    <span class="text-muted small text-uppercase">Pending Orders</span>
                    <div class="metric-value mt-1 text-dark"><?php echo htmlspecialchars($pendingOrders); ?></div>
                    <span class="text-warning small fw-bold"><i class="bi bi-caret-right-fill"></i> Active</span> <span class="text-muted small">awaiting processing</span>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="stat-card-light shadow-sm h-100">
                    <span class="text-muted small text-uppercase">Monthly Income</span>
                    <div class="metric-value mt-1 text-success">LKR <?php echo number_format($monthlyIncome, 2); ?></div>
                    <span class="text-success small fw-bold"><i class="bi bi-check-circle"></i> Net Flow</span>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="stat-card-light shadow-sm h-100">
                    <span class="text-muted small text-uppercase">Processing Orders</span>
                    <div class="metric-value mt-1 text-dark"><?php echo htmlspecialchars($processingOrders); ?></div>
                    <span class="text-primary small fw-bold"><i class="bi bi-arrow-repeat"></i> In Progress</span> <span class="text-muted small">manufacturing</span>               
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="stat-card-light shadow-sm h-100">
                    <span class="text-muted small text-uppercase">Dispatched Orders</span>
                    <div class="metric-value mt-1 text-dark"><?php echo htmlspecialchars($dispatchedOrders); ?></div>
                    <span class="text-info small fw-bold"><i class="bi bi-truck"></i> In Transit</span> <span class="text-muted small">out for delivery</span>                
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="stat-card-light shadow-sm h-100">
                    <span class="text-muted small text-uppercase">Sold Units (Dispatched)</span>
                    <div class="metric-value mt-1 text-dark"><?php echo htmlspecialchars($soldUnits); ?></div>
                    <span class="text-success small fw-bold"><i class="bi bi-box-seam"></i> Tracked</span> <span class="text-muted small">live volume</span>
                </div>
            </div>
        </div>

        <!-- Bottom Details Row -->
        <div class="row g-4">
            <!-- Chart Column -->
            <div class="col-12 col-lg-7">
                <div class="stat-card-light shadow-sm h-100">
                    <h6 class="fw-bold text-dark mb-3">6-Month Revenue Trend</h6>
                    <div style="height: 250px; width: 100%;">
                        <canvas id="salesChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Recent Orders Table Column -->
            <div class="col-12 col-lg-5">
                <div class="stat-card-light shadow-sm h-100">
                    <h6 class="fw-bold text-dark mb-3">Recent Orders</h6>
                    <div class="table-responsive">
                        <table class="table custom-table mb-0">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Customer</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recentOrders)): ?>
                                    <?php foreach ($recentOrders as $order): ?>
                                        <tr>
                                            <td class="font-monospace text-muted">#<?php echo $order['orderID']; ?></td>
                                            <td class="fw-bold"><?php echo htmlspecialchars($order['companyname']); ?></td>
                                            <td class="text-success fw-bold">LKR <?php echo number_format($order['totamt'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" class="text-center text-muted">No recent orders</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Initialize the Chart.js diagram
    const ctx = document.getElementById('salesChart').getContext('2d');
    
    // Pass PHP arrays to JavaScript
    const months = <?php echo json_encode($chartMonths); ?>;
    const salesData = <?php echo json_encode($chartSales); ?>;

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: months,
            datasets: [{
                label: 'Monthly Revenue (LKR)',
                data: salesData,
                borderColor: '#2e7d32',
                backgroundColor: 'rgba(46, 125, 50, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                x: { grid: { display: false } },
                y: { 
                    beginAtZero: true,
                    grid: { color: '#f1f5f3' }
                }
            }
        }
    });
</script>
</body>
</html>
