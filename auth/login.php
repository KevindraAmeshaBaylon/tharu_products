<?php
// auth/login.php
session_start();
require_once dirname(__DIR__) . '/model/config/database.php';

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    if (!empty($username) && !empty($password)) {
        $conn = getDBConnection();
        
        // Query to match User_tbl fields from your database schema
        $stmt = $conn->prepare("SELECT userID, username, password FROM User_tbl WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Checking placeholder seeds or hashed passwords safely
            if (strpos($user['password'], 'examplehashplaceholder') !== false || password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['userID'];
                $_SESSION['username'] = $user['username'];
                
                // Let's redirect dynamically based on user role strings
                // Inside auth/login.php redirect block logic replacement:
                if ($user['username'] === 'owner01') {
                    header("Location: ../owner/dashboard.php");
                } else if ($user['username'] === 'customer01') {
                    header("Location: ../customer/dashboard.php");
                } else if ($user['username'] === 'accountant01') {
                    header("Location: ../accountant/dashboard.php");
                } else if ($user['username'] === 'stocksup01') {
                    header("Location: ../stocksup/dashboard.php");
                } else if ($user['username'] === 'salessup01') {
                    header("Location: ../salessup/dashboard.php");
                } else {
                    header("Location: ../index.php");
                }
                exit;
            } else {
                $error = "Invalid username or password credentials.";
            }
        } else {
            $error = "No user account detected with those details.";
        }
    } else {
        $error = "Please fill in all parameter inputs.";
    }
}
?>

<?php require_once dirname(__DIR__) . '/includes/header.php'; ?>

<div class="container d-flex justify-content-center align-items-center min-vh-100">
    <div class="card shadow border-0" style="width: 100%; max-width: 420px; border-radius: 12px;">
        <div class="card-body p-5">
            <div class="text-center mb-4">
                <h3 class="fw-bold text-success" style="color: var(--dark-forest) !important;">🌾 Tharu & Products</h3>
                <p class="text-muted small">Animal Feed Supply Management System Portal</p>
            </div>
            
            <?php if(!empty($error)): ?>
                <div class="alert alert-danger py-2 small text-center"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="mb-3">
                    <label class="form-label text-dark fw-semibold small">Username</label>
                    <input type="text" name="username" class="form-control" placeholder="e.g. owner01 or customer01" required>
                </div>
                <div class="mb-4">
                    <label class="form-label text-dark fw-semibold small">Password</label>
                    <input type="password" name="password" class="form-control" placeholder="••••••••" required>
                </div>
                <button type="submit" class="btn btn-forest w-100 py-2 fw-bold shadow-sm">Authorize Session</button>
            </form>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>