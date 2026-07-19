<?php
/**
 * Checkout Guard Script
 * File: auth/checkout_guard.php
 * 
 * This script ensures that only logged-in users can proceed to checkout.
 * - If the user is NOT logged in, they are redirected to the login page with an auth_required message parameter.
 * - If the user IS logged in, they are redirected to the customer dashboard with the view set to "checkout" to complete their purchase.
 */

// Start the session to check user authentication status
session_start();

// Verify if the user is authenticated by checking the presence of 'user_id' in the session
if (!isset($_SESSION['user_id'])) {
    // If not logged in, redirect the user to the login page with a query parameter 'msg=auth_required'
    // This allows the login page to display a warning/alert notification to the user.
    header("Location: login.php?msg=auth_required");
    // Stop script execution immediately to prevent any further processing
    exit;
}

// If the user is authenticated, redirect them directly to the Customer Dashboard.
// The query parameter 'view=checkout' tells the customer dashboard UI to display the checkout tab/view.
header("Location: ../view/cust_dashboard.php?view=checkout");
// Stop script execution immediately
exit;
?>