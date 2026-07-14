<?php
// customer/dashboard.php
session_start();
require_once dirname(__DIR__) . '/config/database.example.php';

// Strict Session Guard Check
if (!isset($_SESSION['user_id']) || $_SESSION['username'] !== 'customer01') {
    header("Location: ../auth/login.php");
    exit;
}

$conn = getDBConnection();
$userID = $_SESSION['user_id'];

// 1. Fetch Customer Profile details matching Customer_tbl with error catch
$custQuery = "SELECT customerID, companyname, contact, address FROM Customer_tbl WHERE userID = ?";
$custStmt = $conn->prepare($custQuery);

if (!$custStmt) {
    // If the database structure doesn't match, show us exactly what went wrong
    die("<h4>Database Query Failure on Customer Registration Lookup:</h4>" . $conn->error . "<br><br>Please check if the columns inside <code>Customer_tbl</code> exactly match your database schema.");
}

$custStmt->bind_param("i", $userID);
$custStmt->execute();
$customerResult = $custStmt->get_result();
$customer = $customerResult->fetch_assoc();

$customerID = $customer['customerID'] ?? 0;

// 2. Query all Order logs connected to this Customer account
$ordersQuery = "SELECT orderID, date, totamt, pending, processed, delivered, cancelled 
                FROM Order_tbl 
                WHERE customerID = ? 
                ORDER BY date DESC";
$ordersStmt = $conn->prepare($ordersQuery);

if (!$ordersStmt) {
    die("<h4>Database Query Failure on Order Log Lookup:</h4>" . $conn->error);
}

$ordersStmt->bind_param("i", $customerID);
$ordersStmt->execute();
$ordersResult = $ordersStmt->get_result();
?>

<?php require_once dirname(__DIR__) . '/includes/header.php'; ?>

<!-- Top Navigation Module -->
<nav class="navbar navbar-expand-lg navbar-dark bg-forest shadow-sm">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="#">🌾 Customer Workspace</a>
        <div class="ms-auto d-flex align-items-center gap-3">
            <span class="badge bg-light text-success px-3 py-2 font-monospace fw-bold">
                🏢 <?= htmlspecialchars($customer['companyname'] ?? 'Client Profile') ?>
            </span>
            <a class="btn btn-sm btn-outline-light" href="../auth/logout.php">Log Out</a>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 my-4">
    <div class="row">
        <!-- Profile Overview Sidebar -->
        <div class="col-12 col-md-3 mb-4">
            <div class="card border-0 shadow-sm p-4 bg-white rounded">
                <h5 class="fw-bold text-dark border-bottom pb-2 mb-3">Client Details</h5>
                <div class="small gap-2 d-flex flex-column">
                    <div>
                        <span class="text-muted d-block font-monospace text-uppercase" style="font-size: 0.75rem;">Contact Person</span>
                        <strong class="text-dark"><?= htmlspecialchars($customer['contact'] ?? 'N/A') ?></strong>
                    </div>
                    <div>
                        <span class="text-muted d-block font-monospace text-uppercase" style="font-size: 0.75rem;">Email Address</span>
                        <strong class="text-dark"><?= htmlspecialchars($customer['email'] ?? 'N/A') ?></strong>
                    </div>
                    <div>
                        <span class="text-muted d-block font-monospace text-uppercase" style="font-size: 0.75rem;">Shipping Destination</span>
                        <strong class="text-dark"><?= htmlspecialchars($customer['address'] ?? 'N/A') ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Dashboard Activity Workspace -->
        <div class="col-12 col-md-9">
            <div class="mb-4">
                <h2 class="fw-bold text-dark">Purchasing & Delivery Tracking</h2>
                <p class="text-muted small">Monitor dispatch pipeline progress, historical transaction values, and fulfillment states live.</p>
            </div>

            <!-- Orders Activity Table Component -->
            <div class="card border-0 shadow-sm rounded bg-white">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="mb-0 fw-bold text-dark">Your Historic Supply Orders</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light text-secondary font-monospace small">
                                <tr>
                                    <th class="ps-4">Order Ref</th>
                                    <th>Date Generated</th>
                                    <th>Total Value</th>
                                    <th class="text-center">Fulfillment Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($ordersResult && $ordersResult->num_rows > 0): ?>
                                    <?php while ($order = $ordersResult->fetch_assoc()): ?>
                                        <tr>
                                            <td class="ps-4 fw-bold text-dark">#ORD-<?= $order['orderID'] ?></td>
                                            <td class="text-secondary small"><?= htmlspecialchars($order['date']) ?></td>
                                            <td class="fw-bold text-emerald">LKR <?= number_format($order['totamt'], 2) ?></td>
                                            <td class="text-center">
                                                <?php if ($order['cancelled'] == 1): ?>
                                                    <span class="badge bg-danger rounded-pill px-3 py-1.5 small">Cancelled</span>
                                                <?php elseif ($order['delivered'] == 1): ?>
                                                    <span class="badge bg-success rounded-pill px-3 py-1.5 small">Delivered</span>
                                                <?php elseif ($order['processed'] == 1): ?>
                                                    <span class="badge bg-primary rounded-pill px-3 py-1.5 small">Processed</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark rounded-pill px-3 py-1.5 small">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-4 text-muted small">No personal order lines currently tracked to this business unit.</td>
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