<?php
// owner/dashboard.php
session_start();
require_once dirname(__DIR__) . '/config/database.example.php';

// Session Security Guard Check
if (!isset($_SESSION['user_id']) || $_SESSION['username'] !== 'owner01') {
    header("Location: ../auth/login.php");
    exit;
}

$conn = getDBConnection();

// 1. Calculate Total Enterprise Income from Cleared Incoming Payments
$incomeQuery = "SELECT SUM(amount) AS total_income FROM Payment_tbl WHERE incoming = 1 AND cleared = 1";
$incomeResult = $conn->query($incomeQuery);
$incomeRow = $incomeResult->fetch_assoc();
$totalIncome = $incomeRow['total_income'] ?? 0.00;

// 2. Calculate Operational Outflows from Expense Records
$expenseQuery = "SELECT SUM(amount) AS total_expenses FROM Expense_tbl";
$expenseResult = $conn->query($expenseQuery);
$expenseRow = $expenseResult->fetch_assoc();
$totalExpenses = $expenseRow['total_expenses'] ?? 0.00;

// 3. Calculate Net Profit Matrix
$netProfit = $totalIncome - $totalExpenses;

// 4. Fetch Recent Orders for the Data Table Display
$ordersQuery = "SELECT o.orderID, o.date, o.totamt, c.companyname, o.processed 
                FROM Order_tbl o 
                LEFT JOIN Customer_tbl c ON o.customerID = c.customerID 
                ORDER BY o.date DESC LIMIT 5";
$ordersResult = $conn->query($ordersQuery);
?>

<?php require_once dirname(__DIR__) . '/includes/header.php'; ?>

<!-- Top Navigation Module -->
<nav class="navbar navbar-expand-lg navbar-dark bg-forest shadow-sm">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="#">🌾 Executive Control Control Panel</a>
        <div class="ms-auto d-flex align-items-center gap-3">
            <span class="badge bg-success text-white px-3 py-2 font-monospace">Role: System Owner</span>
            <a class="btn btn-sm btn-outline-light" href="../auth/logout.php">Log Out</a>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 my-4">
    <div class="row">
        <!-- Sidebar Menu Layout Grid Component -->
        <div class="col-12 col-md-2 mb-4">
            <div class="card border-0 shadow-sm p-3 bg-white rounded">
                <p class="text-uppercase text-secondary small fw-bold tracking-wider mb-2">Management</p>
                <ul class="nav flex-column gap-2 font-monospace small">
                    <li class="nav-item">
                        <a class="nav-link text-emerald fw-bold p-2 bg-light rounded" href="#">📊 Analytics</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link text-dark p-2" href="../customer/dashboard.php">🛒 Test Customer</a>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Main Dashboard Functional Workspace Workspace -->
        <div class="col-12 col-md-10">
            <div class="mb-4">
                <h2 class="fw-bold text-dark">Enterprise Overview Matrix</h2>
                <p class="text-muted small">Real-time analytical metrics aggregated across operations, supply chains, and settlements.</p>
            </div>

            <!-- Real-Time Analytical KPI Cards Grid Row -->
            <div class="row g-3 mb-4">
                <div class="col-12 col-sm-4">
                    <div class="card border-0 shadow-sm rounded bg-white">
                        <div style="height: 4px; background-color: var(--emerald); border-radius: 4px 4px 0 0;"></div>
                        <div class="card-body p-4">
                            <h6 class="text-muted text-uppercase small font-monospace">Total Cleared Income</h6>
                            <h3 class="fw-bold text-success mt-1">LKR <?= number_format($totalIncome, 2) ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-sm-4">
                    <div class="card border-0 shadow-sm rounded bg-white">
                        <div style="height: 4px; background-color: #ef4444; border-radius: 4px 4px 0 0;"></div>
                        <div class="card-body p-4">
                            <h6 class="text-muted text-uppercase small font-monospace">Operational Expenses</h6>
                            <h3 class="fw-bold text-danger mt-1">LKR <?= number_format($totalExpenses, 2) ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-sm-4">
                    <div class="card border-0 shadow-sm rounded bg-white">
                        <div style="height: 4px; background-color: #3b82f6; border-radius: 4px 4px 0 0;"></div>
                        <div class="card-body p-4">
                            <h6 class="text-muted text-uppercase small font-monospace">Net Profit Margin</h6>
                            <h3 class="fw-bold text-primary mt-1">LKR <?= number_format($netProfit, 2) ?></h3>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Orders Data Monitoring Grid Block -->
            <div class="card border-0 shadow-sm rounded bg-white">
                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                    <h5 class="mb-0 fw-bold text-dark">Recent Core Sales Pipeline</h5>
                    <span class="badge bg-light text-dark font-monospace small">Live Tracking</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light text-secondary font-monospace small">
                                <tr>
                                    <th class="ps-4">Order Ref</th>
                                    <th>Client Target Account</th>
                                    <th>Transaction Value</th>
                                    <th class="text-center">Workflow Stage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($ordersResult && $ordersResult->num_rows > 0): ?>
                                    <?php while ($order = $ordersResult->fetch_assoc()): ?>
                                        <tr>
                                            <td class="ps-4 fw-bold text-dark">#ORD-<?= $order['orderID'] ?></td>
                                            <td class="small fw-semibold text-secondary"><?= htmlspecialchars($order['companyname'] ?? 'Walk-in Trade') ?></td>
                                            <td class="fw-bold text-emerald">LKR <?= number_format($order['totamt'], 2) ?></td>
                                            <td class="text-center">
                                                <?php if ($order['processed'] == 1): ?>
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3">Processed</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill px-3">Pending Verification</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-4 text-muted small">No order entries currently cataloged in the framework.</td>
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

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>