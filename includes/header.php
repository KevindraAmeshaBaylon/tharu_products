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
    <!-- Bootstrap Icons CDN --><style>
    :root {
        --dark-forest: #064e3b;
        --emerald: #10b981;
        --mint: #ecfdf5;
        --slate: #1e293b;
    }
    .bg-forest { background-color: var(--dark-forest) !important; }
    .text-emerald { color: var(--emerald) !important; }
    
    /* NORMAL STATE: Your clean, solid forest green button */
    .btn-forest {
        background-color: var(--dark-forest);
        color: #ffffff;
        border: 1px solid transparent;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); /* Ultra smooth transition */
    }
    
    /* HOVER STATE: Transitioning to Emerald */
    .btn-forest:hover {
        background-color: var(--emerald);
        color: #ffffff;
    }

    /* CLICKED (ACTIVE) STATE: Transforms instantly into glassmorphism */
    .btn-forest:active {
        /* Semi-transparent background matching your dark forest green */
        background-color: rgba(6, 78, 59, 0.4) !important; 
        color: var(--dark-forest) !important; /* Dark text so it's readable over the glass */
        
        /* The Glassmorphism Recipe */
        backdrop-filter: blur(8px) saturate(120%);
        -webkit-backdrop-filter: blur(8px) saturate(120%);
        
        /* Highlighted glass borders */
        border: 1px solid rgba(255, 255, 255, 0.45) !important;
        
        /* Soft, spread-out shadow */
        box-shadow: 0 8px 32px 0 rgba(6, 78, 59, 0.15) !important;
        
        /* Slightly scale down for physical click feedback */
        transform: scale(0.96); 
    }
</style>
</head>
<body class="bg-light">