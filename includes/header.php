<?php
// includes/header.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tharu & Products - Management System</title>
    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- ADD THIS LINE: Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
    <!-- Bootstrap Icons CDN --><style>
    :root {
        --dark-forest: #064e3b;
        --emerald: #10b981;
        --mint: #ecfdf5;
        --slate: #1e293b;
    }
    .bg-forest { background-color: var(--dark-forest) !important; }
    .text-emerald { color: var(--emerald) !important; }
    
    /* NORMAL STATE: Bright cool green glassmorphism button */
    .btn-forest {
        background: rgba(52, 211, 153, 0.25) !important;
        backdrop-filter: blur(12px) saturate(180%) !important;
        -webkit-backdrop-filter: blur(12px) saturate(180%) !important;
        border: 2px solid rgba(52, 211, 153, 0.6) !important;
        color: #064e3b !important;
        font-weight: 700 !important;
        border-radius: 12px !important;
        transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1) !important;
        box-shadow: 0 8px 32px 0 rgba(16, 185, 129, 0.15) !important;
        text-shadow: 0 1px 1px rgba(255, 255, 255, 0.4) !important;
    }
    
    /* HOVER STATE: Glowing bright green */
    .btn-forest:hover {
        background: rgba(52, 211, 153, 0.45) !important;
        border-color: rgba(52, 211, 153, 0.9) !important;
        color: #022c22 !important;
        box-shadow: 0 12px 40px 0 rgba(16, 185, 129, 0.25) !important;
        transform: translateY(-2px) !important;
    }

    /* CLICKED (ACTIVE) STATE: Deeper press feedback */
    .btn-forest:active {
        background: rgba(52, 211, 153, 0.6) !important;
        transform: scale(0.96) translateY(-1px) !important;
        box-shadow: 0 4px 16px 0 rgba(16, 185, 129, 0.2) !important;
    }
</style>
</head>
<body class="bg-light">