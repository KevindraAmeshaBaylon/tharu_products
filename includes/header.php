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
    <style>
        :root {
            --dark-forest: #064e3b;
            --emerald: #10b981;
            --mint: #ecfdf5;
            --slate: #1e293b;
        }
        .bg-forest { background-color: var(--dark-forest) !important; }
        .text-emerald { color: var(--emerald) !important; }
        .btn-forest {
            background-color: var(--dark-forest);
            color: #ffffff;
            transition: all 0.3s ease;
        }
        .btn-forest:hover {
            background-color: var(--emerald);
            color: #ffffff;
        }
    </style>
</head>
<body class="bg-light">