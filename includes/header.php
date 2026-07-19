<?php
/**
 * Global Header Component
 * File: includes/header.php
 * 
 * This file is included at the top of web pages to ensure consistent styling,
 * responsiveness, session management, and dependencies across the application.
 */

// Start the session if it hasn't been initialized already to avoid duplicate session notices
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Document character encoding (UTF-8) to support standard and special characters -->
    <meta charset="UTF-8">
    
    <!-- Viewport configuration for responsive web design on mobile, tablet, and desktop devices -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- The title of the web page shown in the browser tab -->
    <title>Tharu & Products - Management System</title>
    
    <!-- Bootstrap 5 CSS CDN for grid system, utilities, and styling components -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- ADD THIS LINE: Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
    <!-- Bootstrap Icons CDN --><style>
    :root {
        --dark-forest: #064e3b; /* Primary dark color (deep forest green) */
        --emerald: #10b981;     /* Primary accent color (bright emerald green) */
        --mint: #ecfdf5;        /* Light background tone (mint) */
        --slate: #1e293b;       /* Dark slate text/icon color */
    }
    
    /* Utility background color class utilizing the dark-forest CSS variable */
    .bg-forest { background-color: var(--dark-forest) !important; }
    
    /* Utility text color class utilizing the emerald CSS variable */
    .text-emerald { color: var(--emerald) !important; }
    
    /* 
     * Custom Glassmorphism Button Style (.btn-forest)
     * - NORMAL STATE: Semi-transparent green background with blur backdrop-filter, border, and soft drop shadow.
     */
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
    
    /* 
     * - HOVER STATE: Glowing effects, slightly less transparent background, and translate up.
     */
    .btn-forest:hover {
        background: rgba(52, 211, 153, 0.45) !important;
        border-color: rgba(52, 211, 153, 0.9) !important;
        color: #022c22 !important;
        box-shadow: 0 12px 40px 0 rgba(16, 185, 129, 0.25) !important;
        transform: translateY(-2px) !important;
    }

    /* 
     * - CLICKED (ACTIVE) STATE: Scale down and reduce shadow to simulate physical pressing.
     */
    .btn-forest:active {
        background: rgba(52, 211, 153, 0.6) !important;
        transform: scale(0.96) translateY(-1px) !important;
        box-shadow: 0 4px 16px 0 rgba(16, 185, 129, 0.2) !important;
    }
</style>
</head>
<body class="bg-light">