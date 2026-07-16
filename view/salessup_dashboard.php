<?php
// salessup/dashboard.php
session_start();
require_once dirname(__DIR__) . '/model/config/database.php';

// Strict Session Guard Check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'salessup') {
    header("Location: ../auth/login.php");
    exit;
}

$conn = getDBConnection();
$message = "";
$error = "";

// Handle Order State Interventions (Status Toggles)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_order') {
    $orderID = intval($_POST['order_id']);
    $status_type = $_POST['status_type']; // 'processed', 'delivered', 'cancelled'
    
    // Reset flags cleanly first, then elevate the targeted status flag
    $clearQuery = "UPDATE Order_tbl SET pending = 0, processed = 0, delivered = 0, cancelled = 0 WHERE orderID = ?";
    $clearStmt = $conn->prepare($clearQuery);
    $clearStmt->bind_param("i", $orderID);
    $clearStmt->execute();

    $updateQuery = "UPDATE Order_tbl SET `$status_type` = 1 WHERE orderID = ?";
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->bind_param("i", $orderID);
    
    if ($updateStmt->execute()) {
        $message = "Order status vector updated securely.";
    } else {
        $error = "Failed to mutate workflow stage status.";
    }
}

// 1. Fetch Complete Order Matrix Tracking
$ordersQuery = "SELECT o.orderID, o.date, o.totamt, c.companyname, o.pending, o.processed, o.delivered, o.cancelled 
                FROM Order_tbl o 
                LEFT JOIN Customer_tbl c ON o.customerID = c.customerID 
                ORDER BY o.date DESC";
$ordersResult = $conn->query($ordersQuery);

// 2. Fetch Aggregated Performance Metric Logs
$salesQuery = "SELECT salesID, dateofsales, dailyincome FROM DailySales_tbl ORDER BY dateofsales DESC LIMIT 7";
$salesResult = $conn->query($salesQuery);
?>

<?php require_once dirname(__DIR__) . '/includes/header.php'; ?>

<!-- Top Navigation Module -->
<nav class="navbar navbar-expand-lg navbar-dark bg-forest shadow-sm">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="#">🌾 Sales Channels & Pipeline Control</a>
        <div class="ms-auto d-flex align-items-center gap-3">
            <span class="badge bg-light text-success px-3 py-2 font-monospace fw-bold">Role: Sales Supervisor</span>
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

    <div class="row">
        <!-- Revenue Matrix Pipeline Trend Tracker -->
        <div class="col-12 col-md-3 mb-4">
            <div class="card border-0 shadow-sm p-4 bg-white rounded mb-4">
                <h5 class="fw-bold text-dark border-bottom pb-2 mb-3">Daily Intake Trends</h5>
                <div class="d-flex flex-column gap-3">
                    <?php if ($salesResult && $salesResult->num_rows > 0): ?>
                        <?php while ($sale = $salesResult->fetch_assoc()): ?>
                            <div class="d-flex justify-content-between align-items-center border-bottom pb-2">
                                <span class="small text-muted font-monospace"><?= htmlspecialchars($sale['dateofsales']) ?></span>
                                <strong class="small text-emerald">LKR <?= number_format($sale['dailyincome'], 2) ?></strong>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <span class="text-muted small">No dynamic income lines tracked over the past week cycle.</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Order Workflow Grid Workspace Panel -->
        <div class="col-12 col-md-9">
            <div class="card border-0 shadow-sm rounded bg-white">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="mb-0 fw-bold text-dark">System-Wide Fulfillment Center</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light text-secondary font-monospace small">
                                <tr>
                                    <th class="ps-4">Ref Key</th>
                                    <th>Client Target</th>
                                    <th>Total Value</th>
                                    <th>Fulfillment Phase</th>
                                    <th class="text-center">Workflow Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($ordersResult && $ordersResult->num_rows > 0): ?>
                                    <?php while ($order = $ordersResult->fetch_assoc()): ?>
                                        <tr>
                                            <td class="ps-4 fw-bold text-dark">#ORD-<?= $order['orderID'] ?></td>
                                            <td class="small fw-semibold text-secondary"><?= htmlspecialchars($order['companyname'] ?? 'Walk-in Register') ?></td>
                                            <td class="fw-bold text-emerald">LKR <?= number_format($order['totamt'], 2) ?></td>
                                            <td>
                                                <?php if ($order['cancelled'] == 1): ?>
                                                    <span class="badge bg-danger-subtle text-danger rounded-pill px-2">Cancelled</span>
                                                <?php elseif ($order['delivered'] == 1): ?>
                                                    <span class="badge bg-success-subtle text-success rounded-pill px-2">Delivered</span>
                                                <?php elseif ($order['processed'] == 1): ?>
                                                    <span class="badge bg-primary-subtle text-primary rounded-pill px-2">Processed</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning-subtle text-warning rounded-pill px-2">Pending Execution</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <form method="POST" action="" class="d-inline-flex gap-1">
                                                    <input type="hidden" name="order_id" value="<?= $order['orderID'] ?>">
                                                    <input type="hidden" name="action" value="update_order">
                                                    
                                                    <button type="submit" name="status_type" value="processed" class="btn btn-sm btn-outline-primary py-0 px-2 small" title="Mark Processed">⚙️ Process</button>
                                                    <button type="submit" name="status_type" value="delivered" class="btn btn-sm btn-outline-success py-0 px-2 small" title="Mark Delivered">✓ Deliver</button>
                                                    <button type="submit" name="status_type" value="cancelled" class="btn btn-sm btn-outline-danger py-0 px-2 small" title="Cancel Order">✕ Drop</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-muted small">No global orders recorded inside database collections.</td>
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