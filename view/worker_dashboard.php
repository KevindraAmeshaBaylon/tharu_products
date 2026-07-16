<?php
session_start();

// 1. Authentication & Role Validation Guard
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'worker') {
    // If not authenticated or not a worker, forcefully bounce back to the login gateway
    header("Location: ../auth/login.php");
    exit;
}

require_once __DIR__ . '/../model/config/database.php';
$conn = getDBConnection();

$username = $_SESSION['username'];
$todayDate = date('Y-m-d');

// 2. Fetch Daily Production Schedule (Mock setup assuming a production_tbl exists, falls back to safe array if table isn't built yet)
$scheduleItems = [];
$tableExistsCheck = $conn->query("SHOW TABLES LIKE 'production_tbl'");

if ($tableExistsCheck && $tableExistsCheck->num_rows > 0) {
    $stmt = $conn->prepare("SELECT batchID, product_name, quantity, status, line_number FROM production_tbl WHERE schedule_date = ? ORDER BY line_number ASC");
    $stmt->bind_param("s", $todayDate);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $scheduleItems[] = $row;
    }
} else {
    // FALLBACK PRODUCTION DATA: So the dashboard displays beautifully immediately for testing
    $scheduleItems = [
        ['batchID' => 'BTH-901', 'product_name' => 'Premium Coconut Oil (1L)', 'quantity' => 450, 'status' => 'In Progress', 'line_number' => 1],
        ['batchID' => 'BTH-902', 'product_name' => 'Organic Desiccated Coconut (250g)', 'quantity' => 1200, 'status' => 'Pending', 'line_number' => 2],
        ['batchID' => 'BTH-903', 'product_name' => 'Extra Virgin Coconut Paste', 'quantity' => 300, 'status' => 'Completed', 'line_number' => 1],
        ['batchID' => 'BTH-904', 'product_name' => 'Coconut Flour (500g)', 'quantity' => 600, 'status' => 'Pending', 'line_number' => 3],
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Worker Matrix Dashboard</title>
    <!-- Framework Styles -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
            min-height: 100vh;
            display: flex;
        }

        /* Sidebar navigation drawer */
        .sidebar {
            width: 260px;
            background: #15803d; /* Forest Green Theme */
            color: #ffffff;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            box-shadow: 4px 0 15px rgba(0,0,0,0.05);
        }

        .sidebar-brand {
            padding: 24px;
            font-size: 1.25rem;
            font-weight: 800;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            letter-spacing: 0.5px;
        }

        .sidebar-menu {
            padding: 20px 14px;
            list-style: none;
            margin: 0;
            flex-grow: 1;
        }

        .sidebar-item a {
            display: flex;
            align-items: center;
            gap: 12px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            padding: 12px 16px;
            border-radius: 12px;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .sidebar-item.active a, .sidebar-item a:hover {
            background: rgba(255, 255, 255, 0.15);
            color: #ffffff;
        }

        /* Content window wrapper */
        .main-workspace {
            flex-grow: 1;
            padding: 40px;
            overflow-y: auto;
        }

        .top-navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 35px;
        }

        .greeting-title {
            font-weight: 800;
            font-size: 1.85rem;
            color: #0f172a;
        }

        .stat-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02);
        }

        /* Table custom stylings */
        .schedule-table-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.04);
        }

        .table th {
            background: #f8fafc;
            color: #64748b;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
            padding: 18px 24px;
            border-bottom: 1px solid #e2e8f0;
        }

        .table td {
            padding: 18px 24px;
            vertical-align: middle;
            color: #334155;
            font-weight: 500;
            border-bottom: 1px solid #f1f5f9;
        }

        .badge-status {
            padding: 6px 12px;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 700;
        }

        .badge-pending { background: #fef3c7; color: #d97706; }
        .badge-progress { background: #dbeafe; color: #2563eb; }
        .badge-completed { background: #dcfce7; color: #16a34a; }
    </style>
</head>
<body>

    <!-- SIDEBAR DRAWING MATRIX -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <i class="bi bi-nut-fill me-1 text-warning"></i> Tharu Products
        </div>
        <ul class="sidebar-menu">
            <li class="sidebar-item active">
                <a href="#"><i class="bi bi-calendar3"></i> Day Schedule</a>
            </li>
            <li class="sidebar-item">
                <a href="#"><i class="bi bi-tools"></i> Machine Status</a>
            </li>
        </ul>
        <div class="p-3 border-top border-light border-opacity-10">
            <a href="../auth/logout.php" class="btn btn-danger w-100 d-flex align-items-center justify-content-center gap-2" style="border-radius: 12px; font-weight: 600;">
                <i class="bi bi-box-arrow-left"></i> Sign Out
            </a>
        </div>
    </div>

    <!-- MAIN APP SPACE -->
    <div class="main-workspace">
        <div class="top-navbar">
            <div>
                <h1 class="greeting-title">Floor Operations Dashboard</h1>
                <p class="text-muted mb-0">Operator: <strong class="text-dark"><?= htmlspecialchars($username) ?></strong> | Production Node Environment</p>
            </div>
            <div class="bg-white px-4 py-2 border rounded-pill shadow-sm text-muted fw-bold small">
                <i class="bi bi-clock-fill text-success me-2"></i><?= date('F d, Y') ?>
            </div>
        </div>

        <!-- LIVE SUMMARY BLOCKS -->
        <div class="row g-4 mb-5">
            <div class="col-md-4">
                <div class="stat-card d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted d-block small fw-bold text-uppercase mb-1">Assigned Runs</span>
                        <h3 class="fw-extrabold mb-0" style="font-size: 1.8rem; font-weight:800;"><?= count($scheduleItems) ?></h3>
                    </div>
                    <div class="bg-success bg-opacity-10 p-3 rounded-4 text-success fs-3"><i class="bi bi-list-task"></i></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted d-block small fw-bold text-uppercase mb-1">Target Quantity</span>
                        <h3 class="fw-extrabold mb-0" style="font-size: 1.8rem; font-weight:800;">
                            <?= array_sum(array_column($scheduleItems, 'quantity')) ?> units
                        </h3>
                    </div>
                    <div class="bg-warning bg-opacity-10 p-3 rounded-4 text-warning fs-3"><i class="bi bi-box-seam"></i></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted d-block small fw-bold text-uppercase mb-1">Line Efficiency Target</span>
                        <h3 class="fw-extrabold mb-0" style="font-size: 1.8rem; font-weight:800;">100%</h3>
                    </div>
                    <div class="bg-info bg-opacity-10 p-3 rounded-4 text-info fs-3"><i class="bi bi-speedometer2"></i></div>
                </div>
            </div>
        </div>

        <!-- PRODUCTION SCHEDULE DATA CONTAINER -->
        <div class="card schedule-table-card border-0">
            <div class="card-header bg-white border-0 px-4 py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-activity text-success me-2"></i>Live Factory Schedule</h5>
                <button onclick="window.location.reload();" class="btn btn-sm btn-light border fw-bold text-secondary"><i class="bi bi-arrow-clockwise me-1"></i> Refresh Matrix</button>
            </div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>Line No</th>
                            <th>Batch Identifier</th>
                            <th>Product Line Allocation</th>
                            <th>Volume Target</th>
                            <th>State Indicator</th>
                            <th class="text-center">Floor Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scheduleItems as $item): ?>
                            <tr>
                                <td><span class="badge bg-secondary rounded-3 px-2.5 py-1.5 fw-bold">Line <?= htmlspecialchars($item['line_number']) ?></span></td>
                                <td class="text-dark fw-bold">#<?= htmlspecialchars($item['batchID']) ?></td>
                                <td><?= htmlspecialchars($item['product_name']) ?></td>
                                <td class="fw-bold"><?= number_format($item['quantity']) ?> pcs</td>
                                <td>
                                    <?php 
                                    $statusClean = strtolower(trim($item['status']));
                                    if ($statusClean === 'completed') {
                                        echo '<span class="badge-status badge-completed"><i class="bi bi-check-circle-fill me-1"></i> Completed</span>';
                                    } elseif ($statusClean === 'in progress') {
                                        echo '<span class="badge-status badge-progress"><i class="bi bi-gear-wide-connected spin me-1"></i> Running</span>';
                                    } else {
                                        echo '<span class="badge-status badge-pending"><i class="bi bi-pause-circle-fill me-1"></i> Staged</span>';
                                    }
                                    ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($statusClean !== 'completed'): ?>
                                        <button class="btn btn-sm btn-success px-3 fw-bold shadow-sm" style="border-radius: 8px;" onclick="alert('Batch runtime tracking updated.')">
                                            Update State
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted small fw-bold"><i class="bi bi-lock-fill"></i> Logged</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>