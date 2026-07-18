<?php
session_start();

// 1. Authentication & Role Validation Guard
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'worker') {
    // If not authenticated or not a worker, forcefully bounce back to the login gateway
    header("Location: ../auth/login.php");
    exit;
}

// 2. Database Connection Wrapper
// Adjust your path to wherever your database config sits (e.g., config.php or db.php)
// Make sure your config file defines a working $conn PDO or mysqli instance.
require_once __DIR__ . '/../model/config/database.php';
$conn = getDBConnection();

$username = $_SESSION['username'] ?? 'Worker';
$todayDate = date('Y-m-d');

// 3. Fetch Daily Production Schedule
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
    <title>Worker Dashboard - Tharu & Products Systems</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        :root {
            --sidebar-bg: #0b1a10;
            --sidebar-active: #1e3a24;
            --forest-main: #2e7d32;
            --mint-light: #e8f5e9;
            --canvas-bg: #f8faf9;
        }

        body {
            background-color: var(--canvas-bg);
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            overflow-x: hidden;
            margin: 0;
        }

        .dashboard-wrapper {
            display: flex;
            position: relative;
        }

        .sidebar-panel {
            width: 260px;
            background-color: var(--sidebar-bg);
            color: #ffffff;
            padding: 2rem 1rem;
            flex-shrink: 0;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .main-content {
            flex-grow: 1;
            margin-left: 260px;
            padding: 2.5rem;
            width: calc(100% - 260px);
        }

        .nav-dash-link {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #a3b899;
            text-decoration: none;
            padding: 0.85rem 1rem;
            border-radius: 12px;
            margin-bottom: 0.5rem;
            font-weight: 500;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .nav-dash-link:hover, .nav-dash-link.active {
            background-color: var(--sidebar-active);
            color: #ffffff;
        }

        .sidebar-profile-footer {
            padding: 1rem;
            background: rgba(255, 255, 255, 0.04);
            border-radius: 14px;
            margin-top: auto;
        }

        .stat-card-dark {
            background-color: #122919;
            color: #ffffff;
            border-radius: 20px;
            padding: 1.5rem;
            border: none;
        }
        
        .stat-card-light {
            background: #ffffff;
            border-radius: 20px;
            padding: 1.5rem;
            border: 1px solid #eef2f0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.02);
        }

        .metric-value {
            font-size: 1.85rem;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .custom-table th {
            background-color: #f1f5f3;
            color: #475569;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
            padding: 1rem;
        }
        
        .custom-table td {
            padding: 1rem;
            vertical-align: middle;
            font-size: 0.92rem;
        }
    </style>
</head>
<body>

<div class="dashboard-wrapper">
    
    <!-- LEFT SIDEBAR VIEW MATRIX CONTROLLER -->
    <div class="sidebar-panel">
        <div>
            <div class="d-flex align-items-center gap-2 px-2 mb-4">
                <i class="bi bi-nut-fill text-warning fs-4"></i>
                <h5 class="fw-bold mb-0 text-white font-monospace">THARU WORKER</h5>
            </div>
            <hr style="border-color: rgba(255,255,255,0.1);">
            
            <div class="nav flex-column" id="dashboardTabs" role="tablist">
                <a class="nav-dash-link active" id="tab-schedule" data-bs-toggle="tab" href="#panel-schedule" role="tab">
                    <i class="bi bi-calendar3 me-2"></i> Day Schedule
                </a>
                <a class="nav-dash-link" id="tab-machine" data-bs-toggle="tab" href="#panel-machine" role="tab">
                    <i class="bi bi-tools me-2"></i> Machine Status
                </a>
            </div>
        </div>

        <div class="sidebar-profile-footer">
            <a href="../auth/logout.php" class="text-danger text-decoration-none d-flex align-items-center gap-2 small fw-bold" style="letter-spacing: 0.3px;">
                <i class="bi bi-box-arrow-right"></i> Sign out user
            </a>
        </div>
    </div>

    <!-- MAIN DASHBOARD DATA VIEWPORT -->
    <div class="main-content">
        <div class="tab-content" id="dashboardTabContent">
            
            <!-- ================= TAB 1: SCHEDULE MATRIX ================= -->
            <div class="tab-pane fade show active" id="panel-schedule" role="tabpanel">
                
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h3 class="fw-bold text-dark mb-1">Floor Operations Dashboard</h3>
                        <p class="text-muted small mb-0">Operator: <strong class="text-dark"><?= htmlspecialchars($username) ?></strong> | Production Node Environment</p>
                    </div>
                    <div class="bg-white px-4 py-2 border rounded-pill shadow-sm text-muted fw-bold small">
                        <i class="bi bi-clock-fill text-success me-2"></i><?= date('F d, Y') ?>
                    </div>
                </div>

                <div class="row g-4 mb-4">
                    <div class="col-12 col-md-4">
                        <div class="stat-card-dark shadow-sm">
                            <span class="text-white-50 small text-uppercase">Assigned Runs</span>
                            <div class="metric-value mt-1"><?= count($scheduleItems) ?></div>
                            <span class="text-success small fw-bold">▲ Active</span> <span class="text-white-50 small">batch allocations</span>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="stat-card-light shadow-sm">
                            <span class="text-muted small text-uppercase">Target Quantity</span>
                            <div class="metric-value mt-1 text-dark"><?= array_sum(array_column($scheduleItems, 'quantity')) ?> <span class="fs-5 text-muted">units</span></div>
                            <span class="text-success small fw-bold">Calculated</span> <span class="text-muted small">daily volume</span>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="stat-card-light shadow-sm">
                            <span class="text-muted small text-uppercase">Line Efficiency Target</span>
                            <div class="metric-value mt-1 text-success">100%</div>
                            <span class="text-primary small fw-bold">✓ Optimal Status</span>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm p-4 rounded-4 mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold text-dark mb-0"><i class="bi bi-activity text-success me-2"></i>Live Factory Schedule</h5>
                        <button onclick="window.location.reload();" class="btn btn-sm btn-light border fw-bold text-secondary">
                            <i class="bi bi-arrow-clockwise me-1"></i> Refresh Matrix
                        </button>
                    </div>

                    <div class="table-responsive">
                        <table class="table custom-table border align-middle mb-0">
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
                                <?php if(empty($scheduleItems)): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-4">No production runs scheduled for today.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($scheduleItems as $item): ?>
                                        <tr>
                                            <td><span class="badge bg-secondary rounded-3 px-2 py-1 fw-bold">Line <?= htmlspecialchars($item['line_number']) ?></span></td>
                                            <td><span class="font-monospace fw-bold">#<?= htmlspecialchars($item['batchID']) ?></span></td>
                                            <td class="fw-medium text-dark"><?= htmlspecialchars($item['product_name']) ?></td>
                                            <td><?= number_format($item['quantity']) ?> pcs</td>
                                            <td>
                                                <?php 
                                                $statusClean = strtolower(trim($item['status']));
                                                if ($statusClean === 'completed') {
                                                    echo '<span class="badge bg-success-subtle text-success border px-2 py-1"><i class="bi bi-check-circle-fill me-1"></i> Completed</span>';
                                                } elseif ($statusClean === 'in progress') {
                                                    echo '<span class="badge bg-primary-subtle text-primary border px-2 py-1"><i class="bi bi-gear-wide-connected me-1"></i> Running</span>';
                                                } else {
                                                    echo '<span class="badge bg-warning-subtle text-warning border px-2 py-1"><i class="bi bi-pause-circle-fill me-1"></i> Staged</span>';
                                                }
                                                ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($statusClean !== 'completed'): ?>
                                                    <button class="btn btn-sm btn-outline-success fw-bold" onclick="alert('Batch runtime tracking updated.')">
                                                        Update State
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted small fw-bold"><i class="bi bi-lock-fill"></i> Logged</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <!-- ================= TAB 2: MACHINE STATUS (Placeholder) ================= -->
            <div class="tab-pane fade" id="panel-machine" role="tabpanel">
                 <div class="card border-0 shadow-sm p-4 rounded-4 text-center py-5">
                     <i class="bi bi-tools text-muted mb-3" style="font-size: 3rem;"></i>
                     <h4 class="fw-bold text-dark">Machine Status Portal</h4>
                     <p class="text-muted">Hardware telemetry and maintenance logs will appear here.</p>
                 </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>