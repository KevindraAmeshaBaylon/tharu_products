<?php
// auth/checkout_guard.php
session_start();

if (isset($_SESSION['user_id'])) {
    // If authenticated customer, forward directly to order processor pipeline
    header("Location: ../customer/dashboard.php?action=review_checkout");
} else {
    // Force registration or access credential collection
    $_SESSION['auth_redirect_reason'] = "To proceed with payments and secure inventory orders, please create a corporate profile or sign in below.";
    header("Location: register.php");
}
exit;
?>