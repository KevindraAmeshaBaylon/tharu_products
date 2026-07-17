<?php
// auth/checkout_guard.php
session_start();

if (!isset($_SESSION['user_id'])) {
    // If not logged in, redirect to login page with alert parameters
    header("Location: login.php?msg=auth_required");
    exit;
}

// Redirect them instantly to their Customer Dashboard, instructing the view layer to load the Checkout tab.
header("Location: ../view/cust_dashboard.php?view=checkout");
exit;