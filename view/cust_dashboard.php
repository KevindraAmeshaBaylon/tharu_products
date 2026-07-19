<?php
// view/cust_dashboard.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../model/config/database.php';

if (isset($_SESSION['user_id']) && !isset($_SESSION['userID'])) {
    $_SESSION['userID'] = $_SESSION['user_id'];
}

// AUTHENTICATION GUARD
if (!isset($_SESSION['userID']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'customer') {
    header("Location: ../auth/login.php");
    exit;
}

$conn = getDBConnection();
$loggedInUserID = $_SESSION['userID']; 
$viewTab = $_GET['view'] ?? 'overview';

// 1. RESOLVE USER ID TO CUSTOMER ID
$custQuery = $conn->prepare("SELECT customerID, companyname, address FROM customer_tbl WHERE userID = ?");
$custQuery->bind_param("i", $loggedInUserID);
$custQuery->execute();
$customerProfile = $custQuery->get_result()->fetch_assoc();

$customerID = $customerProfile['customerID'] ?? 0;

// Fetch User Login Details for the profile view
$userStmt = $conn->prepare("SELECT username, email FROM user_tbl WHERE userID = ?");
$userStmt->bind_param("i", $loggedInUserID);
$userStmt->execute();
$userProfile = $userStmt->get_result()->fetch_assoc();

// 2. PRODUCT CATALOG SEARCH & FILTER
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$products = [];
if (!empty($search)) {
    $prodStmt = $conn->prepare("SELECT productID, name, description, unitprice FROM product_tbl WHERE name LIKE ? OR description LIKE ?");
    $searchTerm = "%$search%";
    $prodStmt->bind_param("ss", $searchTerm, $searchTerm);
    $prodStmt->execute();
    $products = $prodStmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $prodResult = $conn->query("SELECT productID, name, description, unitprice FROM product_tbl");
    if ($prodResult) {
        $products = $prodResult->fetch_all(MYSQLI_ASSOC);
    }
}

// 3. FETCH HISTORIC ORDERS & REPORT FILTERS
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';

// Start with the base query
$orderQuery = "SELECT orderID, date, totamt, delivered, cancelled FROM order_tbl WHERE customerID = ?";
$orderParams = [$customerID];
$orderTypes = "i"; // "i" is for the customerID (integer)

// Add date filtering only if both dates are provided
if (!empty($filterDateFrom) && !empty($filterDateTo)) {
    $orderQuery .= " AND date BETWEEN ? AND ?";
    $orderParams[] = $filterDateFrom;
    $orderParams[] = $filterDateTo;
    $orderTypes .= "ss"; // "ss" is for the two date strings
}

$orderQuery .= " ORDER BY date DESC";

// Prepare and execute
$historyStmt = $conn->prepare($orderQuery);
$historyStmt->bind_param($orderTypes, ...$orderParams);
$historyStmt->execute();
$orderHistory = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 4. ACTION: ADD TO CART OR UPDATE CART QUANTITY
if (isset($_GET['action']) && $_GET['action'] === 'add_to_cart' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pID = intval($_POST['product_id']);
    $qty = intval($_POST['quantity']);

    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }

    if ($qty <= 0) {
        unset($_SESSION['cart'][$pID]);
    } else {
        $_SESSION['cart'][$pID] = $qty; 
    }
    header("Location: cust_dashboard.php?view=catalog&status=updated");
    exit;
}

// 5. ACTION: UPDATE CART ITEM QUANTITY FROM CHECKOUT PANE
if (isset($_GET['action']) && $_GET['action'] === 'update_cart' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($_POST['quantities'] as $pID => $qty) {
        $qty = intval($qty);
        if ($qty <= 0) {
            unset($_SESSION['cart'][$pID]);
        } else {
            $_SESSION['cart'][$pID] = $qty;
        }
    }
    header("Location: cust_dashboard.php?view=checkout&status=cart_updated");
    exit;
}

// 6. ACTION: CANCEL AN ORDER
if (isset($_GET['action']) && $_GET['action'] === 'cancel_order') {
    $orderID = intval($_GET['orderID']);
    $cancelStmt = $conn->prepare("UPDATE order_tbl SET cancelled = 1 WHERE orderID = ? AND customerID = ? AND delivered = 0");
    $cancelStmt->bind_param("ii", $orderID, $customerID);
    if ($cancelStmt->execute()) {
        header("Location: cust_dashboard.php?view=history&status=cancelled");
        exit;
    }
}

// 7. ACTION: SUBMIT INQUIRY
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_inquiry'])) {
    $message = trim($_POST['inquiry_message']);
    
    if (!empty($message)) {
        $inquiryStmt = $conn->prepare("INSERT INTO inquiry_tbl (message, response, pending, answered, customerID) VALUES (?, NULL, 1, 0, ?)");
        if ($inquiryStmt) {
            $inquiryStmt->bind_param("si", $message, $customerID);
            if ($inquiryStmt->execute()) {
                $successMsg = "Your inquiry has been successfully sent and recorded in the system!";
            } else {
                $errorMsg = "Failed to send inquiry: " . $conn->error;
            }
        } else {
            $errorMsg = "Query preparation failed: " . $conn->error;
        }
    } else {
        $errorMsg = "Inquiry message cannot be empty.";
    }
}

// FETCH MY INQUIRIES
$myInquiries = [];
$iqStmt = $conn->prepare("SELECT message, response, answered FROM inquiry_tbl WHERE customerID = ? ORDER BY inquiryID DESC");
$iqStmt->bind_param("i", $customerID);
$iqStmt->execute();
$myInquiries = $iqStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 8. ACTION: PLACE AND CONFIRM ORDER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    $cart = $_SESSION['cart'] ?? [];
    if (!empty($cart)) {
        $totalAmount = floatval($_POST['total_amount']);
        
        $conn->begin_transaction();
        try {
            $orderQueryIns = "INSERT INTO order_tbl (customerID, date, totamt, delivered, cancelled) VALUES (?, CURDATE(), ?, 0, 0)";
            $orderStmt = $conn->prepare($orderQueryIns);
            if ($orderStmt === false) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            
            $orderStmt->bind_param("id", $customerID, $totalAmount);
            $orderStmt->execute();
            $orderID = $orderStmt->insert_id;

            $_SESSION['cart'] = [];
            $conn->commit();
            
            header("Location: cust_dashboard.php?view=history&success=1&ref=" . $orderID);
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $errorMsg = "Order placement transaction failed: " . $e->getMessage();
        }
    }
}

if (isset($_GET['success'])) {
    $successMsg = "Order confirmed and placed successfully! Ref ID: #ORD-" . intval($_GET['ref']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Dashboard | Tharu Products</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f4f7f6; color: #1e293b; }
        .sidebar { height: 100vh; background: #042f22; color: #fff; padding-top: 1.5rem; position: sticky; top: 0; }
        .sidebar-brand { font-size: 1.1rem; font-weight: 700; letter-spacing: 0.5px; padding: 0.5rem 1rem; color: #fff; display: flex; align-items: center; }
        .sidebar .nav-link { 
            color: rgba(255,255,255,0.75); 
            margin: 0.4rem 1rem; 
            border-radius: 12px; 
            padding: 12px 16px; 
            font-weight: 500; 
            display: flex; 
            align-items: center; 
            transition: all 0.2s ease-in-out;
        }
        .sidebar .nav-link i { font-size: 1.2rem; margin-right: 12px; }
        .sidebar .nav-link:hover { background: rgba(16, 185, 129, 0.15); color: #fff; }
        .sidebar .nav-link.active { 
            background: #134e38; 
            color: #ffffff; 
            position: relative; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .sidebar .nav-link.active::before { 
            content: ""; 
            position: absolute; 
            left: 0; 
            top: 15%; 
            height: 70%; 
            width: 4px; 
            background: #10b981; 
            border-radius: 0 4px 4px 0; 
        }
        .dashboard-container { background: #ffffff; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); padding: 2.5rem; margin-top: 2rem; min-height: 70vh; }
        .text-emerald { color: #10b981 !important; }
        .text-amber { color: #d97706 !important; }
        .btn-forest { background-color: #042f22; color: #ffffff; }
        .btn-forest:hover { background-color: #10b981; color: #ffffff; }
        @media print {
            .no-print { display: none !important; }
            .sidebar { display: none !important; }
            .dashboard-container { box-shadow: none; margin: 0; padding: 0; width: 100%; max-width: 100%; }
        }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar Navigation -->
        <div class="col-md-3 col-lg-2 sidebar no-print px-2 d-flex flex-column">
            <div class="sidebar-brand mb-4 px-3 d-flex align-items-center">
                <span class="me-2 fs-4">🌾</span> <span class="fw-bold text-white fs-6">Customer</span>
            </div>
            
            <div class="nav flex-column flex-grow-1">
                <a href="?view=overview" class="nav-link <?= $viewTab === 'overview' ? 'active' : '' ?>">
                    <i class="bi bi-speedometer2"></i> Overview
                </a>
                <a href="?view=catalog" class="nav-link <?= $viewTab === 'catalog' ? 'active' : '' ?>">
                    <i class="bi bi-grid-fill"></i> Product Catalog
                </a>
                <a href="?view=checkout" class="nav-link <?= $viewTab === 'checkout' ? 'active' : '' ?>">
                    <i class="bi bi-cart-check-fill"></i> Place Order (<?= isset($_SESSION['cart']) ? count($_SESSION['cart']) : 0 ?>)
                </a>
                <a href="?view=history" class="nav-link <?= $viewTab === 'history' ? 'active' : '' ?>">
                    <i class="bi bi-clock-history"></i> Order History
                </a>
                <a href="?view=report" class="nav-link <?= $viewTab === 'report' ? 'active' : '' ?>">
                    <i class="bi bi-file-earmark-text-fill"></i> Order Report
                </a>
                <a href="?view=inquiry" class="nav-link <?= $viewTab === 'inquiry' ? 'active' : '' ?>">
                    <i class="bi bi-chat-left-text-fill"></i> Send Inquiry
                </a>
            </div>

            <div class="mt-auto">
                <a href="../auth/logout.php" class="nav-link text-danger mb-3">
                    <i class="bi bi-box-arrow-right"></i> Sign Out
                </a>
            </div>
        </div>

        <!-- Dashboard Panel Content -->
        <div class="col-md-9 col-lg-10 px-md-4 ms-auto">
            <div class="dashboard-container">
                <?php if (isset($successMsg)): ?>
                    <div class="alert alert-success no-print"><?= $successMsg ?></div>
                <?php endif; ?>
                <?php if (isset($errorMsg)): ?>
                    <div class="alert alert-danger no-print"><?= $errorMsg ?></div>
                <?php endif; ?>
                <?php if (isset($_GET['status']) && $_GET['status'] === 'cart_updated'): ?>
                    <div class="alert alert-info no-print">Quantities updated inside your shopping cart.</div>
                <?php endif; ?>
                <?php if (isset($_GET['status']) && $_GET['status'] === 'cancelled'): ?>
                    <div class="alert alert-warning no-print">Order state updated to Cancelled.</div>
                <?php endif; ?>

                <!-- TAB 1: OVERVIEW -->
                <?php if ($viewTab === 'overview'): ?>
                    <h3 class="fw-bold text-dark">Welcome back, <?= htmlspecialchars($userProfile['username'] ?? 'User') ?>!</h3>
                    <p class="text-secondary">Manage your livestock supply orders and manage accounts from this panel.</p>
                    <hr>
                    <div class="p-4 bg-light rounded border col-md-6">
                        <h5 class="fw-bold text-dark mb-3">Account Information</h5>
                        <p class="mb-1 text-secondary"><strong>Company:</strong> <span class="text-dark"><?= htmlspecialchars($customerProfile['companyname'] ?? 'Not Assigned') ?></span></p>
                        <p class="mb-1 text-secondary"><strong>Email:</strong> <span class="text-dark"><?= htmlspecialchars($userProfile['email'] ?? 'Not Assigned') ?></span></p>
                        <p class="mb-1 text-secondary"><strong>Address:</strong> <span class="text-dark"><?= htmlspecialchars($customerProfile['address'] ?? 'Not Assigned') ?></span></p>
                    </div>

                <!-- TAB 2: CATALOG -->
                <?php elseif ($viewTab === 'catalog'): ?>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3 class="fw-bold text-dark m-0">Animal Feed Catalog</h3>
                        <form method="GET" class="d-flex gap-2">
                            <input type="hidden" name="view" value="catalog">
                            <input type="text" name="search" class="form-control form-control-sm" placeholder="Search products..." value="<?= htmlspecialchars($search) ?>">
                            <button type="submit" class="btn btn-sm btn-forest">Search</button>
                            <?php if (!empty($search)): ?>
                                <a href="?view=catalog" class="btn btn-sm btn-secondary">Clear</a>
                            <?php endif; ?>
                        </form>
                    </div>
                    <hr>
                    <div class="row g-3">
                        <?php if (!empty($products)): ?>
                            <?php foreach ($products as $product): ?>
                                <div class="col-md-4">
                                    <div class="card h-100 shadow-sm border-0 p-2 bg-light">
                                        <div class="card-body d-flex flex-column">
                                            <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($product['name']) ?></h5>
                                            <p class="text-secondary small mb-3"><?= htmlspecialchars($product['description']) ?></p>
                                            <div class="mt-auto">
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span class="text-secondary small">Unit Price</span>
                                                    <span class="fw-bold text-emerald">LKR <?= number_format($product['unitprice'], 2) ?></span>
                                                </div>
                                                <form action="cust_dashboard.php?action=add_to_cart" method="POST">
                                                    <input type="hidden" name="product_id" value="<?= $product['productID'] ?>">
                                                    <div class="input-group input-group-sm mb-2">
                                                        <span class="input-group-text bg-white text-secondary">Qty</span>
                                                        <input type="number" name="quantity" class="form-control text-center text-dark" value="<?= $_SESSION['cart'][$product['productID']] ?? 1 ?>" min="1">
                                                    </div>
                                                    <button type="submit" class="btn btn-forest btn-sm w-100">Add / Update Cart</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-secondary py-4">No feed items match your request parameters.</div>
                        <?php endif; ?>
                    </div>

                <!-- TAB 3: CHECKOUT -->
                <?php elseif ($viewTab === 'checkout'): ?>
                    <h3 class="fw-bold text-dark">Complete Checkout & Confirm Order</h3>
                    <p class="text-secondary">Review items staging inside your cart session to calculate total bill values.</p>
                    <hr>
                    <?php if (empty($_SESSION['cart'])): ?>
                        <div class="alert alert-info text-center py-4">Your staging cart queue is empty. <a href="?view=catalog" class="text-emerald fw-semibold">Browse the Product Catalog</a>.</div>
                    <?php else: ?>
                        <div class="row g-4">
                            <div class="col-md-7">
                                <form action="cust_dashboard.php?action=update_cart" method="POST" class="mb-4 bg-light p-3 rounded border">
                                    <h6 class="fw-bold text-dark mb-3">Adjust Quantities</h6>
                                    <?php 
                                    $checkoutTotal = 0;
                                    foreach($_SESSION['cart'] as $id => $qty): 
                                        $pStmt = $conn->prepare("SELECT name, unitprice FROM product_tbl WHERE productID = ?");
                                        $pStmt->bind_param("i", $id);
                                        $pStmt->execute();
                                        $prod = $pStmt->get_result()->fetch_assoc();
                                        
                                        // FIXED: Added safety check for null products
                                        if ($prod):
                                            $checkoutTotal += $prod['unitprice'] * $qty;
                                    ?>
                                        <div class="row align-items-center mb-2 small">
                                            <div class="col-6 text-dark fw-medium"><?= htmlspecialchars($prod['name']) ?></div>
                                            <div class="col-4">
                                                <input type="number" name="quantities[<?= $id ?>]" class="form-control form-control-sm text-center text-dark" value="<?= $qty ?>" min="0">
                                            </div>
                                            <div class="col-2 text-end text-secondary fw-medium">LKR <?= number_format($prod['unitprice'] * $qty, 2) ?></div>
                                        </div>
                                    <?php 
                                        else: 
                                            unset($_SESSION['cart'][$id]);
                                        endif; 
                                    endforeach; ?>
                                    <button type="submit" class="btn btn-sm btn-secondary w-100 mt-2">Update Cart Quantities</button>
                                </form>

                                <form method="POST">
                                    <input type="hidden" name="total_amount" value="<?= $checkoutTotal ?>">
                                    <button type="submit" name="place_order" class="btn btn-forest w-100 py-3 fw-bold">Confirm Payment & Place Order</button>
                                </form>
                            </div>
                            
                            <div class="col-md-5">
                                <div class="p-3 bg-dark text-white rounded border">
                                    <h6 class="fw-bold border-bottom pb-2 text-white">Total Invoice Summary</h6>
                                    <div class="d-flex justify-content-between py-2 fs-5">
                                        <span class="text-light">Total Bill:</span>
                                        <strong class="text-emerald">LKR <?= number_format($checkoutTotal, 2) ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                <!-- TAB 4: ORDER HISTORY -->
                <?php elseif ($viewTab === 'history'): ?>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <h3 class="fw-bold text-dark">Your Supply Order Pipeline</h3>
                            <p class="text-secondary m-0">Track live order status, total bill, or request cancellations for processing batches.</p>
                        </div>
                        <div>
                            <a href="?view=report&date_from=<?= htmlspecialchars($filterDateFrom) ?>&date_to=<?= htmlspecialchars($filterDateTo) ?>" class="btn btn-sm btn-dark">
                                <i class="bi bi-file-earmark-text me-1"></i> View Printable Report
                            </a>
                        </div>
                    </div>
                    <hr>

                    <!-- DATE FILTER FORM -->
                    <form method="GET" action="cust_dashboard.php" class="row g-3 mb-4 align-items-center bg-light p-3 rounded">
                        <input type="hidden" name="view" value="history">
                        <div class="col-auto">
                            <label class="form-label small fw-bold">From:</label>
                            <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($filterDateFrom) ?>">
                        </div>
                        <div class="col-auto">
                            <label class="form-label small fw-bold">To:</label>
                            <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($filterDateTo) ?>">
                        </div>
                        <div class="col-auto mt-4">
                            <button type="submit" class="btn btn-primary btn-sm">Filter Results</button>
                            <a href="?view=history" class="btn btn-outline-secondary btn-sm">Clear</a>
                        </div>
                    </form>

                    <div class="table-responsive mt-3">
                        <table class="table table-hover align-middle small">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-dark">Order Ref</th>
                                    <th class="text-dark">Date Generated</th>
                                    <th class="text-dark">Total Value</th>
                                    <th class="text-dark">Fulfillment Status</th>
                                    <th class="text-dark">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($orderHistory)): ?>
                                    <?php foreach ($orderHistory as $order): ?>
                                        <tr>
                                            <td class="text-dark"><strong>#ORD-<?= htmlspecialchars($order['orderID'])?></strong></td>
                                            <td class="text-secondary"><?= htmlspecialchars($order['date']) ?></td>
                                            <td class="text-emerald fw-semibold">LKR <?= number_format($order['totamt'], 2) ?></td>
                                            <td>
                                                <?php 
                                                if (intval($order['cancelled']) === 1) {
                                                    echo '<span class="badge bg-danger px-2 py-1">Cancelled</span>';
                                                } elseif (intval($order['delivered']) === 1) {
                                                    echo '<span class="badge bg-success px-2 py-1">Delivered</span>';
                                                } else {
                                                    echo '<span class="badge bg-warning text-dark px-2 py-1">Pending Processing</span>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php if (intval($order['delivered']) === 0 && intval($order['cancelled']) === 0): ?>
                                                    <a href="cust_dashboard.php?action=cancel_order&orderID=<?= $order['orderID'] ?>" class="btn btn-sm btn-outline-danger py-0" onclick="return confirm('Are you sure you want to request cancellation for this order batch?');">Cancel Order</a>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" class="text-center text-secondary py-4">No historical orders found for this period.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                <!-- TAB 5: ORDER REPORT VIEW -->
                <?php elseif ($viewTab === 'report'): ?>
                    <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-3">
                        <div>
                            <h3 class="fw-bold text-dark">Customer Order History Report</h3>
                            <p class="text-secondary m-0">Filtered Report from <?= htmlspecialchars($filterDateFrom ?: 'Start') ?> to <?= htmlspecialchars($filterDateTo ?: 'Present') ?></p>
                        </div>
                        <div class="no-print d-flex gap-2">
                            <button onclick="window.print();" class="btn btn-sm btn-dark"><i class="bi bi-printer me-1"></i> Print Report</button>
                            <a href="?view=history&date_from=<?= htmlspecialchars($filterDateFrom) ?>&date_to=<?= htmlspecialchars($filterDateTo) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Back to History</a>
                        </div>
                    </div>

                    <div id="pdf-report-content">
                        <div class="mb-4">
                            <p class="mb-1 text-secondary"><strong>Company:</strong> <span class="text-dark"><?= htmlspecialchars($customerProfile['companyname'] ?? 'N/A') ?></span></p>
                            <p class="mb-1 text-secondary"><strong>Address:</strong> <span class="text-dark"><?= htmlspecialchars($customerProfile['address'] ?? 'N/A') ?></span></p>
                            <p class="text-secondary small">Report Period: <?= htmlspecialchars($filterDateFrom ?: 'All Time') ?> to <?= htmlspecialchars($filterDateTo ?: 'Present') ?></p>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered align-middle small">
                                <thead class="table-dark">
                                    <tr>
                                        <th class="text-white">Order Ref</th>
                                        <th class="text-white">Date</th>
                                        <th class="text-white">Total Value</th>
                                        <th class="text-white">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($orderHistory)): ?>
                                        <?php 
                                        $grandTotal = 0;
                                        foreach ($orderHistory as $order): 
                                            $grandTotal += $order['totamt'];
                                        ?>
                                            <tr>
                                                <td class="text-dark"><strong>#ORD-<?= htmlspecialchars($order['orderID']) ?></strong></td>
                                                <td class="text-secondary"><?= htmlspecialchars($order['date']) ?></td>
                                                <td class="text-emerald fw-semibold">LKR <?= number_format($order['totamt'], 2) ?></td>
                                                <td>
                                                    <?php 
                                                    if (intval($order['cancelled']) === 1) echo '<span class="text-danger fw-bold">Cancelled</span>';
                                                    elseif (intval($order['delivered']) === 1) echo '<span class="text-success fw-bold">Delivered</span>';
                                                    else echo '<span class="text-amber fw-bold">Pending Processing</span>';
                                                    ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="4" class="text-center text-secondary py-4">No records found for this period.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if (!empty($orderHistory)): ?>
                            <div class="d-flex justify-content-end mt-4">
                                <div class="p-3 bg-light border rounded text-end col-md-4">
                                    <span class="text-secondary small">Total Cumulative Value:</span>
                                    <h5 class="fw-bold text-success m-0">LKR <?= number_format($grandTotal, 2) ?></h5>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>    
             
                      

                <!-- TAB 6: SEND INQUIRY -->
                <?php elseif ($viewTab === 'inquiry'): ?>
                    <h3 class="fw-bold text-dark">Send Inquiry to Operations Panel</h3>
                    <p class="text-secondary">Directly ask questions regarding stock variants, delivery issues, or pricing changes.</p>
                    <hr>
                    <form method="POST" class="col-md-6 mt-3">
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark small">Your Message / Inquiry Text</label>
                            <textarea class="form-control text-dark" name="inquiry_message" rows="4" placeholder="Type what you want to ask our team..." required></textarea>
                        </div>
                        <button type="submit" name="send_inquiry" class="btn btn-forest px-4">Submit Inquiry</button>
                    </form>

                    <!-- Response of inquiries -->
                    <div class="mt-5">
                        <h5 class="fw-bold text-dark">Your Previous Inquiries</h5>
                        <div class="table-responsive mt-3">
                            <table class="table table-bordered align-middle small">
                                <thead class="table-light">
                                    <tr>
                                        <th>Your Message</th>
                                        <th>Response</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    if (!empty($myInquiries)): 
                                        // 1. Filter and Group the inquiries
                                        $uniqueInquiries = [];
                                        foreach ($myInquiries as $row) {
                                            $msg = $row['message'];
                                            // If we don't have this message yet, OR this current row is 'Answered', 
                                            // we prioritize the 'Answered' version.
                                            if (!isset($uniqueInquiries[$msg]) || !empty($row['response'])) {
                                                $uniqueInquiries[$msg] = $row;
                                            }
                                        }
                                    ?>
                                        <?php foreach ($uniqueInquiries as $row): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($row['message']) ?></td>
                                                
                                                <td>
                                                    <?php if (!empty($row['response'])): ?>
                                                        <?= htmlspecialchars($row['response']) ?>
                                                    <?php else: ?>
                                                        <em class="text-muted">Waiting for response...</em>
                                                    <?php endif; ?>
                                                </td>
                                                
                                                <td>
                                                    <?php if (!empty($row['response'])): ?>
                                                        <span class="badge bg-success">Answered</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning text-dark">Pending</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="3" class="text-center text-secondary">No inquiries sent yet.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                    
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>