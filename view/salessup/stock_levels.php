<?php
session_start();

// Include database
require_once '../../model/config/database.php';
$conn = getDBConnection();

// --- VERY BASIC BACKEND LOGIC ---

// 1. Handle "Add New Material" form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_material'])) {
    $name = $conn->real_escape_string($_POST['name']);
    $qty = $conn->real_escape_string($_POST['quantity']);
    $price = $conn->real_escape_string($_POST['unitprice']);
    $supplierID = $conn->real_escape_string($_POST['supplierID']);

    // Simple INSERT query
    $sql = "INSERT INTO Rawmaterial_tbl (name, quantity, unitprice, supplierID) 
            VALUES ('$name', '$qty', '$price', '$supplierID')";
    $conn->query($sql);
    
    header("Location: stock_levels.php");
    exit();
}

// 2. Handle "Adjust Stock" form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_material'])) {
    $materialID = $conn->real_escape_string($_POST['materialID']);
    $qty = $conn->real_escape_string($_POST['quantity']);
    $price = $conn->real_escape_string($_POST['unitprice']);

    // Simple UPDATE query
    $sql = "UPDATE Rawmaterial_tbl SET quantity='$qty', unitprice='$price' WHERE materialID='$materialID'";
    $conn->query($sql);
    
    header("Location: stock_levels.php");
    exit();
}

// 3. Fetch Suppliers for the "Add New" dropdown
$suppliers = [];
$supSql = "SELECT supplierID, companyname FROM Supplier_tbl";
$supResult = $conn->query($supSql);
if ($supResult && $supResult->num_rows > 0) {
    while($row = $supResult->fetch_assoc()) {
        $suppliers[] = $row;
    }
}

// 4. Fetch all stock items to display in the table and chart
$sql = "SELECT materialID, name, quantity, unitprice FROM Rawmaterial_tbl ORDER BY quantity DESC";
$result = $conn->query($sql);

$chartLabels = [];
$chartData = [];
$tableRows = [];

if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $tableRows[] = $row;
        $chartLabels[] = $row['name'];
        $chartData[] = $row['quantity'];
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Levels - Tharu Systems</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Chart.js for the diagram -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        /* Reused specific styles for the light dashboard theme */
        .content-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 1.5rem;
            border: 1px solid #eef2f0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.02);
            margin-bottom: 25px;
        }

        .custom-table th {
            background-color: #f1f5f3;
            color: #475569;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
            padding: 1rem;
            border-bottom: none;
        }
        
        .custom-table td {
            padding: 1rem;
            vertical-align: middle;
            font-size: 0.92rem;
            border-bottom: 1px solid #eef2f0;
            color: #334155;
        }

        .btn-forest {
            background: #2e7d32;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        .btn-forest:hover {
            background: #1b5e20;
            color: #ffffff;
        }

        /* Badge for low stock warning */
        .badge-low { background-color: #dc3545; color: white; padding: 5px 10px; border-radius: 6px; font-size: 0.8rem; }
        .badge-good { background-color: #198754; color: white; padding: 5px 10px; border-radius: 6px; font-size: 0.8rem; }

        /* Simple Custom Modal Overlay */
        .custom-modal-overlay {
            display: none; 
            position: fixed; 
            top: 0; left: 0; 
            width: 100%; height: 100%; 
            background: rgba(0, 0, 0, 0.5); 
            backdrop-filter: blur(3px);
            z-index: 2000;
            align-items: center; 
            justify-content: center;
        }
        .custom-modal-box {
            width: 100%;
            max-width: 500px;
            background: #ffffff;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>

<div class="dashboard-wrapper">
    
    <!-- INCLUDE THE REUSABLE SIDEBAR -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold text-dark mb-1">Stock Levels</h3>
                <span class="text-muted small">Monitor raw material inventory and adjust quantities</span>
            </div>
            <button class="btn btn-forest px-4" onclick="openAddModal()">➕ Add New Material</button>
        </div>

        <!-- Diagram / Chart Card -->
        <div class="content-card">
            <h5 class="fw-bold text-dark mb-4">Inventory Overview Diagram</h5>
            <div style="height: 300px; width: 100%;">
                <canvas id="stockChart"></canvas>
            </div>
        </div>

        <!-- Data Table Card -->
        <div class="content-card">
            <h5 class="fw-bold text-dark mb-3">Inventory Details</h5>
            <div class="table-responsive">
                <table class="table custom-table mb-0">
                    <thead>
                        <tr>
                            <th>SYS ID</th>
                            <th>MATERIAL NAME</th>
                            <th>UNIT PRICE</th>
                            <th>AVAILABLE QUANTITY</th>
                            <th>STATUS</th>
                            <th>ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if (!empty($tableRows)) {
                            foreach($tableRows as $row) { 
                                // Basic logic to show a warning if stock is below 1000
                                $statusBadge = ($row['quantity'] < 1000) ? "<span class='badge-low'>Low Stock</span>" : "<span class='badge-good'>Healthy</span>";
                        ?>
                        <tr>
                            <td><span class="font-monospace text-muted fw-bold">#RAW-<?php echo $row['materialID']; ?></span></td>
                            <td class="fw-bold text-dark"><?php echo htmlspecialchars($row['name']); ?></td>
                            <td class="text-muted">LKR <?php echo number_format($row['unitprice'], 2); ?></td>
                            <td class="font-monospace fw-bold text-dark"><?php echo htmlspecialchars($row['quantity']); ?> Units</td>
                            <td><?php echo $statusBadge; ?></td>
                            <td>
                                <button class="btn btn-sm btn-forest px-3" onclick="openAdjustModal(
                                    '<?php echo $row['materialID']; ?>', 
                                    '<?php echo htmlspecialchars(addslashes($row['name'])); ?>', 
                                    '<?php echo $row['quantity']; ?>', 
                                    '<?php echo $row['unitprice']; ?>'
                                )">⚙️ Adjust Stock</button>
                            </td>
                        </tr>
                        <?php 
                            }
                        } else {
                            echo "<tr><td colspan='6' class='text-center py-4 text-muted'>No stock records found in database.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- Modal for Adding New Material -->
<div id="addMaterialModal" class="custom-modal-overlay">
    <div class="custom-modal-box">
        <h4 class="text-dark mb-4 fw-bold">Add New Raw Material</h4>
        
        <form method="POST" action="stock_levels.php">
            <div class="mb-3">
                <label class="form-label text-muted small fw-bold">Material Name</label>
                <input type="text" class="form-control bg-light" name="name" required placeholder="e.g. Corn Flour">
            </div>
            <div class="mb-3">
                <label class="form-label text-muted small fw-bold">Initial Quantity</label>
                <input type="number" step="0.01" class="form-control bg-light" name="quantity" required value="0">
            </div>
            <div class="mb-3">
                <label class="form-label text-muted small fw-bold">Unit Price (LKR)</label>
                <input type="number" step="0.01" class="form-control bg-light" name="unitprice" required value="0.00">
            </div>
            <div class="mb-4">
                <label class="form-label text-muted small fw-bold">Supplier</label>
                <select class="form-select bg-light" name="supplierID" required>
                    <option value="">-- Select Supplier --</option>
                    <?php foreach($suppliers as $sup): ?>
                        <option value="<?php echo $sup['supplierID']; ?>">
                            <?php echo htmlspecialchars($sup['companyname']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light border px-4" onclick="closeAddModal()">Cancel</button>
                <button type="submit" name="add_material" class="btn btn-forest px-4">Add Material</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal for Adjusting Existing Stock -->
<div id="adjustStockModal" class="custom-modal-overlay">
    <div class="custom-modal-box">
        <h4 class="text-dark mb-2 fw-bold">Adjust Stock Level</h4>
        <p class="text-muted small mb-4">Updating: <span id="display_mat_name" class="fw-bold text-dark"></span></p>
        
        <form method="POST" action="stock_levels.php">
            <input type="hidden" id="adj_id" name="materialID">
            
            <div class="mb-3">
                <label class="form-label text-muted small fw-bold">Update Quantity</label>
                <input type="number" step="0.01" class="form-control bg-light" id="adj_qty" name="quantity" required>
            </div>
            <div class="mb-4">
                <label class="form-label text-muted small fw-bold">Update Unit Price (LKR)</label>
                <input type="number" step="0.01" class="form-control bg-light" id="adj_price" name="unitprice" required>
            </div>
            
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light border px-4" onclick="closeAdjustModal()">Cancel</button>
                <button type="submit" name="update_material" class="btn btn-forest px-4">Save Adjustments</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Modal Functions for Adding Material
    function openAddModal() {
        document.getElementById('addMaterialModal').style.display = 'flex';
    }
    function closeAddModal() {
        document.getElementById('addMaterialModal').style.display = 'none';
    }

    // Modal Functions for Adjusting Stock
    function openAdjustModal(id, name, qty, price) {
        document.getElementById('adj_id').value = id;
        document.getElementById('display_mat_name').innerText = name;
        document.getElementById('adj_qty').value = qty;
        document.getElementById('adj_price').value = price;
        
        document.getElementById('adjustStockModal').style.display = 'flex';
    }
    function closeAdjustModal() {
        document.getElementById('adjustStockModal').style.display = 'none';
    }

    // Grab the data from PHP and convert it into Javascript arrays for Chart.js
    const chartLabels = <?php echo json_encode($chartLabels); ?>;
    const chartData = <?php echo json_encode($chartData); ?>;

    const ctx = document.getElementById('stockChart').getContext('2d');

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: chartLabels,
            datasets: [{
                label: 'Available Quantity',
                data: chartData,
                backgroundColor: 'rgba(46, 125, 50, 0.7)',
                borderColor: '#2e7d32',
                borderWidth: 1,
                borderRadius: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: '#f1f5f3' }
                },
                x: {
                    grid: { display: false }
                }
            },
            plugins: {
                legend: { display: false }
            }
        }
    });
</script>

</body>
</html>