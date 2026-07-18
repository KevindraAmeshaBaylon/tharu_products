<?php
session_start();

// 1. Authentication & Role Validation Guard
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'worker') {
    header("Location: ../auth/login.php");
    exit;
}

// 2. Database Connection Wrapper
require_once __DIR__ . '/../model/config/database.php';
$conn = getDBConnection();

$username = $_SESSION['username'] ?? 'Worker';
$todayDate = date('Y-m-d'); // Example: Will match formats like '2026-07-02'

// 3. Fetch Daily Production Schedule using actual schema
$scheduleItems = [];
$scheduleQuery = "
    SELECT 
        pb.batchID, 
        p.name AS product_name, 
        pb.outputqty AS quantity, 
        pb.inproduction, 
        pb.completed 
    FROM productionbatch_tbl pb
    JOIN productperbatch_tbl ppb ON pb.batchID = ppb.batchID
    JOIN product_tbl p ON ppb.ProductID = p.ProductID
    WHERE pb.date = ?
";

if ($stmt = $conn->prepare($scheduleQuery)) {
    $stmt->bind_param("s", $todayDate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        // Translate boolean flags from DB into status text
        if ($row['completed'] == 1) {
            $row['status'] = 'Completed';
        } elseif ($row['inproduction'] == 1) {
            $row['status'] = 'In Progress';
        } else {
            $row['status'] = 'Pending';
        }
        // DB doesn't track specific line numbers, so we default to 1
        $row['line_number'] = 1; 
        
        $scheduleItems[] = $row;
    }
    $stmt->close();
}

// 4. Fetch Raw Materials for the Request Dropdown
$materials = [];
$matResult = $conn->query("SELECT materialID, name, quantity FROM rawmaterial_tbl ORDER BY name ASC");
if ($matResult && $matResult->num_rows > 0) {
    while ($matRow = $matResult->fetch_assoc()) {
        $materials[] = $matRow;
    }
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
            min-height: 100vh;
        }

        .sidebar-panel {
            width: 260px;
            background-color: var(--sidebar-bg);
            color: #ffffff;
            padding: 2rem 1rem;
            flex-shrink: 0;
            height: 100vh;
            position: fixed;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .sidebar-panel h5 {
            letter-spacing: 1px;
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
            position: relative;
            overflow: hidden;
            z-index: 1;
        }

        .nav-dash-link::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 25%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(74, 222, 128, 0.3), rgba(255, 255, 255, 0.7), transparent);
            transform: skewX(-25deg);
        }
        
        .nav-dash-link:hover::after { animation: katanaGlint 0.4s ease-out forwards; }

        @keyframes katanaGlint {
            0% { left: -50%; }
            100% { left: 150%; }
        }

        .nav-dash-link:hover, .nav-dash-link.active {
            background-color: var(--sidebar-active);
            color: #ffffff;
            border-left: 4px solid var(--forest-main);
            animation: hakiEmission 1.5s infinite alternate ease-in-out;
        }

        @keyframes hakiEmission {
            0% { border-left-color: #2e7d32; box-shadow: -2px 0 5px -2px rgba(46, 125, 50, 0); }
            50% { border-left-color: #4ade80; box-shadow: -4px 0 15px -2px rgba(74, 222, 128, 0.4), inset 3px 0 8px -3px rgba(74, 222, 128, 0.2); }
            100% { border-left-color: #22c55e; box-shadow: -2px 0 8px -2px rgba(34, 197, 94, 0.1); }
        }

        .sidebar-profile-footer {
            padding: 1rem;
            background: rgba(255, 255, 255, 0.04);
            border-radius: 14px;
            margin-top: auto;
            position: relative;
            z-index: 1;
        }

        .sign-out-text {
            position: relative;
            z-index: 2;
            transition: color 0.2s;
        }

        .sign-out-text:hover {
            color: #ff3333 !important;
            text-shadow: 0 0 5px rgba(255, 0, 0, 0.6), 1px 1px 0px #000;
        }

        .sign-out-text::before, .sign-out-text::after {
            content: '';
            position: absolute;
            background: linear-gradient(90deg, transparent, #000, #ff0000, #000, transparent);
            height: 2px;
            width: 100%;
            left: 0;
            top: 50%;
            opacity: 0;
            pointer-events: none;
            box-shadow: 0 0 8px #ff0000;
            z-index: -1;
        }

        .sign-out-text:hover::before { animation: supremeKingLightning1 0.4s infinite; }
        .sign-out-text:hover::after { background: linear-gradient(90deg, transparent, #ff0000, #1a0000, #ff0000, transparent); animation: supremeKingLightning2 0.3s infinite reverse; }

        @keyframes supremeKingLightning1 {
            0%, 100% { opacity: 0; transform: scaleX(0.8) rotate(0deg) translateY(0); }
            20% { opacity: 1; transform: scaleX(1.2) rotate(-4deg) translateY(-8px); }
            40% { opacity: 0; transform: scaleX(0.9) rotate(2deg) translateY(4px); }
            60% { opacity: 1; transform: scaleX(1.1) rotate(-2deg) translateY(-4px); }
            80% { opacity: 0; transform: scaleX(1) rotate(0deg) translateY(0); }
        }

        @keyframes supremeKingLightning2 {
            0%, 100% { opacity: 0; transform: scaleX(0.7) rotate(0deg) translateY(0); }
            15% { opacity: 1; transform: scaleX(1.3) rotate(5deg) translateY(10px); }
            35% { opacity: 0; transform: scaleX(0.8) rotate(-3deg) translateY(-6px); }
            55% { opacity: 1; transform: scaleX(1.1) rotate(3deg) translateY(4px); }
            75% { opacity: 0; transform: scaleX(0.9) rotate(0deg) translateY(0); }
        }

        .main-content {
            flex-grow: 1;
            margin-left: 260px;
            padding: 2.5rem;
            width: calc(100% - 260px);
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
            border-bottom: 2px solid #e2e8f0;
        }
        
        .custom-table td {
            padding: 1rem;
            vertical-align: middle;
            font-size: 0.92rem;
            border-bottom: 1px solid #f1f5f3;
        }
    </style>
</head>
<body>

<div class="dashboard-wrapper">
    
    <!-- LEFT SIDEBAR -->
    <div class="sidebar-panel">
        <div style="position: relative; z-index: 1;">
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
                <a class="nav-dash-link" id="tab-qc" data-bs-toggle="tab" href="#panel-qc" role="tab">
                    <i class="bi bi-shield-check me-2"></i> Quality Control
                </a>
                <a class="nav-dash-link" id="tab-materials" data-bs-toggle="tab" href="#panel-materials" role="tab">
                    <i class="bi bi-box-seam me-2"></i> Material Requests
                </a>
            </div>
        </div>

        <div class="sidebar-profile-footer">
            <a href="../auth/logout.php" class="text-danger text-decoration-none d-flex align-items-center gap-2 small fw-bold sign-out-text" style="letter-spacing: 0.3px; width: 100%;">
                <i class="bi bi-box-arrow-right"></i> Sign out user
            </a>
        </div>
    </div>

    <!-- MAIN DASHBOARD DATA VIEWPORT -->
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold text-dark mb-1">Floor Operations Dashboard</h3>
                <p class="text-muted small mb-0">Operator: <strong class="text-dark"><?= htmlspecialchars($username) ?></strong> | Production Node Environment</p>
            </div>
            <div class="bg-white px-4 py-2 border rounded-pill shadow-sm text-muted fw-bold small">
                <i class="bi bi-clock-fill text-success me-2"></i><?= date('F d, Y') ?>
            </div>
        </div>

        <div class="tab-content" id="dashboardTabContent">
            
            <!-- ================= TAB 1: SCHEDULE MATRIX ================= -->
            <div class="tab-pane fade show active" id="panel-schedule" role="tabpanel">
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
                                            <td><span class="font-monospace fw-bold">#BTH-<?= htmlspecialchars($item['batchID']) ?></span></td>
                                            <td class="fw-medium text-dark"><?= htmlspecialchars($item['product_name']) ?></td>
                                            <td><?= number_format($item['quantity']) ?> pcs</td>
                                            <td>
                                                <?php 
                                                if ($item['status'] === 'Completed') {
                                                    echo '<span class="badge bg-success-subtle text-success border px-2 py-1"><i class="bi bi-check-circle-fill me-1"></i> Completed</span>';
                                                } elseif ($item['status'] === 'In Progress') {
                                                    echo '<span class="badge bg-primary-subtle text-primary border px-2 py-1"><i class="bi bi-gear-wide-connected me-1"></i> Running</span>';
                                                } else {
                                                    echo '<span class="badge bg-warning-subtle text-warning border px-2 py-1"><i class="bi bi-pause-circle-fill me-1"></i> Staged</span>';
                                                }
                                                ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($item['status'] !== 'Completed'): ?>
                                                    <!-- Setup for a form submission to an update_batch.php endpoint later -->
                                                    <form method="POST" action="update_batch_status.php" style="display:inline;">
                                                        <input type="hidden" name="batchID" value="<?= $item['batchID'] ?>">
                                                        <button type="button" class="btn btn-sm btn-outline-success fw-bold" onclick="alert('Batch runtime tracking updated.')">
                                                            Update State
                                                        </button>
                                                    </form>
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

            <!-- ================= TAB 2: MACHINE STATUS ================= -->
            <div class="tab-pane fade" id="panel-machine" role="tabpanel">
                 <div class="card border-0 shadow-sm p-4 rounded-4 mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold text-dark mb-0"><i class="bi bi-tools text-primary me-2"></i>Hardware Telemetry</h5>
                        <button class="btn btn-sm btn-danger fw-bold"><i class="bi bi-exclamation-triangle-fill me-1"></i> Report Hardware Fault</button>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table custom-table border align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Machine ID</th>
                                    <th>Type</th>
                                    <th>Assigned Line</th>
                                    <th>Status</th>
                                    <th>Last Inspected</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><span class="font-monospace fw-bold">EXT-01</span></td>
                                    <td>Oil Extractor</td>
                                    <td>Line 1</td>
                                    <td><span class="badge bg-success-subtle text-success border px-2 py-1">Operational</span></td>
                                    <td class="text-muted">Today, 06:00 AM</td>
                                </tr>
                                <tr>
                                    <td><span class="font-monospace fw-bold">FLT-03</span></td>
                                    <td>Filtration Unit</td>
                                    <td>Line 1</td>
                                    <td><span class="badge bg-success-subtle text-success border px-2 py-1">Operational</span></td>
                                    <td class="text-muted">Today, 06:15 AM</td>
                                </tr>
                                <tr>
                                    <td><span class="font-monospace fw-bold">PKG-02</span></td>
                                    <td>Packaging Sealer</td>
                                    <td>Line 2</td>
                                    <td><span class="badge bg-warning-subtle text-warning border px-2 py-1">Maintenance Needed</span></td>
                                    <td class="text-muted">Yesterday, 14:30 PM</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                 </div>
            </div>

            <!-- ================= TAB 3: QUALITY CONTROL ================= -->
            <div class="tab-pane fade" id="panel-qc" role="tabpanel">
                <div class="row g-4">
                    <div class="col-lg-4">
                        <div class="card border-0 shadow-sm p-4 rounded-4">
                            <h6 class="fw-bold text-dark mb-3"><i class="bi bi-clipboard2-check text-info me-2"></i>Log QC Sample</h6>
                            <form>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">Select Batch</label>
                                    <select class="form-select bg-light">
                                        <?php if(!empty($scheduleItems)): ?>
                                            <?php foreach ($scheduleItems as $item): ?>
                                                <option value="<?= $item['batchID'] ?>">#BTH-<?= htmlspecialchars($item['batchID']) ?> - <?= htmlspecialchars($item['product_name']) ?></option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option disabled>No active batches</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">Sample Status</label>
                                    <div class="d-flex gap-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="qc_status" id="qcPass" checked>
                                            <label class="form-check-label text-success fw-bold" for="qcPass">Pass</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="qc_status" id="qcFail">
                                            <label class="form-check-label text-danger fw-bold" for="qcFail">Fail</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">Inspector Notes</label>
                                    <textarea class="form-control bg-light" rows="3" placeholder="Enter anomalies, temperatures, etc."></textarea>
                                </div>
                                <button type="button" class="btn btn-primary w-100 fw-bold">Submit Report</button>
                            </form>
                        </div>
                    </div>
                    
                    <div class="col-lg-8">
                        <div class="card border-0 shadow-sm p-4 rounded-4 h-100">
                            <h6 class="fw-bold text-dark mb-3">Recent Inspections</h6>
                            <div class="table-responsive">
                                <table class="table custom-table border align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Batch ID</th>
                                            <th>Time Logged</th>
                                            <th>Result</th>
                                            <th>Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><span class="font-monospace fw-bold">#BTH-1</span></td>
                                            <td>10:45 AM</td>
                                            <td><span class="badge bg-success-subtle text-success border">Passed</span></td>
                                            <td class="text-muted small">Color and viscosity nominal.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ================= TAB 4: MATERIAL REQUESTS ================= -->
            <div class="tab-pane fade" id="panel-materials" role="tabpanel">
                 <div class="row g-4">
                    <div class="col-lg-4">
                        <div class="card border-0 shadow-sm p-4 rounded-4">
                            <h6 class="fw-bold text-dark mb-3"><i class="bi bi-box-arrow-in-right text-warning me-2"></i>Request Inventory</h6>
                            <form>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">Material Needed</label>
                                    <select class="form-select bg-light" name="material_id">
                                        <?php if(!empty($materials)): ?>
                                            <?php foreach ($materials as $mat): ?>
                                                <!-- Fetched dynamically from rawmaterial_tbl -->
                                                <option value="<?= $mat['materialID'] ?>">
                                                    <?= htmlspecialchars($mat['name']) ?> (Available: <?= htmlspecialchars($mat['quantity']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option disabled>No materials found in database</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">Quantity Requested</label>
                                    <input type="number" name="request_qty" class="form-control bg-light" value="1" min="1">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">Urgency</label>
                                    <select class="form-select bg-light" name="urgency">
                                        <option value="low">Standard Restock</option>
                                        <option value="high" class="text-danger">Immediate (Line Blocking)</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-warning w-100 fw-bold">Send to Warehouse</button>
                            </form>
                        </div>
                    </div>

                    <div class="col-lg-8">
                        <div class="card border-0 shadow-sm p-4 rounded-4 h-100">
                            <h6 class="fw-bold text-dark mb-3">Active Requisitions</h6>
                            <div class="table-responsive">
                                <table class="table custom-table border align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Req ID</th>
                                            <th>Item</th>
                                            <th>Qty</th>
                                            <th>Urgency</th>
                                            <th>Warehouse Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><span class="font-monospace fw-bold">REQ-104</span></td>
                                            <td>Packaging Box</td>
                                            <td>200</td>
                                            <td><span class="badge bg-secondary">Standard</span></td>
                                            <td><span class="text-primary fw-bold small"><i class="bi bi-truck"></i> In Transit</span></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                 </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>