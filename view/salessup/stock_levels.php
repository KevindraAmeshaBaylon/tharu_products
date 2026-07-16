<?php
session_start();

// Include database
require_once '../../model/config/database.php';
$conn = getDBConnection();

// --- VERY BASIC BACKEND LOGIC ---
// Fetch all stock items to display in the table and chart
$sql = "SELECT name, quantity FROM Rawmaterial_tbl ORDER BY quantity DESC";
$result = $conn->query($sql);

// We need to prepare data for the JavaScript chart
$chartLabels = [];
$chartData = [];

// Create an array to hold table rows so we only need to loop through the database result once
$tableRows = [];

if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        // Save for the HTML table
        $tableRows[] = $row;
        
        // Save for the JS Chart
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

        /* Badge for low stock warning */
        .badge-low {
            background-color: #dc3545;
            color: white;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 0.8rem;
        }
        .badge-good {
            background-color: #198754;
            color: white;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>

<div class="dashboard-wrapper">
    
    <!-- INCLUDE THE REUSABLE SIDEBAR -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="main-content">
        <div class="mb-4">
            <h3 class="fw-bold text-dark mb-1">Stock Levels</h3>
            <span class="text-muted small">Monitor raw material inventory and graphical analytics</span>
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
                            <th>MATERIAL NAME</th>
                            <th>AVAILABLE QUANTITY</th>
                            <th>STOCK STATUS</th>
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
                            <td class="fw-bold text-dark"><?php echo htmlspecialchars($row['name']); ?></td>
                            <td class="font-monospace"><?php echo htmlspecialchars($row['quantity']); ?> Units</td>
                            <td><?php echo $statusBadge; ?></td>
                        </tr>
                        <?php 
                            }
                        } else {
                            echo "<tr><td colspan='3' class='text-center py-4 text-muted'>No stock records found in database.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- Basic Javascript to render the Chart.js diagram -->
<script>
    // Grab the data from PHP and convert it into Javascript arrays
    const chartLabels = <?php echo json_encode($chartLabels); ?>;
    const chartData = <?php echo json_encode($chartData); ?>;

    // Get the canvas element
    const ctx = document.getElementById('stockChart').getContext('2d');

    // Create the basic bar chart
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: chartLabels,
            datasets: [{
                label: 'Available Quantity',
                data: chartData,
                backgroundColor: 'rgba(46, 125, 50, 0.7)', // Forest green to match theme
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
                legend: { display: false } // Hide the top legend to keep it clean
            }
        }
    });
</script>

</body>
</html>D