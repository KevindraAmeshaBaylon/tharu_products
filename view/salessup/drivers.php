<?php
session_start();

// Include database
require_once '../../model/config/database.php';
$conn = getDBConnection();

// --- VERY BASIC BACKEND LOGIC FOR CRUD OPERATIONS ---

// 1. CREATE: Hire a new driver
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_driver'])) {
    $name = $conn->real_escape_string($_POST['drivername']);
    $dob = $conn->real_escape_string($_POST['driverDOB']);
    $salary = $conn->real_escape_string($_POST['fixed_salary']);
    
    // Step A: Create a basic user record first (Required by your database design)
    $tempUsername = "driver_" . time(); // Generate a simple unique username
    $tempEmail = $tempUsername . "@tharu.lk";
    $tempPassword = password_hash("123456", PASSWORD_DEFAULT); // Default password
    
    $userSql = "INSERT INTO User_tbl (username, password, email) VALUES ('$tempUsername', '$tempPassword', '$tempEmail')";
    
    if ($conn->query($userSql)) {
        // Step B: Get the new userID and insert into Driver_tbl
        $userID = $conn->insert_id;
        $driverSql = "INSERT INTO Driver_tbl (userID, driverDOB, drivername, fixed_salary) 
                      VALUES ('$userID', '$dob', '$name', '$salary')";
        $conn->query($driverSql);
    }
    
    // Refresh the page
    header("Location: drivers.php");
    exit();
}

// 2. UPDATE: Edit existing driver details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_driver'])) {
    $driverID = $conn->real_escape_string($_POST['driverID']);
    $name = $conn->real_escape_string($_POST['drivername']);
    $dob = $conn->real_escape_string($_POST['driverDOB']);
    $salary = $conn->real_escape_string($_POST['fixed_salary']);

    $sql = "UPDATE Driver_tbl SET drivername='$name', driverDOB='$dob', fixed_salary='$salary' WHERE driverID='$driverID'";
    $conn->query($sql);
    
    // Refresh the page
    header("Location: drivers.php");
    exit();
}

// 3. DELETE: Remove a driver
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_driver'])) {
    $userID = $conn->real_escape_string($_POST['userID']); // We delete by userID to trigger CASCADE
    
    // Deleting the user automatically deletes the driver profile due to ON DELETE CASCADE
    $sql = "DELETE FROM User_tbl WHERE userID='$userID'";
    $conn->query($sql);
    
    // Refresh the page
    header("Location: drivers.php");
    exit();
}

// 4. READ: Fetch all drivers to display in the table
$sql = "SELECT driverID, userID, drivername, driverDOB, fixed_salary FROM Driver_tbl ORDER BY driverID DESC";
$result = $conn->query($sql);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Fleet Management - Tharu Systems</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

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

        .btn-danger-light {
            background: #ffebe9;
            color: #dc3545;
            border: 1px solid #ffcdd2;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        .btn-danger-light:hover {
            background: #dc3545;
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
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold text-dark mb-1">Driver Fleet Management</h3>
                <span class="text-muted small">Hire, edit, and remove delivery personnel</span>
            </div>
            <button class="btn btn-forest px-4" onclick="openAddModal()">➕ Hire New Driver</button>
        </div>

        <!-- Data Table Card -->
        <div class="content-card">
            <h6 class="fw-bold text-dark mb-3">Active Logistics Fleet</h6>
            <div class="table-responsive">
                <table class="table custom-table mb-0">
                    <thead>
                        <tr>
                            <th>SYS ID</th>
                            <th>DRIVER NAME</th>
                            <th>DATE OF BIRTH</th>
                            <th>FIXED SALARY</th>
                            <th>ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if ($result && $result->num_rows > 0) {
                            while($row = $result->fetch_assoc()) { 
                        ?>
                        <tr>
                            <td><span class="font-monospace text-muted fw-bold">#DRV-<?php echo $row['driverID']; ?></span></td>
                            <td class="fw-bold text-dark"><?php echo htmlspecialchars($row['drivername']); ?></td>
                            <td class="text-muted"><?php echo htmlspecialchars($row['driverDOB']); ?></td>
                            <td class="text-success fw-bold">LKR <?php echo number_format($row['fixed_salary'], 2); ?></td>
                            <td>
                                <div class="d-flex gap-2">
                                    <!-- Edit Button -->
                                    <button class="btn btn-sm btn-forest px-3" onclick="openEditModal(
                                        '<?php echo $row['driverID']; ?>', 
                                        '<?php echo htmlspecialchars(addslashes($row['drivername'])); ?>', 
                                        '<?php echo $row['driverDOB']; ?>', 
                                        '<?php echo $row['fixed_salary']; ?>'
                                    )">✏️ Edit</button>

                                    <!-- Delete Button inside a mini-form for safety -->
                                    <form method="POST" action="drivers.php" onsubmit="return confirm('Are you sure you want to permanently remove this driver?');">
                                        <input type="hidden" name="userID" value="<?php echo $row['userID']; ?>">
                                        <button type="submit" name="delete_driver" class="btn btn-sm btn-danger-light px-3">🗑️ Remove</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php 
                            }
                        } else {
                            echo "<tr><td colspan='5' class='text-center py-4 text-muted'>No drivers currently hired in the fleet.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- Modal for Adding New Driver -->
<div id="addDriverModal" class="custom-modal-overlay">
    <div class="custom-modal-box">
        <h4 class="text-dark mb-4 fw-bold">Hire New Driver</h4>
        
        <form method="POST" action="drivers.php">
            <div class="mb-3">
                <label class="form-label text-muted small fw-bold">Driver Full Name</label>
                <input type="text" class="form-control bg-light" name="drivername" required placeholder="e.g. Nimal Perera">
            </div>
            <div class="mb-3">
                <label class="form-label text-muted small fw-bold">Date of Birth</label>
                <input type="date" class="form-control bg-light" name="driverDOB" required>
            </div>
            <div class="mb-4">
                <label class="form-label text-muted small fw-bold">Monthly Fixed Salary (LKR)</label>
                <input type="number" step="0.01" class="form-control bg-light" name="fixed_salary" required value="0.00">
            </div>
            
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light border px-4" onclick="closeAddModal()">Cancel</button>
                <button type="submit" name="add_driver" class="btn btn-forest px-4">Add to Fleet</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal for Editing Driver Details -->
<div id="editDriverModal" class="custom-modal-overlay">
    <div class="custom-modal-box">
        <h4 class="text-dark mb-4 fw-bold">Update Driver Profile</h4>
        
        <form method="POST" action="drivers.php">
            <input type="hidden" id="edit_id" name="driverID">
            
            <div class="mb-3">
                <label class="form-label text-muted small fw-bold">Driver Full Name</label>
                <input type="text" class="form-control bg-light" id="edit_name" name="drivername" required>
            </div>
            <div class="mb-3">
                <label class="form-label text-muted small fw-bold">Date of Birth</label>
                <input type="date" class="form-control bg-light" id="edit_dob" name="driverDOB" required>
            </div>
            <div class="mb-4">
                <label class="form-label text-muted small fw-bold">Monthly Fixed Salary (LKR)</label>
                <input type="number" step="0.01" class="form-control bg-light" id="edit_salary" name="fixed_salary" required>
            </div>
            
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light border px-4" onclick="closeEditModal()">Cancel</button>
                <button type="submit" name="update_driver" class="btn btn-forest px-4">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Very Simple Javascript to handle the form popups -->
<script>
    // Functions for Add Modal
    function openAddModal() {
        document.getElementById('addDriverModal').style.display = 'flex';
    }
    function closeAddModal() {
        document.getElementById('addDriverModal').style.display = 'none';
    }

    // Functions for Edit Modal
    function openEditModal(id, name, dob, salary) {
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_name').value = name;
        document.getElementById('edit_dob').value = dob;
        document.getElementById('edit_salary').value = salary;
        
        document.getElementById('editDriverModal').style.display = 'flex';
    }
    function closeEditModal() {
        document.getElementById('editDriverModal').style.display = 'none';
    }
</script>

</body>
</html>