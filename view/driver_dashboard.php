<?php
session_start();
require_once __DIR__ . '/../model/config/database.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'driver') {
    header('Location: ../auth/login.php');
    exit;
}

$conn = getDBConnection();
$message = '';
$error = '';

$driverID = null;
$driverStmt = $conn->prepare('SELECT driverID FROM Driver_tbl WHERE userID = ? LIMIT 1');
if ($driverStmt) {
    $driverStmt->bind_param('i', $_SESSION['user_id']);
    $driverStmt->execute();
    $driverResult = $driverStmt->get_result();
    if ($driverResult && $driverResult->num_rows > 0) {
        $driverRow = $driverResult->fetch_assoc();
        $driverID = (int)($driverRow['driverID'] ?? 0);
    }
}

// Make the delivery-status columns available when the existing schema is older.
foreach ([
    ['Order_tbl', 'dispatched', 'ALTER TABLE Order_tbl ADD COLUMN dispatched TINYINT(1) NOT NULL DEFAULT 0'],
    ['Order_tbl', 'accepted_by_driver', 'ALTER TABLE Order_tbl ADD COLUMN accepted_by_driver TINYINT(1) NOT NULL DEFAULT 0']
] as $definition) {
    $check = $conn->query("SHOW COLUMNS FROM {$definition[0]} LIKE '{$definition[1]}'");
    if ($check && $check->num_rows === 0) {
        $conn->query($definition[2]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'accept_task') {
        $orderID = intval($_POST['order_id'] ?? 0);
        if ($orderID > 0 && $driverID) {
            $stmt = $conn->prepare('UPDATE Order_tbl SET driverID = ?, accepted_by_driver = 1, dispatched = 1 WHERE orderID = ? AND (driverID IS NULL OR driverID = ?)');
            if ($stmt) {
                $stmt->bind_param('iii', $driverID, $orderID, $driverID);
                $stmt->execute();
                $message = 'Delivery task accepted. The order has been marked as dispatched.';
            } else {
                $error = 'Unable to accept the task.';
            }
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'update_status') {
        $orderID = intval($_POST['order_id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        if ($orderID > 0 && $driverID) {
            $updateDelivered = 0;
            $updateCancelled = 0;
            if ($status === 'delivered') {
                $updateDelivered = 1;
            } elseif ($status === 'cancelled') {
                $updateCancelled = 1;
            }

            $stmt = $conn->prepare('UPDATE Order_tbl SET delivered = ?, cancelled = ?, dispatched = 0 WHERE orderID = ? AND driverID = ?');
            if ($stmt) {
                $stmt->bind_param('iiii', $updateDelivered, $updateCancelled, $orderID, $driverID);
                $stmt->execute();
                $message = 'Delivery status updated.';
            } else {
                $error = 'Unable to update delivery status.';
            }
        }
    }
}

$acceptedTasks = [];
if ($driverID) {
    $acceptedQuery = $conn->prepare('SELECT o.orderID, o.date, o.totamt, c.companyname, o.delivered, o.cancelled, o.dispatched, o.accepted_by_driver FROM Order_tbl o LEFT JOIN Customer_tbl c ON o.customerID = c.customerID WHERE o.driverID = ? ORDER BY o.date DESC');
    if ($acceptedQuery) {
        $acceptedQuery->bind_param('i', $driverID);
        $acceptedQuery->execute();
        $acceptedResult = $acceptedQuery->get_result();
        while ($row = $acceptedResult->fetch_assoc()) {
            $acceptedTasks[] = $row;
        }
    }
}

$availableSchedules = [];
$viewSchedules = true;
if ($driverID) {
    $scheduleQuery = $conn->prepare('SELECT o.orderID, o.date, o.totamt, c.companyname, o.delivered, o.cancelled FROM Order_tbl o LEFT JOIN Customer_tbl c ON o.customerID = c.customerID WHERE (o.driverID IS NULL OR o.driverID = 0) AND o.delivered = 0 AND o.cancelled = 0 ORDER BY o.date ASC');
    if ($scheduleQuery) {
        $scheduleQuery->execute();
        $scheduleResult = $scheduleQuery->get_result();
        while ($row = $scheduleResult->fetch_assoc()) {
            $availableSchedules[] = $row;
        }
    }
    $viewSchedules = empty($acceptedTasks);
}

$history = [];
if ($driverID) {
    $historyQuery = $conn->prepare('SELECT o.orderID, o.date, o.totamt, c.companyname, o.delivered, o.cancelled FROM Order_tbl o LEFT JOIN Customer_tbl c ON o.customerID = c.customerID WHERE o.driverID = ? AND (o.delivered = 1 OR o.cancelled = 1) ORDER BY o.date DESC');
    if ($historyQuery) {
        $historyQuery->bind_param('i', $driverID);
        $historyQuery->execute();
        $historyResult = $historyQuery->get_result();
        while ($row = $historyResult->fetch_assoc()) {
            $history[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-success">
    <div class="container-fluid px-4">
        <span class="navbar-brand mb-0 h4">Driver Delivery Dashboard</span>
        <a class="btn btn-outline-light btn-sm" href="../auth/logout.php">Log Out</a>
    </div>
</nav>
<div class="container py-4">
    <?php if (!empty($message)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-12 col-lg-7">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">Delivery Schedules</h5>
                    <?php if ($viewSchedules): ?>
                        <?php if (!empty($availableSchedules)): ?>
                            <table class="table table-sm mt-3">
                                <thead>
                                    <tr>
                                        <th>Order</th>
                                        <th>Customer</th>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($availableSchedules as $schedule): ?>
                                        <tr>
                                            <td>#<?= (int)($schedule['orderID'] ?? 0) ?></td>
                                            <td><?= htmlspecialchars($schedule['companyname'] ?? 'Walk-in') ?></td>
                                            <td><?= htmlspecialchars($schedule['date'] ?? '') ?></td>
                                            <td>LKR <?= number_format((float)($schedule['totamt'] ?? 0), 2) ?></td>
                                            <td>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="accept_task">
                                                    <input type="hidden" name="order_id" value="<?= (int)($schedule['orderID'] ?? 0) ?>">
                                                    <button type="submit" class="btn btn-sm btn-success">Accept Task</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p class="text-muted mb-0">No delivery schedules available right now.</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-info mb-0">A delivery task is already accepted. New schedules are hidden until the active task is completed or cleared.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">Accepted Deliveries</h5>
                    <?php if (!empty($acceptedTasks)): ?>
                        <?php foreach ($acceptedTasks as $task): ?>
                            <div class="border rounded p-3 mb-3">
                                <div class="d-flex justify-content-between">
                                    <strong>#<?= (int)($task['orderID'] ?? 0) ?></strong>
                                    <span class="badge bg-success">Accepted</span>
                                </div>
                                <div class="small text-muted mt-2">Customer: <?= htmlspecialchars($task['companyname'] ?? 'Walk-in') ?></div>
                                <div class="small text-muted">Amount: LKR <?= number_format((float)($task['totamt'] ?? 0), 2) ?></div>
                                <form method="POST" class="mt-3">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="order_id" value="<?= (int)($task['orderID'] ?? 0) ?>">
                                    <select name="status" class="form-select form-select-sm mb-2">
                                        <option value="pending" <?= ((int)($task['delivered'] ?? 0) === 0 && (int)($task['cancelled'] ?? 0) === 0) ? 'selected' : '' ?>>In Transit</option>
                                        <option value="delivered" <?= ((int)($task['delivered'] ?? 0) === 1) ? 'selected' : '' ?>>Delivered</option>
                                        <option value="cancelled" <?= ((int)($task['cancelled'] ?? 0) === 1) ? 'selected' : '' ?>>Cancelled</option>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-outline-success w-100">Update Delivery Status</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted mb-0">No accepted deliveries yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mt-4">
        <div class="card-body">
            <h5 class="card-title">Delivery History</h5>
            <?php if (!empty($history)): ?>
                <table class="table table-sm mt-3">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $entry): ?>
                            <tr>
                                <td>#<?= (int)($entry['orderID'] ?? 0) ?></td>
                                <td><?= htmlspecialchars($entry['companyname'] ?? 'Walk-in') ?></td>
                                <td><?= htmlspecialchars($entry['date'] ?? '') ?></td>
                                <td>LKR <?= number_format((float)($entry['totamt'] ?? 0), 2) ?></td>
                                <td><?= ((int)($entry['delivered'] ?? 0) === 1) ? 'Delivered' : 'Cancelled' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="text-muted mb-0">No delivery history yet.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
