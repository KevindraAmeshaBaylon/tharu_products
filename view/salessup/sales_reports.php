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

// 1. Get the selected month from the URL, or default to the current month
// check if they picked a month, otherwise just use this month
$selectedMonth = isset($_GET['report_month']) ? $_GET['report_month'] : date('Y-m');

// Make it safe
$safeMonth = $conn->real_escape_string($selectedMonth);

// For displaying nicely on the UI (e.g., "July 2026")
$displayMonthName = date('F Y', strtotime($safeMonth . '-01'));

// Initialize metrics
$totalOrders = 0;
$totalSalesAmount = 0;
$totalIncome = 0;
$soldUnits = 0;

// 2. Fetch Number of Orders and Total Sales Amount (Invoiced) for the month
// get the count and sum of all orders for the month we are looking at
$orderSql = "SELECT COUNT(orderID) AS order_count, SUM(totamt) AS total_sales 
             FROM Order_tbl 
             WHERE DATE_FORMAT(date, '%Y-%m') = '$safeMonth' AND cancelled = 0";
$orderResult = $conn->query($orderSql);
if ($orderResult && $row = $orderResult->fetch_assoc()) {
    $totalOrders = $row['order_count'] ?: 0;
    $totalSalesAmount = $row['total_sales'] ?: 0;
}

// 3. Fetch Total Income (Actual payments received) for the month
// get the actual cash that came in this month
$incomeSql = "SELECT SUM(amount) AS total_income 
              FROM Payment_tbl 
              WHERE DATE_FORMAT(date, '%Y-%m') = '$safeMonth'";
$incomeResult = $conn->query($incomeSql);
if ($incomeResult && $row = $incomeResult->fetch_assoc()) {
    $totalIncome = $row['total_income'] ?: 0;
}

// 4. Fetch Sold Units (Dispatched) for the month
// count how many actual units went out the door
$unitsSql = "SELECT SUM(pb.outputqty) AS sold_units 
             FROM ProductionBatch_tbl pb
             JOIN Order_Batch_tbl ob ON pb.batchID = ob.batchID
             JOIN Order_tbl o ON ob.orderID = o.orderID
             WHERE pb.dispatched = 1 AND o.cancelled = 0 AND DATE_FORMAT(o.date, '%Y-%m') = '$safeMonth'";
$unitsResult = $conn->query($unitsSql);
if ($unitsResult && $row = $unitsResult->fetch_assoc()) {
    $soldUnits = $row['sold_units'] ?: 0;
}

// 5. Fetch the detailed list of orders for the table
// get the list of orders to put in the big table at the bottom
$tableRows = [];
$detailSql = "SELECT o.orderID, o.date, o.totamt, c.companyname 
              FROM Order_tbl o
              JOIN Customer_tbl c ON o.customerID = c.customerID
              WHERE DATE_FORMAT(o.date, '%Y-%m') = '$safeMonth' AND o.cancelled = 0
              ORDER BY o.date DESC";
$detailResult = $conn->query($detailSql);
if ($detailResult && $detailResult->num_rows > 0) {
    while ($row = $detailResult->fetch_assoc()) {
        $tableRows[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Reports - Tharu Systems</title>
    
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

        .stat-card-light {
            background: #ffffff;
            border-radius: 20px;
            padding: 1.5rem;
            border: 1px solid #eef2f0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.02);
            height: 100%;
        }

        .metric-value {
            font-size: 1.85rem;
            font-weight: 700;
            letter-spacing: -0.5px;
            color: #1e293b;
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

        /* Basic CSS to make printing look good (Hides sidebar and buttons when printing) */
        @media print {
            .sidebar-panel, .no-print {
                display: none !important;
            }
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 0 !important;
            }
            body {
                background-color: white;
            }
            .content-card, .stat-card-light {
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }
    </style>
</head>
<body>

<div class="dashboard-wrapper">
    
    <!-- INCLUDE THE REUSABLE SIDEBAR -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="main-content">
        
        <!-- Header with Print Button -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold text-dark mb-1">Monthly Sales Report</h3>
                <span class="text-muted small no-print">Generate and print operational sales statistics</span>
            </div>
            <button onclick="window.print()" class="btn btn-forest px-4 no-print"><i class="bi bi-printer"></i> Print Report</button>
        </div>

        <!-- Very Simple Form to Select Month -->
        <div class="content-card no-print">
            <form method="GET" action="sales_reports.php" class="d-flex align-items-end gap-3">
                <div>
                    <label class="form-label text-muted small fw-bold">Select Report Month</label>
                    <input type="month" name="report_month" class="form-control bg-light" value="<?php echo htmlspecialchars($selectedMonth); ?>" required>
                </div>
                <button type="submit" class="btn btn-dark px-4">Generate</button>
            </form>
        </div>

        <!-- Report Title -->
        <h4 class="fw-bold text-dark mb-3 mt-4 text-center">Sales Performance for <?php echo $displayMonthName; ?></h4>

        <!-- Top Metrics Row -->
        <div class="row g-4 mb-4">
            <div class="col-12 col-md-3">
                <div class="stat-card-light">
                    <span class="text-muted small text-uppercase fw-bold">Total Orders</span>
                    <div class="metric-value mt-2"><?php echo htmlspecialchars($totalOrders); ?></div>
                </div>
            </div>
            
            <div class="col-12 col-md-3">
                <div class="stat-card-light">
                    <span class="text-muted small text-uppercase fw-bold">Units Sold</span>
                    <div class="metric-value mt-2"><?php echo htmlspecialchars($soldUnits); ?></div>
                </div>
            </div>

            <div class="col-12 col-md-3">
                <div class="stat-card-light">
                    <span class="text-muted small text-uppercase fw-bold">Total Sales (Invoiced)</span>
                    <div class="metric-value mt-2 text-primary">LKR <?php echo number_format($totalSalesAmount, 2); ?></div>
                </div>
            </div>

            <div class="col-12 col-md-3">
                <div class="stat-card-light">
                    <span class="text-muted small text-uppercase fw-bold">Total Income (Received)</span>
                    <div class="metric-value mt-2 text-success">LKR <?php echo number_format($totalIncome, 2); ?></div>
                </div>
            </div>
        </div>

        <!-- Detailed Table -->
        <div class="content-card">
            <h6 class="fw-bold text-dark mb-3">Order Breakdown (<?php echo $displayMonthName; ?>)</h6>
            <div class="table-responsive">
                <table class="table custom-table mb-0">
                    <thead>
                        <tr>
                            <th>ORDER DATE</th>
                            <th>ORDER ID</th>
                            <th>CUSTOMER</th>
                            <th>INVOICED AMOUNT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if (!empty($tableRows)) {
                            foreach($tableRows as $row) { 
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['date']); ?></td>
                            <td><span class="font-monospace text-muted fw-bold">#ORD-<?php echo $row['orderID']; ?></span></td>
                            <td class="fw-bold text-dark"><?php echo htmlspecialchars($row['companyname']); ?></td>
                            <td class="text-success fw-bold">LKR <?php echo number_format($row['totamt'], 2); ?></td>
                        </tr>
                        <?php 
                            }
                        } else {
                            echo "<tr><td colspan='4' class='text-center py-4 text-muted'>No verified orders recorded for this month.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
