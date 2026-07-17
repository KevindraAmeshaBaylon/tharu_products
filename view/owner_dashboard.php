<?php
// owner/dashboard.php
session_start();

// 1. Basic Session Guard
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'owner') {
    header("Location: ../auth/login.php");
    exit;
}

// 2. Database Connection Wrapper
// Adjust your path to wherever your database config sits (e.g., config.php or db.php)
// Make sure your config file defines a working $conn PDO or mysqli instance.
require_once __DIR__ . '/../model/config/database.php'; 

$conn=getDBConnection(); // Assuming getDBConnection() returns a PDO or mysqli connection

// 🔌 Dynamic Global Header File Included
include_once __DIR__ . '/../includes/header.php'; 


// ==========================================
// 3. DATABASE CONTROLLER LOGIC FOR ACTIONS
// ==========================================

// Handle Salary Provision Authorization Form Submission
$active_tab = $_GET['tab'] ?? ($_POST['tab'] ?? 'panel-analytics');
$salary_success_msg = "";
$salary_error_msg = "";
$report_success_msg = "";
$report_error_msg = "";
$selected_report_month = trim((string)($_POST['report_month'] ?? date('Y-m')));
$selected_report_month = preg_match('/^\d{4}-\d{2}$/', $selected_report_month) ? $selected_report_month : date('Y-m');
$report_sales_total = 0.0;
$report_expenses_total = 0.0;
$report_net_profit = 0.0;
$report_sales_rows = [];
$report_expense_rows = [];
$report_generated = false;
$report_chart_labels = ['Sales', 'Expenses', 'Net Profit'];
$report_chart_values = [0, 0, 0];
$download_format = strtolower(trim((string)($_POST['download_format'] ?? 'pdf')));
if (!in_array($download_format, ['pdf', 'xlsx', 'png', 'jpeg'], true)) {
    $download_format = 'pdf';
}
$auto_download_format = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'authorize_salary') {
    $emp_id = intval($_POST['employee_id'] ?? 0);
    $base_pay = floatval($_POST['base_pay'] ?? 0);
    $ot_pay = floatval($_POST['ot_pay'] ?? 0);
    $bonus_pay = floatval($_POST['bonus_pay'] ?? 0);
    $total_payout = $base_pay + $ot_pay + $bonus_pay;
    $attendance_id = intval($_POST['attendance_id'] ?? 0);
    $accountant_id = null;

    $accountantLookup = $conn->prepare('SELECT accountantID FROM accountant_tbl WHERE userID = ? LIMIT 1');
    if ($accountantLookup) {
        $accountantLookup->bind_param('i', $emp_id);
        $accountantLookup->execute();
        $accountantRow = $accountantLookup->get_result()->fetch_assoc();
        if ($accountantRow) {
            $accountant_id = (int)($accountantRow['accountantID'] ?? 0);
        }
    }

    if ($accountant_id > 0 && $attendance_id > 0) {
        $stmt = $conn->prepare('INSERT INTO salary_tbl (paydate, totamtpaid, attendanceID, accountantID) VALUES (CURDATE(), ?, ?, ?)');
        if ($stmt) {
            $stmt->bind_param('dii', $total_payout, $attendance_id, $accountant_id);
            if ($stmt->execute()) {
                $salary_success_msg = 'Payroll release successfully authorized and saved to salary_tbl.';
                $active_tab = 'panel-salary';
            } else {
                $salary_error_msg = 'Ledger write execution failed: ' . $stmt->error;
                $active_tab = 'panel-salary';
            }
            $stmt->close();
        } else {
            $salary_error_msg = 'Ledger statement preparation failed: ' . $conn->error;
        }
    } else {
        $salary_error_msg = 'Please choose a valid accountant and attendance record.';
        $active_tab = 'panel-salary';
    }
}

// Handle Staff Removal Request (CRUD)
if (isset($_GET['delete_staff'])) {
    $del_id = intval($_GET['delete_staff']);
    $deleteUser = $conn->prepare('DELETE FROM user_tbl WHERE userID = ? AND username != ?');
    if ($deleteUser) {
        $ownerUsername = 'r.tharu';
        $deleteUser->bind_param('is', $del_id, $ownerUsername);
        if ($deleteUser->execute()) {
            header('Location: owner_dashboard.php?tab=panel-employees#panel-employees');
            exit;
        } else {
            $salary_error_msg = 'Could not remove staff member: ' . $deleteUser->error;
            $active_tab = 'panel-employees';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_staff') {
    $user_id = intval($_POST['user_id'] ?? 0);
    $new_role = strtolower(trim((string)($_POST['role'] ?? '')));
    $allowed_roles = ['owner', 'stocksup', 'accountant', 'salessup', 'worker', 'driver', 'cust'];
    if ($user_id > 0 && in_array($new_role, $allowed_roles, true)) {
        $stmt = $conn->prepare('UPDATE user_tbl SET role = ? WHERE userID = ?');
        if ($stmt) {
            $stmt->bind_param('si', $new_role, $user_id);
            $stmt->execute();
            $salary_success_msg = 'Employee role updated successfully.';
            $active_tab = 'panel-employees';
        } else {
            $salary_error_msg = 'Unable to update employee role.';
            $active_tab = 'panel-employees';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_profit_report') {
    $selected_report_month = trim((string)($_POST['report_month'] ?? date('Y-m')));
    if (!preg_match('/^\d{4}-\d{2}$/', $selected_report_month)) {
        $selected_report_month = date('Y-m');
    }

    $ownerLookup = $conn->prepare('SELECT ownerID FROM owner_tbl WHERE userID = ? LIMIT 1');
    $owner_id = 0;
    if ($ownerLookup) {
        $ownerLookup->bind_param('i', $_SESSION['user_id']);
        $ownerLookup->execute();
        $ownerRow = $ownerLookup->get_result()->fetch_assoc();
        if ($ownerRow) {
            $owner_id = (int)($ownerRow['ownerID'] ?? 0);
        }
    }

    $report_sales_rows = [];
    $salesStmt = $conn->prepare("SELECT orderID, date, totamt, customerID FROM order_tbl WHERE DATE_FORMAT(date, '%Y-%m') = ? AND cancelled = 0 ORDER BY date DESC, orderID DESC");
    if ($salesStmt) {
        $salesStmt->bind_param('s', $selected_report_month);
        $salesStmt->execute();
        $salesResult = $salesStmt->get_result();
        while ($row = $salesResult->fetch_assoc()) {
            $report_sales_rows[] = $row;
        }
        $salesStmt->close();
    }

    $report_expense_rows = [];
    $expensesStmt = $conn->prepare("SELECT e.expenseID, e.type, e.amount, e.accountantID FROM expense_tbl e LEFT JOIN Exp_Report_tbl er ON er.expenseID = e.expenseID LEFT JOIN ExpenseReport_tbl ep ON ep.expenserepID = er.expenserepID WHERE ep.month = ? ORDER BY e.expenseID DESC");
    if ($expensesStmt) {
        $expensesStmt->bind_param('s', $selected_report_month);
        $expensesStmt->execute();
        $expenseResult = $expensesStmt->get_result();
        while ($row = $expenseResult->fetch_assoc()) {
            $report_expense_rows[] = $row;
        }
        $expensesStmt->close();
    }

    $report_sales_total = 0.0;
    foreach ($report_sales_rows as $row) {
        $report_sales_total += (float)($row['totamt'] ?? 0);
    }
    $report_expenses_total = 0.0;
    foreach ($report_expense_rows as $row) {
        $report_expenses_total += (float)($row['amount'] ?? 0);
    }
    $report_net_profit = $report_sales_total - $report_expenses_total;
    $report_chart_labels = ['Sales', 'Expenses', 'Net Profit'];
    $report_chart_values = [$report_sales_total, $report_expenses_total, $report_net_profit];

    if ($report_sales_total <= 0 && $report_expenses_total <= 0) {
        $report_error_msg = 'No sales were recorded for the selected month, so no report was generated.';
        $report_generated = false;
        $active_tab = 'panel-reports';
    } else {
        $insertStmt = $conn->prepare('INSERT INTO ProfitReport_tbl (month, ownerID) VALUES (?, ?)');
        if ($insertStmt && $owner_id > 0) {
            $insertStmt->bind_param('si', $selected_report_month, $owner_id);
            if ($insertStmt->execute()) {
                $report_success_msg = 'Monthly profit report prepared for download for ' . htmlspecialchars($selected_report_month) . '.';
                $report_generated = true;
                $auto_download_format = $download_format;
                $active_tab = 'panel-reports';
            } else {
                $report_error_msg = 'Could not save the profit report: ' . $insertStmt->error;
                $report_generated = false;
                $active_tab = 'panel-reports';
            }
            $insertStmt->close();
        } else {
            $report_error_msg = 'Unable to generate the monthly report using the selected month.';
            $report_generated = false;
            $active_tab = 'panel-reports';
        }
    }
}

// ==========================================
// 4. DATA ENGINE: FETCHING REAL DATABASE METRICS
// ==========================================

// 1. Initialize default values
$total_sales = 0;
$total_expenses = 0;

// 2. Sales totals from Order_tbl (the live order table)
$sales_query = $conn->query("SELECT COALESCE(SUM(totamt), 0) as total FROM order_tbl WHERE MONTH(date) = MONTH(CURRENT_DATE()) AND YEAR(date) = YEAR(CURRENT_DATE())");
if ($sales_query) {
    $sales_data = $sales_query->fetch_assoc();
    $total_sales = floatval($sales_data['total'] ?? 0);
}

// 3. Expense totals from Expense_tbl (the live expense table)
$expense_query = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM expense_tbl");
if ($expense_query) {
    $expense_data = $expense_query->fetch_assoc();
    $total_expenses = floatval($expense_data['total'] ?? 0);
}

$net_profit = $total_sales - $total_expenses;
$profit_margin = ($total_sales > 0) ? round(($net_profit / $total_sales) * 100) : 0;

$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$monthly_sales_rows = [];
$monthly_sales_query = $conn->query("SELECT orderID, date, totamt, customerID FROM order_tbl WHERE date BETWEEN '$monthStart' AND '$monthEnd' ORDER BY date DESC, orderID DESC");
if ($monthly_sales_query) {
    while ($row = $monthly_sales_query->fetch_assoc()) {
        $monthly_sales_rows[] = $row;
    }
}

$monthly_expense_rows = [];
$monthly_expense_query = $conn->query("SELECT expenseID, type, amount, accountantID FROM expense_tbl ORDER BY expenseID DESC");
if ($monthly_expense_query) {
    while ($row = $monthly_expense_query->fetch_assoc()) {
        $monthly_expense_rows[] = $row;
    }
}

// Fetch staff members and salary-related data using the actual schema
$staff_members = [];
$staff_query = $conn->query("SELECT u.userID as id, u.username, u.role, COALESCE(a.accountantID, 0) as accountantID, COALESCE(a.base_salary, 0) as base_salary, COALESCE(a.OT_rate, 0) as ot_rate, COALESCE(s.stocksupID, 0) as stocksupID, COALESCE(l.salessupID, 0) as salessupID FROM user_tbl u LEFT JOIN accountant_tbl a ON a.userID = u.userID LEFT JOIN stocksuperviser_tbl s ON s.userID = u.userID LEFT JOIN salessuperviser_tbl l ON l.userID = u.userID WHERE u.username != 'r.tharu' ORDER BY u.userID");
if ($staff_query) {
    while ($row = $staff_query->fetch_assoc()) {
        $staff_members[] = [
            'id' => (int)($row['id'] ?? 0),
            'username' => $row['username'],
            'role' => $row['role'],
            'accountantID' => (int)($row['accountantID'] ?? 0),
            'base_salary' => (float)($row['base_salary'] ?? 0),
            'ot_rate' => (float)($row['ot_rate'] ?? 0),
            'stocksupID' => (int)($row['stocksupID'] ?? 0),
            'salessupID' => (int)($row['salessupID'] ?? 0),
        ];
    }
}

// Attendance records for the salary calculator (accountant attendance rows)
$attendance_rows = [];
$attendance_query = $conn->query("SELECT a.attendanceID, a.date, a.login, a.logout, a.accountantID, ac.accountantname, ac.base_salary, ac.OT_rate FROM attendance_tbl a LEFT JOIN accountant_tbl ac ON ac.accountantID = a.accountantID WHERE a.accountantID IS NOT NULL ORDER BY a.date DESC, a.attendanceID DESC");
if ($attendance_query) {
    while ($row = $attendance_query->fetch_assoc()) {
        $attendance_rows[] = $row;
    }
}

// Fetch Active Customer Index
$customers = [];
$cust_query = $conn->query("SELECT c.userID as user_id, c.customerID, u.username, u.email, u.createdAt, c.companyname FROM customer_tbl c JOIN user_tbl u ON u.userID = c.userID ORDER BY c.customerID DESC LIMIT 20");
if ($cust_query) {
    while ($row = $cust_query->fetch_assoc()) {
        $customers[] = [
            'id' => (int)($row['customerID'] ?? 0),
            'name' => $row['companyname'] ?? $row['username'],
            'email' => $row['email'] ?? '',
            'created_at' => $row['createdAt'] ?? '',
        ];
    }
}

// Fetch salary payment history from salary_tbl
$payroll_history = [];
$ledger_query = $conn->query("SELECT s.salaryID as id, u.username, s.totamtpaid as total_paid, s.paydate, CONCAT('PAID & VERIFIED') as settlement_status FROM salary_tbl s JOIN accountant_tbl a ON a.accountantID = s.accountantID JOIN user_tbl u ON u.userID = a.userID ORDER BY s.paydate DESC, s.salaryID DESC");
if ($ledger_query) {
    while ($row = $ledger_query->fetch_assoc()) {
        $payroll_history[] = $row;
    }
}

// Retrieve generated profit reports for the owner
$generated_reports = [];
$generatedReportsQuery = $conn->query("SELECT profitrepID, month, genaratedAt, ownerID FROM ProfitReport_tbl ORDER BY genaratedAt DESC LIMIT 20");
if ($generatedReportsQuery) {
    while ($row = $generatedReportsQuery->fetch_assoc()) {
        $generated_reports[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Dashboard - Tharu & Products Systems</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Chart.js Engine CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.2/xlsx.full.min.js"></script>
    
    <style>
        :root {
            --sidebar-bg: #0b1a10;
            --sidebar-active: #1e3a24;
            --forest-main: #2e7d32;
            --mint-light: #e8f5e9;
            --canvas-bg: #f8faf9;
        }

        body {
            background-color: var(--canvas-bg);
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            overflow-x: hidden;
            margin: 0;
        }

        .dashboard-wrapper {
            display: flex;
            position: relative;
        }

        .sidebar-panel {
            width: 260px;
            background-color: var(--sidebar-bg);
            color: #ffffff;
            padding: 2rem 1rem;
            flex-shrink: 0;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .main-content {
            flex-grow: 1;
            margin-left: 260px;
            padding: 2.5rem;
            width: calc(100% - 260px);
        }

        .nav-dash-link {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #a3b899;
            text-decoration: none;
            padding: 0.85rem 1rem;
            border-radius: 12px;
            margin-bottom: 0.5rem;
            font-weight: 500;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        .nav-dash-link:hover, .nav-dash-link.active {
            background-color: var(--sidebar-active);
            color: #ffffff;
        }

        .brand-logo-img {
            height: 36px;
            width: auto;
            object-fit: contain;
        }

        .sidebar-profile-footer {
            padding: 1rem;
            background: rgba(255, 255, 255, 0.04);
            border-radius: 14px;
            margin-top: auto;
        }

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
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
            padding: 1rem;
        }
        .custom-table td {
            padding: 1rem;
            vertical-align: middle;
            font-size: 0.92rem;
        }
    </style>
</head>
<body>

<div class="dashboard-wrapper">
    
    <!-- LEFT SIDEBAR VIEW MATRIX CONTROLLER -->
    <div class="sidebar-panel">
        <div>
            <div class="d-flex align-items-center gap-2 px-2 mb-4">
                <img src="../images/LOGO.png" alt="Tharu Logo" class="brand-logo-img rounded">
                <h5 class="fw-bold mb-0 text-white font-monospace">THARU OWNER</h5>
            </div>
            <hr style="border-color: rgba(255,255,255,0.1);">
            
            <div class="nav flex-column" id="dashboardTabs" role="tablist">
                <a class="nav-dash-link <?= $active_tab === 'panel-analytics' ? 'active' : '' ?>" id="tab-analytics" data-bs-toggle="tab" href="#panel-analytics" role="tab"><i class="bi bi-bar-chart-line-fill me-2"></i> Sales Analytics</a>
                <a class="nav-dash-link <?= $active_tab === 'panel-salary' ? 'active' : '' ?>" id="tab-salary" data-bs-toggle="tab" href="#panel-salary" role="tab"><i class="bi bi-calculator-fill me-2"></i> Salary Calculator</a>
                <a class="nav-dash-link <?= $active_tab === 'panel-status' ? 'active' : '' ?>" id="tab-status" data-bs-toggle="tab" href="#panel-status" role="tab"><i class="bi bi-credit-card-2-front-fill me-2"></i> Payment Status</a>
                <a class="nav-dash-link <?= $active_tab === 'panel-customers' ? 'active' : '' ?>" id="tab-customers" data-bs-toggle="tab" href="#panel-customers" role="tab"><i class="bi bi-people-fill me-2"></i> Customer Details</a>
                <a class="nav-dash-link <?= $active_tab === 'panel-employees' ? 'active' : '' ?>" id="tab-employees" data-bs-toggle="tab" href="#panel-employees" role="tab"><i class="bi bi-tools me-2"></i> System Users</a>
                <a class="nav-dash-link <?= $active_tab === 'panel-reports' ? 'active' : '' ?>" id="tab-reports" data-bs-toggle="tab" href="#panel-reports" role="tab"><i class="bi bi-file-earmark-bar-graph-fill me-2"></i> Reports</a>
            </div>
        </div>

        <div class="sidebar-profile-footer">
            <a href="../auth/logout.php" class="text-danger text-decoration-none d-flex align-items-center gap-2 small fw-bold" style="letter-spacing: 0.3px;">
                <i class="bi bi-box-arrow-right"></i> Sign out user
            </a>
        </div>
    </div>

    <!-- MAIN DASHBOARD DATA VIEWPORT -->
    <div class="main-content">
        
<div class="tab-content" id="dashboardTabContent">
            
            <!-- ================= TAB 1: SALES & PERFORMANCE METRIC WAVES ================= -->
            <div class="tab-pane fade <?= $active_tab === 'panel-analytics' ? 'show active' : '' ?>" id="panel-analytics" role="tabpanel">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h3 class="fw-bold text-dark mb-1">Executive Analytics Overview</h3>
                        <p class="text-muted small mb-0">System performance audit track compiled natively from active cycle logs.</p>
                    </div>
                </div>

                <div class="row g-4 mb-4">
                    <div class="col-12 col-md-3">
                        <div class="stat-card-dark shadow-sm">
                            <span class="text-white-50 small text-uppercase">Total Sales</span>
                            <div class="metric-value mt-1">LKR <?= number_format($total_sales, 2) ?></div>
                            <span class="text-success small fw-bold">▲ Live</span> <span class="text-white-50 small">database metric</span>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="stat-card-light shadow-sm">
                            <span class="text-muted small text-uppercase">Net Profit Margin</span>
                            <div class="metric-value mt-1 text-dark"><?= $profit_margin ?>%</div>
                            <span class="text-success small fw-bold">Calculated</span> <span class="text-muted small">rate</span>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="stat-card-light shadow-sm">
                            <span class="text-muted small text-uppercase">Operating Overhead</span>
                            <div class="metric-value mt-1 text-dark">LKR <?= number_format($total_expenses, 2) ?></div>
                            <span class="text-danger small fw-bold">▼ Tracked</span> <span class="text-muted small">payouts</span>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="stat-card-light shadow-sm">
                            <span class="text-muted small text-uppercase">Net Return Asset</span>
                            <div class="metric-value mt-1 text-success">LKR <?= number_format($net_profit, 2) ?></div>
                            <span class="text-success small fw-bold">✓ Net Flow</span>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm p-4 rounded-4 mb-4">
                    <h5 class="fw-bold text-dark mb-4">Sales vs Expense Waves Summary</h5>
                    <div style="height: 350px; width: 100%;">
                        <canvas id="waveAnalyticsChart"></canvas>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-lg-6">
                        <div class="card border-0 shadow-sm p-4 rounded-4">
                            <h5 class="fw-bold text-dark mb-3">Sales</h5>
                            <div class="table-responsive">
                                <table class="table table-sm custom-table border align-middle mb-0">
                                    <thead>
                                        <tr><th>Order</th><th>Date</th><th>Amount</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($monthly_sales_rows as $sale): ?>
                                            <tr>
                                                <td>#<?= (int)($sale['orderID'] ?? 0) ?></td>
                                                <td><?= htmlspecialchars($sale['date'] ?? '') ?></td>
                                                <td>LKR <?= number_format((float)($sale['totamt'] ?? 0), 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="card border-0 shadow-sm p-4 rounded-4">
                            <h5 class="fw-bold text-dark mb-3">Expenses</h5>
                            <div class="table-responsive">
                                <table class="table table-sm custom-table border align-middle mb-0">
                                    <thead>
                                        <tr><th>Expense</th><th>Amount</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($monthly_expense_rows as $expense): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($expense['type'] ?? '') ?></td>
                                                <td>LKR <?= number_format((float)($expense['amount'] ?? 0), 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ================= TAB 2: SALARY COMPUTATION PORTAL ================= -->
            <div class="tab-pane fade <?= $active_tab === 'panel-salary' ? 'show active' : '' ?>" id="panel-salary" role="tabpanel">
                <div class="card border-0 shadow-sm p-4 rounded-4">
                    <h4 class="fw-bold text-dark mb-1"><i class="bi bi-calculator-fill me-2 text-success"></i>Salary Provisioning Calculator Portal</h4>
                    <p class="text-muted small mb-4">Select an operator from your live employee roster to run operations.</p>

                    <?php if (!empty($salary_success_msg)): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($salary_success_msg) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($salary_error_msg)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($salary_error_msg) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form action="owner_dashboard.php?tab=panel-salary" method="POST" id="salaryForm">
                        <input type="hidden" name="action" value="authorize_salary">
                        <input type="hidden" name="tab" value="panel-salary">
                        
                        <div class="row g-3">
                            <div class="col-md-12 mb-2">
                                <label class="form-label small fw-bold text-dark">Target Employee Operator</label>
                                <select class="form-select border" id="employeeSelect" name="employee_id" onchange="loadEmployeeData()" required>
                                    <option value="">-- Choose Active Staff Member --</option>
                                    <?php foreach ($staff_members as $member): ?>
                                        <?php if ((int)($member['accountantID'] ?? 0) > 0): ?>
                                            <option value="<?= (int)($member['id'] ?? 0) ?>"
                                                    data-base-salary="<?= number_format((float)($member['base_salary'] ?? 0), 2, '.', '') ?>"
                                                    data-ot-rate="<?= number_format((float)($member['ot_rate'] ?? 0), 2, '.', '') ?>">
                                                <?= htmlspecialchars($member['username']) ?> (<?= htmlspecialchars($member['role'] ?? 'Staff') ?>)
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Attendance Record</label>
                                <select class="form-select border" id="attendanceSelect" name="attendance_id" onchange="loadAttendanceData()" required>
                                    <option value="">-- Choose Attendance Record --</option>
                                    <?php foreach ($attendance_rows as $attendance): ?>
                                        <option value="<?= (int)($attendance['attendanceID'] ?? 0) ?>"
                                                data-login="<?= htmlspecialchars($attendance['login'] ?? '') ?>"
                                                data-logout="<?= htmlspecialchars($attendance['logout'] ?? '') ?>"
                                                data-date="<?= htmlspecialchars($attendance['date'] ?? '') ?>"
                                                data-accountant="<?= htmlspecialchars($attendance['accountantname'] ?? '') ?>"
                                                data-base-salary="<?= number_format((float)($attendance['base_salary'] ?? 0), 2, '.', '') ?>"
                                                data-ot-rate="<?= number_format((float)($attendance['OT_rate'] ?? 0), 2, '.', '') ?>">
                                            <?= htmlspecialchars($attendance['date'] ?? '') ?> | <?= htmlspecialchars($attendance['accountantname'] ?? '') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Login / Logout Times</label>
                                <input type="text" id="attendanceSummary" class="form-control bg-light" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Base Salary (LKR)</label>
                                <input type="number" id="baseSalary" class="form-control bg-light" name="base_salary_display" value="0" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">OT Rate Per Hour (LKR)</label>
                                <input type="number" id="displayOtRate" class="form-control bg-light" name="ot_rate_display" value="0" readonly>
                            </div>

                            <input type="hidden" id="basePayHidden" name="base_pay" value="0">

                            <div class="col-12 my-3"><hr></div>

                            <div class="col-md-6">
                                <label class="form-label small text-muted">OT Hours Worked</label>
                                <input type="number" id="otHours" class="form-control" value="0" step="0.01" oninput="calculateTotalSalary()">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted">OT Rate Per Hour (LKR)</label>
                                <input type="number" id="otRateDisplay" class="form-control" value="0" step="0.01" oninput="calculateTotalSalary()">
                            </div>

                            <input type="hidden" id="otRateHidden" name="ot_rate" value="0">
                            <input type="hidden" id="otPayHidden" name="ot_pay" value="0">

                            <div class="col-12 my-3"><hr></div>

                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="bonusToggle" onclick="toggleBonusField()">
                                    <label class="form-check-input-label fw-bold text-dark" for="bonusToggle">Apply Corporate Performance Bonus Milestone</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted">Bonus Payout (LKR)</label>
                                <input type="number" id="bonusAmount" class="form-control" name="bonus_pay" value="0" disabled oninput="calculateTotalSalary()">
                            </div>

                            <div class="col-12 mt-4">
                                <div class="p-4 rounded-3 text-start d-flex justify-content-between align-items-center" style="background-color: var(--mint-light);">
                                    <div>
                                        <h6 class="text-success fw-bold text-uppercase mb-1 small">Total Calculated Payroll Release</h6>
                                        <div class="fs-2 fw-bold text-dark" id="displayTotalPayout">LKR 0.00</div>
                                    </div>
                                    <button type="submit" class="btn btn-success px-4 py-2 fw-bold rounded-pill shadow-sm">Authorize Payout Track</button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ================= TAB 3: MONTHLY PROFIT REPORTS ================= -->
            <div class="tab-pane fade <?= $active_tab === 'panel-reports' ? 'show active' : '' ?>" id="panel-reports" role="tabpanel">
                <div class="card border-0 shadow-sm p-4 rounded-4">
                    <h5 class="fw-bold text-dark mb-3"><i class="bi bi-file-earmark-bar-graph-fill me-2 text-success"></i>Monthly Profit Reports</h5>

                    <?php if (!empty($report_success_msg)): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($report_success_msg) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($report_error_msg)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($report_error_msg) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="owner_dashboard.php?tab=panel-reports" class="row g-3 align-items-end mb-4">
                        <input type="hidden" name="action" value="generate_profit_report">
                        <input type="hidden" name="tab" value="panel-reports">
                        <div class="col-md-5">
                            <label class="form-label small fw-bold text-dark">Choose Report Month</label>
                            <input type="month" name="report_month" class="form-control" value="<?= htmlspecialchars($selected_report_month) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-success px-4 py-2 fw-bold rounded-pill shadow-sm">Generate Report</button>
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="reportExportFormat" name="download_format">
                                <option value="pdf" <?= $download_format === 'pdf' ? 'selected' : '' ?>>Download PDF</option>
                                <option value="xlsx" <?= $download_format === 'xlsx' ? 'selected' : '' ?>>Download XLSX</option>
                                <option value="png" <?= $download_format === 'png' ? 'selected' : '' ?>>Download PNG</option>
                                <option value="jpeg" <?= $download_format === 'jpeg' ? 'selected' : '' ?>>Download JPEG</option>
                            </select>
                        </div>
                    </form>

                    <?php if ($report_generated): ?>
                        <div class="d-flex flex-wrap justify-content-end gap-2 mb-3">
                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="exportReport('pdf')">Export PDF</button>
                            <button type="button" class="btn btn-outline-success btn-sm" onclick="exportReport('xlsx')">Export XLSX</button>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="exportReport('png')">Export PNG</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="exportReport('jpeg')">Export JPEG</button>
                        </div>
                        <div id="reportPreview" class="border rounded-4 p-4 mt-3" style="background: linear-gradient(135deg, #f7fff7 0%, #ffffff 100%);">
                            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                                <div>
                                    <h6 class="fw-bold text-dark mb-1">Monthly Profit Report</h6>
                                    <div class="text-muted small">Generated for <?= htmlspecialchars($selected_report_month) ?></div>
                                </div>
                            </div>
                            <div class="row g-4 mb-4">
                                <div class="col-md-4">
                                    <div class="card border-0 bg-light p-3 rounded-3">
                                        <div class="small text-muted">Selected Month</div>
                                        <div class="fw-bold text-dark mt-1"><?= htmlspecialchars($selected_report_month) ?></div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card border-0 bg-light p-3 rounded-3">
                                        <div class="small text-muted">Sales Total</div>
                                        <div class="fw-bold text-dark mt-1">LKR <?= number_format($report_sales_total, 2) ?></div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card border-0 bg-light p-3 rounded-3">
                                        <div class="small text-muted">Expenses Total</div>
                                        <div class="fw-bold text-dark mt-1">LKR <?= number_format($report_expenses_total, 2) ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="row g-4">
                                <div class="col-lg-8">
                                    <div class="card border-0 p-3 rounded-3" style="background-color: var(--mint-light);">
                                        <div class="small text-uppercase text-success fw-bold">Net Profit</div>
                                        <div class="fs-3 fw-bold text-dark">LKR <?= number_format($report_net_profit, 2) ?></div>
                                    </div>
                                    <div class="table-responsive mt-4">
                                        <table class="table table-sm custom-table border align-middle mb-0">
                                            <thead>
                                                <tr><th>Sales Detail</th><th>Amount</th></tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($report_sales_rows)): ?>
                                                    <tr><td colspan="2" class="text-center text-muted">No sales for this month.</td></tr>
                                                <?php else: ?>
                                                    <?php foreach ($report_sales_rows as $sale): ?>
                                                        <tr>
                                                            <td>#<?= (int)($sale['orderID'] ?? 0) ?> · <?= htmlspecialchars($sale['date'] ?? '') ?></td>
                                                            <td>LKR <?= number_format((float)($sale['totamt'] ?? 0), 2) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="table-responsive mt-4">
                                        <table class="table table-sm custom-table border align-middle mb-0">
                                            <thead>
                                                <tr><th>Expense Detail</th><th>Amount</th></tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($report_expense_rows)): ?>
                                                    <tr><td colspan="2" class="text-center text-muted">No expense entries for this month.</td></tr>
                                                <?php else: ?>
                                                    <?php foreach ($report_expense_rows as $expense): ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($expense['type'] ?? '') ?></td>
                                                            <td>LKR <?= number_format((float)($expense['amount'] ?? 0), 2) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <canvas id="profitReportChart" height="240"></canvas>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="table-responsive mt-4">
                        <table class="table custom-table border align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Report ID</th>
                                    <th>Month</th>
                                    <th>Generated At</th>
                                    <th>Owner ID</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($generated_reports)): ?>
                                    <tr><td colspan="4" class="text-center text-muted">No monthly profit reports generated yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($generated_reports as $report): ?>
                                        <tr>
                                            <td><span class="font-monospace fw-bold">#PR-<?= (int)($report['profitrepID'] ?? 0) ?></span></td>
                                            <td><?= htmlspecialchars($report['month'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($report['genaratedAt'] ?? '') ?></td>
                                            <td><?= (int)($report['ownerID'] ?? 0) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ================= TAB 4: SALARY PAYMENT STATUS ================= -->
            <div class="tab-pane fade <?= $active_tab === 'panel-status' ? 'show active' : '' ?>" id="panel-status" role="tabpanel">
                <div class="card border-0 shadow-sm p-4 rounded-4">
                    <h5 class="fw-bold text-dark mb-3"><i class="bi bi-credit-card-2-front-fill me-2 text-success"></i>Employee Ledger & Payout Settlement Logs</h5>
                    <div class="table-responsive">
                        <table class="table custom-table border align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Log Key Identity</th>
                                    <th>Corporate Operator Identity</th>
                                    <th>Total Authorized Pay</th>
                                    <th>Settlement Status Matrix</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($payroll_history)): ?>
                                    <tr><td colspan="4" class="text-center text-muted">No historical database payouts found.</td></tr>
                                <?php else: ?>
                                    <?php foreach($payroll_history as $row): ?>
                                        <tr>
                                            <td><span class="font-monospace fw-bold">#PAY-00<?= (int)($row['id'] ?? 0) ?></span></td>
                                            <td><?= htmlspecialchars($row['username'] ?? '') ?></td>
                                            <td>LKR <?= number_format((float)($row['total_paid'] ?? 0), 2) ?></td>
                                            <td><span class="badge bg-success-subtle text-success border px-2 py-1"><?= htmlspecialchars($row['settlement_status'] ?? '✓ PAID') ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ================= TAB 5: CUSTOMER PROFILES INDEX ================= -->
            <div class="tab-pane fade <?= $active_tab === 'panel-customers' ? 'show active' : '' ?>" id="panel-customers" role="tabpanel">
                <div class="card border-0 shadow-sm p-4 rounded-4">
                    <h5 class="fw-bold text-dark mb-3"><i class="bi bi-people-fill me-2 text-success"></i>Active Verified Customer Profile Indexes</h5>
                    <div class="table-responsive">
                        <table class="table custom-table border align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Customer ID</th>
                                    <th>Profile Name</th>
                                    <th>Email Endpoint</th>
                                    <th>Account Registration Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($customers as $cust): ?>
                                    <tr>
                                        <td><span class="font-monospace fw-bold">#CST-<?= (int)($cust['id'] ?? 0) ?></span></td>
                                        <td><?= htmlspecialchars($cust['name'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($cust['email'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($cust['created_at'] ?? '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ================= TAB 6: STAFF ROLES CRUD MANAGEMENT ================= -->
            <div class="tab-pane fade <?= $active_tab === 'panel-employees' ? 'show active' : '' ?>" id="panel-employees" role="tabpanel">
                <div class="card border-0 shadow-sm p-4 rounded-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold text-dark mb-0"><i class="bi bi-tools me-2 text-success"></i>Corporate Employee Resource Provisioning Matrix</h5>
                    </div>

                    <?php if (!empty($salary_success_msg) && $active_tab === 'panel-employees'): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($salary_success_msg) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($salary_error_msg) && $active_tab === 'panel-employees'): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($salary_error_msg) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <div class="table-responsive">
                        <table class="table custom-table border align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>System Key</th>
                                    <th>Assigned Operator</th>
                                    <th>System Role Level</th>
                                    <th class="text-center">Operational Actions Matrix</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($staff_members as $member): ?>
                                    <tr>
                                        <td><span class="font-monospace fw-bold">USR-0<?= (int)($member['id'] ?? 0) ?></span></td>
                                        <td><?= htmlspecialchars($member['username'] ?? '') ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($member['role'] ?? 'System Asset') ?></span></td>
                                        <td class="text-center">
                                            <form method="POST" action="owner_dashboard.php?tab=panel-employees" class="d-flex flex-column gap-2">
                                                <input type="hidden" name="action" value="update_staff">
                                                <input type="hidden" name="tab" value="panel-employees">
                                                <input type="hidden" name="user_id" value="<?= (int)($member['id'] ?? 0) ?>">
                                                <select name="role" class="form-select form-select-sm">
                                                    <option value="owner" <?= strtolower((string)($member['role'] ?? '')) === 'owner' ? 'selected' : '' ?>>Owner</option>
                                                    <option value="stocksup" <?= strtolower((string)($member['role'] ?? '')) === 'stocksup' ? 'selected' : '' ?>>Stock Supervisor</option>
                                                    <option value="accountant" <?= strtolower((string)($member['role'] ?? '')) === 'accountant' ? 'selected' : '' ?>>Accountant</option>
                                                    <option value="salessup" <?= strtolower((string)($member['role'] ?? '')) === 'salessup' ? 'selected' : '' ?>>Sales Supervisor</option>
                                                    <option value="worker" <?= strtolower((string)($member['role'] ?? '')) === 'worker' ? 'selected' : '' ?>>Worker</option>
                                                    <option value="driver" <?= strtolower((string)($member['role'] ?? '')) === 'driver' ? 'selected' : '' ?>>Driver</option>
                                                    <option value="cust" <?= strtolower((string)($member['role'] ?? '')) === 'cust' ? 'selected' : '' ?>>Customer</option>
                                                </select>
                                                <button type="submit" class="btn btn-sm btn-outline-primary">Update</button>
                                            </form>
                                            <a href="owner_dashboard.php?delete_staff=<?= (int)($member['id'] ?? 0) ?>" class="btn btn-sm btn-outline-danger mt-2" onclick="return confirm('Confirm removal of operator account from security index?')">Delete User Asset</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
let currentOtHours = 0;
let currentOtRate = 0;

function parseTimeValue(inputValue) {
    if (!inputValue) {
        return null;
    }
    const parts = String(inputValue).split(':');
    if (parts.length < 2) {
        return null;
    }
    const hours = parseInt(parts[0], 10) || 0;
    const minutes = parseInt(parts[1], 10) || 0;
    const seconds = parseInt(parts[2], 10) || 0;
    return new Date(2000, 0, 1, hours, minutes, seconds);
}

function calculateOvertimeHours(loginValue, logoutValue) {
    const loginTime = parseTimeValue(loginValue);
    const logoutTime = parseTimeValue(logoutValue);
    if (!loginTime || !logoutTime) {
        return 0;
    }
    let workedMs = logoutTime.getTime() - loginTime.getTime();
    if (workedMs < 0) {
        workedMs += 24 * 60 * 60 * 1000;
    }
    const workedHours = workedMs / (1000 * 60 * 60);
    const overtimeHours = workedHours > 8 ? workedHours - 8 : 0;
    return overtimeHours > 0 ? overtimeHours : 0;
}

function loadEmployeeData() {
    const select = document.getElementById('employeeSelect');
    const selectedOption = select.options[select.selectedIndex];
    const baseSalaryInput = document.getElementById('baseSalary');
    const otRateInput = document.getElementById('displayOtRate');
    const otRateDisplayInput = document.getElementById('otRateDisplay');

    if (!selectedOption || !selectedOption.value) {
        baseSalaryInput.value = 0;
        otRateInput.value = 0;
        otRateDisplayInput.value = 0;
        currentOtRate = 0;
        calculateTotalSalary();
        return;
    }

    const baseSalary = parseFloat(selectedOption.getAttribute('data-base-salary')) || 0;
    const otRate = parseFloat(selectedOption.getAttribute('data-ot-rate')) || 0;
    baseSalaryInput.value = baseSalary.toFixed(2);
    otRateInput.value = otRate.toFixed(2);
    otRateDisplayInput.value = otRate.toFixed(2);
    currentOtRate = otRate;
    calculateTotalSalary();
}

function loadAttendanceData() {
    const select = document.getElementById('attendanceSelect');
    const selectedOption = select.options[select.selectedIndex];
    const baseSalaryInput = document.getElementById('baseSalary');
    const otRateInput = document.getElementById('displayOtRate');
    const otRateDisplayInput = document.getElementById('otRateDisplay');

    if (!selectedOption || !selectedOption.value) {
        document.getElementById('attendanceSummary').value = '';
        baseSalaryInput.value = 0;
        otRateInput.value = 0;
        otRateDisplayInput.value = 0;
        document.getElementById('basePayHidden').value = 0;
        document.getElementById('otHours').value = 0;
        currentOtHours = 0;
        currentOtRate = 0;
        calculateTotalSalary();
        return;
    }

    const login = selectedOption.getAttribute('data-login') || '';
    const logout = selectedOption.getAttribute('data-logout') || '';
    const date = selectedOption.getAttribute('data-date') || '';
    const accountName = selectedOption.getAttribute('data-accountant') || '';
    const baseSalary = parseFloat(selectedOption.getAttribute('data-base-salary')) || 0;
    const otRate = parseFloat(selectedOption.getAttribute('data-ot-rate')) || 0;

    document.getElementById('attendanceSummary').value = date + ' | ' + login + ' - ' + logout + ' | ' + accountName;
    baseSalaryInput.value = baseSalary.toFixed(2);
    otRateInput.value = otRate.toFixed(2);
    otRateDisplayInput.value = otRate.toFixed(2);
    document.getElementById('basePayHidden').value = baseSalary.toFixed(2);
    document.getElementById('otRateHidden').value = otRate.toFixed(2);
    currentOtRate = otRate;
    currentOtHours = calculateOvertimeHours(login, logout);
    document.getElementById('otHours').value = currentOtHours.toFixed(2);
    calculateTotalSalary();
}

function toggleBonusField() {
    const isChecked = document.getElementById('bonusToggle').checked;
    const bonusInput = document.getElementById('bonusAmount');
    bonusInput.disabled = !isChecked;
    if(!isChecked) {
        bonusInput.value = 0;
    }
    calculateTotalSalary();
}

function calculateTotalSalary() {
    const baseSalary = parseFloat(document.getElementById('baseSalary').value) || 0;
    const otHours = parseFloat(document.getElementById('otHours').value) || 0;
    const otRate = parseFloat(document.getElementById('otRateDisplay').value) || 0;
    const bonus = parseFloat(document.getElementById('bonusAmount').value) || 0;

    const basePay = baseSalary;
    const otPay = (otHours > 0 && otRate > 0) ? (otHours * otRate) : 0;
    document.getElementById('basePayHidden').value = baseSalary.toFixed(2);
    document.getElementById('otPayHidden').value = otPay.toFixed(2);
    document.getElementById('otRateHidden').value = otRate.toFixed(2);

    const totalPayout = basePay + otPay + bonus;
    document.getElementById('displayTotalPayout').innerText = 'LKR ' + totalPayout.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function exportReport(format) {
    const preview = document.getElementById('reportPreview');
    if (!preview) {
        alert('Generate a report first so the preview can be exported.');
        return;
    }

    const monthValue = document.querySelector('input[name="report_month"]')?.value || 'report';
    const filename = `profit-report-${monthValue}`;
    const reportData = window.reportExportData || { salesTotal: 0, expensesTotal: 0, netProfit: 0, salesRows: [], expenseRows: [] };

    if (format === 'xlsx') {
        const rows = [
            ['THARU PRODUCTS MONTHLY PROFIT REPORT'],
            ['Month', monthValue],
            ['Generated At', new Date().toLocaleString()],
            [],
            ['Sales Total', `LKR ${Number(reportData.salesTotal || 0).toFixed(2)}`],
            ['Expenses Total', `LKR ${Number(reportData.expensesTotal || 0).toFixed(2)}`],
            ['Net Profit', `LKR ${Number(reportData.netProfit || 0).toFixed(2)}`],
            [],
            ['Sales Details'],
            ['Order ID', 'Date', 'Amount']
        ];
        (reportData.salesRows || []).forEach(function (sale) {
            rows.push([`#${sale.orderID || 0}`, sale.date || '', `LKR ${Number(sale.totamt || 0).toFixed(2)}`]);
        });
        rows.push([], ['Expense Details'], ['Expense', 'Amount']);
        (reportData.expenseRows || []).forEach(function (expense) {
            rows.push([expense.type || '', `LKR ${Number(expense.amount || 0).toFixed(2)}`]);
        });
        const ws = XLSX.utils.aoa_to_sheet(rows);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Report');
        XLSX.writeFile(wb, `${filename}.xlsx`);
        return;
    }

    html2canvas(preview, { scale: 2, backgroundColor: '#ffffff', useCORS: true }).then(function (canvas) {
        const imageUrl = canvas.toDataURL(format === 'jpeg' ? 'image/jpeg' : 'image/png');
        if (format === 'png' || format === 'jpeg') {
            const link = document.createElement('a');
            link.href = imageUrl;
            link.download = `${filename}.${format}`;
            link.click();
            return;
        }

        const { jsPDF } = window.jspdf;
        const pdf = new jsPDF('p', 'mm', 'a4');
        const pageWidth = pdf.internal.pageSize.getWidth();
        const pageHeight = pdf.internal.pageSize.getHeight();
        const imgWidth = pageWidth - 20;
        const imgHeight = (canvas.height * imgWidth) / canvas.width;
        let position = 10;
        let heightLeft = imgHeight;
        pdf.addImage(imageUrl, 'PNG', 10, position, imgWidth, imgHeight);
        heightLeft -= pageHeight - 20;
        while (heightLeft > 0) {
            position = heightLeft - imgHeight + 10;
            pdf.addPage();
            pdf.addImage(imageUrl, 'PNG', 10, position, imgWidth, imgHeight);
            heightLeft -= pageHeight - 20;
        }
        pdf.save(`${filename}.pdf`);
    });
}

// Chart.js gradient pipeline setup
document.addEventListener("DOMContentLoaded", function () {
    window.reportExportData = {
        salesTotal: <?= json_encode($report_sales_total) ?>,
        expensesTotal: <?= json_encode($report_expenses_total) ?>,
        netProfit: <?= json_encode($report_net_profit) ?>,
        salesRows: <?= json_encode($report_sales_rows) ?>,
        expenseRows: <?= json_encode($report_expense_rows) ?>
    };

    const tabLinks = document.querySelectorAll('[data-bs-toggle="tab"]');
    tabLinks.forEach(function (tabLink) {
        tabLink.addEventListener('shown.bs.tab', function () {
            const target = tabLink.getAttribute('href') || tabLink.getAttribute('data-bs-target');
            if (target) {
                const hash = target.startsWith('#') ? target : '#' + target;
                if (window.location.hash !== hash) {
                    history.replaceState(null, '', hash);
                }
            }
        });
    });

    const analyticsCtx = document.getElementById('waveAnalyticsChart');
    if (analyticsCtx) {
        const ctx = analyticsCtx.getContext('2d');
        
        const gradientSales = ctx.createLinearGradient(0, 0, 0, 350);
        gradientSales.addColorStop(0, 'rgba(46, 125, 50, 0.45)');
        gradientSales.addColorStop(1, 'rgba(46, 125, 50, 0.00)');

        const gradientExpenses = ctx.createLinearGradient(0, 0, 0, 350);
        gradientExpenses.addColorStop(0, 'rgba(231, 76, 60, 0.15)');
        gradientExpenses.addColorStop(1, 'rgba(231, 76, 60, 0.00)');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['1', '5', '10', '15', '20', '25', '30'],
                datasets: [
                    {
                        label: 'Sales Revenue Track',
                        data: [180000, 150000, 240000, 290000, 210000, 260000, <?= $total_sales ?>],
                        borderColor: '#2e7d32',
                        backgroundColor: gradientSales,
                        fill: true,
                        tension: 0.45,
                        borderWidth: 3,
                        pointRadius: 4,
                        pointBackgroundColor: '#2e7d32'
                    },
                    {
                        label: 'Operational Expense Track',
                        data: [140000, 110000, 160000, 150000, 130000, 170000, <?= $total_expenses ?>],
                        borderColor: '#e74c3c',
                        backgroundColor: gradientExpenses,
                        fill: true,
                        tension: 0.45,
                        borderWidth: 2,
                        pointRadius: 0
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', labels: { boxWidth: 12, font: { weight: 600 } } }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { 
                        grid: { color: '#f1f5f3' },
                        ticks: { callback: value => 'LKR ' + value.toLocaleString() }
                    }
                }
            }
        });
    }

    const reportChartCtx = document.getElementById('profitReportChart');
    if (reportChartCtx) {
        new Chart(reportChartCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($report_chart_labels) ?>,
                datasets: [{
                    label: 'Monthly Profit Summary',
                    data: <?= json_encode($report_chart_values) ?>,
                    backgroundColor: ['#2e7d32', '#e74c3c', '#4caf50']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: value => 'LKR ' + value.toLocaleString() } }
                }
            }
        });
    }

    const hash = window.location.hash;
    if (hash) {
        const triggerEl = document.querySelector(`[href="${hash}"]`);
        if (triggerEl) {
            bootstrap.Tab.getOrCreateInstance(triggerEl).show();
        }
    } else {
        const activeId = document.querySelector('.tab-pane.show.active')?.id || 'panel-analytics';
        const triggerEl = document.querySelector(`[href="#${activeId}"]`);
        if (triggerEl) {
            bootstrap.Tab.getOrCreateInstance(triggerEl).show();
        }
    }

    if (window.reportExportData && <?= json_encode($report_generated) ?> && <?= json_encode($auto_download_format) ?>) {
        setTimeout(function () {
            exportReport(<?= json_encode($auto_download_format) ?>);
        }, 400);
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>