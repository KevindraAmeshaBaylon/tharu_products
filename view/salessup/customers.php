<?php
session_start();

// Include database
require_once '../../model/config/database.php';
$conn = getDBConnection();

// --- VERY BASIC BACKEND LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_customer'])) {
    
    // 1. Get data from form and make it safe
    $id = $conn->real_escape_string($_POST['customerID']);
    $nic = $conn->real_escape_string($_POST['customerNIC']);
    $name = $conn->real_escape_string($_POST['companyname']);
    $address = $conn->real_escape_string($_POST['address']);

    // 2. Simple UPDATE query
    $sql = "UPDATE Customer_tbl SET customerNIC='$nic', companyname='$name', address='$address' WHERE customerID='$id'";
    $conn->query($sql);
    
    // Refresh the page
    header("Location: customers.php");
    exit();
}

// 3. Simple SELECT query
$sql = "SELECT * FROM Customer_tbl";
$result = $conn->query($sql);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Customers - Tharu Systems</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        /* Specific styles for the customer page */
        .content-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 1.5rem;
            border: 1px solid #eef2f0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.02);
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
        <div class="mb-4">
            <h3 class="fw-bold text-dark mb-1">Customer Profiles</h3>
            <span class="text-muted small">View and update client corporate information</span>
        </div>

        <!-- White Card Container -->
        <div class="content-card">
            <div class="table-responsive">
                <table class="table custom-table mb-0">
                    <thead>
                        <tr>
                            <th>SYS ID</th>
                            <th>REG NIC</th>
                            <th>COMPANY NAME</th>
                            <th>BILLING ADDRESS</th>
                            <th>ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if ($result && $result->num_rows > 0) {
                            while($row = $result->fetch_assoc()) { 
                        ?>
                        <tr>
                            <td><span class="font-monospace text-muted fw-bold">#CST-<?php echo $row['customerID']; ?></span></td>
                            <td><?php echo htmlspecialchars($row['customerNIC']); ?></td>
                            <td class="fw-bold text-dark"><?php echo htmlspecialchars($row['companyname']); ?></td>
                            <td><?php echo htmlspecialchars($row['address']); ?></td>
                            <td>
                                <!-- Simple Javascript trigger for the edit form -->
                                <button class="btn btn-sm btn-forest px-3" onclick="openEditForm(
                                    '<?php echo $row['customerID']; ?>', 
                                    '<?php echo htmlspecialchars(addslashes($row['customerNIC'])); ?>', 
                                    '<?php echo htmlspecialchars(addslashes($row['companyname'])); ?>', 
                                    '<?php echo htmlspecialchars(addslashes($row['address'])); ?>'
                                )">✏️ Edit</button>
                            </td>
                        </tr>
                        <?php 
                            }
                        } else {
                            echo "<tr><td colspan='5' class='text-center py-4 text-muted'>No registered customers found in database.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Simple Custom Modal for Updating -->
<div id="editCustomerModal" class="custom-modal-overlay">
    <div class="custom-modal-box">
        <h4 class="text-dark mb-4 fw-bold">Update Customer Profile</h4>
        
        <!-- Basic HTML Form -->
        <form method="POST" action="customers.php">
            <input type="hidden" id="edit_id" name="customerID">
            
            <div class="mb-3">
                <label class="form-label text-muted small fw-bold">National ID (NIC)</label>
                <input type="text" class="form-control bg-light" id="edit_nic" name="customerNIC" required>
            </div>
            
            <div class="mb-3">
                <label class="form-label text-muted small fw-bold">Company Name</label>
                <input type="text" class="form-control bg-light" id="edit_name" name="companyname" required>
            </div>
            
            <div class="mb-4">
                <label class="form-label text-muted small fw-bold">Billing Address</label>
                <input type="text" class="form-control bg-light" id="edit_address" name="address" required>
            </div>
            
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light border px-4" onclick="closeEditForm()">Cancel</button>
                <button type="submit" name="update_customer" class="btn btn-forest px-4">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Very Simple Javascript to handle the form popup -->
<script>
    function openEditForm(id, nic, name, address) {
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_nic').value = nic;
        document.getElementById('edit_name').value = name;
        document.getElementById('edit_address').value = address;
        
        document.getElementById('editCustomerModal').style.display = 'flex';
    }

    function closeEditForm() {
        document.getElementById('editCustomerModal').style.display = 'none';
    }
</script>

</body>
</html>