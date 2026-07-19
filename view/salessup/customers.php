<?php
session_start();

// Include database setup
require_once '../../model/config/database.php';

// STRICT AUTHENTICATION GUARD (Preserved exactly as requested)
// basically making sure only sales supervisors can get in here
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'salessup') {
    header("Location: ../../auth/login.php");
    exit;
}

$conn = getDBConnection();

// --- VERY BASIC BACKEND LOGIC ---

// 1. Handle Customer Profile Update
// when someone clicks 'save changes' on a customer profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_customer'])) {
    $id = $conn->real_escape_string($_POST['customerID']);
    $nic = $conn->real_escape_string($_POST['customerNIC']);
    $name = $conn->real_escape_string($_POST['companyname']);
    $address = $conn->real_escape_string($_POST['address']);

    $sql = "UPDATE Customer_tbl SET customerNIC='$nic', companyname='$name', address='$address' WHERE customerID='$id'";
    $conn->query($sql);
    
    header("Location: customers.php");
    exit();
}

// 2. Handle Inquiry Response
// when we reply to a customer's message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['respond_inquiry'])) {
    $inquiryID = $conn->real_escape_string($_POST['inquiryID']);
    $response = $conn->real_escape_string($_POST['response']);

    // Update the inquiry with the response and change flags
    // just updating the inquiry row in the db to show it's answered
    $sql = "UPDATE Inquiry_tbl SET response='$response', pending=0, answered=1 WHERE inquiryID='$inquiryID'";
    $conn->query($sql);
    
    header("Location: customers.php");
    exit();
}

// 3. Fetch Customers
// getting all the customers from the database so we can list them
$sql = "SELECT * FROM Customer_tbl";
$result = $conn->query($sql);

// 4. Fetch Inquiries with Customer Names
// pulling in the messages and matching them up with the company name
$inquirySql = "SELECT i.inquiryID, i.message, i.response, i.pending, i.answered, c.companyname 
               FROM Inquiry_tbl i
               JOIN Customer_tbl c ON i.customerID = c.customerID
               ORDER BY i.pending DESC, i.inquiryID DESC";
$inquiryResult = $conn->query($inquirySql);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Customers & Inquiries - Tharu Systems</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

    <style>
        /* Specific styles for the customer page */
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

        .badge-pending { background-color: #dc3545; color: white; padding: 5px 10px; border-radius: 6px; font-size: 0.8rem; }
        .badge-answered { background-color: #198754; color: white; padding: 5px 10px; border-radius: 6px; font-size: 0.8rem; }

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
        
        <!-- SECTION 1: CUSTOMER PROFILES -->
        <div class="mb-4">
            <h3 class="fw-bold text-dark mb-1">Customer Profiles</h3>
            <span class="text-muted small">View and update client corporate information</span>
        </div>

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
                                <button class="btn btn-sm btn-forest px-3" onclick="openEditForm(
                                    '<?php echo $row['customerID']; ?>', 
                                    '<?php echo htmlspecialchars(addslashes($row['customerNIC'])); ?>', 
                                    '<?php echo htmlspecialchars(addslashes($row['companyname'])); ?>', 
                                    '<?php echo htmlspecialchars(addslashes($row['address'])); ?>'
                                )"><i class="bi bi-pencil-square"></i> Edit</button>
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

        <!-- SECTION 2: CUSTOMER INQUIRIES -->
        <div class="mb-4 mt-5">
            <h3 class="fw-bold text-dark mb-1">Customer Inquiries</h3>
            <span class="text-muted small">Read and respond to messages from your clients</span>
        </div>

        <div class="content-card">
            <div class="table-responsive">
                <table class="table custom-table mb-0">
                    <thead>
                        <tr>
                            <th>INQ ID</th>
                            <th>CUSTOMER</th>
                            <th>MESSAGE</th>
                            <th>STATUS</th>
                            <th>ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if ($inquiryResult && $inquiryResult->num_rows > 0) {
                            while($inq = $inquiryResult->fetch_assoc()) { 
                                $statusBadge = $inq['pending'] == 1 ? '<span class="badge-pending">Pending</span>' : '<span class="badge-answered">Answered</span>';
                        ?>
                        <tr>
                            <td><span class="font-monospace text-muted fw-bold">#INQ-<?php echo $inq['inquiryID']; ?></span></td>
                            <td class="fw-bold text-dark"><?php echo htmlspecialchars($inq['companyname']); ?></td>
                            <td><?php echo htmlspecialchars($inq['message']); ?></td>
                            <td><?php echo $statusBadge; ?></td>
                            <td>
                                <button class="btn btn-sm <?php echo $inq['pending'] == 1 ? 'btn-forest' : 'btn-secondary'; ?> px-3" onclick="openRespondForm(
                                    '<?php echo $inq['inquiryID']; ?>', 
                                    '<?php echo htmlspecialchars(addslashes($inq['companyname'])); ?>', 
                                    '<?php echo htmlspecialchars(addslashes($inq['message'])); ?>', 
                                    '<?php echo htmlspecialchars(addslashes($inq['response'] ?? '')); ?>'
                                )">
                                    <?php echo $inq['pending'] == 1 ? '<i class="bi bi-envelope"></i> Respond' : '<i class="bi bi-eye"></i> View / Edit'; ?>
                                </button>
                            </td>
                        </tr>
                        <?php 
                            }
                        } else {
                            echo "<tr><td colspan='5' class='text-center py-4 text-muted'>No inquiries found in database.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- Modal for Updating Customer -->
<div id="editCustomerModal" class="custom-modal-overlay">
    <div class="custom-modal-box">
        <h4 class="text-dark mb-4 fw-bold">Update Customer Profile</h4>
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

<!-- Modal for Responding to Inquiry -->
<div id="respondInquiryModal" class="custom-modal-overlay">
    <div class="custom-modal-box">
        <h4 class="text-dark mb-2 fw-bold">Inquiry Response</h4>
        <p class="text-muted small mb-4">Responding to: <span id="inq_customer_name" class="fw-bold text-dark"></span></p>
        
        <form method="POST" action="customers.php">
            <input type="hidden" id="inq_id" name="inquiryID">
            
            <div class="mb-3">
                <label class="form-label text-muted small fw-bold">Customer's Message</label>
                <textarea class="form-control bg-light" id="inq_message" rows="3" disabled></textarea>
            </div>
            
            <div class="mb-4">
                <label class="form-label text-muted small fw-bold">Your Response</label>
                <textarea class="form-control" id="inq_response" name="response" rows="4" required placeholder="Type your response here..."></textarea>
            </div>
            
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light border px-4" onclick="closeRespondForm()">Cancel</button>
                <button type="submit" name="respond_inquiry" class="btn btn-forest px-4">Submit Response</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Functions for Customer Edit Modal
    // this pops up the edit form and fills in the current details
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

    // Functions for Inquiry Response Modal
    // this opens up the reply box for a customer message
    function openRespondForm(id, customerName, message, response) {
        document.getElementById('inq_id').value = id;
        document.getElementById('inq_customer_name').innerText = customerName;
        document.getElementById('inq_message').value = message;
        document.getElementById('inq_response').value = response;
        document.getElementById('respondInquiryModal').style.display = 'flex';
    }

    function closeRespondForm() {
        document.getElementById('respondInquiryModal').style.display = 'none';
    }
</script>

</body>
</html>
