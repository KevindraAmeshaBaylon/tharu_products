<?php
// stocksup/dashboard.php
session_start();
require_once dirname(__DIR__) . '/model/config/database.php';

// Strict Session Guard Check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'stocksup') {
    header("Location: ../auth/login.php");
    exit;
}

$conn = getDBConnection();
$message = "";
$error = "";

// Handle Raw Material Update Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_material') {
    $name = trim($_POST['name']);
    $quantity = floatval($_POST['quantity']);
    $unit = trim($_POST['unit']);
    $buyingprice = floatval($_POST['buyingprice']);
    
    if (!empty($name) && $quantity >= 0 && !empty($unit) && $buyingprice >= 0) {
        $stmt = $conn->prepare("INSERT INTO RawMaterial_tbl (name, quantity, unit, buyingprice) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("sdsd", $name, $quantity, $unit, $buyingprice);
            if ($stmt->execute()) {
                $message = "Raw material inventory resource successfully updated.";
            } else {
                $error = "Failed to commit inventory record: " . $conn->error;
            }
        }
    } else {
        $error = "Please provide valid resource metrics.";
    }
}

// 1. Fetch Current Raw Materials
$materialQuery = "SELECT materialID, name, quantity, unit, buyingprice FROM RawMaterial_tbl ORDER BY materialID DESC";
$materialResult = $conn->query($materialQuery);

// 2. Fetch Live Feed Product Finished Goods Inventory Stock Status
$stockQuery = "SELECT stockID, beginingstock, weeklypurchase, weeklysales, qtyavailable, unitprice FROM InventoryStock_tbl ORDER BY stockID DESC";
$stockResult = $conn->query($stockQuery);
?>

<?php require_once dirname(__DIR__) . '/includes/header.php'; ?>

<!-- Top Navigation Module -->
<nav class="navbar navbar-expand-lg navbar-dark bg-forest shadow-sm">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="#">🌾 Supply Chain & Stock Desk</a>
        <div class="ms-auto d-flex align-items-center gap-3">
            <span class="badge bg-light text-success px-3 py-2 font-monospace fw-bold">Role: Stock Supervisor</span>
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
        <!-- Input Management Actions Sidebar -->
        <div class="col-12 col-md-4 mb-4">
            <div class="card border-0 shadow-sm p-4 bg-white rounded">
                <h5 class="fw-bold text-dark border-bottom pb-2 mb-3">Log Raw Material Intake</h5>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="add_material">
                    <div class="mb-3">
                        <label class="form-label text-dark fw-semibold small">Material Name</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Maize Corn Coarse, Soy Meal" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <label class="form-label text-dark fw-semibold small">Intake Volume</label>
                            <input type="number" step="0.01" name="quantity" class="form-control" placeholder="0.00" required>
                        </div>
                        <div class="col-5">
                            <label class="form-label text-dark fw-semibold small">Unit Metric</label>
                            <input type="text" name="unit" class="form-control" placeholder="kg / metric ton" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-dark fw-semibold small">Buying Unit Cost (LKR)</label>
                        <input type="number" step="0.01" name="buyingprice" class="form-control" placeholder="0.00" required>
                    </div>
                    <button type="submit" class="btn btn-forest w-100 fw-bold shadow-sm">Log Material Resource</button>
                </form>
            </div>
        </div>

        <!-- Material and Finished Stock Output Monitors -->
        <div class="col-12 col-md-8">
            <!-- Raw Material Supply Ledger Card -->
            <div class="card border-0 shadow-sm rounded bg-white mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="mb-0 fw-bold text-dark">Raw Silo Component Reserves</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light text-secondary font-monospace small">
                                <tr>
                                    <th class="ps-4">Resource ID</th>
                                    <th>Ingredient Matrix Name</th>
                                    <th>On-Hand Supply Balance</th>
                                    <th>Procurement Unit Cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($materialResult && $materialResult->num_rows > 0): ?>
                                    <?php while ($mat = $materialResult->fetch_assoc()): ?>
                                        <tr>
                                            <td class="ps-4 fw-bold text-dark">#MAT-<?= $mat['materialID'] ?></td>
                                            <td class="text-dark small fw-semibold"><?= htmlspecialchars($mat['name']) ?></td>
                                            <td class="fw-bold font-monospace text-secondary small"><?= number_format($mat['quantity'], 2) ?> <?= htmlspecialchars($mat['unit']) ?></td>
                                            <td class="fw-bold text-emerald">LKR <?= number_format($mat['buyingprice'], 2) ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-4 text-muted small">No bulk raw storage ingredient states cataloged yet.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Finished Goods Feed Product Tracking Card -->
            <div class="card border-0 shadow-sm rounded bg-white">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="mb-0 fw-bold text-dark">Finished Animal Feed Product Valuation</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light text-secondary font-monospace small">
                                <tr>
                                    <th class="ps-4">Stock Key</th>
                                    <th>Start Vol</th>
                                    <th>Purchases</th>
                                    <th>Sales Outflow</th>
                                    <th>Available Balance</th>
                                    <th>Market Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($stockResult && $stockResult->num_rows > 0): ?>
                                    <?php while ($stk = $stockResult->fetch_assoc()): ?>
                                        <tr>
                                            <td class="ps-4 fw-bold text-dark">#STK-<?= $stk['stockID'] ?></td>
                                            <td class="font-monospace text-muted small"><?= $stk['beginingstock'] ?> bags</td>
                                            <td class="font-monospace text-success small">+<?= $stk['weeklypurchase'] ?></td>
                                            <td class="font-monospace text-danger small">-<?= $stk['weeklysales'] ?></td>
                                            <td class="fw-bold text-dark font-monospace"><?= $stk['qtyavailable'] ?> bags</td>
                                            <td class="fw-bold text-emerald">LKR <?= number_format($stk['unitprice'], 2) ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted small">No production batch inventory records calculated inside this ledger.</td>
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