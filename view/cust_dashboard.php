<?php
// auth/customer_dashboard.php
session_start();
require_once __DIR__ . '/../model/config/database.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'cust') {
    header("Location: ../auth/login.php");
    exit;
}

$conn = getDBConnection();
$userID = $_SESSION['user_id'];

// Default viewing module
$viewTab = $_GET['view'] ?? 'overview';

// Fetch Current User Details
$userStmt = $conn->prepare("SELECT username, email FROM User_tbl WHERE userID = ?");
$userStmt->bind_param("i", $userID);
$userStmt->execute();
$userProfile = $userStmt->get_result()->fetch_assoc();

// Handle Order Placement & Payment Logic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    $cart = $_SESSION['cart'] ?? [];
    if (!empty($cart)) {
        $totalAmount = floatval($_POST['total_amount']);
        $address = htmlspecialchars($_POST['shipping_address']);
        $paymentMethod = htmlspecialchars($_POST['payment_method']);
        
        $conn->begin_transaction();
        try {
            // 1. Insert Order
            $orderQuery = "INSERT INTO Order_tbl (userID, totamtpaid, shipping_address, payment_method, status) VALUES (?, ?, ?, ?, 'Pending')";
            $orderStmt = $conn->prepare($orderQuery);
            $orderStmt->bind_param("idss", $userID, $totalAmount, $address, $paymentMethod);
            $orderStmt->execute();
            $orderID = $conn->insert_id;

            // 2. Clear out checkout staging sessions
            $_SESSION['cart'] = [];
            $conn->commit();
            $successMsg = "Order placed successfully! Order Reference ID: #ORD-" . $orderID;
            $viewTab = 'history';
        } catch (Exception $e) {
            $conn->rollback();
            $errorMsg = "Order placement transaction failed: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Dashboard | Tharu Products</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #f4f7f6;
            color: #1e293b;
        }
        .sidebar {
            height: 100vh;
            background: #042f22;
            color: #fff;
            padding-top: 2rem;
        }
        .sidebar .nav-link {
            color: rgba(255,255,255,0.75);
            margin: 0.5rem 1rem;
            border-radius: 8px;
        }
        .sidebar .nav-link.active, .sidebar .nav-link:hover {
            background: #10b981;
            color: #fff;
        }
        .dashboard-container {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            padding: 2.5rem;
            margin-top: 2rem;
        }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <!-- Dashboard Left Sidebar -->
        <div class="col-md-3 col-lg-2 sidebar">
            <h5 class="text-center fw-bold mb-4">🌾 MY PORTAL</h5>
            <div class="nav flex-column nav-pills">
                <a href="?view=overview" class="nav-link <?= $viewTab === 'overview' ? 'active' : '' ?>">Overview</a>
                <a href="?view=checkout" class="nav-link <?= $viewTab === 'checkout' ? 'active' : '' ?>">Place Order & Pay</a>
                <a href="../index.php" class="nav-link">Back to Catalog</a>
                <a href="logout.php" class="nav-link text-danger mt-5">Log Out</a>
            </div>
        </div>

        <!-- Dashboard Content pane -->
        <div class="col-md-9 col-lg-10 px-md-4">
            <div class="dashboard-container">
                <?php if (isset($successMsg)): ?>
                    <div class="alert alert-success"><?= $successMsg ?></div>
                <?php endif; ?>
                <?php if (isset($errorMsg)): ?>
                    <div class="alert alert-danger"><?= $errorMsg ?></div>
                <?php endif; ?>

                <?php if ($viewTab === 'overview'): ?>
                    <h3>Welcome back, <?= htmlspecialchars($userProfile['username'] ?? 'User') ?>!</h3>
                    <p class="text-muted">Manage your livestock supply orders and manage accounts from this panel.</p>
                    <hr>
                    <div class="row g-3 mt-3">
                        <div class="col-md-6">
                            <div class="p-4 bg-light rounded border">
                                <h5>Account Information</h5>
                                <p class="mb-1"><strong>Email:</strong> <?= htmlspecialchars($userProfile['email'] ?? 'Not Assigned') ?></p>
                            </div>
                        </div>
                    </div>

                <?php elseif ($viewTab === 'checkout'): ?>
                    <h3>Complete Checkout & Make Payment</h3>
                    <p class="text-muted">Review items staging inside your cart session to initiate feed procurement procedures.</p>
                    <hr>

                    <?php if (empty($_SESSION['cart'])): ?>
                        <div class="alert alert-info text-center py-4">
                            Your checkout queue is currently empty. <a href="../index.php">Go back and select some items</a>.
                        </div>
                    <?php else: ?>
                        <div class="row g-4">
                            <div class="col-md-7">
                                <form method="POST">
                                    <h5 class="mb-3">Shipping & Payment Form</h5>
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Shipping / Delivery Address</label>
                                        <textarea class="form-control" name="shipping_address" rows="3" placeholder="Enter full delivery coordinates..." required></textarea>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Payment Method</label>
                                        <select class="form-select" name="payment_method" required>
                                            <option value="Bank Transfer">Bank Wire Transfer</option>
                                            <option value="Cash on Delivery">Cash on Delivery</option>
                                        </select>
                                    </div>

                                    <?php 
                                    $checkoutTotal = 0;
                                    foreach($_SESSION['cart'] as $id => $qty) {
                                        $pStmt = $conn->prepare("SELECT unitprice FROM product_tbl WHERE productID = ?");
                                        $pStmt->bind_param("i", $id);
                                        $pStmt->execute();
                                        $res = $pStmt->get_result()->fetch_assoc();
                                        $checkoutTotal += ($res['unitprice'] ?? 0) * $qty;
                                    }
                                    ?>
                                    <input type="hidden" name="total_amount" value="<?= $checkoutTotal ?>">

                                    <button type="submit" name="place_order" class="btn btn-forest w-100 py-3">
                                        Confirm Payment & Place Order (LKR <?= number_format($checkoutTotal, 2) ?>)
                                    </button>
                                </form>
                            </div>

                            <div class="col-md-5">
                                <div class="p-3 bg-light rounded border">
                                    <h6 class="fw-bold mb-3">Order Summary</h6>
                                    <?php foreach($_SESSION['cart'] as $id => $qty): 
                                        $pStmt = $conn->prepare("SELECT name, unitprice FROM product_tbl WHERE productID = ?");
                                        $pStmt->bind_param("i", $id);
                                        $pStmt->execute();
                                        $prod = $pStmt->get_result()->fetch_assoc();
                                    ?>
                                        <div class="d-flex justify-content-between small border-bottom py-2">
                                            <span><?= htmlspecialchars($prod['name']) ?> (x<?= $qty ?>)</span>
                                            <strong>LKR <?= number_format($prod['unitprice'] * $qty, 2) ?></strong>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

</body>
</html>