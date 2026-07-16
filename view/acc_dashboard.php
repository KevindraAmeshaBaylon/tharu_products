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

// ==========================================
// FORM PROCESSOR 1: SALARY RUN CALCULATION 
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'calculate_salary') {
    $attendanceID = intval($_POST['attendance_id']);
    $paydate = $_POST['paydate'];
    $has_bonus = isset($_POST['holiday_bonus_toggle']) ? 1 : 0;
    $bonus_amount = $has_bonus ? floatval($_POST['bonus_amount']) : 0.00;

    if (empty($paydate)) {
        $error_message = "Please select a valid payment settlement date.";
    } else {
        // Find matching employee attendance profiles from all role domains
        // excluding accountant IDs entirely
        $query = "
            SELECT a.*, 
                   w.workerID, w.hour_rate, wu.username as worker_name,
                   s.stocksupID, s.base_salary as stock_base, s.OT_rate as stock_ot, su.username as stock_name,
                   ss.salessupID, ss.base_salary as sales_base, ss.OT_rate as sales_ot, ssu.username as sales_name,
                   d.driverID, d.fixed_salary, du.username as driver_name
            FROM attendance_tbl a
            LEFT JOIN worker_tbl w ON a.attendanceID = w.attendanceID
            LEFT JOIN user_tbl wu ON w.userID = wu.userID
            LEFT JOIN stocksupervisor_tbl s ON a.attendanceID = s.attendanceID
            LEFT JOIN user_tbl su ON s.userID = su.userID
            LEFT JOIN salessupervisor_tbl ss ON a.attendanceID = ss.attendanceID
            LEFT JOIN user_tbl ssu ON ss.userID = ssu.userID
            LEFT JOIN driver_tbl d ON a.attendanceID = d.attendanceID
            LEFT JOIN user_tbl du ON d.userID = du.userID
            WHERE a.attendanceID = ? LIMIT 1
        ";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $attendanceID);
        $stmt->execute();
        $employee = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$employee) {
            $error_message = "Selected target attendance matrix map does not exist.";
        } else {
            // Apply core business formulas
            $calc_salary = 0.00;
            $emp_display_name = "Employee";
            $emp_role_label = "";
            
            // Core hours calculation parsing
            $hours_worked = 0;
            if (!empty($employee['login']) && !empty($employee['logout'])) {
                $hours_worked = (strtotime($employee['logout']) - strtotime($employee['login'])) / 3600;
                if ($hours_worked < 0) $hours_worked = 0;
            }

            if (!empty($employee['workerID'])) {
                $calc_salary = $hours_worked * floatval($employee['hour_rate']);
                $emp_display_name = $employee['worker_name'];
                $emp_role_label = "Worker";
            } elseif (!empty($employee['stocksupID'])) {
                $base = floatval($employee['stock_base']);
                $ot_rate = floatval($employee['stock_ot']);
                $ot_hours = max(0, $hours_worked - 8);
                $calc_salary = $base + ($ot_rate * $ot_hours);
                $emp_display_name = $employee['stock_name'];
                $emp_role_label = "Stock Supervisor";
            } elseif (!empty($employee['salessupID'])) {
                $base = floatval($employee['sales_base']);
                $ot_rate = floatval($employee['sales_ot']);
                $ot_hours = max(0, $hours_worked - 8);
                $calc_salary = $base + ($ot_rate * $ot_hours);
                $emp_display_name = $employee['sales_name'];
                $emp_role_label = "Sales Supervisor";
            } elseif (!empty($employee['driverID'])) {
                $calc_salary = floatval($employee['fixed_salary']);
                $emp_display_name = $employee['driver_name'];
                $emp_role_label = "Driver";
            }

            $total_amount_paid = $calc_salary + $bonus_amount;

            // Database Transaction Commit Phase
            $conn->begin_transaction();
            try {
                // 1. Commit records to salary_tbl
                $salStmt = $conn->prepare("INSERT INTO salary_tbl (paydate, totamtpaid, attendanceID, acountantID) VALUES (?, ?, ?, ?)");
                $salStmt->bind_param("sdii", $paydate, $total_amount_paid, $attendanceID, $accountantID);
                $salStmt->execute();
                $salStmt->close();

                // 2. Automated double-write system allocation into expense_tbl
                $exp_type = "Salary Payment - " . $emp_display_name . " (" . $emp_role_label . ")";
                $expStmt = $conn->prepare("INSERT INTO expense_tbl (type, amount, acountantID, materialID) VALUES (?, ?, ?, NULL)");
                $expStmt->bind_param("sdi", $exp_type, $total_amount_paid, $accountantID);
                $expStmt->execute();
                $expStmt->close();

                $conn->commit();
                $success_message = "Payroll allocated cleanly! $total_amount_paid LKR disbursed and tracked in operational expenses.";
            } catch (Exception $e) {
                $conn->rollback();
                $error_message = "relational engine rejected insert operation: " . $e->getMessage();
            }
        }
    }
}

// ==========================================
// FORM PROCESSOR 2: MANUAL EXPENSE MANIFESTS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'log_expense') {
    $expense_type = trim($_POST['expense_type']);
    $custom_type = trim($_POST['custom_expense_type'] ?? '');
    $amount = floatval($_POST['amount']);

    $final_type = ($expense_type === 'Other' && !empty($custom_type)) ? $custom_type : $expense_type;

    if (!empty($final_type) && $amount > 0) {
        $stmt = $conn->prepare("INSERT INTO expense_tbl (type, amount, acountantID, materialID) VALUES (?, ?, ?, NULL)");
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
    LEFT JOIN stocksupervisor_tbl s ON a.attendanceID = s.attendanceID LEFT JOIN user_tbl su ON s.userID = su.userID
    LEFT JOIN salessupervisor_tbl ss ON a.attendanceID = ss.attendanceID LEFT JOIN user_tbl ssu ON ss.userID = ssu.userID
    LEFT JOIN driver_tbl d ON a.attendanceID = d.attendanceID LEFT JOIN user_tbl du ON d.userID = du.userID
    LEFT JOIN salary_tbl sal ON a.attendanceID = sal.attendanceID
    WHERE sal.salaryID IS NULL 
      AND (w.workerID IS NOT NULL OR s.stocksupID IS NOT NULL OR ss.salessupID IS NOT NULL OR d.driverID IS NOT NULL)
    ORDER BY a.date DESC
");

// Fetch processed Salary ledger accounts histories
$salaryLedger = $conn->query("
    SELECT s.salaryID, s.paydate, s.totamtpaid, a.date as att_date, a.login, a.logout,
           COALESCE(wu.username, su.username, ssu.username, du.username) as emp_name
    FROM salary_tbl s
    JOIN attendance_tbl a ON s.attendanceID = a.attendanceID
    LEFT JOIN worker_tbl w ON a.attendanceID = w.attendanceID LEFT JOIN user_tbl wu ON w.userID = wu.userID
    LEFT JOIN stocksupervisor_tbl st ON a.attendanceID = st.attendanceID LEFT JOIN user_tbl su ON st.userID = su.userID
    LEFT JOIN salessupervisor_tbl ss ON a.attendanceID = ss.attendanceID LEFT JOIN user_tbl ssu ON ss.userID = ssu.userID
    LEFT JOIN driver_tbl d ON a.attendanceID = d.attendanceID LEFT JOIN user_tbl du ON d.userID = du.userID
    ORDER BY s.paydate DESC
");

// Fetch combined organizational layout expense structures
$expenseLedger = $conn->query("SELECT * FROM expense_tbl ORDER BY expenseID DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Accountant Operations Workspace Node</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; color: #1e293b; }
        .card { border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
        .table { vertical-align: middle; }
        .btn-success { background-color: #16a34a; border-color: #16a34a; border-radius: 10px; font-weight: 600; }
        .btn-success:hover { background-color: #15803d; }
        .form-switch .form-check-input { width: 2.5em; height: 1.25em; cursor: pointer; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container">
        <span class="navbar-brand fw-bold text-success">Tharu Products Ledger Interface</span>
        <div class="d-flex text-light align-items-center">
            <span class="me-3 small text-muted">Active Node: Accountant #<?= htmlspecialchars($accountantID) ?></span>
            <a href="../../auth/logout.php" class="btn btn-outline-danger btn-sm rounded-pill">Disconnect Workspace</a>
        </div>
    </div>
</nav>

<div class="container mb-5">
    
    <?php if(!empty($error_message)): ?>
        <div class="alert alert-danger border-0 shadow-sm mb-4" style="border-radius:12px;">⚠️ <?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>
    <?php if(!empty($success_message)): ?>
        <div class="alert alert-success border-0 shadow-sm mb-4" style="border-radius:12px;">✓ <?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <div class="row g-4">
        
        <div class="col-12 col-xl-7">
            <div class="card bg-white p-4">
                <h4 class="fw-bold mb-3 text-dark">Awaiting Payroll Calculation Sessions</h4>
                <p class="text-muted small">Select an employee session below to run calculations based on their specialized compensation model.</p>
                
                <div class="table-responsive">
                    <table class="table table-hover border-top">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Name</th>
                                <th>Operational Position</th>
                                <th>Login/Logout</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($attendanceList && $attendanceList->num_rows > 0): ?>
                                <?php while($row = $attendanceList->fetch_assoc()): ?>
                                    <tr>
                                        <td class="fw-semibold small"><?= htmlspecialchars($row['date']) ?></td>
                                        <td><?= htmlspecialchars($row['emp_name']) ?></td>
                                        <td><span class="badge bg-secondary rounded-pill"><?= htmlspecialchars($row['emp_role']) ?></span></td>
                                        <td class="small text-muted"><?= htmlspecialchars($row['login']) ?> - <?= htmlspecialchars($row['logout']) ?></td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-success btn-sm px-3" onclick="openPayrollModal(<?= $row['attendanceID'] ?>, '<?= htmlspecialchars($row['emp_name']) ?>', '<?= htmlspecialchars($row['emp_role']) ?>')">Calculate</button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-center text-muted small py-4">No missing records discovered inside attendance ledger matrices.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-5">
            <div class="card bg-white p-4">
                <h4 class="fw-bold mb-3 text-dark">Manual Operating Expenses Registry</h4>
                <form action="dashboard.php" method="POST">
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

    </div>

    <div class="row mt-5 g-4">
        
        <div class="col-12 col-lg-6">
            <div class="card bg-white p-4">
                <h5 class="fw-bold mb-3 text-success">Salary Disbursements Summary Ledger</h5>
                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-sm text-center border-top">
                        <thead class="table-light">
                            <tr>
                                <th>Pay Day</th>
                                <th>Staff Target</th>
                                <th>Attendance Ref</th>
                                <th>Disbursed Total</th>
                            </tr>
                        </thead>
                        <tbody class="small">
                            <?php if($salaryLedger && $salaryLedger->num_rows > 0): ?>
                                <?php while($sl = $salaryLedger->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($sl['paydate']) ?></td>
                                        <td class="fw-semibold text-start"><?= htmlspecialchars($sl['emp_name']) ?></td>
                                        <td class="text-muted"><?= htmlspecialchars($sl['att_date']) ?></td>
                                        <td class="text-success fw-bold"><?= number_format($sl['totamtpaid'], 2) ?> LKR</td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="text-muted py-3">No historical payments indexed.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card bg-white p-4">
                <h5 class="fw-bold mb-3 text-dark">Combined General Expense Ledger</h5>
                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-sm text-center border-top">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Expense Classification Type</th>
                                <th>Disbursed Amount</th>
                            </tr>
                        </thead>
                        <tbody class="small">
                            <?php if($expenseLedger && $expenseLedger->num_rows > 0): ?>
                                <?php while($el = $expenseLedger->fetch_assoc()): ?>
                                    <tr>
                                        <td>#<?= htmlspecialchars($el['expenseID']) ?></td>
                                        <td class="text-start"><?= htmlspecialchars($el['type']) ?></td>
                                        <td class="fw-semibold text-danger"><?= number_format($el['amount'], 2) ?> LKR</td>
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

</div>

<div class="modal fade" id="payrollProcessingModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form action="dashboard.php" method="POST" class="modal-content border-0 shadow-lg" style="border-radius:20px;">
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
</script>
</body>
</html>