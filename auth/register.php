<?php
// auth/register.php
session_start();
require_once dirname(__DIR__) . '/model/config/database.php';

$error = "";
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $companyname = trim($_POST['companyname']);
    $contact = trim($_POST['contact']);
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);

    if (!empty($username) && !empty($password) && !empty($email) && !empty($companyname)) {
        $conn = getDBConnection();
        
        // 1. Check if Username or Email is already taken
        $checkStmt = $conn->prepare("SELECT userID FROM User_tbl WHERE username = ?");
        $checkStmt->bind_param("s", $username);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            $error = "This username is already taken.";
        } else {
            // 2. Hash Password for DB Safety
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            
            $conn->begin_transaction();
            try {
                // 3. Insert into User_tbl
                $userStmt = $conn->prepare("INSERT INTO User_tbl (username, password) VALUES (?, ?)");
                $userStmt->bind_param("ss", $username, $hashedPassword);
                $userStmt->execute();
                $newUserID = $conn->insert_id;

                // 4. Insert into Customer_tbl linked by newUserID
                $custStmt = $conn->prepare("INSERT INTO Customer_tbl (userID, companyname, contact, email, address) VALUES (?, ?, ?, ?, ?)");
                $custStmt->bind_param("issss", $newUserID, $companyname, $contact, $email, $address);
                $custStmt->execute();

                $conn->commit();
                $success = "Registration successful! You can now log in below.";
            } catch (Exception $e) {
                $conn->rollback();
                $error = "System registration breakdown: " . $e->getMessage();
            }
        }
    } else {
        $error = "Please fill in all mandatory identity elements.";
    }
}
?>

<?php require_once dirname(__DIR__) . '/includes/header.php'; ?>

<div class="container d-flex justify-content-center align-items-center min-vh-100 my-5">
    <div class="card shadow border-0" style="width: 100%; max-width: 500px; border-radius: 12px;">
        <div class="card-body p-5">
            <h3 class="fw-bold text-success mb-2">🌱 Create Corporate Account</h3>
            <p class="text-muted small mb-4">Register your agricultural business unit to unlock inventory settlement pipelines.</p>

            <?php if(!empty($_SESSION['auth_redirect_reason'])): ?>
                <div class="alert alert-warning small py-2"><?= htmlspecialchars($_SESSION['auth_redirect_reason']); unset($_SESSION['auth_redirect_reason']); ?></div>
            <?php endif; ?>
            <?php if(!empty($error)): ?>
                <div class="alert alert-danger small py-2"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if(!empty($success)): ?>
                <div class="alert alert-success small py-2"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <h6 class="text-secondary text-uppercase small font-monospace border-bottom pb-1 mb-3">Authentication Credentials</h6>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Target Account Username</label>
                    <input type="text" name="username" class="form-control form-control-sm" required>
                </div>
                <div class="mb-4">
                    <label class="form-label small fw-semibold">Account Access Password</label>
                    <input type="password" name="password" class="form-control form-control-sm" required>
                </div>

                <h6 class="text-secondary text-uppercase small font-monospace border-bottom pb-1 mb-3">Business Enterprise Particulars</h6>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Company Name</label>
                    <input type="text" name="companyname" class="form-control form-control-sm" placeholder="e.g. Western Agro Holdings" required>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Contact Person</label>
                        <input type="text" name="contact" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Corporate Email</label>
                        <input type="email" name="email" class="form-control form-control-sm" required>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label small fw-semibold">Delivery Shipping Address</label>
                    <textarea name="address" class="form-control form-control-sm" rows="2" required></textarea>
                </div>

                <button type="submit" class="btn btn-forest w-100 py-2 fw-bold rounded-2">Register Corporate Profile</button>
            </form>
            <div class="text-center mt-3">
                <a href="login.php" class="text-success small fw-semibold decoration-none">Already have an account? Log in here</a>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>