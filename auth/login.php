<?php
// 1. OBLITERATE REDIRECT ERRORS (Must be the absolute first things in the file)
session_start();
require_once __DIR__ . '/../model/config/database.php';

$conn = getDBConnection();
$error_message = "";
$success_message = "";

// --- SIGN IN PROCESSOR ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'signin') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (!empty($username) && !empty($password)) {
        $stmt = $conn->prepare("SELECT userID, username, password, email FROM user_tbl WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['userID'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                header("Location: ../index.php");
                exit;
            } else {
                $error_message = "Invalid system credentials configuration provided.";
            }
        } else {
            $error_message = "Username profile does not exist within the system registry.";
        }
    } else {
        $error_message = "Please populate all necessary entry blocks.";
    }
}

// --- SIGN UP PROCESSOR ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'signup') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    
    if (!empty($username) && !empty($email) && !empty($password)) {
        $checkStmt = $conn->prepare("SELECT userID FROM user_tbl WHERE username = ? OR email = ? LIMIT 1");
        $checkStmt->bind_param("ss", $username, $email);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if ($checkResult->num_rows > 0) {
            $error_message = "Username or Registration Email addresses already configured.";
        } else {
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            $defaultCatalogID = 1; 

            $insertStmt = $conn->prepare("INSERT INTO user_tbl (username, password, email) VALUES (?, ?, ?, ?)");
            $insertStmt->bind_param("sssi", $username, $hashedPassword, $email, $defaultCatalogID);
            
            if ($insertStmt->execute()) {
                $success_message = "Registration profile deployed! You can now authenticate via Sign In panel.";
            } else {
                $error_message = "Execution pipeline anomaly encountered during system insertion.";
            }
        }
    } else {
        $error_message = "Please complete all fields within the sign up application.";
    }
}
?>

<?php require_once __DIR__ . '/../includes/header.php'; ?>

<!-- External Layout Icons & Fonts -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
    body {
        background: url('https://images.unsplash.com/photo-1500937386664-56d1dfef3854?auto=format&fit=crop&w=1920&q=80') no-repeat center center fixed;
        background-size: cover;
        font-family: 'Plus Jakarta Sans', sans-serif;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0;
        padding: 20px;
    }

    /* Master Interface Frame */
    #mainWrapper {
        width: 100%;
        max-width: 900px;
        min-height: 550px;
        background: rgba(255, 255, 255, 0.06);
        border: 1px solid rgba(255, 255, 255, 0.15);
        border-radius: 40px;
        overflow: hidden;
        position: relative;
        box-shadow: 0 30px 60px rgba(0, 0, 0, 0.3);
    }

    /* White Form Block */
    .form-panel-side {
        position: absolute;
        top: 0;
        height: 100%;
        width: 50%;
        padding: 50px;
        background: #ffffff;
        display: flex;
        flex-direction: column;
        justify-content: center;
        transition: all 0.6s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        border-radius: 40px 0 0 40px;
    }

    .signin-panel-view { left: 0; z-index: 2; opacity: 1; }
    .signup-panel-view { left: 0; opacity: 0; z-index: 1; }

    #mainWrapper.right-panel-active .signin-panel-view {
        transform: translateX(100%);
        opacity: 0;
        z-index: 1;
    }

    #mainWrapper.right-panel-active .signup-panel-view {
        transform: translateX(100%);
        opacity: 1;
        z-index: 5;
        border-radius: 0 40px 40px 0;
    }

    /* Premium Blurred Frosted Glass Section */
    .slider-container-overlay {
        position: absolute;
        top: 0;
        left: 50%;
        width: 50%;
        height: 100%;
        overflow: hidden;
        transition: transform 0.6s ease-in-out;
        z-index: 100;
        backdrop-filter: blur(40px) brightness(0.8);
        -webkit-backdrop-filter: blur(40px) brightness(0.8);
        background: rgba(0, 0, 0, 0.35); 
        border-left: 1px solid rgba(255, 255, 255, 0.2);
    }

    #mainWrapper.right-panel-active .slider-container-overlay {
        transform: translateX(-100%);
        border-left: none;
        border-right: 1px solid rgba(255, 255, 255, 0.2);
    }

    .slider-track {
        position: relative;
        left: -100%;
        height: 100%;
        width: 200%;
        transform: translateX(0);
        transition: transform 0.6s ease-in-out;
    }

    #mainWrapper.right-panel-active .slider-track {
        transform: translateX(50%);
    }

    .slider-content-block {
        position: absolute;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        padding: 40px;
        text-align: center;
        top: 0;
        height: 100%;
        width: 50%;
        transition: transform 0.6s ease-in-out;
        color: #ffffff;
        text-shadow: 0 2px 8px rgba(0, 0, 0, 0.5); 
    }

    .slider-content-block p { color: rgba(255, 255, 255, 0.85) !important; }
    .slider-block-left { transform: translateX(-200%); }
    #mainWrapper.right-panel-active .slider-block-left { transform: translateX(0); }
    .slider-block-right { right: 0; transform: translateX(0); }
    #mainWrapper.right-panel-active .slider-block-right { transform: translateX(200%); }

    .form-panel-side h2 {
        color: #16a34a; 
        font-weight: 800;
        font-size: 2.4rem;
        margin-bottom: 5px;
    }

    /* Clean Mockup Input Blocks */
    .input-group-custom {
        position: relative;
        width: 100%;
    }

    .form-control-glass {
        background: #f1f5f9 !important;
        border: 1px solid transparent !important;
        color: #334155 !important;
        border-radius: 16px;
        padding: 14px 18px;
        font-size: 0.95rem;
        width: 100%;
        transition: all 0.2s ease;
    }

    .form-control-glass:focus {
        background: #e2e8f0 !important;
        box-shadow: none !important;
    }

    .password-toggle-eye {
        position: absolute;
        right: 18px;
        top: 50%;
        transform: translateY(-50%);
        cursor: pointer;
        color: #94a3b8;
        z-index: 10;
    }

    /* Vibrant Green Button Gradient */
    .btn-forest {
        background: linear-gradient(135deg, #22c55e 0%, #15803d 100%);
        color: #ffffff;
        font-weight: 700;
        border-radius: 16px;
        padding: 14px 24px;
        border: none;
        width: 100%;
        transition: all 0.2s ease;
        box-shadow: 0 4px 15px rgba(22, 163, 74, 0.35);
    }

    .btn-forest:hover {
        background: linear-gradient(135deg, #16a34a 0%, #166534 100%);
        transform: translateY(-1px);
        box-shadow: 0 6px 22px rgba(22, 163, 74, 0.45);
    }

    .toggle-link-inline {
        color: #16a34a;
        font-weight: 700;
        text-decoration: none;
        cursor: pointer;
    }

    .btn-outline-glass-action {
        border: 2px solid #ffffff;
        color: #ffffff;
        background: transparent;
        border-radius: 50px;
        padding: 10px 32px;
        font-size: 0.9rem;
        font-weight: 600;
        transition: all 0.2s ease;
    }

    .btn-outline-glass-action:hover {
        background: #ffffff;
        color: #15803d;
    }
</style>

<div class="d-flex flex-column align-items-center justify-content-center w-100">
    
    <!-- Notifications -->
    <?php if(!empty($error_message)): ?>
        <div class="alert border-0 text-center w-100 mb-3" style="max-width: 900px; background: rgba(220, 53, 69, 0.95); color: #ffffff; border-radius: 16px;">
            ⚠️ <?= $error_message ?>
        </div>
    <?php endif; ?>

    <?php if(!empty($success_message)): ?>
        <div class="alert border-0 text-center w-100 mb-3" style="max-width: 900px; background: #16a34a; color: #ffffff; border-radius: 16px;">
            ✓ <?= $success_message ?>
        </div>
    <?php endif; ?>

    <!-- Master UI Card -->
    <div id="mainWrapper">
        
        <!-- SIGN UP VIEW -->
        <div class="form-panel-side signup-panel-view">
            <form action="login.php" method="POST">
                <input type="hidden" name="action" value="signup">
                <h2>Join Us</h2>
                <p class="mb-4 text-muted small">Register your new system buyer access node.</p>
                
                <div class="mb-3">
                    <input type="text" name="username" class="form-control form-control-glass" placeholder="Username" required autocomplete="off">
                </div>
                
                <div class="mb-3">
                    <input type="email" name="email" class="form-control form-control-glass" placeholder="Email Address" required autocomplete="off">
                </div>
                
                <div class="mb-4 input-group-custom">
                    <input type="password" id="signupPassword" name="password" class="form-control form-control-glass" placeholder="Password" required>
                    <i class="bi bi-eye password-toggle-eye" id="eyeSignup" onclick="toggleVisibility('signupPassword', 'eyeSignup')"></i>
                </div>
                
                <button type="submit" class="btn btn-forest mb-3">Sign Up</button>
                <p class="text-center text-muted small mb-0">Already have an account? <span class="toggle-link-inline" id="linkToSignIn">Log in</span></p>
            </form>
        </div>

        <!-- SIGN IN VIEW -->
        <div class="form-panel-side signin-panel-view">
            <form action="login.php" method="POST">
                <input type="hidden" name="action" value="signin">
                <h2>Welcome</h2>
                <p class="mb-4 text-muted small">Access your localized ordering matrix.</p>
                
                <div class="mb-3">
                    <input type="text" name="username" class="form-control form-control-glass" placeholder="Username / Email" required autocomplete="off">
                </div>
                
                <div class="mb-4 input-group-custom">
                    <input type="password" id="signinPassword" name="password" class="form-control form-control-glass" placeholder="Password" required>
                    <i class="bi bi-eye password-toggle-eye" id="eyeSignin" onclick="toggleVisibility('signinPassword', 'eyeSignin')"></i>
                </div>
                
                <button type="submit" class="btn btn-forest mb-3">Log in</button>
                <p class="text-center text-muted small mb-0">Don't have an account? <span class="toggle-link-inline" id="linkToSignUp">Sign-up</span></p>
            </form>
        </div>

        <!-- GLASSMORPHISM SLIDER LAYER -->
        <div class="slider-container-overlay">
            <div class="slider-track">
                
                <div class="slider-content-block slider-block-left">
                    <h3 class="fw-bold mb-2">Back to the Herd?</h3>
                    <p class="small mb-4" style="max-width: 280px; font-weight: 500;">Access your saved product profiles and checkout manifests directly inside.</p>
                    <button type="button" class="btn-outline-glass-action" id="toSignIn">Sign In</button>
                </div>
                
                <div class="slider-content-block slider-block-right">
                    <h3 class="fw-bold mb-2">New to the Farm?</h3>
                    <p class="small mb-4" style="max-width: 280px; font-weight: 500;">Set up your company profile directly inside.</p>
                    <button type="button" class="btn-outline-glass-action" id="toSignUp">Sign Up</button>
                </div>
                
            </div>
        </div>

    </div>
</div>

<!-- Perfected Combined Script Handler -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    const wrapper = document.getElementById('mainWrapper');
    const toSignUpBtn = document.getElementById('toSignUp');
    const toSignInBtn = document.getElementById('toSignIn');
    const linkToSignUp = document.getElementById('linkToSignUp');
    const linkToSignIn = document.getElementById('linkToSignIn');

    function showSignUp() {
        if (wrapper) wrapper.classList.add("right-panel-active");
    }

    function showSignIn() {
        if (wrapper) wrapper.classList.remove("right-panel-active");
    }

    if (toSignUpBtn) toSignUpBtn.addEventListener('click', showSignUp);
    if (toSignInBtn) toSignInBtn.addEventListener('click', showSignIn);
    if (linkToSignUp) linkToSignUp.addEventListener('click', showSignUp);
    if (linkToSignIn) linkToSignIn.addEventListener('click', showSignIn);

    // Context check for automated checkout flips
    const urlCheck = new URLSearchParams(window.location.search);
    if (urlCheck.get('action') === 'signup') {
        showSignUp();
    }
});

// Interactive Eye Icon Action Routine
function toggleVisibility(inputId, eyeId) {
    const passwordInput = document.getElementById(inputId);
    const toggleIcon = document.getElementById(eyeId);
    
    if (passwordInput && toggleIcon) {
        if (passwordInput.type === "password") {
            passwordInput.type = "text";
            toggleIcon.className = "bi bi-eye-slash password-toggle-eye";
        } else {
            passwordInput.type = "password";
            toggleIcon.className = "bi bi-eye password-toggle-eye";
        }
    }
}
</script>
</body>
</html>