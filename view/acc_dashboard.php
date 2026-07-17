<?php
session_start();
require_once __DIR__ . '/../model/config/database.php';

// Access Control Validation Strategy
if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'accountant' || !isset($_SESSION['accountant_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$conn = getDBConnection();
$accountantID = $_SESSION['accountant_id'];

$error_message = "";
$success_message = "";

// Attendance Helper: resolve role-specific foreign keys from a selected user
function resolveAttendanceEmployeeReference($conn, $userID) {
    $stmt = $conn->prepare(
        "SELECT u.userID, u.role,
                s.stocksupID, ss.salessupID, w.workerID, d.driverID, ac.accountantID
         FROM user_tbl u
         LEFT JOIN StockSuperviser_tbl s ON u.userID = s.userID
         LEFT JOIN SalesSuperviser_tbl ss ON u.userID = ss.userID
         LEFT JOIN Worker_tbl w ON u.userID = w.userID
         LEFT JOIN Driver_tbl d ON u.userID = d.userID
         LEFT JOIN Accountant_tbl ac ON u.userID = ac.userID
         WHERE u.userID = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $userID);
    $stmt->execute();
    $result = $stmt->get_result();
    $employee = $result->fetch_assoc();
    $stmt->close();
    return $employee;
}

// FORM PROCESSOR 1: SALARY RUN CALCULATION 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'calculate_salary') {
    $attendanceID = intval($_POST['attendance_id'] ?? 0);
    $employeeID = intval($_POST['employee_id'] ?? 0);
    $paydate = trim($_POST['paydate'] ?? '');
    $base_pay = floatval($_POST['base_pay'] ?? 0);
    $ot_pay = floatval($_POST['ot_pay'] ?? 0);
    $bonus_pay = floatval($_POST['bonus_pay'] ?? 0);
    $total_amount_paid = $base_pay + $ot_pay + $bonus_pay;

    if ($attendanceID <= 0 || $employeeID <= 0 || empty($paydate)) {
        $error_message = "Please select a supervisor, attendance record, and payment date.";
    } elseif ($base_pay < 0 || $ot_pay < 0 || $bonus_pay < 0) {
        $error_message = "Salary values must be valid non-negative numbers.";
    } else {
        $query = "
            SELECT a.attendanceID,
                   COALESCE(s.userID, ss.userID, w.userID, d.userID) AS userID,
                   COALESCE(s.base_salary, ss.base_salary, 0) AS base_salary,
                   COALESCE(s.OT_rate, ss.OT_rate, 0) AS ot_rate,
                   w.hour_rate AS hour_rate,
                   d.fixed_salary AS fixed_salary,
                   COALESCE(su.username, ssu.username, wu.username, du.username) AS emp_name,
                   CASE 
                     WHEN s.stocksupID IS NOT NULL THEN 'Stock Supervisor' 
                     WHEN ss.salessupID IS NOT NULL THEN 'Sales Supervisor' 
                     WHEN w.workerID IS NOT NULL THEN 'Worker' 
                     WHEN d.driverID IS NOT NULL THEN 'Driver' 
                   END AS emp_role
            FROM attendance_tbl a
            LEFT JOIN StockSuperviser_tbl s ON a.stocksupID = s.stocksupID
            LEFT JOIN user_tbl su ON s.userID = su.userID
            LEFT JOIN SalesSuperviser_tbl ss ON a.salessupID = ss.salessupID
            LEFT JOIN user_tbl ssu ON ss.userID = ssu.userID
            LEFT JOIN Worker_tbl w ON a.workerID = w.workerID
            LEFT JOIN user_tbl wu ON w.userID = wu.userID
            LEFT JOIN Driver_tbl d ON a.driverID = d.driverID
            LEFT JOIN user_tbl du ON d.userID = du.userID
            WHERE a.attendanceID = ? AND COALESCE(s.userID, ss.userID, w.userID, d.userID) = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("ii", $attendanceID, $employeeID);
        $stmt->execute();
        $employee = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$employee) {
            $error_message = "Selected attendance does not belong to the chosen supervisor.";
        } else {
            $emp_display_name = $employee['emp_name'] ?: 'Supervisor';
            $emp_role_label = $employee['emp_role'] ?: 'Supervisor';
            $employeeUserID = $employee['userID'];
            $emp_base_salary = $employee['base_salary'];

            // For supervisors: check if already paid this month; if so, exclude base_salary
            $adjusted_base_pay = $base_pay;
            $baseSalaryExcluded = false;
            if (in_array($emp_role_label, ['Stock Supervisor', 'Sales Supervisor'], true)) {
                // Check for existing salary payment this month for this employee
                $currentMonthStart = date('Y-m-01');
                $currentMonthEnd = date('Y-m-t');
                $checkStmt = $conn->prepare(
                    "SELECT COUNT(*) as count FROM salary_tbl s
                     JOIN attendance_tbl a ON s.attendanceID = a.attendanceID
                     WHERE s.paydate BETWEEN ? AND ?
                     AND (a.stocksupID = (SELECT stocksupID FROM StockSuperviser_tbl WHERE userID = ?)
                          OR a.salessupID = (SELECT salessupID FROM SalesSuperviser_tbl WHERE userID = ?))"
                );
                $checkStmt->bind_param("ssii", $currentMonthStart, $currentMonthEnd, $employeeUserID, $employeeUserID);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result()->fetch_assoc();
                $checkStmt->close();

                // If already paid this month, exclude base salary
                if ($checkResult['count'] > 0) {
                    $adjusted_base_pay = 0;
                    $baseSalaryExcluded = true;
                }
            }

            $total_amount_paid = $adjusted_base_pay + $ot_pay + $bonus_pay;

            $conn->begin_transaction();
            try {
                $salStmt = $conn->prepare("INSERT INTO salary_tbl (paydate, totamtpaid, attendanceID, accountantID) VALUES (?, ?, ?, ?)");
                $salStmt->bind_param("sdii", $paydate, $total_amount_paid, $attendanceID, $accountantID);
                $salStmt->execute();
                $salStmt->close();

                // Format expense type based on payment components
                $expenseTypeLabel = "";
                if ($adjusted_base_pay > 0) {
                    $expenseTypeLabel = "salary-" . $emp_role_label;
                } elseif ($ot_pay > 0) {
                    $expenseTypeLabel = "OT-" . $emp_role_label;
                } else {
                    $expenseTypeLabel = "bonus-" . $emp_role_label;
                }
                
                // If bonus is included, append bonus indicator
                if ($bonus_pay > 0 && $adjusted_base_pay > 0) {
                    $expenseTypeLabel = "salary-bonus-" . $emp_role_label;
                } elseif ($bonus_pay > 0 && $ot_pay > 0) {
                    $expenseTypeLabel = "OT-bonus-" . $emp_role_label;
                }

                $expStmt = $conn->prepare("INSERT INTO expense_tbl (type, amount, accountantID, materialID) VALUES (?, ?, ?, NULL)");
                $expStmt->bind_param("sdi", $expenseTypeLabel, $total_amount_paid, $accountantID);
                $expStmt->execute();
                $expStmt->close();

                $conn->commit();
                $payTypeNote = ($baseSalaryExcluded) ? " [OT + Bonuses only — base salary paid earlier this month]" : "";
                $success_message = "Payroll allocated cleanly! " . number_format($total_amount_paid, 2) . " LKR disbursed and tracked in operational expenses." . $payTypeNote;
            } catch (Exception $e) {
                $conn->rollback();
                $error_message = "Relational engine rejected insert operation: " . $e->getMessage();
            }
        }
    }
}
// FORM PROCESSOR 2: MANUAL EXPENSE MANIFESTS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'log_expense') {
    $expense_type = trim($_POST['expense_type']);
    $custom_type = trim($_POST['custom_expense_type'] ?? '');
    $amount = floatval($_POST['amount']);

    $final_type = ($expense_type === 'Other' && !empty($custom_type)) ? $custom_type : $expense_type;

    if (!empty($final_type) && $amount > 0) {
        $stmt = $conn->prepare("INSERT INTO expense_tbl (type, amount, accountantID, materialID) VALUES (?, ?, ?, NULL)");
        $stmt->bind_param("sdi", $final_type, $amount, $accountantID);
        if ($stmt->execute()) {
            $success_message = "Manual overhead profile parsed and indexed correctly.";
        } else {
            $error_message = "Fault in manual ledger population sequence execution.";
        }
        $stmt->close();
    } else {
        $error_message = "Please complete all core description metrics correctly.";
    }
}

// FORM PROCESSOR 3: ATTENDANCE CRUD OPERATIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_attendance') {
    $attDate = trim($_POST['attendance_date'] ?? '');
    $loginTime = trim($_POST['login_time'] ?? '');
    $logoutTime = trim($_POST['logout_time'] ?? '');
    $selectedUserID = intval($_POST['attendance_user_id'] ?? 0);

    if (empty($attDate) || empty($loginTime) || empty($logoutTime) || $selectedUserID <= 0) {
        $error_message = "Please fill in date, login, logout and select an employee.";
    } else {
        $employeeRef = resolveAttendanceEmployeeReference($conn, $selectedUserID);
        if (!$employeeRef) {
            $error_message = "Selected employee is not valid for attendance tracking.";
        } else {
            $stocksupID = null;
            $salessupID = null;
            $workerID = null;
            $driverID = null;
            $acctID = null;

            switch (strtolower($employeeRef['role'])) {
                case 'stocksup':
                    $stocksupID = $employeeRef['stocksupID'];
                    break;
                case 'salessup':
                    $salessupID = $employeeRef['salessupID'];
                    break;
                case 'worker':
                    $workerID = $employeeRef['workerID'];
                    break;
                case 'driver':
                    $driverID = $employeeRef['driverID'];
                    break;
                case 'accountant':
                    $acctID = $employeeRef['accountantID'];
                    break;
                default:
                    $error_message = "Selected employee role cannot be assigned attendance.";
            }

            if (empty($error_message)) {
                $stmt = $conn->prepare(
                    "INSERT INTO attendance_tbl (date, login, logout, stocksupID, salessupID, workerID, driverID, accountantID) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param('sssiiiii', $attDate, $loginTime, $logoutTime, $stocksupID, $salessupID, $workerID, $driverID, $acctID);
                if ($stmt->execute()) {
                    $success_message = "Attendance record added successfully.";
                } else {
                    $error_message = "Failed to insert attendance record: " . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_attendance') {
    $attendanceID = intval($_POST['attendance_id'] ?? 0);
    $attDate = trim($_POST['attendance_date'] ?? '');
    $loginTime = trim($_POST['login_time'] ?? '');
    $logoutTime = trim($_POST['logout_time'] ?? '');
    $selectedUserID = intval($_POST['attendance_user_id'] ?? 0);

    if ($attendanceID <= 0 || empty($attDate) || empty($loginTime) || empty($logoutTime) || $selectedUserID <= 0) {
        $error_message = "Please provide valid attendance details for update.";
    } else {
        $employeeRef = resolveAttendanceEmployeeReference($conn, $selectedUserID);
        if (!$employeeRef) {
            $error_message = "Selected employee is not valid for attendance tracking.";
        } else {
            $stocksupID = null;
            $salessupID = null;
            $workerID = null;
            $driverID = null;
            $acctID = null;

            switch (strtolower($employeeRef['role'])) {
                case 'stocksup':
                    $stocksupID = $employeeRef['stocksupID'];
                    break;
                case 'salessup':
                    $salessupID = $employeeRef['salessupID'];
                    break;
                case 'worker':
                    $workerID = $employeeRef['workerID'];
                    break;
                case 'driver':
                    $driverID = $employeeRef['driverID'];
                    break;
                case 'accountant':
                    $acctID = $employeeRef['accountantID'];
                    break;
                default:
                    $error_message = "Selected employee role cannot be assigned attendance.";
            }

            if (empty($error_message)) {
                $stmt = $conn->prepare(
                    "UPDATE attendance_tbl SET date = ?, login = ?, logout = ?, stocksupID = ?, salessupID = ?, workerID = ?, driverID = ?, accountantID = ? WHERE attendanceID = ?"
                );
                $stmt->bind_param('sssiiiiii', $attDate, $loginTime, $logoutTime, $stocksupID, $salessupID, $workerID, $driverID, $acctID, $attendanceID);
                if ($stmt->execute()) {
                    $success_message = "Attendance record updated successfully.";
                } else {
                    $error_message = "Failed to update attendance record: " . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_attendance') {
    $attendanceID = intval($_POST['attendance_id'] ?? 0);
    if ($attendanceID <= 0) {
        $error_message = "Invalid attendance record selected for deletion.";
    } else {
        $stmt = $conn->prepare("DELETE FROM attendance_tbl WHERE attendanceID = ?");
        $stmt->bind_param('i', $attendanceID);
        if ($stmt->execute()) {
            $success_message = "Attendance record deleted successfully.";
        } else {
            $error_message = "Unable to delete attendance record: " . $stmt->error;
        }
        $stmt->close();
    }
}

// FORM PROCESSOR 4: SALARY RECORD EDIT / DELETE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_salary') {
    $salaryID = intval($_POST['salary_id']);
    $paydate = trim($_POST['paydate']);
    $amount = floatval($_POST['amount']);

    if (!empty($paydate) && $amount > 0) {
        $stmt = $conn->prepare("UPDATE salary_tbl SET paydate = ?, totamtpaid = ? WHERE salaryID = ? AND accountantID = ?");
        $stmt->bind_param("sdii", $paydate, $amount, $salaryID, $accountantID);
        if ($stmt->execute()) {
            $success_message = "Salary record updated successfully.";
        } else {
            $error_message = "Unable to update salary record: " . $conn->error;
        }
        $stmt->close();
    } else {
        $error_message = "Please provide a valid date and amount for salary update.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_salary') {
    $salaryID = intval($_POST['salary_id']);
    $stmt = $conn->prepare("DELETE FROM salary_tbl WHERE salaryID = ? AND accountantID = ?");
    $stmt->bind_param("ii", $salaryID, $accountantID);
    if ($stmt->execute()) {
        $success_message = "Salary record deleted successfully.";
    } else {
        $error_message = "Unable to delete salary record: " . $conn->error;
    }
    $stmt->close();
}

// FORM PROCESSOR 4: EXPENSE RECORD EDIT / DELETE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_expense') {
    $expenseID = intval($_POST['expense_id']);
    $expense_type = trim($_POST['expense_type']);
    $custom_type = trim($_POST['custom_expense_type'] ?? '');
    $amount = floatval($_POST['amount']);

    $final_type = ($expense_type === 'Other' && !empty($custom_type)) ? $custom_type : $expense_type;
    if (!empty($final_type) && $amount > 0) {
        $stmt = $conn->prepare("UPDATE expense_tbl SET type = ?, amount = ? WHERE expenseID = ? AND accountantID = ?");
        $stmt->bind_param("sdii", $final_type, $amount, $expenseID, $accountantID);
        if ($stmt->execute()) {
            $success_message = "Expense record updated successfully.";
        } else {
            $error_message = "Unable to update expense record: " . $conn->error;
        }
        $stmt->close();
    } else {
        $error_message = "Please provide a valid expense description and amount.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_expense') {
    $expenseID = intval($_POST['expense_id']);
    $stmt = $conn->prepare("DELETE FROM expense_tbl WHERE expenseID = ? AND accountantID = ?");
    $stmt->bind_param("ii", $expenseID, $accountantID);
    if ($stmt->execute()) {
        $success_message = "Expense record deleted successfully.";
    } else {
        $error_message = "Unable to delete expense record: " . $conn->error;
    }
    $stmt->close();
}

// Fetch active payroll items awaiting processing
$attendanceList = $conn->query("
    SELECT a.attendanceID, a.date, a.login, a.logout,
           COALESCE(wu.username, su.username, ssu.username, du.username) as emp_name,
           CASE 
             WHEN w.workerID IS NOT NULL THEN 'Worker'
             WHEN s.stocksupID IS NOT NULL THEN 'Stock Supervisor'
             WHEN ss.salessupID IS NOT NULL THEN 'Sales Supervisor'
             WHEN d.driverID IS NOT NULL THEN 'Driver'
           END as emp_role
    FROM attendance_tbl a
    LEFT JOIN worker_tbl w ON a.attendanceID = w.attendanceID LEFT JOIN user_tbl wu ON w.userID = wu.userID
    LEFT JOIN StockSuperviser_tbl s ON a.stocksupID = s.stocksupID LEFT JOIN user_tbl su ON s.userID = su.userID
    LEFT JOIN SalesSuperviser_tbl ss ON a.salessupID = ss.salessupID LEFT JOIN user_tbl ssu ON ss.userID = ssu.userID
    LEFT JOIN driver_tbl d ON a.attendanceID = d.attendanceID LEFT JOIN user_tbl du ON d.userID = du.userID
    LEFT JOIN salary_tbl sal ON a.attendanceID = sal.attendanceID
    WHERE sal.salaryID IS NULL 
      AND (w.workerID IS NOT NULL OR s.stocksupID IS NOT NULL OR ss.salessupID IS NOT NULL OR d.driverID IS NOT NULL)
    ORDER BY a.date DESC
");

// Fetch processed Salary ledger grouped by role for current month
$currentMonthStart = date('Y-m-01');
$currentMonthEnd = date('Y-m-t');

// Stock Supervisors - monthly salary with OT breakdown and bonus check
$stockSupLedger = $conn->query("
    SELECT 
        st.stocksupID as emp_id, 
        su.username, 
        su.userID,
        MIN(s.paydate) as base_paid_date,
        COUNT(DISTINCT s.salaryID) as payment_count,
        COALESCE(SUM(s.totamtpaid), 0) as total_paid,
        COALESCE(SUM(
            CASE 
                WHEN (TIME_TO_SEC(TIMEDIFF(a.logout, a.login)) / 3600) > 8 
                THEN ((TIME_TO_SEC(TIMEDIFF(a.logout, a.login)) / 3600) - 8) * st.OT_rate
                ELSE 0
            END
        ), 0) as ot_paid,
        (CASE WHEN EXISTS (
            SELECT 1 FROM expense_tbl e 
            WHERE e.type LIKE '%bonus%' AND e.accountantID = $accountantID
        ) THEN 'Yes' ELSE 'No' END) as has_bonus
    FROM salary_tbl s
    INNER JOIN attendance_tbl a ON s.attendanceID = a.attendanceID
    INNER JOIN StockSuperviser_tbl st ON a.stocksupID = st.stocksupID
    INNER JOIN user_tbl su ON st.userID = su.userID
    WHERE s.accountantID = $accountantID AND s.paydate BETWEEN '$currentMonthStart' AND '$currentMonthEnd'
    GROUP BY st.stocksupID, su.userID, su.username
    ORDER BY su.username
");

// Sales Supervisors - monthly salary with OT breakdown and bonus check
$salesSupLedger = $conn->query("
    SELECT 
        ss.salessupID as emp_id, 
        ssu.username, 
        ssu.userID,
        MIN(s.paydate) as base_paid_date,
        COUNT(DISTINCT s.salaryID) as payment_count,
        COALESCE(SUM(s.totamtpaid), 0) as total_paid,
        COALESCE(SUM(
            CASE 
                WHEN (TIME_TO_SEC(TIMEDIFF(a.logout, a.login)) / 3600) > 8 
                THEN ((TIME_TO_SEC(TIMEDIFF(a.logout, a.login)) / 3600) - 8) * ss.OT_rate
                ELSE 0
            END
        ), 0) as ot_paid,
        (CASE WHEN EXISTS (
            SELECT 1 FROM expense_tbl e 
            WHERE e.type LIKE '%bonus%' AND e.accountantID = $accountantID
        ) THEN 'Yes' ELSE 'No' END) as has_bonus
    FROM salary_tbl s
    INNER JOIN attendance_tbl a ON s.attendanceID = a.attendanceID
    INNER JOIN SalesSuperviser_tbl ss ON a.salessupID = ss.salessupID
    INNER JOIN user_tbl ssu ON ss.userID = ssu.userID
    WHERE s.accountantID = $accountantID AND s.paydate BETWEEN '$currentMonthStart' AND '$currentMonthEnd'
    GROUP BY ss.salessupID, ssu.userID, ssu.username
    ORDER BY ssu.username
");

// Workers - daily salary with days worked and bonus check
$workerLedger = $conn->query("
    SELECT 
        w.workerID as emp_id, 
        wu.username, 
        wu.userID,
        COUNT(DISTINCT a.attendanceID) as days_worked,
        COALESCE(SUM(s.totamtpaid), 0) as total_paid,
        (CASE WHEN EXISTS (
            SELECT 1 FROM expense_tbl e 
            WHERE e.type LIKE '%bonus%' AND e.accountantID = $accountantID
        ) THEN 'Yes' ELSE 'No' END) as has_bonus
    FROM salary_tbl s
    INNER JOIN attendance_tbl a ON s.attendanceID = a.attendanceID
    INNER JOIN Worker_tbl w ON a.workerID = w.workerID
    INNER JOIN user_tbl wu ON w.userID = wu.userID
    WHERE s.accountantID = $accountantID AND s.paydate BETWEEN '$currentMonthStart' AND '$currentMonthEnd'
    GROUP BY w.workerID, wu.userID, wu.username
    ORDER BY wu.username
");

// Drivers - daily salary with days worked and bonus check
$driverLedger = $conn->query("
    SELECT 
        d.driverID as emp_id, 
        du.username, 
        du.userID,
        COUNT(DISTINCT a.attendanceID) as days_worked,
        COALESCE(SUM(s.totamtpaid), 0) as total_paid,
        (CASE WHEN EXISTS (
            SELECT 1 FROM expense_tbl e 
            WHERE e.type LIKE '%bonus%' AND e.accountantID = $accountantID
        ) THEN 'Yes' ELSE 'No' END) as has_bonus
    FROM salary_tbl s
    INNER JOIN attendance_tbl a ON s.attendanceID = a.attendanceID
    INNER JOIN Driver_tbl d ON a.driverID = d.driverID
    INNER JOIN user_tbl du ON d.userID = du.userID
    WHERE s.accountantID = $accountantID AND s.paydate BETWEEN '$currentMonthStart' AND '$currentMonthEnd'
    GROUP BY d.driverID, du.userID, du.username
    ORDER BY du.username
");

// Fetch combined organizational layout expense structures
$expenseLedger = $conn->query("SELECT * FROM expense_tbl WHERE accountantID = $accountantID ORDER BY expenseID DESC");

// Fetch employee roster for attendance CRUD (exclude customer accounts)
$attendanceEmployees = $conn->query("SELECT u.userID, u.username,
       CASE
         WHEN LOWER(u.role) = 'stocksup' THEN 'Stock Supervisor'
         WHEN LOWER(u.role) = 'salessup' THEN 'Sales Supervisor'
         WHEN LOWER(u.role) = 'worker' THEN 'Worker'
         WHEN LOWER(u.role) = 'driver' THEN 'Driver'
         WHEN LOWER(u.role) = 'accountant' THEN 'Accountant'
         ELSE 'Employee'
       END AS display_role,
       s.stocksupID, ss.salessupID, w.workerID, d.driverID, ac.accountantID
    FROM user_tbl u
    LEFT JOIN StockSuperviser_tbl s ON u.userID = s.userID
    LEFT JOIN SalesSuperviser_tbl ss ON u.userID = ss.userID
    LEFT JOIN Worker_tbl w ON u.userID = w.userID
    LEFT JOIN Driver_tbl d ON u.userID = d.userID
    LEFT JOIN Accountant_tbl ac ON u.userID = ac.userID
    WHERE LOWER(u.role) IN ('stocksup','salessup','worker','driver','accountant')
    ORDER BY u.username");

$attendanceEmployeeRows = [];
if ($attendanceEmployees && $attendanceEmployees->num_rows > 0) {
    while ($row = $attendanceEmployees->fetch_assoc()) {
        $attendanceEmployeeRows[] = $row;
    }
}

// Fetch attendance records for the attendance CRUD tab
$attendanceCRUD = $conn->query("SELECT a.attendanceID, a.date, a.login, a.logout,
    COALESCE(s.userID, ss.userID, w.userID, d.userID, ac.accountantID) AS userID,
    COALESCE(u_stock.username, u_sales.username, u_worker.username, u_driver.username, u_accountant.username) AS emp_name,
       CASE
         WHEN a.stocksupID IS NOT NULL THEN 'Stock Supervisor'
         WHEN a.salessupID IS NOT NULL THEN 'Sales Supervisor'
         WHEN a.workerID IS NOT NULL THEN 'Worker'
         WHEN a.driverID IS NOT NULL THEN 'Driver'
         WHEN a.accountantID IS NOT NULL THEN 'Accountant'
       END AS emp_role,
       a.stocksupID, a.salessupID, a.workerID, a.driverID, a.accountantID
    FROM attendance_tbl a
    LEFT JOIN StockSuperviser_tbl s ON a.stocksupID = s.stocksupID
    LEFT JOIN user_tbl u_stock ON s.userID = u_stock.userID
    LEFT JOIN SalesSuperviser_tbl ss ON a.salessupID = ss.salessupID
    LEFT JOIN user_tbl u_sales ON ss.userID = u_sales.userID
    LEFT JOIN Worker_tbl w ON a.workerID = w.workerID
    LEFT JOIN user_tbl u_worker ON w.userID = u_worker.userID
    LEFT JOIN Driver_tbl d ON a.driverID = d.driverID
    LEFT JOIN user_tbl u_driver ON d.userID = u_driver.userID
    LEFT JOIN Accountant_tbl ac ON a.accountantID = ac.accountantID
    LEFT JOIN user_tbl u_accountant ON ac.userID = u_accountant.userID
    ORDER BY a.date DESC");

// Salary portal data for stock supervisors, sales supervisors, drivers, and workers
$salaryEmployees = $conn->query("SELECT u.userID, u.username, 'Stock Supervisor' AS role, s.base_salary, s.OT_rate, NULL AS hour_rate, NULL AS fixed_salary FROM StockSuperviser_tbl s JOIN user_tbl u ON s.userID = u.userID UNION SELECT u.userID, u.username, 'Sales Supervisor' AS role, ss.base_salary, ss.OT_rate, NULL AS hour_rate, NULL AS fixed_salary FROM SalesSuperviser_tbl ss JOIN user_tbl u ON ss.userID = u.userID UNION SELECT u.userID, u.username, 'Worker' AS role, NULL AS base_salary, NULL AS OT_rate, w.hour_rate, NULL AS fixed_salary FROM Worker_tbl w JOIN user_tbl u ON w.userID = u.userID UNION SELECT u.userID, u.username, 'Driver' AS role, NULL AS base_salary, NULL AS OT_rate, NULL AS hour_rate, d.fixed_salary FROM Driver_tbl d JOIN user_tbl u ON d.userID = u.userID");
$salaryAttendance = $conn->query("SELECT a.attendanceID, a.date, a.login, a.logout, COALESCE(s.userID, ss.userID, w.userID, d.userID) AS userID, COALESCE(su.username, ssu.username, wu.username, du.username) AS username, COALESCE(s.base_salary, ss.base_salary, 0) AS base_salary, COALESCE(s.OT_rate, ss.OT_rate, 0) AS ot_rate, w.hour_rate AS hour_rate, d.fixed_salary AS fixed_salary, CASE WHEN s.stocksupID IS NOT NULL THEN 'Stock Supervisor' WHEN ss.salessupID IS NOT NULL THEN 'Sales Supervisor' WHEN w.workerID IS NOT NULL THEN 'Worker' WHEN d.driverID IS NOT NULL THEN 'Driver' END AS role FROM attendance_tbl a LEFT JOIN StockSuperviser_tbl s ON a.stocksupID = s.stocksupID LEFT JOIN user_tbl su ON s.userID = su.userID LEFT JOIN SalesSuperviser_tbl ss ON a.salessupID = ss.salessupID LEFT JOIN user_tbl ssu ON ss.userID = ssu.userID LEFT JOIN Worker_tbl w ON a.workerID = w.workerID LEFT JOIN user_tbl wu ON w.userID = wu.userID LEFT JOIN Driver_tbl d ON a.driverID = d.driverID LEFT JOIN user_tbl du ON d.userID = du.userID LEFT JOIN salary_tbl sal ON a.attendanceID = sal.attendanceID WHERE sal.salaryID IS NULL AND (s.stocksupID IS NOT NULL OR ss.salessupID IS NOT NULL OR w.workerID IS NOT NULL OR d.driverID IS NOT NULL) ORDER BY a.date DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Accountant Operations Workspace Node</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --sidebar-bg: #112a1b;
            --sidebar-active: #1e3a24;
            --sidebar-text: #d6e8d8;
            --sidebar-muted: #8fae95;
            --surface-bg: #f7faf9;
            --panel-bg: #ffffff;
            --panel-border: rgba(15, 23, 42, 0.08);
            --success: #16a34a;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--surface-bg);
            color: #0f172a;
            min-height: 100vh;
            margin: 0;
        }

        .dashboard-shell {
            display: flex;
            min-height: 100vh;
        }

        .dashboard-sidebar {
            width: 260px;
            background: var(--sidebar-bg);
            color: white;
            padding: 2rem 1.5rem;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            overflow-y: auto;
            box-shadow: 4px 0 30px rgba(0,0,0,0.12);
        }

        .dashboard-sidebar img {
            height: 44px;
            width: auto;
            margin-bottom: 1.5rem;
            display: block;
        }

        .sidebar-title {
            font-size: 0.9rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            margin-bottom: 1.75rem;
            color: var(--sidebar-muted);
        }

        .sidebar-nav {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .sidebar-nav button {
            border: none;
            width: 100%;
            text-align: left;
            border-radius: 14px;
            padding: 0.95rem 1rem;
            color: var(--sidebar-text);
            background: transparent;
            transition: background 0.25s ease, color 0.25s ease;
        }

        .sidebar-nav button.active,
        .sidebar-nav button:hover {
            background: var(--sidebar-active);
            color: white;
        }

        .dashboard-main {
            margin-left: 260px;
            width: calc(100% - 260px);
            padding: 2rem 2.5rem;
        }

        .page-heading {
            margin-bottom: 1.25rem;
        }

        .page-heading h1 {
            font-size: 2rem;
            letter-spacing: -0.04em;
        }

        .page-heading p {
            color: #64748b;
        }

        .card {
            border-radius: 22px;
            border: 1px solid var(--panel-border);
            background: var(--panel-bg);
            box-shadow: 0 18px 50px rgba(15, 23, 42, 0.05);
        }

        .table {
            vertical-align: middle;
        }

        .btn-success {
            background-color: var(--success);
            border-color: var(--success);
            border-radius: 12px;
            font-weight: 600;
        }

        .btn-success:hover {
            background-color: #15803d;
        }

        .form-check-input {
            cursor: pointer;
        }

        .content-section {
            margin-bottom: 1.75rem;
        }

        @media (max-width: 1199px) {
            .dashboard-sidebar {
                position: relative;
                width: 100%;
                height: auto;
                box-shadow: none;
            }

            .dashboard-main {
                margin-left: 0;
                width: 100%;
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-shell">
    <aside class="dashboard-sidebar">
        <img src="../images/LOGO.png" alt="Tharu Products" />
        <div class="sidebar-title">Accountant Workspace</div>
        <div class="sidebar-nav" role="tablist" aria-orientation="vertical">
            <button class="active" id="attendanceTab-tab" data-bs-toggle="pill" data-bs-target="#attendanceTab" type="button" role="tab" aria-controls="attendanceTab" aria-selected="true">Attendance Tracking</button>
            <button id="payroll-tab" data-bs-toggle="pill" data-bs-target="#payroll" type="button" role="tab" aria-controls="payroll" aria-selected="false">Payroll</button>
            <button id="expenses-tab" data-bs-toggle="pill" data-bs-target="#expenses" type="button" role="tab" aria-controls="expenses" aria-selected="false">Manual Expenses</button>
            <button id="salaryLedger-tab" data-bs-toggle="pill" data-bs-target="#salaryLedger" type="button" role="tab" aria-controls="salaryLedger" aria-selected="false">Salary Ledger</button>
            <button id="expenseLedger-tab" data-bs-toggle="pill" data-bs-target="#expenseLedger" type="button" role="tab" aria-controls="expenseLedger" aria-selected="false">Expense Ledger</button>
        </div>
        <div class="mt-4">
            <span class="d-block text-muted small mb-2">Active Node</span>
            <strong>Accountant #<?= htmlspecialchars($accountantID) ?></strong>
        </div>
        <a href="../auth/logout.php" class="btn btn-danger btn-sm mt-4 w-100">Sign out user</a>
    </aside>

    <main class="dashboard-main">
        <?php if(!empty($error_message)): ?>
            <div class="alert alert-danger border-0 shadow-sm mb-4" style="border-radius:16px;">⚠️ <?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>
        <?php if(!empty($success_message)): ?>
            <div class="alert alert-success border-0 shadow-sm mb-4" style="border-radius:16px;">✓ <?= htmlspecialchars($success_message) ?></div>
        <?php endif; ?>

        <section class="page-heading">
            <h1>Accountant Dashboard</h1>
            <p class="small">Manage payroll, expenses, and ledgers from one central workspace.</p>
        </section>

        <div class="tab-content" id="acctTabContent">
            <div class="tab-pane fade" id="payroll" role="tabpanel" aria-labelledby="payroll-tab">
                <div class="card border-0 shadow-sm p-4 rounded-4 mb-4">
                    <h4 class="fw-bold text-dark mb-1"><i class="bi bi-calculator-fill me-2 text-success"></i>Salary Provisioning Calculator Portal</h4>
                    <p class="text-muted small mb-4">Select an operator from your live employee roster to run operations.</p>

                    <form action="" method="POST" id="salaryForm">
                        <input type="hidden" name="action" value="calculate_salary">

                        <div class="row g-3">
                            <div class="col-md-12 mb-2">
                                <label class="form-label small fw-bold text-dark">Target Employee Operator</label>
                                <select class="form-select border" id="employeeSelect" name="employee_id" onchange="loadEmployeeData()" required>
                                    <option value="">-- Choose Operator --</option>
                                    <?php if ($salaryEmployees && $salaryEmployees->num_rows > 0): ?>
                                        <?php while ($sup = $salaryEmployees->fetch_assoc()): ?>
                                            <option value="<?= htmlspecialchars($sup['userID']) ?>"
                                                    data-role="<?= htmlspecialchars($sup['role']) ?>"
                                                    data-base-salary="<?= number_format((float)($sup['base_salary'] ?? 0), 2, '.', '') ?>"
                                                    data-ot-rate="<?= number_format((float)($sup['OT_rate'] ?? 0), 2, '.', '') ?>"
                                                    data-hour-rate="<?= number_format((float)($sup['hour_rate'] ?? 0), 2, '.', '') ?>"
                                                    data-fixed-salary="<?= number_format((float)($sup['fixed_salary'] ?? 0), 2, '.', '') ?>">
                                                <?= htmlspecialchars($sup['username']) ?> (<?= htmlspecialchars($sup['role']) ?>)
                                            </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Attendance Record</label>
                                <select class="form-select border" id="attendanceSelect" name="attendance_id" onchange="loadAttendanceData()" required>
                                    <option value="">-- Choose Attendance Record --</option>
                                    <?php if ($salaryAttendance && $salaryAttendance->num_rows > 0): ?>
                                        <?php while ($att = $salaryAttendance->fetch_assoc()): ?>
                                            <option value="<?= htmlspecialchars($att['attendanceID']) ?>"
                                                    data-user-id="<?= htmlspecialchars($att['userID']) ?>"
                                                    data-login="<?= htmlspecialchars($att['login']) ?>"
                                                    data-logout="<?= htmlspecialchars($att['logout']) ?>"
                                                    data-date="<?= htmlspecialchars($att['date']) ?>"
                                                    data-role="<?= htmlspecialchars($att['role']) ?>"
                                                    data-base-salary="<?= number_format((float)($att['base_salary'] ?? 0), 2, '.', '') ?>"
                                                    data-ot-rate="<?= number_format((float)($att['ot_rate'] ?? 0), 2, '.', '') ?>"
                                                    data-hour-rate="<?= number_format((float)($att['hour_rate'] ?? 0), 2, '.', '') ?>"
                                                    data-fixed-salary="<?= number_format((float)($att['fixed_salary'] ?? 0), 2, '.', '') ?>">
                                                <?= htmlspecialchars($att['date']) ?> | <?= htmlspecialchars($att['username']) ?> (<?= htmlspecialchars($att['role']) ?>)
                                            </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Login / Logout Times</label>
                                <input type="text" id="attendanceSummary" class="form-control bg-light" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Settlement Pay Date</label>
                                <input type="date" name="paydate" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Base Salary (LKR)</label>
                                <input type="number" id="baseSalary" class="form-control bg-light" value="0" readonly>
                            </div>
                            <input type="hidden" id="otRate" value="0">
                            <input type="hidden" id="basePayHidden" name="base_pay" value="0">
                            <input type="hidden" id="otPayHidden" name="ot_pay" value="0">

                            <div class="col-12 my-3"><hr></div>

                            <div class="col-md-6">
                                <label class="form-label small text-muted">OT Hours Worked</label>
                                <input type="number" id="otHours" class="form-control" value="0" step="0.01" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted">OT Rate Per Hour (LKR)</label>
                                <input type="number" id="otRateDisplay" class="form-control" value="0" step="0.01" readonly>
                            </div>

                            <div class="col-12 my-3"><hr></div>

                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="bonusToggle" onclick="toggleBonusField()">
                                    <label class="form-check-label fw-bold text-dark" for="bonusToggle">Apply Corporate Performance Bonus Milestone</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted">Bonus Payout Quantum (LKR)</label>
                                <input type="number" id="bonusAmount" class="form-control" name="bonus_pay" value="0" disabled oninput="calculateTotalSalary()">
                            </div>

                            <div class="col-12 mt-4">
                                <div class="p-4 rounded-3 text-start d-flex justify-content-between align-items-center" style="background-color: #e8f5e9;">
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

            <div class="tab-pane fade show active" id="attendanceTab" role="tabpanel" aria-labelledby="attendanceTab-tab">
                <div class="card p-4 content-section">
                    <h4 class="fw-bold mb-3 text-dark">Attendance Tracking CRUD</h4>
                    <p class="text-muted small mb-4">Add, update, delete, and review attendance records for employees and accountants.</p>

                    <form action="" method="POST" class="row g-3 mb-4">
                        <input type="hidden" name="action" value="create_attendance">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Employee</label>
                            <select class="form-select" name="attendance_user_id" required>
                                <option value="">-- Select Employee --</option>
                                <?php if (!empty($attendanceEmployeeRows)): ?>
                                    <?php foreach ($attendanceEmployeeRows as $user): ?>
                                        <option value="<?= htmlspecialchars($user['userID']) ?>"><?= htmlspecialchars($user['username']) ?> (<?= htmlspecialchars($user['display_role']) ?>)</option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold">Date</label>
                            <input type="date" name="attendance_date" class="form-control" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold">Login Time</label>
                            <input type="time" name="login_time" class="form-control" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold">Logout Time</label>
                            <input type="time" name="logout_time" class="form-control" required>
                        </div>
                        <div class="col-12 text-end">
                            <button type="submit" class="btn btn-success rounded-pill px-4">Create Attendance</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-hover border-top align-middle small">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Employee</th>
                                    <th>Role</th>
                                    <th>Login</th>
                                    <th>Logout</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($attendanceCRUD && $attendanceCRUD->num_rows > 0): ?>
                                    <?php while ($att = $attendanceCRUD->fetch_assoc()): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($att['date']) ?></td>
                                            <td><?= htmlspecialchars($att['emp_name']) ?></td>
                                            <td><span class="badge bg-secondary text-dark rounded-pill"><?= htmlspecialchars($att['emp_role']) ?></span></td>
                                            <td><?= htmlspecialchars($att['login']) ?></td>
                                            <td><?= htmlspecialchars($att['logout']) ?></td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-outline-primary btn-sm me-1" onclick='openAttendanceEditModal(<?= $att['attendanceID'] ?>, <?= json_encode($att['date']) ?>, <?= json_encode($att['login']) ?>, <?= json_encode($att['logout']) ?>, <?= $att['userID'] ?? 'null' ?>)'>Edit</button>
                                                <form action="" method="POST" class="d-inline" onsubmit="return confirm('Delete this attendance record?');">
                                                    <input type="hidden" name="action" value="delete_attendance">
                                                    <input type="hidden" name="attendance_id" value="<?= htmlspecialchars($att['attendanceID']) ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" class="text-center text-muted py-4">No attendance records have been entered yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="expenses" role="tabpanel" aria-labelledby="expenses-tab">
                <div class="card p-4 content-section">
                    <h4 class="fw-bold mb-3 text-dark">Manual Operating Expenses Registry</h4>
                    <form action="" method="POST">
                        <input type="hidden" name="action" value="log_expense">
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Expense Type Profile</label>
                            <select id="expenseTypeSelect" name="expense_type" class="form-select form-select-md" onchange="toggleCustomExpenseBox(this.value)" required>
                                <option value="Machine Maintainance Costs">Machine Maintainance Costs</option>
                                <option value="Utility Bills">Utility Bills</option>
                                <option value="Raw Material Purchases">Raw Material Purchases</option>
                                <option value="Other">Other / Custom Description Override</option>
                            </select>
                        </div>

                        <div id="customExpenseWrapper" class="mb-3 d-none">
                            <label class="form-label small fw-semibold">Specify Custom Description</label>
                            <input type="text" name="custom_expense_type" class="form-control" placeholder="e.g. Factory Office Repairs">
                        </div>

                        <div class="mb-4">
                            <label class="form-label small fw-semibold">Amount Disbursed (LKR)</label>
                            <input type="number" step="0.01" min="1" name="amount" class="form-control" placeholder="0.00" required>
                        </div>

                        <button type="submit" class="btn btn-dark w-100 rounded-3 py-2 fw-bold">Log Financial Expense</button>
                    </form>
                </div>
            </div>

            <div class="tab-pane fade" id="salaryLedger" role="tabpanel" aria-labelledby="salaryLedger-tab">
                <div class="card p-4 content-section">
                    <h4 class="fw-bold mb-4 text-success">Salary Disbursements Summary Ledger</h4>
                    <p class="text-muted small mb-4">Monthly salary overview for all employees, grouped by role.</p>

                    <!-- Stock Supervisors Section -->
                    <div class="mb-5">
                        <h5 class="fw-bold text-secondary mb-3">📊 Stock Supervisors (Monthly Salary)</h5>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover border-top">
                                <thead class="table-light">
                                    <tr>
                                        <th>Employee ID</th>
                                        <th>Name</th>
                                        <th>Base Salary Paid On</th>
                                        <th>Total OT Payments</th>
                                        <th>Holiday Bonus</th>
                                        <th>Total Salary (Month)</th>
                                    </tr>
                                </thead>
                                <tbody class="small">
                                    <?php if($stockSupLedger && $stockSupLedger->num_rows > 0): ?>
                                        <?php while($row = $stockSupLedger->fetch_assoc()): ?>
                                            <tr>
                                                <td class="fw-semibold"><?= htmlspecialchars($row['emp_id']) ?></td>
                                                <td><?= htmlspecialchars($row['username']) ?></td>
                                                <td><?= htmlspecialchars($row['base_paid_date']) ?></td>
                                                <td class="text-info fw-semibold"><?= number_format($row['ot_paid'], 2) ?> LKR</td>
                                                <td><span class="badge <?= $row['has_bonus'] === 'Yes' ? 'bg-success' : 'bg-secondary' ?>"><?= htmlspecialchars($row['has_bonus']) ?></span></td>
                                                <td class="text-success fw-bold"><?= number_format($row['total_paid'], 2) ?> LKR</td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="6" class="text-muted py-3">No stock supervisor payments this month.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Sales Supervisors Section -->
                    <div class="mb-5">
                        <h5 class="fw-bold text-secondary mb-3">📊 Sales Supervisors (Monthly Salary)</h5>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover border-top">
                                <thead class="table-light">
                                    <tr>
                                        <th>Employee ID</th>
                                        <th>Name</th>
                                        <th>Base Salary Paid On</th>
                                        <th>Total OT Payments</th>
                                        <th>Holiday Bonus</th>
                                        <th>Total Salary (Month)</th>
                                    </tr>
                                </thead>
                                <tbody class="small">
                                    <?php if($salesSupLedger && $salesSupLedger->num_rows > 0): ?>
                                        <?php while($row = $salesSupLedger->fetch_assoc()): ?>
                                            <tr>
                                                <td class="fw-semibold"><?= htmlspecialchars($row['emp_id']) ?></td>
                                                <td><?= htmlspecialchars($row['username']) ?></td>
                                                <td><?= htmlspecialchars($row['base_paid_date']) ?></td>
                                                <td class="text-info fw-semibold"><?= number_format($row['ot_paid'], 2) ?> LKR</td>
                                                <td><span class="badge <?= $row['has_bonus'] === 'Yes' ? 'bg-success' : 'bg-secondary' ?>"><?= htmlspecialchars($row['has_bonus']) ?></span></td>
                                                <td class="text-success fw-bold"><?= number_format($row['total_paid'], 2) ?> LKR</td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="6" class="text-muted py-3">No sales supervisor payments this month.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Workers Section -->
                    <div class="mb-5">
                        <h5 class="fw-bold text-secondary mb-3">👷 Workers (Daily Salary)</h5>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover border-top">
                                <thead class="table-light">
                                    <tr>
                                        <th>Employee ID</th>
                                        <th>Name</th>
                                        <th>Days Worked (Month)</th>
                                        <th>Holiday Bonus</th>
                                        <th>Total Salary (Month)</th>
                                    </tr>
                                </thead>
                                <tbody class="small">
                                    <?php if($workerLedger && $workerLedger->num_rows > 0): ?>
                                        <?php while($row = $workerLedger->fetch_assoc()): ?>
                                            <tr>
                                                <td class="fw-semibold"><?= htmlspecialchars($row['emp_id']) ?></td>
                                                <td><?= htmlspecialchars($row['username']) ?></td>
                                                <td class="text-center"><?= htmlspecialchars($row['days_worked']) ?> days</td>
                                                <td><span class="badge <?= $row['has_bonus'] === 'Yes' ? 'bg-success' : 'bg-secondary' ?>"><?= htmlspecialchars($row['has_bonus']) ?></span></td>
                                                <td class="text-success fw-bold"><?= number_format($row['total_paid'], 2) ?> LKR</td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="5" class="text-muted py-3">No worker payments this month.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Drivers Section -->
                    <div class="mb-5">
                        <h5 class="fw-bold text-secondary mb-3">🚗 Drivers (Daily Salary)</h5>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover border-top">
                                <thead class="table-light">
                                    <tr>
                                        <th>Employee ID</th>
                                        <th>Name</th>
                                        <th>Days Worked (Month)</th>
                                        <th>Holiday Bonus</th>
                                        <th>Total Salary (Month)</th>
                                    </tr>
                                </thead>
                                <tbody class="small">
                                    <?php if($driverLedger && $driverLedger->num_rows > 0): ?>
                                        <?php while($row = $driverLedger->fetch_assoc()): ?>
                                            <tr>
                                                <td class="fw-semibold"><?= htmlspecialchars($row['emp_id']) ?></td>
                                                <td><?= htmlspecialchars($row['username']) ?></td>
                                                <td class="text-center"><?= htmlspecialchars($row['days_worked']) ?> days</td>
                                                <td><span class="badge <?= $row['has_bonus'] === 'Yes' ? 'bg-success' : 'bg-secondary' ?>"><?= htmlspecialchars($row['has_bonus']) ?></span></td>
                                                <td class="text-success fw-bold"><?= number_format($row['total_paid'], 2) ?> LKR</td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="5" class="text-muted py-3">No driver payments this month.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="expenseLedger" role="tabpanel" aria-labelledby="expenseLedger-tab">
                <div class="card p-4 content-section">
                    <h4 class="fw-bold mb-3 text-dark">Combined General Expense Ledger</h4>
                    <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                        <table class="table table-sm text-center border-top">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Expense Classification Type</th>
                                    <th>Disbursed Amount</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="small">
                                <?php if($expenseLedger && $expenseLedger->num_rows > 0): ?>
                                    <?php while($el = $expenseLedger->fetch_assoc()): ?>
                                        <tr>
                                            <td>#<?= htmlspecialchars($el['expenseID']) ?></td>
                                            <td class="text-start"><?= htmlspecialchars($el['type']) ?></td>
                                            <td class="fw-semibold text-danger"><?= number_format($el['amount'], 2) ?> LKR</td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-outline-primary btn-sm me-1" onclick="openExpenseEditModal(<?= $el['expenseID'] ?>, <?= json_encode($el['type']) ?>, <?= json_encode($el['amount']) ?>)">Edit</button>
                                                <form action="" method="POST" class="d-inline" onsubmit="return confirm('Delete this expense record?');">
                                                    <input type="hidden" name="action" value="delete_expense">
                                                    <input type="hidden" name="expense_id" value="<?= htmlspecialchars($el['expenseID']) ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" class="text-muted py-3">No overhead metrics allocated.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<div class="modal fade" id="payrollProcessingModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form action="" method="POST" class="modal-content border-0 shadow-lg" style="border-radius:20px;">
            <input type="hidden" name="action" value="calculate_salary">
            <input type="hidden" id="modalAttendanceID" name="attendance_id" value="">
            
            <div class="modal-header border-0 bg-light px-4 pt-4">
                <div>
                    <h5 class="modal-title fw-bold" id="modalTargetName">Calculate Salary Profile</h5>
                    <span class="badge bg-dark rounded-pill" id="modalTargetRole">Role</span>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <div class="modal-body px-4 py-3">
                
                <div class="mb-3">
                    <label class="form-label small fw-bold">Select Settlement Pay Day</label>
                    <input type="date" name="paydate" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="form-check form-switch p-0 mb-3 d-flex align-items-center justify-content-between border rounded p-3 bg-light">
                    <div>
                        <label class="form-check-label small fw-bold text-dark d-block">Holiday Bonus Condition</label>
                        <small class="text-muted d-block" style="font-size:0.75rem;">Toggle to manually overlay specialized adjustments.</small>
                    </div>
                    <input class="form-check-input me-0" type="checkbox" id="holidayBonusToggle" name="holiday_bonus_toggle" onchange="toggleBonusInputBox(this.checked)">
                </div>

                <div id="bonusInputContainer" class="mb-2 d-none">
                    <label class="form-label small fw-bold text-success">Manual Holiday Premium Amount (LKR)</label>
                    <input type="number" step="0.01" min="0" id="bonusAmountField" name="bonus_amount" class="form-control" value="0.00">
                </div>

            </div>
            
            <div class="modal-footer border-0 bg-light p-3 px-4">
                <button type="button" class="btn btn-secondary border-0 text-dark bg-transparent fw-semibold" data-bs-dismiss="modal">Abort</button>
                <button type="submit" class="btn btn-success px-4 shadow-sm">Process & Record Payout</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="salaryEditModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form action="" method="POST" class="modal-content border-0 shadow-lg" style="border-radius:20px;">
            <input type="hidden" name="action" value="update_salary">
            <input type="hidden" id="salaryEditID" name="salary_id" value="">
            <div class="modal-header border-0 bg-light px-4 pt-4">
                <h5 class="modal-title fw-bold">Edit Salary Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body px-4 py-3">
                <div class="mb-3">
                    <label class="form-label small fw-bold">Payment Date</label>
                    <input type="date" id="salaryEditPaydate" name="paydate" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Total Amount Paid (LKR)</label>
                    <input type="number" step="0.01" min="0" id="salaryEditAmount" name="amount" class="form-control" required>
                </div>
            </div>
            <div class="modal-footer border-0 bg-light p-3 px-4">
                <button type="button" class="btn btn-secondary border-0 text-dark bg-transparent fw-semibold" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary px-4 shadow-sm">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="attendanceEditModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form action="" method="POST" class="modal-content border-0 shadow-lg" style="border-radius:20px;">
            <input type="hidden" name="action" value="update_attendance">
            <input type="hidden" id="attendanceEditID" name="attendance_id" value="">
            <div class="modal-header border-0 bg-light px-4 pt-4">
                <h5 class="modal-title fw-bold">Edit Attendance Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body px-4 py-3">
                <div class="mb-3">
                    <label class="form-label small fw-bold">Employee</label>
                    <select class="form-select" id="attendanceEditUser" name="attendance_user_id" required>
                        <option value="">-- Select Employee --</option>
                        <?php if (!empty($attendanceEmployeeRows)): ?>
                            <?php foreach ($attendanceEmployeeRows as $user): ?>
                                <option value="<?= htmlspecialchars($user['userID']) ?>"><?= htmlspecialchars($user['username']) ?> (<?= htmlspecialchars($user['display_role']) ?>)</option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Date</label>
                    <input type="date" id="attendanceEditDate" name="attendance_date" class="form-control" required>
                </div>
                <div class="mb-3 row gx-2">
                    <div class="col-6">
                        <label class="form-label small fw-bold">Login Time</label>
                        <input type="time" id="attendanceEditLogin" name="login_time" class="form-control" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-bold">Logout Time</label>
                        <input type="time" id="attendanceEditLogout" name="logout_time" class="form-control" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 bg-light p-3 px-4">
                <button type="button" class="btn btn-secondary border-0 text-dark bg-transparent fw-semibold" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary px-4 shadow-sm">Save Attendance</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="expenseEditModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form action="" method="POST" class="modal-content border-0 shadow-lg" style="border-radius:20px;">
            <input type="hidden" name="action" value="update_expense">
            <input type="hidden" id="expenseEditID" name="expense_id" value="">
            <div class="modal-header border-0 bg-light px-4 pt-4">
                <h5 class="modal-title fw-bold">Edit Expense Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body px-4 py-3">
                <div class="mb-3">
                    <label class="form-label small fw-bold">Expense Description</label>
                    <input type="text" id="expenseEditType" name="expense_type" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Amount Paid (LKR)</label>
                    <input type="number" step="0.01" min="0" id="expenseEditAmount" name="amount" class="form-control" required>
                </div>
            </div>
            <div class="modal-footer border-0 bg-light p-3 px-4">
                <button type="button" class="btn btn-secondary border-0 text-dark bg-transparent fw-semibold" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary px-4 shadow-sm">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Handle Custom Dropdown Selector Displays
    function toggleCustomExpenseBox(val) {
        const wrap = document.getElementById('customExpenseWrapper');
        if (val === 'Other') {
            wrap.classList.remove('d-none');
            wrap.querySelector('input').setAttribute('required', 'true');
        } else {
            wrap.classList.add('d-none');
            wrap.querySelector('input').removeAttribute('required');
        }
    }

    // Modal Interaction Handlers
    let myModal;
    document.addEventListener("DOMContentLoaded", function() {
        myModal = new bootstrap.Modal(document.getElementById('payrollProcessingModal'));
    });

    function openPayrollModal(attId, empName, empRole) {
        document.getElementById('modalAttendanceID').value = attId;
        document.getElementById('modalTargetName').innerText = "Process Run: " + empName;
        document.getElementById('modalTargetRole').innerText = empRole;
        
        // Reset dynamic elements cleanly on form load initialization
        document.getElementById('holidayBonusToggle').checked = false;
        const container = document.getElementById('bonusInputContainer');
        const field = document.getElementById('bonusAmountField');
        container.classList.add('d-none');
        field.value = "0.00";
        field.removeAttribute('required');
        
        myModal.show();
    }

    function openSalaryEditModal(salaryId, paydate, amount) {
        document.getElementById('salaryEditID').value = salaryId;
        document.getElementById('salaryEditPaydate').value = paydate;
        document.getElementById('salaryEditAmount').value = parseFloat(amount).toFixed(2);
        new bootstrap.Modal(document.getElementById('salaryEditModal')).show();
    }

    function openAttendanceEditModal(attendanceId, date, login, logout, userID) {
        document.getElementById('attendanceEditID').value = attendanceId;
        document.getElementById('attendanceEditDate').value = date;
        document.getElementById('attendanceEditLogin').value = login;
        document.getElementById('attendanceEditLogout').value = logout;

        const editUserSelect = document.getElementById('attendanceEditUser');
        // userID is the user_tbl.userID for the employee; set it directly
        editUserSelect.value = userID || '';
        new bootstrap.Modal(document.getElementById('attendanceEditModal')).show();
    }

    function openExpenseEditModal(expenseId, expenseType, amount) {
        document.getElementById('expenseEditID').value = expenseId;
        document.getElementById('expenseEditType').value = expenseType;
        document.getElementById('expenseEditAmount').value = parseFloat(amount).toFixed(2);
        new bootstrap.Modal(document.getElementById('expenseEditModal')).show();
    }

    // Javascript Toggle Listener revealing or hiding the Manual Holiday Box component
    function toggleBonusInputBox(isChecked) {
        const container = document.getElementById('bonusInputContainer');
        const field = document.getElementById('bonusAmountField');
        if (isChecked) {
            container.classList.remove('d-none');
            field.setAttribute('required', 'true');
            field.value = "";
            field.focus();
        } else {
            container.classList.add('d-none');
            field.value = "0.00";
            field.removeAttribute('required');
        }
    }

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
        return workedHours > 8 ? workedHours - 8 : 0;
    }

    function loadEmployeeData() {
        const select = document.getElementById('employeeSelect');
        const selectedOption = select.options[select.selectedIndex];
        const baseSalaryInput = document.getElementById('baseSalary');
        const otRateInput = document.getElementById('otRate');
        const otRateDisplayInput = document.getElementById('otRateDisplay');
        const bonusAmount = document.getElementById('bonusAmount');
        const attendanceSelect = document.getElementById('attendanceSelect');

        if (!selectedOption || !selectedOption.value) {
            baseSalaryInput.value = 0;
            otRateInput.value = 0;
            otRateDisplayInput.value = 0;
            bonusAmount.value = 0;
            calculateTotalSalary();
            filterAttendanceRecords('');
            attendanceSelect.value = '';
            return;
        }

        const baseSalary = parseFloat(selectedOption.getAttribute('data-base-salary')) || 0;
        const otRate = parseFloat(selectedOption.getAttribute('data-ot-rate')) || 0;
        baseSalaryInput.value = baseSalary.toFixed(2);
        otRateInput.value = otRate.toFixed(2);
        otRateDisplayInput.value = otRate.toFixed(2);
        document.getElementById('basePayHidden').value = baseSalary.toFixed(2);
        filterAttendanceRecords(selectedOption.value);

        const firstVisibleOption = Array.from(attendanceSelect.options).find(option => option.value && !option.hidden && !option.disabled);
        if (firstVisibleOption) {
            attendanceSelect.value = firstVisibleOption.value;
            loadAttendanceData();
        } else {
            attendanceSelect.value = '';
            calculateTotalSalary();
        }
    }

    function loadAttendanceData() {
        const select = document.getElementById('attendanceSelect');
        const selectedOption = select.options[select.selectedIndex];
        const attendanceSummary = document.getElementById('attendanceSummary');
        const baseSalaryInput = document.getElementById('baseSalary');
        const otRateInput = document.getElementById('otRate');
        const otRateDisplayInput = document.getElementById('otRateDisplay');
        const otHoursInput = document.getElementById('otHours');

        if (!selectedOption || !selectedOption.value) {
            attendanceSummary.value = '';
            baseSalaryInput.value = 0;
            otRateInput.value = 0;
            otRateDisplayInput.value = 0;
            otHoursInput.value = 0;
            document.getElementById('basePayHidden').value = 0;
            document.getElementById('otPayHidden').value = 0;
            calculateTotalSalary();
            return;
        }

        const login = selectedOption.getAttribute('data-login') || '';
        const logout = selectedOption.getAttribute('data-logout') || '';
        const date = selectedOption.getAttribute('data-date') || '';
        const role = selectedOption.getAttribute('data-role') || 'Supervisor';
        const baseSalary = parseFloat(selectedOption.getAttribute('data-base-salary')) || 0;
        const otRate = parseFloat(selectedOption.getAttribute('data-ot-rate')) || 0;
        const hourRate = parseFloat(selectedOption.getAttribute('data-hour-rate')) || 0;
        const fixedSalary = parseFloat(selectedOption.getAttribute('data-fixed-salary')) || 0;

        attendanceSummary.value = date + ' | ' + login + ' - ' + logout;
        let effectiveBase = 0;
        let effectiveOtRate = 0;
        let otHours = 0;

        if (role === 'Worker') {
            const workedHours = calculateWorkedHours(login, logout);
            effectiveBase = workedHours * hourRate;
            effectiveOtRate = 0;
            otHours = 0;
        } else if (role === 'Driver') {
            effectiveBase = fixedSalary;
            effectiveOtRate = 0;
            otHours = 0;
        } else {
            effectiveBase = baseSalary;
            effectiveOtRate = otRate;
            otHours = calculateOvertimeHours(login, logout).toFixed(2);
        }

        baseSalaryInput.value = effectiveBase.toFixed(2);
        otRateInput.value = effectiveOtRate.toFixed(2);
        otRateDisplayInput.value = effectiveOtRate.toFixed(2);
        document.getElementById('basePayHidden').value = effectiveBase.toFixed(2);
        document.getElementById('otPayHidden').value = (otHours * effectiveOtRate).toFixed(2);
        otHoursInput.value = otHours;
        calculateTotalSalary();
    }

    function calculateWorkedHours(loginValue, logoutValue) {
        const loginTime = parseTimeValue(loginValue);
        const logoutTime = parseTimeValue(logoutValue);
        if (!loginTime || !logoutTime) {
            return 0;
        }
        let workedMs = logoutTime.getTime() - loginTime.getTime();
        if (workedMs < 0) {
            workedMs += 24 * 60 * 60 * 1000;
        }
        return workedMs / (1000 * 60 * 60);
    }

    function filterAttendanceRecords(selectedEmployeeId) {
        const attendanceSelect = document.getElementById('attendanceSelect');
        const attendanceSummary = document.getElementById('attendanceSummary');
        const selectedValue = attendanceSelect.value;
        let preservedSelectionValid = false;
        let visibleCount = 0;

        for (let i = 0; i < attendanceSelect.options.length; i++) {
            const option = attendanceSelect.options[i];
            if (!option.value) {
                option.hidden = false;
                option.disabled = false;
                option.style.display = '';
                continue;
            }
            const optionUserId = (option.getAttribute('data-user-id') || '').trim();
            const shouldShow = !selectedEmployeeId || optionUserId === selectedEmployeeId;
            option.hidden = !shouldShow;
            option.disabled = !shouldShow;
            option.style.display = shouldShow ? '' : 'none';
            if (shouldShow) {
                visibleCount++;
            }
            if (option.value === selectedValue && shouldShow) {
                preservedSelectionValid = true;
            }
        }

        if (!preservedSelectionValid) {
            attendanceSelect.value = '';
            attendanceSummary.value = '';
            document.getElementById('basePayHidden').value = 0;
            document.getElementById('otPayHidden').value = 0;
            calculateTotalSalary();
        }

        const placeholder = attendanceSelect.querySelector('option[value=""]');
        if (placeholder) {
            placeholder.textContent = visibleCount === 0
                ? '-- No attendance record for selected supervisor --'
                : '-- Choose Attendance Record --';
        }

        console.debug('Attendance filter', { selectedEmployeeId, visibleCount });
    }

    function toggleBonusField() {
        const bonusToggle = document.getElementById('bonusToggle');
        const bonusInput = document.getElementById('bonusAmount');
        bonusInput.disabled = !bonusToggle.checked;
        if (!bonusToggle.checked) {
            bonusInput.value = 0;
        }
        calculateTotalSalary();
    }

    function calculateTotalSalary() {
        const baseSalary = parseFloat(document.getElementById('baseSalary').value) || 0;
        const otHours = parseFloat(document.getElementById('otHours').value) || 0;
        const otRate = parseFloat(document.getElementById('otRateDisplay').value) || parseFloat(document.getElementById('otRate').value) || 0;
        const bonus = parseFloat(document.getElementById('bonusAmount').value) || 0;

        const basePay = baseSalary;
        const otPay = otHours * otRate;
        document.getElementById('basePayHidden').value = basePay.toFixed(2);
        document.getElementById('otPayHidden').value = otPay.toFixed(2);

        const totalPayout = basePay + otPay + bonus;
        document.getElementById('displayTotalPayout').innerText = 'LKR ' + totalPayout.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function resetSupervisorSalaryPortal() {
        document.getElementById('employeeSelect').selectedIndex = 0;
        document.getElementById('attendanceSelect').selectedIndex = 0;
        document.getElementById('attendanceSummary').value = '';
        document.getElementById('baseSalary').value = 0;
        document.getElementById('otRate').value = 0;
        document.getElementById('otRateDisplay').value = 0;
        document.getElementById('otHours').value = 0;
        document.getElementById('bonusAmount').value = 0;
        document.getElementById('bonusToggle').checked = false;
        document.getElementById('basePayHidden').value = 0;
        document.getElementById('otPayHidden').value = 0;
        document.getElementById('displayTotalPayout').innerText = 'LKR 0.00';
    }
</script>
</body>
</html>