<?php
// accountant/dashboard.php
session_start();
require_once dirname(__DIR__) . '/config/database.php';

// Strict Session Guard Check
if (!isset($_SESSION['user_id']) || $_SESSION['username'] !== 'accountant01') {
    header("Location: ../auth/login.php");
    exit;
}

$conn = getDBConnection();
$message = "";
$error = "";

// Handle New Expense Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_expense') {
    $type = trim($_POST['type']);
    $amount = floatval($_POST['amount']);
    
    if (!empty($type) && $amount > 0) {
        $stmt = $conn->prepare("INSERT INTO Expense_tbl (type, amount) VALUES (?, ?)");
        if ($stmt) {
            $stmt->bind_param("sd", $type, $amount);
            if ($stmt->execute()) {
                $message = "Expense successfully logged into the system ledger.";
            } else {
                $error = "Failed to commit expense execution: " . $conn->error;
            }
        }
    } else {
        $error = "Please provide valid transaction values.";
    }
}

// 1. Fetch Expense Log Entries
$expenseQuery = "SELECT expenseID, type, amount FROM Expense_tbl ORDER BY expenseID DESC LIMIT 10";
$expenseResult = $conn->query($expenseQuery);

// 2. Fetch Driver Non-Salaried Wage Records
$wageQuery = "SELECT w.wageID, d.name AS driver_name, w.dailyrate, w.workinghrs, w.totwage, w.wagestatus 
              FROM Wage_tbl w 
              LEFT JOIN Driver_tbl d ON w.driverID = d.driverID 
              ORDER BY w.wageID DESC";
$wageResult = $conn->query($wageQuery);
?>

<?php require_once dirname(__DIR__) . '/includes/header.php'; ?>

<!-- Top Navigation Module -->
<nav class="navbar navbar-expand-lg navbar-dark bg-forest shadow-sm">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="#">🌾 Finance & Accounting Desk</a>
        <div class="ms-auto d-flex align-items-center gap-3">
            <span class="badge bg-light text-success px-3 py-2 font-monospace fw-bold">Role: Accountant</span>
            <a class="btn btn-sm btn-outline-light" href="../auth/logout.php">Log Out</a>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 my-4">
    <!-- Status Alerts -->
    <?php if(!empty($message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if(!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Input Form Panels Sidebar Column -->
        <div class="col-12 col-md-4 mb-4">
            <div class="card border-0 shadow-sm p-4 bg-white rounded mb-4">
                <h5 class="fw-bold text-dark border-bottom pb-2 mb-3">Record Operational Outflow</h5>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="add_expense">
                    <div class="mb-3">
                        <label class="form-label text-dark fw-semibold small">Expense Allocation Type</label>
                        <select name="type" class="form-select" required>
                            <option value="">Choose Category...</option>
                            <option value="Raw Materials Procurement">Raw Materials Procurement</option>
                            <option value="Utility Grid Systems">Utility Grid Systems</option>
                            <option value="Logistics Fuel Operations">Logistics Fuel Operations</option>
                            <option value="Equipment Repair">Equipment Repair</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-dark fw-semibold small">Amount Paid (LKR)</label>
                        <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00" required>
                    </div>
                    <button type="submit" class="btn btn-forest w-100 fw-bold shadow-sm">Commit Expense Log</button>
                </form>
            </div>
        </div>

        <!-- Main Ledger View Rows -->
        <div class="col-12 col-md-8">
            <!-- Expenses Monitoring Module -->
            <div class="card border-0 shadow-sm rounded bg-white mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="mb-0 fw-bold text-dark">Live Enterprise Outflow Ledger</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light text-secondary font-monospace small">
                                <tr>
                                    <th class="ps-4">Expense Ref</th>
                                    <th>Allocation Stream</th>
                                    <th>Amount (LKR)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($expenseResult && $expenseResult->num_rows > 0): ?>
                                    <?php while ($exp = $expenseResult->fetch_assoc()): ?>
                                        <tr>
                                            <td class="ps-4 fw-bold text-dark">#EXP-<?= $exp['expenseID'] ?></td>
                                            <td class="text-secondary small fw-semibold"><?= htmlspecialchars($exp['type']) ?></td>
                                            <td class="fw-bold text-danger">LKR <?= number_format($exp['amount'], 2) ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" class="text-center py-4 text-muted small">No structural outlays recorded inside the current active scope.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Wages Pipeline Module -->
            <div class="card border-0 shadow-sm rounded bg-white">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="mb-0 fw-bold text-dark">Logistics Contractor Wage Processing</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light text-secondary font-monospace small">
                                <tr>
                                    <th class="ps-4">Wage Id</th>
                                    <th>Driver Target</th>
                                    <th>Metrics (Rate × Hours)</th>
                                    <th>Gross Total</th>
                                    <th class="text-center">Settlement Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($wageResult && $wageResult->num_rows > 0): ?>
                                    <?php while ($wage = $wageResult->fetch_assoc()): ?>
                                        <tr>
                                            <td class="ps-4 fw-bold text-dark">#WGE-<?= $wage['wageID'] ?></td>
                                            <td class="small fw-semibold text-secondary"><?= htmlspecialchars($wage['driver_name'] ?? 'Unassigned Operator') ?></td>
                                            <td class="small font-monospace text-muted">LKR <?= number_format($wage['dailyrate'], 2) ?> × <?= $wage['workinghrs'] ?> hrs</td>
                                            <td class="fw-bold text-dark">LKR <?= number_format($wage['totwage'], 2) ?></td>
                                            <td class="text-center">
                                                <?php if (strtolower($wage['wagestatus']) === 'paid'): ?>
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3">Cleared</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill px-3">Pending Action</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-muted small">No variable tactical wages currently logged for settlement processing.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
