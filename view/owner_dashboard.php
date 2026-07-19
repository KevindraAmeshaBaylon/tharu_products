<?php
/**
 * Owner Dashboard Portal
 * File: owner/dashboard.php
 * 
 * This file serves as the main administrative interface for the business owner.
 * It provides a comprehensive view of:
 * - Sales and Expense Analytics (Charts and Data Grids)
 * - Salary Provisioning (OT and Monthly Salary Calculators)
 * - Payout Ledger and Status Logs
 * - Customer Profile Index
 * - System Operator/Employee CRUD Actions
 * - Generation and downloading of Monthly Profit Reports
 */

// Initialize the session to access global user data
session_start();

// 1. Basic Session Guard: Restrict access exclusively to users with the 'owner' role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'owner') {
    // Redirect unauthorized users to the login screen
    header("Location: ../auth/login.php");
    exit;
}

// 2. Database Connection Wrapper
// Requires the central database configuration and establishes a connection instance
require_once __DIR__ . '/../model/config/database.php'; 
$conn = getDBConnection();

// --- AJAX Salary Details Endpoint ---
// This endpoint dynamically fetches an employee's base salary and accumulated OT amount for a given month.
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_salary_details') {
    header('Content-Type: application/json'); // Set header for JSON response
    
    // Sanitize input parameters
    $emp_id = intval($_GET['employee_id'] ?? 0);
    $month = trim($_GET['month'] ?? date('Y-m'));
    
    // Validate month format (YYYY-MM)
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }
    
    // Fetch accountant details (base salary and OT rate) for the specified user
    $acc_stmt = $conn->prepare("SELECT accountantID, base_salary, OT_rate FROM accountant_tbl WHERE userID = ? LIMIT 1");
    if ($acc_stmt) {
        $acc_stmt->bind_param("i", $emp_id);
        $acc_stmt->execute();
        $acc = $acc_stmt->get_result()->fetch_assoc();
        
        if ($acc) {
            $accountant_id = (int)$acc['accountantID'];
            $base_salary = (float)$acc['base_salary'];
            
            // Sum up OT amounts for the selected month from salary_tbl.
            // It ignores records where the total amount paid >= base_salary (assuming those are final salary payouts, not OT records).
            $sal_stmt = $conn->prepare("SELECT totamtpaid FROM salary_tbl WHERE accountantID = ? AND DATE_FORMAT(paydate, '%Y-%m') = ?");
            if ($sal_stmt) {
                $sal_stmt->bind_param("is", $accountant_id, $month);
                $sal_stmt->execute();
                $sal_res = $sal_stmt->get_result();
                
                $sum_ot = 0;
                $ot_records_count = 0;
                
                while ($s_row = $sal_res->fetch_assoc()) {
                    $amt = (float)$s_row['totamtpaid'];
                    if ($amt >= $base_salary) {
                        continue; // Skip final salary entries
                    }
                    $sum_ot += $amt;
                    $ot_records_count++;
                }
                
                // Return data as JSON for the frontend to process
                echo json_encode([
                    'status' => 'success',
                    'base_salary' => $base_salary,
                    'ot_rate' => (float)$acc['OT_rate'],
                    'sum_ot' => $sum_ot,
                    'ot_records_count' => $ot_records_count
                ]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Query preparation failed.']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Accountant profile not found for this user.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Statement preparation failed.']);
    }
    exit; // Terminate script execution after handling AJAX request
}

// 🔌 Dynamic Global Header File Included
// Loads styles, fonts, and meta tags common to the application layout
include_once __DIR__ . '/../includes/header.php'; 


// ==========================================
// 3. DATABASE CONTROLLER LOGIC FOR ACTIONS
// ==========================================

// Determines which tab should be active by default or after form submission
$active_tab = $_GET['tab'] ?? ($_POST['tab'] ?? 'panel-analytics');

// Initialization of feedback message variables
$salary_success_msg = "";
$salary_error_msg = "";
$report_success_msg = "";
$report_error_msg = "";

// Variables to hold report generation data
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

// Validate download format
if (!in_array($download_format, ['pdf', 'xlsx', 'png', 'jpeg'], true)) {
    $download_format = 'pdf';
}
$auto_download_format = '';

// Process POST requests related to actions on the dashboard
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Action: Record calculated Overtime (OT) Amount
    if ($_POST['action'] === 'record_ot_amount') {
        $emp_id = intval($_POST['employee_id'] ?? 0);
        $ot_pay = floatval($_POST['ot_pay'] ?? 0);
        $attendance_id = intval($_POST['attendance_id'] ?? 0);
        $accountant_id = null;

        // Resolve accountant ID based on selected user ID
        $accountantLookup = $conn->prepare('SELECT accountantID FROM accountant_tbl WHERE userID = ? LIMIT 1');
        if ($accountantLookup) {
            $accountantLookup->bind_param('i', $emp_id);
            $accountantLookup->execute();
            $accountantRow = $accountantLookup->get_result()->fetch_assoc();
            if ($accountantRow) {
                $accountant_id = (int)$accountantRow['accountantID'];
            }
        }

        // Validate resolved IDs and payment amount
        if ($accountant_id > 0 && $attendance_id > 0 && $ot_pay > 0) {
            // Check if OT amount is already recorded to prevent duplicate records
            $check_stmt = $conn->prepare('SELECT salaryID FROM salary_tbl WHERE attendanceID = ? AND accountantID = ? AND totamtpaid = ?');
            $check_stmt->bind_param('iid', $attendance_id, $accountant_id, $ot_pay);
            $check_stmt->execute();
            $check_res = $check_stmt->get_result();
            
            if ($check_res->num_rows > 0) {
                $salary_error_msg = 'This OT amount has already been recorded for this attendance record.';
            } else {
                // Insert OT record into the salary table
                $stmt = $conn->prepare('INSERT INTO salary_tbl (paydate, totamtpaid, attendanceID, accountantID) VALUES (CURDATE(), ?, ?, ?)');
                if ($stmt) {
                    $stmt->bind_param('dii', $ot_pay, $attendance_id, $accountant_id);
                    if ($stmt->execute()) {
                        $salary_success_msg = 'OT Amount recorded successfully in salary_tbl.';
                    } else {
                        $salary_error_msg = 'OT record write failed: ' . $stmt->error;
                    }
                    $stmt->close();
                }
            }
        } else {
            $salary_error_msg = 'Please choose a valid accountant, attendance record, and make sure OT pay is calculated.';
        }
        $active_tab = 'panel-salary'; // Keep user on the salary tab
    }

    // Action: Record the total finalized Monthly Salary
    if ($_POST['action'] === 'record_total_salary') {
        $emp_id = intval($_POST['employee_id'] ?? 0);
        $total_salary = floatval($_POST['total_salary'] ?? 0);
        $paydate = trim($_POST['paydate'] ?? date('Y-m-d'));
        
        $accountant_id = null;
        
        // Find corresponding accountant ID
        $accountantLookup = $conn->prepare('SELECT accountantID FROM accountant_tbl WHERE userID = ? LIMIT 1');
        if ($accountantLookup) {
            $accountantLookup->bind_param('i', $emp_id);
            $accountantLookup->execute();
            $accountantRow = $accountantLookup->get_result()->fetch_assoc();
            if ($accountantRow) {
                $accountant_id = (int)$accountantRow['accountantID'];
            }
        }

        $attendance_id = 0;
        
        // Fetch the most recent attendance ID to satisfy Foreign Key constraints in the salary table
        if ($accountant_id > 0) {
            $attLookup = $conn->prepare('SELECT attendanceID FROM attendance_tbl WHERE accountantID = ? ORDER BY date DESC LIMIT 1');
            if ($attLookup) {
                $attLookup->bind_param('i', $accountant_id);
                $attLookup->execute();
                $attRow = $attLookup->get_result()->fetch_assoc();
                if ($attRow) {
                    $attendance_id = (int)$attRow['attendanceID'];
                }
            }
        }

        // Insert final salary record
        if ($accountant_id > 0 && $attendance_id > 0 && $total_salary > 0) {
            $stmt = $conn->prepare('INSERT INTO salary_tbl (paydate, totamtpaid, attendanceID, accountantID) VALUES (?, ?, ?, ?)');
            if ($stmt) {
                $stmt->bind_param('sdii', $paydate, $total_salary, $attendance_id, $accountant_id);
                if ($stmt->execute()) {
                    $salary_success_msg = 'Final monthly salary recorded successfully along with pay date.';
                } else {
                    $salary_error_msg = 'Final salary write failed: ' . $stmt->error;
                }
                $stmt->close();
            }
        } else {
            $salary_error_msg = 'Please choose a valid accountant. An attendance record for the accountant is required in the system.';
        }
        $active_tab = 'panel-salary';
    }
}

// Action: Handle Staff Deletion via GET request
if (isset($_GET['delete_staff'])) {
    $del_id = intval($_GET['delete_staff']);
    
    // Prevent the master owner account from being accidentally deleted
    $deleteUser = $conn->prepare('DELETE FROM user_tbl WHERE userID = ? AND username != ?');
    if ($deleteUser) {
        $ownerUsername = 'r.tharu'; // Hardcoded master owner username
        $deleteUser->bind_param('is', $del_id, $ownerUsername);
        
        if ($deleteUser->execute()) {
            // Reload the page pointing directly to the employees panel
            header('Location: owner_dashboard.php?tab=panel-employees#panel-employees');
            exit;
        } else {
            $salary_error_msg = 'Could not remove staff member: ' . $deleteUser->error;
            $active_tab = 'panel-employees';
        }
    }
}

// Action: Update a staff member's role
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_staff') {
    $user_id = intval($_POST['user_id'] ?? 0);
    $new_role = strtolower(trim((string)($_POST['role'] ?? '')));
    $allowed_roles = ['owner', 'stocksup', 'accountant', 'salessup', 'worker', 'driver', 'cust'];
    
    // Validate inputs against allowed roles
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

// Action: Generate a Monthly Profit Report
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_profit_report') {
    $selected_report_month = trim((string)($_POST['report_month'] ?? date('Y-m')));
    if (!preg_match('/^\d{4}-\d{2}$/', $selected_report_month)) {
        $selected_report_month = date('Y-m');
    }

    // Retrieve owner's ID
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

    // Fetch all sales (orders) for the selected month that are not cancelled
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

    // Fetch all expenses tied to the selected month via expense reports tables
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

    // Calculate totals for reporting
    $report_sales_total = 0.0;
    foreach ($report_sales_rows as $row) {
        $report_sales_total += (float)($row['totamt'] ?? 0);
    }
    
    $report_expenses_total = 0.0;
    foreach ($report_expense_rows as $row) {
        $report_expenses_total += (float)($row['amount'] ?? 0);
    }
    
    // Derive net profit
    $report_net_profit = $report_sales_total - $report_expenses_total;
    
    // Prepare chart payloads
    $report_chart_labels = ['Sales', 'Expenses', 'Net Profit'];
    $report_chart_values = [$report_sales_total, $report_expenses_total, $report_net_profit];

    // Prevent generation of completely empty reports
    if ($report_sales_total <= 0 && $report_expenses_total <= 0) {
        $report_error_msg = 'No sales were recorded for the selected month, so no report was generated.';
        $report_generated = false;
        $active_tab = 'panel-reports';
    } else {
        // Save report metadata into the database
        $insertStmt = $conn->prepare('INSERT INTO ProfitReport_tbl (month, ownerID) VALUES (?, ?)');
        if ($insertStmt && $owner_id > 0) {
            $insertStmt->bind_param('si', $selected_report_month, $owner_id);
            if ($insertStmt->execute()) {
                $report_success_msg = 'Monthly profit report prepared for download for ' . htmlspecialchars($selected_report_month) . '.';
                $report_generated = true;
                $auto_download_format = $download_format; // Trigger automatic download via JS later
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

// Initialize default system totals for analytics
$total_sales = 0;
$total_expenses = 0;

// Gather total sales from the current month
$sales_query = $conn->query("SELECT COALESCE(SUM(totamt), 0) as total FROM order_tbl WHERE MONTH(date) = MONTH(CURRENT_DATE()) AND YEAR(date) = YEAR(CURRENT_DATE())");
if ($sales_query) {
    $sales_data = $sales_query->fetch_assoc();
    $total_sales = floatval($sales_data['total'] ?? 0);
}

// Gather all-time total expenses
$expense_query = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM expense_tbl");
if ($expense_query) {
    $expense_data = $expense_query->fetch_assoc();
    $total_expenses = floatval($expense_data['total'] ?? 0);
}

// Computations for quick analytics overview
$net_profit = $total_sales - $total_expenses;
$profit_margin = ($total_sales > 0) ? round(($net_profit / $total_sales) * 100) : 0;

// Arrays for the current month's sales list
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$monthly_sales_rows = [];
$monthly_sales_query = $conn->query("SELECT orderID, date, totamt, customerID FROM order_tbl WHERE date BETWEEN '$monthStart' AND '$monthEnd' ORDER BY date DESC, orderID DESC");
if ($monthly_sales_query) {
    while ($row = $monthly_sales_query->fetch_assoc()) {
        $monthly_sales_rows[] = $row;
    }
}

// Arrays for expense lists
$monthly_expense_rows = [];
$monthly_expense_query = $conn->query("SELECT expenseID, type, amount, accountantID FROM expense_tbl ORDER BY expenseID DESC");
if ($monthly_expense_query) {
    while ($row = $monthly_expense_query->fetch_assoc()) {
        $monthly_expense_rows[] = $row;
    }
}

// --- Data sets for dashboard charts ---
// Sales sparkline uses the last 7 orders
$sales_chart_values = array_reverse(array_slice(array_column($monthly_sales_rows, 'totamt'), -7));
$sales_chart_labels = array_reverse(array_slice(array_map(function($r){ return '#ORD-' . $r['orderID']; }, $monthly_sales_rows), -7));
if (empty($sales_chart_values)) { $sales_chart_values = [0]; $sales_chart_labels = ['No Sales']; }

// Aggregate expenses by category type
$expense_types = [];
foreach ($monthly_expense_rows as $exp) {
    $type = $exp['type'] ?? 'Other';
    $expense_types[$type] = ($expense_types[$type] ?? 0) + floatval($exp['amount']);
}
$expense_chart_labels = array_keys($expense_types);
$expense_chart_values = array_values($expense_types);
if (empty($expense_chart_values)) { $expense_chart_values = [0]; $expense_chart_labels = ['No Expenses']; }

// Generate data for the 6-month historical wave chart
$wave_labels = [];
$wave_sales = [];
$wave_expenses = [];
for ($i = 5; $i >= 0; $i--) {
    $d = date('Y-m-01', strtotime("-$i months"));
    $wave_labels[] = date('M', strtotime($d));
    $sm = $conn->query("SELECT COALESCE(SUM(totamt),0) s FROM order_tbl WHERE DATE_FORMAT(date,'%Y-%m') = DATE_FORMAT('$d','%Y-%m')");
    $wave_sales[] = floatval($sm && $sm->num_rows ? ($sm->fetch_assoc()['s'] ?? 0) : 0);
    $em = $conn->query("SELECT COALESCE(SUM(amount),0) e FROM expense_tbl WHERE DATE_FORMAT(date,'%Y-%m') = DATE_FORMAT('$d','%Y-%m')");
    $wave_expenses[] = floatval($em && $em->num_rows ? ($em->fetch_assoc()['e'] ?? 0) : 0);
}
// Append the current active total
$wave_sales[] = $total_sales;
$wave_expenses[] = $total_expenses;
$wave_labels[] = 'Now';

// Compile comprehensive staff member details for roles and salaries
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

// Collect attendance rows specifically for accountants to calculate their OT
$attendance_rows = [];
$attendance_query = $conn->query("SELECT a.attendanceID, a.date, a.login, a.logout, a.accountantID, ac.accountantname, ac.base_salary, ac.OT_rate FROM attendance_tbl a LEFT JOIN accountant_tbl ac ON ac.accountantID = a.accountantID WHERE a.accountantID IS NOT NULL ORDER BY a.date DESC, a.attendanceID DESC");
if ($attendance_query) {
    while ($row = $attendance_query->fetch_assoc()) {
        $attendance_rows[] = $row;
    }
}

// Fetch list of active customers
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

// Pull historical payroll payouts from salary records
$payroll_history = [];
$ledger_query = $conn->query("SELECT s.salaryID as id, u.username, s.totamtpaid as total_paid, s.paydate, CONCAT('PAID & VERIFIED') as settlement_status FROM salary_tbl s JOIN accountant_tbl a ON a.accountantID = s.accountantID JOIN user_tbl u ON u.userID = a.userID ORDER BY s.paydate DESC, s.salaryID DESC");
if ($ledger_query) {
    while ($row = $ledger_query->fetch_assoc()) {
        $payroll_history[] = $row;
    }
}

// Retrieve index of generated profit reports to list in the reports tab
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
    <!-- Include Core Bootstrap Framework files -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <!-- Modern Typography definitions from Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    
    <!-- Third Party Scripts for Charts and Report Downloading Tools -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script> <!-- Chart engine -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script> <!-- For image capturing -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script> <!-- For PDF Generation -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.2/xlsx.full.min.js"></script> <!-- For Excel/XLSX support -->
    
    <style>
        /* CSS Root Variables defining standard dashboard colors */
        :root {
            --sidebar-bg: rgba(11, 26, 16, 0.92);
            --sidebar-active: rgba(46, 125, 50, 0.35);
            --forest-main: #2e7d32;
            --mint-light: #e8f5e9;
            --canvas-bg: #f8faf9;
            --glass-border: rgba(255, 255, 255, 0.6);
            --deep-forest: #052e2b;
            --mint-highlight: #6ee7b7;
        }

        /* Ambient styling for the main document body */
        body {
            background-color: var(--canvas-bg);
            background-image:
                radial-gradient(circle at 10% 20%, rgba(16, 185, 129, 0.07) 0%, transparent 42%),
                radial-gradient(circle at 90% 80%, rgba(46, 125, 50, 0.05) 0%, transparent 48%),
                linear-gradient(135deg, #f7fff9 0%, #edf5f1 40%, #e2f0e8 100%);
            background-attachment: fixed;
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            overflow-x: hidden;
            margin: 0;
        }

        /* Flexbox wrapper to position sidebar and content */
        .dashboard-wrapper {
            display: flex;
            position: relative;
        }

        /* Left fixed sidebar styles */
        .sidebar-panel {
            width: 260px;
            background: var(--sidebar-bg);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
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
            border-right: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 6px 0 30px rgba(2, 44, 34, 0.08);
        }

        /* Main content canvas, offset by sidebar width */
        .main-content {
            flex-grow: 1;
            margin-left: 260px;
            padding: 2.5rem;
            width: calc(100% - 260px);
            position: relative;
            z-index: 1;
        }

        /* Navigation menu link standard styling and hover effects */
        .nav-dash-link {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #a3b899;
            text-decoration: none;
            padding: 0.85rem 1rem;
            border-radius: 14px;
            margin-bottom: 0.5rem;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            cursor: pointer;
            border: 1px solid transparent;
            position: relative;
            overflow: hidden;
        }
        
        .nav-dash-link:hover {
            background-color: rgba(255, 255, 255, 0.06);
            color: #ffffff;
            transform: translateX(4px);
        }
        
        /* Styles denoting the current active navigation item */
        .nav-dash-link.active {
            background-color: var(--sidebar-active);
            color: #ffffff;
            border-color: rgba(52, 211, 153, 0.35);
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.12), inset 0 1px 0 rgba(255,255,255,0.12);
        }
        
        /* Left border indicator highlighting the active tab */
        .nav-dash-link.active::before {
            content: "";
            position: absolute;
            left: 0; top: 18%;
            height: 64%; width: 4px;
            border-radius: 0 4px 4px 0;
            background: var(--mint-highlight);
            box-shadow: 0 0 12px rgba(110, 231, 183, 0.8);
        }
        .nav-dash-link i { font-size: 1.1rem; }

        .brand-logo-img {
            height: 36px;
            width: auto;
            object-fit: contain;
        }

        /* Sign-out button region at sidebar bottom */
        .sidebar-profile-footer {
            padding: 1rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            margin-top: auto;
            border: 1px solid rgba(255, 255, 255, 0.06);
            backdrop-filter: blur(6px);
        }

        /* GLASSMORPHISM CARDS (Used to match homepage/customer dashboards theme) */
        
        /* Darker card configuration */
        .stat-card-dark {
            background: linear-gradient(135deg, rgba(11, 26, 16, 0.92), rgba(5, 46, 43, 0.85)) !important;
            backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            color: #ffffff;
            border-radius: 22px;
            padding: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.12);
            box-shadow: 0 14px 34px rgba(2, 44, 34, 0.18);
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        
        .stat-card-dark:hover {
            transform: translateY(-6px);
            border-color: rgba(110, 231, 183, 0.5);
            box-shadow: 0 20px 44px rgba(2, 44, 34, 0.24);
        }

        /* Lighter card configuration */
        .stat-card-light {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.7), rgba(240, 253, 244, 0.55)) !important;
            backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border-radius: 22px;
            padding: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.7);
            box-shadow: 0 14px 34px rgba(2, 44, 34, 0.06), inset 0 1px 0 rgba(255,255,255,0.85);
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        
        .stat-card-light:hover {
            transform: translateY(-6px);
            border-color: rgba(52, 211, 153, 0.7);
            box-shadow: 0 20px 40px rgba(2, 44, 34, 0.1), inset 0 1px 0 rgba(255,255,255,0.9);
        }

        /* Number readout sizing */
        .metric-value {
            font-size: 1.85rem;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        /* Standard content card */
        .card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.7), rgba(240, 253, 244, 0.55)) !important;
            backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.7) !important;
            border-radius: 24px !important;
            box-shadow: 0 16px 40px rgba(2, 44, 34, 0.06), inset 0 1px 0 rgba(255, 255, 255, 0.8) !important;
            transition: all 0.3s ease;
        }
        .card:hover { transform: translateY(-3px); }

        /* Small widget cards containing mini analytics charts */
        .mini-chart-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.75), rgba(236, 253, 245, 0.6)) !important;
            backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.7) !important;
            border-radius: 20px !important;
            box-shadow: 0 12px 30px rgba(2, 44, 34, 0.06);
        }

        /* Smooth tab transition animations */
        .tab-pane.fade { transition: opacity 0.35s ease, transform 0.35s ease; }
        .tab-pane.fade:not(.show) { transform: translateY(8px); }

        /* Custom design for html tables */
        .custom-table th {
            background-color: rgba(46, 125, 50, 0.08) !important;
            color: #1e3a24;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
            padding: 1rem;
            border-bottom: 2px solid rgba(46, 125, 50, 0.14) !important;
        }
        .custom-table td {
            padding: 1rem;
            vertical-align: middle;
            font-size: 0.92rem;
            color: #2c3e35;
        }
        .custom-table tbody tr { transition: background 0.2s ease; }
        .custom-table tbody tr:hover { background: rgba(46, 125, 50, 0.04); }
    </style>
</head>
<body>

<div class="dashboard-wrapper">
    
    <!-- LEFT SIDEBAR VIEW MATRIX CONTROLLER -->
    <div class="sidebar-panel">
        <div>
            <!-- Branding Title -->
            <div class="d-flex align-items-center gap-2 px-2 mb-4">
                <img src="../images/LOGO.png" alt="Tharu Logo" class="brand-logo-img rounded">
                <h5 class="fw-bold mb-0 text-white font-monospace">THARU OWNER</h5>
            </div>
            <hr style="border-color: rgba(255,255,255,0.1);">
            
            <!-- Navigation links utilizing Bootstrap standard data-bs-toggle for tab control -->
            <div class="nav flex-column" id="dashboardTabs" role="tablist">
                <a class="nav-dash-link <?= $active_tab === 'panel-analytics' ? 'active' : '' ?>" id="tab-analytics" data-bs-toggle="tab" href="#panel-analytics" role="tab"><i class="bi bi-bar-chart-line-fill me-2"></i> Sales Analytics</a>
                <a class="nav-dash-link <?= $active_tab === 'panel-salary' ? 'active' : '' ?>" id="tab-salary" data-bs-toggle="tab" href="#panel-salary" role="tab"><i class="bi bi-calculator-fill me-2"></i> Salary Calculator</a>
                <a class="nav-dash-link <?= $active_tab === 'panel-status' ? 'active' : '' ?>" id="tab-status" data-bs-toggle="tab" href="#panel-status" role="tab"><i class="bi bi-credit-card-2-front-fill me-2"></i> Payment Status</a>
                <a class="nav-dash-link <?= $active_tab === 'panel-customers' ? 'active' : '' ?>" id="tab-customers" data-bs-toggle="tab" href="#panel-customers" role="tab"><i class="bi bi-people-fill me-2"></i> Customer Details</a>
                <a class="nav-dash-link <?= $active_tab === 'panel-employees' ? 'active' : '' ?>" id="tab-employees" data-bs-toggle="tab" href="#panel-employees" role="tab"><i class="bi bi-tools me-2"></i> System Users</a>
                <a class="nav-dash-link <?= $active_tab === 'panel-reports' ? 'active' : '' ?>" id="tab-reports" data-bs-toggle="tab" href="#panel-reports" role="tab"><i class="bi bi-file-earmark-bar-graph-fill me-2"></i> Reports</a>
            </div>
        </div>

        <!-- Safe sign-out anchor button -->
        <div class="sidebar-profile-footer">
            <a href="../auth/logout.php" class="text-danger text-decoration-none d-flex align-items-center gap-2 small fw-bold" style="letter-spacing: 0.3px;">
                <i class="bi bi-box-arrow-right"></i> Sign out user
            </a>
        </div>
    </div>

    <!-- MAIN DASHBOARD DATA VIEWPORT -->
    <div class="main-content">
        
        <!-- Tab Content Wrapper -->
        <div class="tab-content" id="dashboardTabContent">
            
            <!-- ================= TAB 1: SALES & PERFORMANCE METRIC WAVES ================= -->
            <div class="tab-pane fade <?= $active_tab === 'panel-analytics' ? 'show active' : '' ?>" id="panel-analytics" role="tabpanel">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h3 class="fw-bold text-dark mb-1">Executive Analytics Overview</h3>
                        <p class="text-muted small mb-0">System performance audit track compiled natively from active cycle logs.</p>
                    </div>
                </div>

                <!-- Snapshot Info Widgets block -->
                <div class="row g-4 mb-4">
                    <!-- Total Sales Indicator -->
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card-dark shadow-sm h-100">
                            <span class="text-white-50 small text-uppercase">Total Sales</span>
                            <div class="metric-value mt-1">LKR <?= number_format($total_sales, 2) ?></div>
                            <span class="text-success small fw-bold">▲ Live</span> <span class="text-white-50 small">database metric</span>
                        </div>
                    </div>
                    <!-- Computed Margin Rate -->
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card-light shadow-sm h-100">
                            <span class="text-muted small text-uppercase">Net Profit Margin</span>
                            <div class="metric-value mt-1 text-dark"><?= $profit_margin ?>%</div>
                            <span class="text-success small fw-bold">Calculated</span> <span class="text-muted small">rate</span>
                        </div>
                    </div>
                    <!-- Total recorded payouts indicator -->
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card-light shadow-sm h-100">
                            <span class="text-muted small text-uppercase">Operating Overhead</span>
                            <div class="metric-value mt-1 text-dark">LKR <?= number_format($total_expenses, 2) ?></div>
                            <span class="text-danger small fw-bold">▼ Tracked</span> <span class="text-muted small">payouts</span>
                        </div>
                    </div>
                    <!-- Overall Computed Net Balance Indicator -->
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="stat-card-light shadow-sm h-100">
                            <span class="text-muted small text-uppercase">Net Return Asset</span>
                            <div class="metric-value mt-1 text-success">LKR <?= number_format($net_profit, 2) ?></div>
                            <span class="text-success small fw-bold">✓ Net Flow</span>
                        </div>
                    </div>
                </div>

                <!-- Equal-size analytics charts row containing mini canvases -->
                <div class="row g-4 mb-4">
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="mini-chart-card p-3 h-100 d-flex flex-column">
                            <h6 class="fw-bold text-dark mb-2"><i class="bi bi-graph-up-arrow me-1 text-success"></i>Total Sales</h6>
                            <div class="flex-grow-1" style="height: 180px;"><canvas id="chartTotalSales"></canvas></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="mini-chart-card p-3 h-100 d-flex flex-column">
                            <h6 class="fw-bold text-dark mb-2"><i class="bi bi-pie-chart-fill me-1 text-success"></i>Net Profit Margin</h6>
                            <div class="flex-grow-1" style="height: 180px;"><canvas id="chartProfitMargin"></canvas></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="mini-chart-card p-3 h-100 d-flex flex-column">
                            <h6 class="fw-bold text-dark mb-2"><i class="bi bi-bar-chart-fill me-1 text-success"></i>Operating Overhead</h6>
                            <div class="flex-grow-1" style="height: 180px;"><canvas id="chartOperatingOverhead"></canvas></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="mini-chart-card p-3 h-100 d-flex flex-column">
                            <h6 class="fw-bold text-dark mb-2"><i class="bi bi-bullseye me-1 text-success"></i>Net Return Asset</h6>
                            <div class="flex-grow-1" style="height: 180px;"><canvas id="chartNetReturnAsset"></canvas></div>
                        </div>
                    </div>
                </div>

                <!-- Large Multi-line Wave Graph -->
                <div class="card border-0 shadow-sm p-4 rounded-4 mb-4">
                    <h5 class="fw-bold text-dark mb-4">Sales vs Expense Waves Summary</h5>
                    <div style="height: 350px; width: 100%;">
                        <canvas id="waveAnalyticsChart"></canvas>
                    </div>
                </div>

                <!-- Transaction lists (Sales and Expenses Tables) -->
                <div class="row g-4">
                    <!-- Sales detailed list table -->
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
                    <!-- Expenses detailed list table -->
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

                    <!-- Alert boxes for script processing feedback -->
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

                    <div class="row g-4">
                        <!-- SECTION 1: OVERTIME (OT) CALCULATOR -->
                        <div class="col-lg-6">
                            <div class="p-4 rounded-4 border bg-white shadow-sm h-100">
                                <h5 class="fw-bold text-success mb-3"><i class="bi bi-clock-history me-2"></i>Calculate & Record OT Amount</h5>
                                <form action="owner_dashboard.php?tab=panel-salary" method="POST">
                                    <input type="hidden" name="action" value="record_ot_amount">
                                    <input type="hidden" name="tab" value="panel-salary">
                                    
                                    <!-- Employee selector, triggers JS to update variables across forms -->
                                    <div class="mb-3">
                                        <label class="form-label small fw-bold text-dark">Target Employee Operator</label>
                                        <select class="form-select border employeeSelectClass" id="employeeSelect" name="employee_id" onchange="syncEmployeeSelections(this.value); loadMonthlySalaryDetails();" required>
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

                                    <!-- Attendance Log Selector; fetches log data automatically on pick -->
                                    <div class="mb-3">
                                        <label class="form-label small fw-bold text-secondary">Attendance Record</label>
                                        <select class="form-select border" id="attendanceSelect" name="attendance_id" onchange="loadAttendanceData()" required>
                                            <option value="">-- Choose Attendance Record --</option>
                                            <?php foreach ($attendance_rows as $attendance): ?>
                                                <option value="<?= (int)($attendance['attendanceID'] ?? 0) ?>"
                                                        data-login="<?= htmlspecialchars($attendance['login'] ?? '') ?>"
                                                        data-logout="<?= htmlspecialchars($attendance['logout'] ?? '') ?>"
                                                        data-date="<?= htmlspecialchars($attendance['date'] ?? '') ?>"
                                                        data-accountant-userid="<?= (int)($attendance['id'] ?? 0) ?>"
                                                        data-accountant="<?= htmlspecialchars($attendance['accountantname'] ?? '') ?>"
                                                        data-base-salary="<?= number_format((float)($attendance['base_salary'] ?? 0), 2, '.', '') ?>"
                                                        data-ot-rate="<?= number_format((float)($attendance['OT_rate'] ?? 0), 2, '.', '') ?>">
                                                    <?= htmlspecialchars($attendance['date'] ?? '') ?> | <?= htmlspecialchars($attendance['accountantname'] ?? '') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- Readonly readout for chosen date details -->
                                    <div class="mb-3">
                                        <label class="form-label small fw-bold text-secondary">Login / Logout Times</label>
                                        <input type="text" id="attendanceSummary" class="form-control bg-light" readonly>
                                    </div>

                                    <!-- Hourly computation readouts computed via JS -->
                                    <div class="row g-2 mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted">OT Rate Per Hour (LKR)</label>
                                            <input type="number" id="displayOtRate" class="form-control bg-light" name="ot_rate_display" value="0" readonly>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted">OT Hours Worked</label>
                                            <input type="number" id="otHours" class="form-control" name="ot_hours" value="0" step="0.01" oninput="calculateOtAmount()" readonly>
                                        </div>
                                    </div>

                                    <!-- Bold display of generated OT money equivalent -->
                                    <div class="p-3 rounded-3 text-start mb-3" style="background-color: var(--mint-light);">
                                        <h6 class="text-success fw-bold text-uppercase mb-1 small">Calculated OT Amount</h6>
                                        <div class="fs-4 fw-bold text-dark" id="displayOtAmount">LKR 0.00</div>
                                        <input type="hidden" id="otPayHidden" name="ot_pay" value="0">
                                    </div>

                                    <button type="submit" class="btn btn-success w-100 py-2 fw-bold rounded-pill shadow-sm">Record OT Amount</button>
                                </form>
                            </div>
                        </div>

                        <!-- SECTION 2: MONTHLY SALARY CALCULATOR -->
                        <div class="col-lg-6">
                            <div class="p-4 rounded-4 border bg-white shadow-sm h-100">
                                <h5 class="fw-bold text-success mb-3"><i class="bi bi-calendar-check me-2"></i>Calculate & Record Monthly Salary</h5>
                                <form action="owner_dashboard.php?tab=panel-salary" method="POST">
                                    <input type="hidden" name="action" value="record_total_salary">
                                    <input type="hidden" name="tab" value="panel-salary">
                                    
                                    <!-- Employee selector, synchronized across tabs -->
                                    <div class="mb-3">
                                        <label class="form-label small fw-bold text-dark">Target Employee Operator</label>
                                        <select class="form-select border employeeSelectClass" id="employeeSelectMonthly" name="employee_id" onchange="syncEmployeeSelections(this.value); loadMonthlySalaryDetails();" required>
                                            <option value="">-- Choose Active Staff Member --</option>
                                            <?php foreach ($staff_members as $member): ?>
                                                <?php if ((int)($member['accountantID'] ?? 0) > 0): ?>
                                                    <option value="<?= (int)($member['id'] ?? 0) ?>">
                                                        <?= htmlspecialchars($member['username']) ?> (<?= htmlspecialchars($member['role'] ?? 'Staff') ?>)
                                                    </option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- Parameter selections: trigger AJAX refresh when month is changed -->
                                    <div class="row g-2 mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold text-secondary">Choose Month</label>
                                            <input type="month" id="salaryMonth" name="salary_month" class="form-control" value="<?= date('Y-m') ?>" onchange="loadMonthlySalaryDetails()" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold text-secondary">Pay Date</label>
                                            <input type="date" name="paydate" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                        </div>
                                    </div>

                                    <!-- View fields populated automatically by AJAX -->
                                    <div class="row g-2 mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted">Base Salary (LKR)</label>
                                            <input type="number" id="monthlyBaseSalary" class="form-control bg-light" value="0" readonly>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted">Total OT for Month (LKR)</label>
                                            <input type="number" id="monthlyOtTotal" class="form-control bg-light" value="0" readonly>
                                        </div>
                                    </div>

                                    <!-- Admin configurable bonus entry field -->
                                    <div class="mb-3">
                                        <label class="form-label small text-muted">Performance Bonus (LKR)</label>
                                        <input type="number" id="monthlyBonus" class="form-control" name="bonus_pay" value="0" oninput="calculateMonthlyTotal()">
                                    </div>

                                    <!-- Sum total readout interface display -->
                                    <div class="p-3 rounded-3 text-start mb-3" style="background-color: var(--mint-light);">
                                        <h6 class="text-success fw-bold text-uppercase mb-1 small">Total Salary for Month</h6>
                                        <div class="fs-3 fw-bold text-dark" id="displayMonthlyTotal">LKR 0.00</div>
                                        <input type="hidden" id="monthlyTotalHidden" name="total_salary" value="0">
                                    </div>

                                    <button type="submit" class="btn btn-success w-100 py-2 fw-bold rounded-pill shadow-sm">Calculate & Record Monthly Salary</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ================= TAB 3: MONTHLY PROFIT REPORTS ================= -->
            <div class="tab-pane fade <?= $active_tab === 'panel-reports' ? 'show active' : '' ?>" id="panel-reports" role="tabpanel">
                <div class="card border-0 shadow-sm p-4 rounded-4">
                    <h5 class="fw-bold text-dark mb-3"><i class="bi bi-file-earmark-bar-graph-fill me-2 text-success"></i>Monthly Profit Reports</h5>

                    <!-- User status messaging -->
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

                    <!-- Form logic generating backend report variables via POST cycle -->
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
                        
                        <!-- File type desired selection input box -->
                        <div class="col-md-3">
                            <select class="form-select" id="reportExportFormat" name="download_format">
                                <option value="pdf" <?= $download_format === 'pdf' ? 'selected' : '' ?>>Download PDF</option>
                                <option value="xlsx" <?= $download_format === 'xlsx' ? 'selected' : '' ?>>Download XLSX</option>
                                <option value="png" <?= $download_format === 'png' ? 'selected' : '' ?>>Download PNG</option>
                                <option value="jpeg" <?= $download_format === 'jpeg' ? 'selected' : '' ?>>Download JPEG</option>
                            </select>
                        </div>
                    </form>

                    <!-- Dynamically renders the preview window only if generation returned valid data rows -->
                    <?php if ($report_generated): ?>
                        
                        <!-- Manual backup download triggers relying on frontend extraction methods -->
                        <div class="d-flex flex-wrap justify-content-end gap-2 mb-3">
                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="exportReport('pdf')">Export PDF</button>
                            <button type="button" class="btn btn-outline-success btn-sm" onclick="exportReport('xlsx')">Export XLSX</button>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="exportReport('png')">Export PNG</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="exportReport('jpeg')">Export JPEG</button>
                        </div>
                        
                        <!-- Container identified as target for HTML-to-Image / PDF cloning libraries -->
                        <div id="reportPreview" class="border rounded-4 p-4 mt-3" style="background: linear-gradient(135deg, #f7fff7 0%, #ffffff 100%);">
                            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                                <div>
                                    <h6 class="fw-bold text-dark mb-1">Monthly Profit Report</h6>
                                    <div class="text-muted small">Generated for <?= htmlspecialchars($selected_report_month) ?></div>
                                </div>
                            </div>
                            
                            <!-- Financial overview cards subset -->
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
                            
                            <!-- Tabular presentation blocks inside PDF template window -->
                            <div class="row g-4">
                                <div class="col-lg-8">
                                    <!-- Dedicated computed Profit readout -->
                                    <div class="card border-0 p-3 rounded-3" style="background-color: var(--mint-light);">
                                        <div class="small text-uppercase text-success fw-bold">Net Profit</div>
                                        <div class="fs-3 fw-bold text-dark">LKR <?= number_format($report_net_profit, 2) ?></div>
                                    </div>
                                    
                                    <!-- Generated Sales table chunk -->
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
                                    
                                    <!-- Generated Expenses table chunk -->
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
                                <!-- Chart.js canvas targeted rendering location -->
                                <div class="col-lg-4">
                                    <canvas id="profitReportChart" height="240"></canvas>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Bottom log list: Previous historical reports generated by users -->
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
                        <!-- Displays flat listing mapping out successfully distributed payment entries -->
                        <table class="table custom-table border align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Log Key Identity</th>
                                    <th>Corporate Operator Identity</th>
                                    <th>Total Authorized Pay</th>
                                    <th>Pay Date</th>
                                    <th>Settlement Status Matrix</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($payroll_history)): ?>
                                    <tr><td colspan="5" class="text-center text-muted">No historical database payouts found.</td></tr>
                                <?php else: ?>
                                    <?php foreach($payroll_history as $row): ?>
                                        <tr>
                                            <td><span class="font-monospace fw-bold">#PAY-00<?= (int)($row['id'] ?? 0) ?></span></td>
                                            <td><?= htmlspecialchars($row['username'] ?? '') ?></td>
                                            <td>LKR <?= number_format((float)($row['total_paid'] ?? 0), 2) ?></td>
                                            <td><span class="font-monospace"><?= htmlspecialchars($row['paydate'] ?? '') ?></span></td>
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
                        <!-- Presents raw customer metric variables for easy lookup mapping -->
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

                    <!-- Role adjustment action status alerts context layout -->
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
                                            <!-- Inline form handling role modification -->
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
                                            
                                            <!-- Dedicated GET link deletion executioner tool block -->
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
/** 
 * DOM Scripting & Frontend Processing Layer
 * Includes Salary form handling, DOM manipulation hooks, and data calculations routines.
 */
let currentOtHours = 0;
let currentOtRate = 0;

/**
 * Standardizes raw HH:MM:SS string parsing into JavaScript Date objects.
 * Returns null if format fails layout check tests.
 */
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
    // Base a dummy date for reliable milliseconds extraction
    return new Date(2000, 0, 1, hours, minutes, seconds);
}

/**
 * Calculates time differences identifying surplus hours past standard 8-hour shift structure bounds.
 */
function calculateOvertimeHours(loginValue, logoutValue) {
    const loginTime = parseTimeValue(loginValue);
    const logoutTime = parseTimeValue(logoutValue);
    if (!loginTime || !logoutTime) {
        return 0;
    }
    let workedMs = logoutTime.getTime() - loginTime.getTime();
    
    // Correct overflow error if shift wraps night cycle to next morning frame
    if (workedMs < 0) {
        workedMs += 24 * 60 * 60 * 1000;
    }
    const workedHours = workedMs / (1000 * 60 * 60);
    const overtimeHours = workedHours > 8 ? workedHours - 8 : 0;
    
    return overtimeHours > 0 ? overtimeHours : 0;
}

/**
 * Replicates active dropdown choice state across mirrored selection elements
 */
function syncEmployeeSelections(val) {
    const selects = document.querySelectorAll('.employeeSelectClass');
    selects.forEach(s => {
        if (s.value !== val) {
            s.value = val;
        }
    });
}

/**
 * Executes field hydration upon attendance selector alteration inside OT sub-calculator
 */
function loadAttendanceData() {
    const select = document.getElementById('attendanceSelect');
    const selectedOption = select.options[select.selectedIndex];
    
    // Clear out readouts if user chooses null option layout
    if (!selectedOption || !selectedOption.value) {
        document.getElementById('attendanceSummary').value = '';
        document.getElementById('displayOtRate').value = 0;
        document.getElementById('otHours').value = 0;
        calculateOtAmount();
        return;
    }

    const login = selectedOption.getAttribute('data-login') || '';
    const logout = selectedOption.getAttribute('data-logout') || '';
    const date = selectedOption.getAttribute('data-date') || '';
    const accountName = selectedOption.getAttribute('data-accountant') || '';
    const otRate = parseFloat(selectedOption.getAttribute('data-ot-rate')) || 0;
    
    // Automatically trigger matched index profile across mirrored fields layout
    const accountantUserId = selectedOption.getAttribute('data-accountant-userid');
    if (accountantUserId) {
        syncEmployeeSelections(accountantUserId);
        loadMonthlySalaryDetails();
    }

    document.getElementById('attendanceSummary').value = date + ' | ' + login + ' - ' + logout + ' | ' + accountName;
    document.getElementById('displayOtRate').value = otRate.toFixed(2);
    
    const calculatedOtHours = calculateOvertimeHours(login, logout);
    document.getElementById('otHours').value = calculatedOtHours.toFixed(2);
    calculateOtAmount();
}

/**
 * Drives UI rendering metric for computed OT monetary payload
 */
function calculateOtAmount() {
    const otRate = parseFloat(document.getElementById('displayOtRate').value) || 0;
    const otHours = parseFloat(document.getElementById('otHours').value) || 0;
    const otAmount = otRate * otHours;
    
    document.getElementById('displayOtAmount').innerText = 'LKR ' + otAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('otPayHidden').value = otAmount.toFixed(2);
}

/**
 * Requests backend salary configuration blocks asynchronously via native Fetch API.
 * Pulls cumulative sum total structures of prior database iterations.
 */
function loadMonthlySalaryDetails() {
    const employeeId = document.getElementById('employeeSelectMonthly').value;
    const month = document.getElementById('salaryMonth').value;
    
    if (!employeeId) {
        document.getElementById('monthlyBaseSalary').value = 0;
        document.getElementById('monthlyOtTotal').value = 0;
        calculateMonthlyTotal();
        return;
    }
    
    // Calls embedded AJAX logic controller defined at document top lines execution scope
    fetch(`owner_dashboard.php?ajax_action=get_salary_details&employee_id=${employeeId}&month=${month}`)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                document.getElementById('monthlyBaseSalary').value = data.base_salary.toFixed(2);
                document.getElementById('monthlyOtTotal').value = data.sum_ot.toFixed(2);
            } else {
                document.getElementById('monthlyBaseSalary').value = 0;
                document.getElementById('monthlyOtTotal').value = 0;
            }
            calculateMonthlyTotal();
        })
        .catch(err => {
            console.error(err);
            calculateMonthlyTotal();
        });
}

/**
 * Handles additive math calculation logic rendering for total monthly payout matrix forms
 */
function calculateMonthlyTotal() {
    const base = parseFloat(document.getElementById('monthlyBaseSalary').value) || 0;
    const ot = parseFloat(document.getElementById('monthlyOtTotal').value) || 0;
    const bonus = parseFloat(document.getElementById('monthlyBonus').value) || 0;
    
    const total = base + ot + bonus;
    document.getElementById('displayMonthlyTotal').innerText = 'LKR ' + total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('monthlyTotalHidden').value = total.toFixed(2);
}

/**
 * Facilitates the generation block converting HTML rendering canvas views directly into standalone files.
 * Uses html2canvas, jsPDF, and sheetJS routines attached previously in head scripts section.
 * 
 * @param {string} format - The file formatting profile rule extension identifier string.
 */
function exportReport(format) {
    const preview = document.getElementById('reportPreview');
    if (!preview) {
        alert('Generate a report first so the preview can be exported.');
        return;
    }

    const monthValue = document.querySelector('input[name="report_month"]')?.value || 'report';
    const filename = `profit-report-${monthValue}`;
    const reportData = window.reportExportData || { salesTotal: 0, expensesTotal: 0, netProfit: 0, salesRows: [], expenseRows: [] };

    // Handler logic branch translating database lists towards XLSX Excel format spreadsheet objects
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
        
        // Execute structural render map translations building binary outputs natively via sheet.js logic
        const ws = XLSX.utils.aoa_to_sheet(rows);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Report');
        XLSX.writeFile(wb, `${filename}.xlsx`);
        return;
    }

    // Capture visual representation using html2canvas rendering engine module hooks
    html2canvas(preview, { scale: 2, backgroundColor: '#ffffff', useCORS: true }).then(function (canvas) {
        const imageUrl = canvas.toDataURL(format === 'jpeg' ? 'image/jpeg' : 'image/png');
        
        // Push standalone images via hidden link nodes clicked explicitly
        if (format === 'png' || format === 'jpeg') {
            const link = document.createElement('a');
            link.href = imageUrl;
            link.download = `${filename}.${format}`;
            link.click();
            return;
        }

        // Compute page wrapping offsets ensuring clean pagination splitting rules for jsPDF execution flow blocks
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
        
        // Inject multiple pages traversing down layout until exhausted layout pixels
        while (heightLeft > 0) {
            position = heightLeft - imgHeight + 10;
            pdf.addPage();
            pdf.addImage(imageUrl, 'PNG', 10, position, imgWidth, imgHeight);
            heightLeft -= pageHeight - 20;
        }
        
        pdf.save(`${filename}.pdf`);
    });
}

/** 
 * Chart.js Graphic Layout Initialization Sequence Engine
 * Instantiates responsive chart context layers applying predefined theme settings logic structures based on metrics variables
 */
document.addEventListener("DOMContentLoaded", function () {
    // Preserve variables inside Javascript Object namespace to guarantee export utilities can consume data structures safely
    window.reportExportData = {
        salesTotal: <?= json_encode($report_sales_total) ?>,
        expensesTotal: <?= json_encode($report_expenses_total) ?>,
        netProfit: <?= json_encode($report_net_profit) ?>,
        salesRows: <?= json_encode($report_sales_rows) ?>,
        expenseRows: <?= json_encode($report_expense_rows) ?>
    };

    // Keep tabs preserved when reloading page contexts using URL hash references tracking
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

    // Chart #1 - Primary Area Wave Chart
    const analyticsCtx = document.getElementById('waveAnalyticsChart');
    if (analyticsCtx) {
        const ctx = analyticsCtx.getContext('2d');
        
        // Define color gradients rules rendering visual fading transitions
        const gradientSales = ctx.createLinearGradient(0, 0, 0, 350);
        gradientSales.addColorStop(0, 'rgba(46, 125, 50, 0.45)');
        gradientSales.addColorStop(1, 'rgba(46, 125, 50, 0.00)');

        const gradientExpenses = ctx.createLinearGradient(0, 0, 0, 350);
        gradientExpenses.addColorStop(0, 'rgba(231, 76, 60, 0.15)');
        gradientExpenses.addColorStop(1, 'rgba(231, 76, 60, 0.00)');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode($wave_labels) ?>,
                datasets: [
                    {
                        label: 'Sales Revenue Track',
                        data: <?= json_encode($wave_sales) ?>,
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
                        data: <?= json_encode($wave_expenses) ?>,
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

    // Chart #2: Total Sales Mini Bar Chart
    const tsCtx = document.getElementById('chartTotalSales');
    if (tsCtx) {
        new Chart(tsCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($sales_chart_labels) ?>,
                datasets: [{
                    data: <?= json_encode($sales_chart_values) ?>,
                    backgroundColor: 'rgba(52, 211, 153, 0.75)',
                    borderColor: 'rgba(52, 211, 153, 1)',
                    borderWidth: 1,
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { enabled: true } },
                scales: {
                    x: { display: false },
                    y: { display: false }
                }
            }
        });
    }

    // Chart #3: Net Profit Margin Mini Doughnut Chart
    const pmCtx = document.getElementById('chartProfitMargin');
    if (pmCtx) {
        new Chart(pmCtx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Profit %', 'Cost %'],
                datasets: [{
                    data: [<?= max(0, $profit_margin) ?>, <?= max(0, 100 - $profit_margin) ?>],
                    backgroundColor: ['#2e7d32', 'rgba(231, 76, 60, 0.25)'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: { legend: { display: false }, tooltip: { enabled: true } }
            }
        });
    }

    // Chart #4: Operating Overhead Mini Bar Chart
    const ohCtx = document.getElementById('chartOperatingOverhead');
    if (ohCtx) {
        new Chart(ohCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($expense_chart_labels) ?>,
                datasets: [{
                    data: <?= json_encode($expense_chart_values) ?>,
                    backgroundColor: 'rgba(231, 76, 60, 0.7)',
                    borderColor: 'rgba(231, 76, 60, 1)',
                    borderWidth: 1,
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { enabled: true } },
                scales: {
                    x: { display: false },
                    y: { display: false }
                }
            }
        });
    }

    // Chart #5: Net Return Asset Mini Doughnut Chart
    const raCtx = document.getElementById('chartNetReturnAsset');
    if (raCtx) {
        new Chart(raCtx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Net Return', 'Overhead'],
                datasets: [{
                    data: [<?= max(0, $net_profit) ?>, <?= max(0, $total_expenses) ?>],
                    backgroundColor: ['#4caf50', '#ff9800'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: { legend: { display: false }, tooltip: { enabled: true } }
            }
        });
    }

    // Chart #6: Report Export PDF Visual Chart
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

    // Bootstrap Tab Activation Fallback Routing Logic Map Check
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

    // Automated trigger downloading reports directly after post reload frame completions when necessary flags persist set 
    if (window.reportExportData && <?= json_encode($report_generated) ?> && <?= json_encode($auto_download_format) ?>) {
        setTimeout(function () {
            exportReport(<?= json_encode($auto_download_format) ?>);
        }, 400); // Wait milliseconds permitting UI Canvas to paint first
    }
});
</script>

<!-- Scripts loading core Bootstrap JavaScript functionalities matching library requirements -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>