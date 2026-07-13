<?php
// auth/login.php
session_start();
require_once dirname(__DIR__) . '/config/database.example.php';

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    if (!empty($username) && !empty($password)) {
        $conn = getDBConnection();
        
        $stmt = $conn->prepare("SELECT userID, username, password FROM User_tbl WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            if ($password === $user['password'] || password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['userID'];
                $_SESSION['username'] = $user['username'];
                
                // RBAC Routing Matrix
                if ($user['username'] === 'owner01') {
                    header("Location: ../owner/dashboard.php");
                } else if ($user['username'] === 'accountant01') {
                    header("Location: ../accountant/dashboard.php");
                } else if ($user['username'] === 'stocksup01') {
                    header("Location: ../stocksup/dashboard.php");
                } else if ($user['username'] === 'salessup01') {
                    header("Location: ../salessup/dashboard.php");
                } else {
                    header("Location: ../customer/dashboard.php");
                }
                exit;
            } else {
                $error = "Invalid password credential.";
            }
        } else {
            $error = "No verified account detected with those details.";
        }
    } else {
        $error = "Please fill in all credentials.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome - Tharu Products</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        :root {
            --tharu-green: #2e7d32;
            --tharu-accent: #81c784;
            --text-main: #2c3e50;
        }

        body {
            /* 🌲 High-quality artistic agriculture illustration background matching the style */
            background: url('https://images.unsplash.com/photo-1500937386664-56d1dfef3854?auto=format&fit=crop&w=1920&q=80') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px;
        }

        /* Full layout card container with transparent border framing matching reference */
        .glass-frame-container {
            width: 100%;
            max-width: 1050px;
            min-height: 650px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(8px);
            border: 2px solid rgba(255, 255, 255, 0.4);
            border-radius: 40px;
            display: flex;
            overflow: hidden;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.2);
            position: relative;
        }

        /* 🤍 The iconic curved asymmetric left white panel */
        .white-card-side {
            background: #ffffff;
            flex: 0 0 52%;
            padding: 4rem 4.5rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            border-radius: 38px 80px 80px 38px; /* Generous distinct asymmetric curves */
            z-index: 2;
            box-shadow: 15px 0 35px rgba(0, 0, 0, 0.1);
        }

        /* Right display pane space holding internal optional nav items */
        .visual-right-side {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            align-items: flex-end;
            padding: 2.5rem 3rem;
            z-index: 1;
        }

        /* Top minimal menu links matching reference image layout */
        .top-mini-nav {
            display: flex;
            gap: 2rem;
        }
        .top-mini-nav a {
            color: #aee99c;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
            opacity: 0.9;
            letter-spacing: 0.5px;
        }
        .top-mini-nav a:hover {
            opacity: 1;
            text-decoration: underline;
        }

        /* Large stylized creative title styling */
        .welcome-hero-text {
            font-size: 4.8rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 2.5rem;
            font-family: 'Georgia', serif;
            letter-spacing: -2px;
            background: linear-gradient(135deg, var(--tharu-green) 45%, var(--tharu-accent) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Pill Input Fields styling */
        .modern-input-box {
            position: relative;
            margin-bottom: 1.25rem;
        }
        .modern-input-box input {
            width: 100%;
            padding: 0.9rem 1.5rem;
            border: 1.5px solid #e2e8f0;
            border-radius: 20px;
            font-size: 0.95rem;
            color: var(--text-main);
            background-color: #f8fafc;
            transition: all 0.2s ease;
        }
        .modern-input-box input:focus {
            outline: none;
            border-color: var(--tharu-green);
            background-color: #ffffff;
            box-shadow: 0 0 0 4px rgba(46, 125, 50, 0.1);
        }

        .forgot-link-inline {
            position: absolute;
            right: 1.5rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 0.85rem;
            color: var(--tharu-green);
            text-decoration: none;
            font-weight: 600;
        }
        .forgot-link-inline:hover {
            text-decoration: underline;
        }

        /* Bright Blue/Green wide action button matching reference proportion */
        .btn-action-pill {
            background: linear-gradient(135deg, #1d5c16 0%, #49ca0d 100%);
            color: #ffffff;
            border: none;
            border-radius: 20px;
            padding: 0.9rem;
            font-weight: 700;
            font-size: 1rem;
            width: 100%;
            margin-top: 0.5rem;
            transition: opacity 0.2s ease;
            box-shadow: 0 6px 20px rgba(11, 76, 29, 0.25);
        }
        .btn-action-pill:hover {
            opacity: 0.95;
        }

        .signup-prompt {
            text-align: center;
            font-size: 0.9rem;
            color: #64748b;
            margin-top: 2rem;
        }
        .signup-prompt a {
            color: var(--tharu-green);
            text-decoration: none;
            font-weight: 700;
        }
        .signup-prompt a:hover {
            text-decoration: underline;
        }

        /* Responsive Breakpoint Matrix */
        @media (max-width: 900px) {
            .glass-frame-container {
                min-height: auto;
            }
            .white-card-side {
                flex: 0 0 100%;
                border-radius: 38px;
                padding: 3rem 2rem;
            }
            .visual-right-side {
                display: none;
            }
        }
    </style>
</head>
<body>

<div class="glass-frame-container">
    
    <!-- Left Asymmetric Curved Form Panel -->
    <div class="white-card-side">
        <h1 class="welcome-hero-text">Welcome</h1>

        <?php if(!empty($error)): ?>
            <div class="alert alert-danger py-2 px-3 small border-0 bg-danger bg-opacity-10 text-danger mb-3" style="border-radius: 12px;">
                ⚠️ <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <!-- Account Username Row -->
            <div class="modern-input-box">
                <input type="text" name="username" placeholder="Username / Email" required autocomplete="off">
            </div>

            <!-- Password Row with Integrated Trigger Link -->
            <div class="modern-input-box">
                <input type="password" name="password" placeholder="Password" required>
                <a href="#" class="forgot-link-inline">Forgot?</a>
            </div>

            <!-- Submission Trigger -->
            <button type="submit" class="btn-action-pill">Log in</button>
        </form>

        <p class="signup-prompt">
            Don't have an account? <a href="register.php">Sign-up</a>
        </p>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>