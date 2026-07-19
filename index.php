<?php
/**
 * Main Public Landing Page & E-Commerce Catalog
 * File: index.php
 * 
 * This file serves as the main entry point of the public website.
 * It features:
 * 1. Session-based shopping cart management.
 * 2. AJAX-driven cart updates (Add, Decrease, Remove items).
 * 3. Dynamic product catalog querying from product_tbl database.
 * 4. Responsive visual design with glassmorphic cards and category filter pills.
 * 5. Interactive Antigravity Canvas particle background reacting to mouse coordinates.
 * 6. Responsive footer with detailed contact information.
 */

// Start session to enable access to shopping cart data stored in $_SESSION
session_start();

// Include database configurations to query the product list
require_once __DIR__ . '/model/config/database.php';

// Initialize the cart as an empty array if not already configured in the user's session
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Retrieve a database connection instance
$conn = getDBConnection();

// Fetch all available products to display on the landing page catalog
$productQuery = "SELECT productID, name, type, unitprice, description FROM product_tbl";
$productsResult = $conn->query($productQuery);

// --- Handle AJAX Actions ---
// intercept POST requests coming from AJAX (fetch calls) to manipulate cart state
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Sanitize the product ID to ensure it is treated as an integer
    $id = intval($_POST['product_id']);

    // ACTION: Add item (or increase quantity of existing item) in the cart
    if ($_POST['action'] === 'add_to_cart') {
        $qty = isset($_POST['qty']) ? intval($_POST['qty']) : 1;
        if ($qty <= 0) $qty = 1;
        
        // Add the quantity to the session cart array
        $_SESSION['cart'][$id] = ($_SESSION['cart'][$id] ?? 0) + $qty;
        
        // Output result as JSON containing the status and the sum total of all items in the cart
        echo json_encode(['status' => 'success', 'cart_count' => array_sum($_SESSION['cart'])]);
        exit;
    }

    // ACTION: Decrease quantity of a product in the cart
    if ($_POST['action'] === 'decrease_quantity') {
        if (isset($_SESSION['cart'][$id])) {
            $_SESSION['cart'][$id]--;
            // Remove the item completely from the session array if the quantity drops to 0 or below
            if ($_SESSION['cart'][$id] <= 0) {
                unset($_SESSION['cart'][$id]);
            }
        }
        echo json_encode(['status' => 'success', 'cart_count' => array_sum($_SESSION['cart'])]);
        exit;
    }

    // ACTION: Remove product entirely from the cart
    if ($_POST['action'] === 'remove_from_cart') {
        if (isset($_SESSION['cart'][$id])) {
            unset($_SESSION['cart'][$id]);
        }
        echo json_encode(['status' => 'success', 'cart_count' => array_sum($_SESSION['cart'])]);
        exit;
    }
}
?>

<?php 
// Include global header component containing CSS frame links and main styles
require_once __DIR__ . '/includes/header.php'; 
?>

<!-- Google Fonts & Bootstrap Icons CDN -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

<style>
    /* Styling variables and themes */
    :root {
        --forest-brand: #0f766e;
        --deep-forest: #052e2b;
        --mint-highlight: #6ee7b7;
        --glass-panel-bg: rgba(255, 255, 255, 0.62);
        --glass-border: rgba(15, 118, 110, 0.22);
        --text-dark: #0f172a;
        --text-muted: #475569;
        --font-primary: 'Plus Jakarta Sans', sans-serif;
        --font-mono: 'Space Grotesk', sans-serif;
    }

    /* Enables smooth scrolling on anchor links click */
    html {
        scroll-behavior: smooth;
    }

    /* Radial and linear gradient background stack for the body */
    body {
        background-color: #f5fef9;
        background-image:
            radial-gradient(circle at 10% 20%, rgba(16, 185, 129, 0.08) 0%, transparent 42%),
            radial-gradient(circle at 90% 80%, rgba(6, 95, 70, 0.06) 0%, transparent 48%),
            linear-gradient(135deg, #f7fff9 0%, #edf9f2 38%, #e2f7ed 100%);
        background-attachment: fixed;
        color: var(--text-dark);
        font-family: var(--font-primary);
        overflow-x: hidden;
    }

    /* Image containment styling for the logo */
    .brand-logo-img {
        height: 42px;
        width: auto;
        object-fit: contain;
    }

    /* 
     * SCROLL-ONLY DYNAMIC HEADER
     * Sits fixed at top, hides initially (translateY -100%) and animates down when scrolling past 250px.
     */
    .scroll-navbar {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        z-index: 1050;
        transform: translateY(-100%);
        opacity: 0;
        transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.3s ease;
        background: rgba(2, 44, 34, 0.92) !important;
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border-bottom: 2px solid var(--mint-highlight);
        box-shadow: 0 10px 30px rgba(2, 44, 34, 0.15);
    }
    
    /* Display class toggled via JavaScript */
    .scroll-navbar.show-nav {
        transform: translateY(0);
        opacity: 1;
    }

    .scroll-navbar .navbar-collapse {
        justify-content: flex-end;
    }

    .scroll-navbar .navbar-brand span {
        color: #ffffff !important;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }
    
    .scroll-navbar .nav-link {
        color: rgba(255, 255, 255, 0.85) !important;
        font-weight: 500;
        transition: color 0.2s ease;
    }
    
    .scroll-navbar .nav-link:hover {
        color: var(--mint-highlight) !important;
    }

    /* 
     * FROSTED GLASS PANELS
     * Semi-transparent panels used in multiple sections of the layout.
     */
    .glass-panel {
        background: linear-gradient(135deg, rgba(255, 255, 255, 0.45), rgba(240, 253, 244, 0.35)) !important;
        backdrop-filter: blur(20px) !important;
        -webkit-backdrop-filter: blur(20px) !important;
        border: 1px solid rgba(255, 255, 255, 0.6) !important;
        border-radius: 24px;
        box-shadow:
            0 16px 40px rgba(2, 44, 34, 0.06),
            inset 0 1px 0 rgba(255, 255, 255, 0.8) !important;
        transition: all 0.3s ease;
    }
    
    .glass-panel p {
        color: var(--text-muted) !important;
    }
    
    .glass-panel h2, .glass-panel h3, .glass-panel h5, .glass-panel h6 {
        color: var(--deep-forest) !important;
        font-weight: 700;
    }

    /* Hero Section styling details */
    .hero-container {
        padding: 9rem 0 6rem 0;
        background: transparent;
        position: relative;
        overflow: hidden;
        border-bottom-left-radius: 32px;
        border-bottom-right-radius: 32px;
    }

    .hero-container .container {
        position: relative;
        z-index: 1;
    }
    
    /* Frosted badge styling */
    .hero-badge {
        font-family: var(--font-mono);
        letter-spacing: 2px;
        background: linear-gradient(135deg, rgba(5, 150, 105, 0.14), rgba(110, 231, 183, 0.2));
        color: var(--forest-brand);
        border: 1px solid rgba(5, 150, 105, 0.2);
        padding: 8px 16px;
        border-radius: 50px;
        font-size: 0.75rem;
        display: inline-block;
        font-weight: 700;
        backdrop-filter: blur(10px);
    }
    
    .hero-title {
        font-weight: 800;
        font-size: 3.5rem;
        line-height: 1.15;
        letter-spacing: -2px;
        color: var(--deep-forest);
        text-shadow: 0 2px 12px rgba(2, 44, 34, 0.08);
    }

    .hero-container .lead {
        color: var(--text-muted) !important;
    }

    /* Custom translucent glass button styling */
    .glass-btn {
        border: 1px solid rgba(5, 150, 105, 0.2);
        background: linear-gradient(135deg, rgba(255,255,255,0.78), rgba(236,253,245,0.7));
        color: var(--forest-brand);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        box-shadow: 0 12px 24px rgba(2, 44, 34, 0.10);
        border-radius: 999px;
        transition: transform 0.2s ease, box-shadow 0.2s ease, color 0.2s ease;
    }

    .glass-btn:hover {
        background: linear-gradient(135deg, rgba(255,255,255,0.92), rgba(220,252,231,0.9));
        color: var(--deep-forest);
        transform: translateY(-1px);
        box-shadow: 0 16px 30px rgba(2, 44, 34, 0.14);
    }

    .footer-contact-col {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
    }

    @media (max-width: 767.98px) {
        .footer-contact-col {
            align-items: center;
            text-align: center;
        }
    }

    /* Premium buttons for interactions */
    .btn-forest {
        background: rgba(52, 211, 153, 0.25) !important;
        backdrop-filter: blur(12px) saturate(180%) !important;
        -webkit-backdrop-filter: blur(12px) saturate(180%) !important;
        border: 2px solid rgba(52, 211, 153, 0.6) !important;
        color: #064e3b !important;
        font-weight: 700 !important;
        border-radius: 12px !important;
        padding: 10px 24px !important;
        transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1) !important;
        box-shadow: 0 8px 32px 0 rgba(16, 185, 129, 0.15) !important;
        text-shadow: 0 1px 1px rgba(255, 255, 255, 0.4) !important;
    }
    
    .btn-forest:hover {
        background: rgba(52, 211, 153, 0.45) !important;
        border-color: rgba(52, 211, 153, 0.9) !important;
        color: #022c22 !important;
        box-shadow: 0 12px 40px 0 rgba(16, 185, 129, 0.25) !important;
        transform: translateY(-2px) !important;
    }

    .btn-forest:active {
        background: rgba(52, 211, 153, 0.6) !important;
        transform: scale(0.96) translateY(-1px) !important;
        box-shadow: 0 4px 16px 0 rgba(16, 185, 129, 0.2) !important;
    }

    /* Style changes for buttons nested in the dark header */
    .scroll-navbar .btn-forest {
        color: #ffffff !important;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.4) !important;
        border-color: rgba(52, 211, 153, 0.8) !important;
        background: rgba(52, 211, 153, 0.3) !important;
    }
    
    .scroll-navbar .btn-forest:hover {
        color: #ffffff !important;
        background: rgba(52, 211, 153, 0.5) !important;
        border-color: #34d399 !important;
    }

    /* Category selection filter pills */
    .category-pill {
        border: 1px solid rgba(16, 185, 129, 0.3) !important;
        background: rgba(255, 255, 255, 0.35) !important;
        color: var(--text-dark) !important;
        padding: 0.55rem 1.2rem;
        border-radius: 50px;
        font-size: 0.85rem;
        cursor: pointer;
        transition: all 0.2s ease;
        font-family: var(--font-mono);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        box-shadow: 0 8px 18px rgba(2, 44, 34, 0.06);
    }
    
    .category-pill.active, .category-pill:hover {
        background: rgba(16, 185, 129, 0.75) !important;
        color: #ffffff !important;
        border-color: rgba(16, 185, 129, 0.9) !important;
        font-weight: bold;
        box-shadow: 0 10px 24px rgba(16, 185, 129, 0.3) !important;
    }

    /* Product catalog grid card elements */
    .menu-card {
        background: linear-gradient(135deg, rgba(255, 255, 255, 0.45), rgba(229, 248, 238, 0.35)) !important;
        border: 1px solid rgba(255, 255, 255, 0.6) !important;
        border-radius: 24px;
        transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        box-shadow: 
            0 8px 32px 0 rgba(15, 118, 110, 0.08),
            inset 0 1px 0 0 rgba(255, 255, 255, 0.8) !important;
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
    }
    
    .menu-card:hover {
        transform: translateY(-8px);
        border-color: rgba(52, 211, 153, 0.8) !important;
        box-shadow: 
            0 16px 38px rgba(5, 150, 105, 0.16),
            inset 0 1px 0 0 rgba(255, 255, 255, 0.9) !important;
    }
    
    .product-img-holder {
        height: 180px;
        background: rgba(255, 255, 255, 0.3) !important;
        border-radius: 18px;
        border: 1px solid rgba(255, 255, 255, 0.5) !important;
        box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
    }

    /* Circular icons styling inside feature widgets */
    .feature-icon-wrapper {
        width: 60px;
        height: 60px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, rgba(5, 150, 105, 0.14), rgba(110, 231, 183, 0.16));
        border: 1px solid var(--glass-border);
        border-radius: 18px;
        font-size: 1.4rem;
        margin: 0 auto 1.25rem auto;
        color: var(--forest-brand);
        box-shadow: inset 0 1px 0 rgba(255,255,255,0.72);
    }

    /* Minimal round buttons used in cart item operations */
    .icon-glass-btn {
        width: 2.1rem;
        height: 2.1rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        border: 1px solid rgba(5, 150, 105, 0.16);
        background: linear-gradient(135deg, rgba(255,255,255,0.86), rgba(236,253,245,0.82));
        color: var(--forest-brand);
        box-shadow: 0 8px 20px rgba(2, 44, 34, 0.08);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .icon-glass-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 10px 24px rgba(2, 44, 34, 0.12);
    }

    /* Sticky container rules to keep the Cart sidebar static while reading the list */
    .sticky-cart-card {
        position: -webkit-sticky;
        position: sticky;
        top: 7rem;
        z-index: 10;
        border: 1px solid var(--glass-border);
    }

    /* Dark theme rules for high-contrast footer */
    .footer-dark {
        background: var(--deep-forest);
        border-top: 3px solid var(--mint-highlight);
        color: #e2e8f0 !important;
    }
    
    .footer-dark p, .footer-dark span {
        color: #cbd5e1 !important;
    }
    
    .footer-dark h6 {
        color: #ffffff !important;
        font-weight: 700;
        letter-spacing: 1px;
    }
</style>

<!-- 1. SCROLL-ACTIVATED FULL WIDTH HEADER (DARK GREEN GLASS) -->
<nav id="landing-scroll-nav" class="navbar navbar-expand-lg navbar-dark scroll-navbar py-3">
    <div class="container px-4">
        <!-- Logo and Brand name links -->
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold font-monospace" href="#">
            <img src="images/LOGO.png" alt="Tharu Logo" class="brand-logo-img rounded">
            <span>THARU & PRODUCTS</span>
        </a>
        
        <!-- Mobile hamburger menu collapse button -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navContent">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <!-- Navbar link listings -->
        <div class="collapse navbar-collapse justify-content-end" id="navContent">
            <ul class="navbar-nav mb-2 mb-lg-0 gap-3 align-items-center me-3">
                <li class="nav-item"><a class="nav-link" href="#menu-catalog">Menu</a></li>
                <li class="nav-item"><a class="nav-link" href="#about-section">About Us</a></li>
                <li class="nav-item"><a class="nav-link" href="#contact-section">Contact Us</a></li>
            </ul>
            <div class="d-flex align-items-center">
            <!-- Link to sign in interface -->
            <div class="d-flex justify-content-center mt-3 mt-lg-0">
                <a href="auth/login.php" class="btn btn-sm btn-forest px-3">Sign In</a>
            </div>
        </div>
    </div>
</nav>

<!-- 2. HERO BANNER -->
<div class="hero-container">
    <div class="container px-4">
        <div class="row align-items-center g-5">
            <!-- Left Info Block -->
            <div class="col-12 col-md-6">
                <span class="hero-badge mb-3">WELCOME TO THARU & PRODUCTS</span>
                <h1 class="hero-title mt-2 mb-3">Premium Quality Animal Feed</h1>
                <p class="lead mb-4" style="color: var(--text-muted);">Optimizing agricultural yields with modern automated silo processing and verifiable feed distribution chains.</p>
                <a href="#menu-catalog" class="btn btn-forest px-4 py-3 shadow">Order Online Now</a>
            </div>
            
            <!-- Right Promotional Image Wrap -->
            <div class="col-12 col-md-6 text-center">
                <div class="hero-image-wrap position-relative">
                    <img src="https://images.unsplash.com/photo-1516467508483-a7212febe31a?auto=format&fit=crop&w=600&q=80" alt="Livestock Feed" class="img-fluid rounded-4 shadow-md border" style="max-height: 380px; object-fit: cover; width: 100%; border-color: var(--glass-border) !important;">
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MAIN LAYOUT BODY -->
<div class="white-segment">
    
    <!-- WHY FARMERS TRUST WIDGETS -->
    <div class="container text-center mb-5">
        <h2 class="fw-bold mb-5" style="letter-spacing: -1px; color: var(--deep-forest);">Why Farmers Trust Our Supply</h2>
        <div class="row g-4 mt-2">
            <!-- Quality widget -->
            <div class="col-12 col-md-4">
                <div class="glass-panel p-4 h-100">
                    <div class="feature-icon-wrapper"><i class="bi bi-flower1"></i></div>
                    <h5 class="fw-bold">Always Fresh Ingredients</h5>
                    <p class="mb-0">Strict raw silo evaluations ensure batch quality remains consistent across operations.</p>
                </div>
            </div>
            <!-- Cart simplicity widget -->
            <div class="col-12 col-md-4">
                <div class="glass-panel p-4 h-100">
                    <div class="feature-icon-wrapper"><i class="bi bi-cart3"></i></div>
                    <h5 class="fw-bold">Easy Online Orders</h5>
                    <p class="mb-0">Registered corporate clients secure output batch allocations with real-time checkout pipelines.</p>
                </div>
            </div>
            <!-- Catalog versatility widget -->
            <div class="col-12 col-md-4">
                <div class="glass-panel p-4 h-100">
                    <div class="feature-icon-wrapper"><i class="bi bi-list-check"></i></div>
                    <h5 class="fw-bold">Plenty to Choose From</h5>
                    <p class="mb-0">Our processing units handle mixed grain matrix ratios specialized for diverse livestock portfolios.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. ABOUT US SECTION -->
    <section class="py-5 mt-5" id="about-section">
        <div class="container text-center">
            <div class="row justify-content-center">
                <div class="col-lg-8 col-md-10 glass-panel p-5">
                    <h2 class="fw-bold mb-3" style="letter-spacing: -0.5px;">About Us</h2>
                    <div style="width: 60px; height: 3px; background-color: var(--forest-brand); margin: 0 auto 1.5rem auto; border-radius: 2px;"></div>
                    <p class="lead mb-0" style="font-size: 1.1rem; line-height: 1.8; color: var(--text-muted) !important;">
                        Welcome to <strong class="text-dark">Tharu Products</strong>. <br>
                        We are committed to delivering high-quality, nutritious animal feed that supports healthier livestock and more productive farms. Based in <strong class="text-dark">Maradagahamula, Sri Lanka</strong>, we specialize in producing premium feed and vitamin products for <b>poultry, cattle,</b> and <b>pigs</b>,<br> serving both large-scale agricultural companies and independent farms with reliability and excellence.<br>
                        With years of industry experience, we source quality raw materials from trusted suppliers and transform them into carefully formulated animal feed that meets the nutritional needs of livestock.
                        <br>Our mission is simple: <strong class="text-dark">to empower the agricultural industry by providing dependable, high-quality animal feed solutions that promote healthier animals and stronger farming communities.</strong>.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- 4. PRODUCT CATALOG GRID & SIDEBAR CART -->
    <div class="container py-5" id="menu-catalog">
        <div class="row g-4">
            
            <!-- Left Sidebar: Cart panel -->
            <div class="col-12 col-lg-4">
                <div class="glass-panel p-4 sticky-cart-card">
                    <h5 class="fw-bold border-bottom pb-3 mb-3" style="font-family: var(--font-mono);"><i class="bi bi-cart3 me-2"></i>Your Cart</h5>
                    
                    <!-- Display fallback message if cart is empty -->
                    <?php if (empty($_SESSION['cart'])): ?>
                        <p class="mb-0 py-2">Your Cart is empty. Select product variants to populate.</p>
                    <?php else: ?>
                        <!-- Loop over cart items and draw list entries -->
                        <div class="mb-3">
                            <?php 
                            $cartGrandTotal = 0;
                            foreach($_SESSION['cart'] as $cartItemID => $quantity): 
                                // Fetch corresponding product details from database for name and pricing
                                $itemStmt = $conn->prepare("SELECT name, unitprice FROM product_tbl WHERE productID = ?");
                                $itemStmt->bind_param("i", $cartItemID);
                                $itemStmt->execute();
                                $itemRes = $itemStmt->get_result()->fetch_assoc();
                                
                                $itemNameResolved = $itemRes['name'] ?? "Product #$cartItemID";
                                $itemPrice = $itemRes['unitprice'] ?? 0.00;
                                $subtotal = $itemPrice * $quantity;
                                $cartGrandTotal += $subtotal;
                            ?>
                                <div class="d-flex justify-content-between align-items-center py-2" style="border-bottom: 1px solid rgba(5, 150, 105, 0.15);">
                                    <div style="max-width: 55%;">
                                        <span class="text-dark d-block fw-semibold" style="font-size: 0.9rem;"><?= htmlspecialchars($itemNameResolved) ?></span>
                                        <span class="small text-success" style="font-family: var(--font-mono);">LKR <?= number_format($itemPrice, 2) ?> × <?= $quantity ?></span>
                                    </div>
                                    <!-- Decrement and Delete trigger buttons -->
                                    <div class="d-flex align-items-center gap-1">
                                        <button onclick="decreaseQty(<?= $cartItemID ?>)" class="icon-glass-btn btn btn-sm p-0" title="Decrease by 1"><i class="bi bi-dash"></i></button>
                                        <button onclick="removeItem(<?= $cartItemID ?>)" class="icon-glass-btn btn btn-sm p-0" title="Remove completely"><i class="bi bi-trash"></i></button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Grand total summary -->
                        <div class="p-3 rounded mb-3 bg-light border border-success">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="small text-muted font-monospace text-uppercase">Subtotal:</span>
                                <span class="fw-bold fs-5 text-success" style="font-family: var(--font-mono);">LKR <?= number_format($cartGrandTotal, 2) ?></span>
                            </div>
                        </div>

                        <!-- Checkout route trigger button: checks session auth state to redirect accordingly -->
                        <?php if (!isset($_SESSION['user_id'])): ?>
                            <a href="auth/login.php?action=signup" class="btn btn-forest w-100 text-uppercase py-2.5 font-monospace">Proceed to Checkout</a>
                        <?php else: ?>
                            <a href="auth/checkout_guard.php" class="btn btn-forest w-100 text-uppercase py-2.5 font-monospace">Proceed to Checkout</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Main Pane: Product listings -->
            <div class="col-12 col-lg-8">
                
                <!-- Category filtering bar & text search bar -->
                <div class="glass-panel p-4 mb-4">
                    <div class="row g-3 align-items-center">
                        <div class="col-12 col-md-6">
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <span class="small fw-bold text-dark me-2" style="font-family: var(--font-mono); letter-spacing: 1px;">FILTER:</span>
                                <button class="category-pill active" onclick="filterCategory('all', event)">All Products</button>
                                <button class="category-pill" onclick="filterCategory('chicken', event)">Poultry Feed</button>
                                <button class="category-pill" onclick="filterCategory('pig', event)">Pig Feed</button>
                                <button class="category-pill" onclick="filterCategory('cow', event)">Cattle Mash</button>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-success"></i></span>
                                <input type="text" id="productSearch" class="form-control border-start-0" placeholder="Search products by name or description..." oninput="filterProducts()">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Products Catalog Grid View -->
                <div class="row g-3" id="products-grid">
                    <?php if ($productsResult && $productsResult->num_rows > 0): ?>
                        <?php while ($prod = $productsResult->fetch_assoc()): ?>
                            <!-- Individual product wrapper node containing categorization data attributes -->
                            <div class="col-12 col-sm-6 product-card-wrapper" data-category="<?= strtolower(htmlspecialchars($prod['type'] ?? 'all')) ?>">
                                <div class="menu-card p-3 d-flex flex-column justify-content-between h-100">
                                    <div>
                                        <?php
                                        // Infer product item image depending on the product name and database category type
                                        $imgSrc = 'images/LOGO.png';
                                        $lowercaseName = strtolower($prod['name']);
                                        $lowercaseType = strtolower($prod['type'] ?? '');
                                        $hasImage = false;
                                        if (strpos($lowercaseName, 'chicken') !== false || strpos($lowercaseType, 'chicken') !== false || strpos($lowercaseType, 'poultry') !== false) {
                                            $imgSrc = 'images/chickenfeed.jpg';
                                            $hasImage = true;
                                        } elseif (strpos($lowercaseName, 'pig') !== false || strpos($lowercaseType, 'pig') !== false) {
                                            $imgSrc = 'images/pigfeed.jpg';
                                            $hasImage = true;
                                        } elseif (strpos($lowercaseName, 'cow') !== false || strpos($lowercaseName, 'cattle') !== false || strpos($lowercaseType, 'cow') !== false || strpos($lowercaseType, 'cattle') !== false || strpos($lowercaseName, 'mash') !== false) {
                                            $imgSrc = 'images/cowfeed.png';
                                            $hasImage = true;
                                        }
                                        ?>
                                        <!-- Image Display Box -->
                                        <div class="product-img-holder rounded mb-3 d-flex align-items-center justify-content-center overflow-hidden">
                                            <?php if ($hasImage): ?>
                                                <img src="<?= $imgSrc ?>" alt="<?= htmlspecialchars($prod['name']) ?>" style="width: 100%; height: 100%; object-fit: cover; transition: transform 0.5s ease;" class="product-card-img">
                                            <?php else: ?>
                                                <i class="bi bi-flower2 display-3" style="filter: drop-shadow(0 4px 10px rgba(5, 150, 105, 0.15)); color: var(--forest-brand);"></i>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Product title details -->
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <h6 class="fw-bold text-dark mb-0"><?= htmlspecialchars($prod['name']) ?></h6>
                                            <span class="badge bg-transparent text-success border border-success font-monospace small">#PROD-<?= $prod['productID'] ?></span>
                                        </div>
                                        <p class="card-text mb-3 text-muted" style="font-size: 0.8rem; line-height: 1.5;">
                                            <?= htmlspecialchars($prod['description'] ?? 'Wholesome formulation optimized for high performance and daily feed schedules.') ?>
                                        </p>
                                    </div>
                                    
                                    <!-- Action row containing LKR unitprice and Add Cart trigger -->
                                    <div class="d-flex justify-content-between align-items-center mt-3 pt-3" style="border-top: 1px solid rgba(5, 150, 105, 0.15);">
                                        <span class="fw-bold fs-5 text-dark" style="font-family: var(--font-mono);">LKR <?= number_format($prod['unitprice'], 2) ?></span>
                                        <div class="d-flex gap-1">
                                            <button onclick="addUnit(<?= $prod['productID'] ?>)" class="btn btn-sm btn-forest"><i class="bi bi-plus-lg me-1"></i>Add Item</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <!-- Fallback message shown when product table returns 0 rows -->
                        <div class="col-12 text-center py-5 text-muted glass-panel">
                            No products are currently available.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- 5. HIGH VISIBILITY FOOTER & CONTACT SECTION -->
<footer class="footer-dark py-5 mt-auto" id="contact-section">
    <div class="container px-4">
        <div class="row g-4 align-items-center">
            
            <!-- Footer Branding & Copyright -->
            <div class="col-12 col-md-4 text-center text-md-start">
                <div class="d-flex align-items-center justify-content-center justify-content-md-start gap-2 mb-3">
                    <img src="images/LOGO.png" alt="Tharu Logo" class="brand-logo-img rounded">
                    <span class="text-white fw-bold font-monospace" style="letter-spacing: 1px;">THARU PRODUCTS</span>
                </div>
                <p class="small mb-0">&copy; <?= date('Y'); ?> Tharu & Products. All Rights Reserved. Engineered for excellence.</p>
            </div>

            <!-- Contact Details Panel -->
            <div class="col-12 col-md-4 footer-contact-col">
                <h6 class="text-white fw-bold font-monospace mb-3">CONTACT US</h6>
                <p class="small mb-1"><i class="bi bi-geo-alt-fill me-2"></i>No.235, Negambo Road,Marandagahamula</p>
                <p class="small mb-1"><i class="bi bi-envelope-fill me-2"></i>tharufeedproducts@gmail.com</p>
                <p class="small mb-0"><i class="bi bi-telephone-fill me-2"></i>+94 11 2345 678</p>
            </div>

            <!-- Portal links shortcuts -->
            <div class="col-12 col-md-4 d-flex justify-content-center">
                <div class="d-flex flex-wrap justify-content-center gap-2">
                    <a href="#" id="backToTopBtn" onclick="scrollToTop(); return false;" class="btn btn-sm px-3 mb-2 glass-btn">Back to Top</a>
                    <a href="auth/login.php" class="btn btn-sm px-3 mb-2 glass-btn">Management Portal Login</a>
                </div>
            </div>
            
        </div>
    </div>
</footer>

<script>
/**
 * Header Scroll Scroll Event Listener
 * Detects page scroll coordinates and slides down the sticky header navigation bar (.scroll-navbar)
 * when scrolling down past 250 pixels.
 */
window.addEventListener('scroll', function() {
    const scrollNav = document.getElementById('landing-scroll-nav');
    if (scrollNav) {
        if (window.scrollY > 250) {
            scrollNav.classList.add('show-nav'); // Slide into view
        } else {
            scrollNav.classList.remove('show-nav'); // Hide/Slide upwards out of screen
        }
    }
});

/**
 * Scroll to top handler
 * Smoothly scrolls user viewport back to page top coordinate 0
 */
function scrollToTop() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Stores the selected filtering category context (default = 'all')
let activeCategory = 'all';

/**
 * Category filter activation routine
 * Updates category-pill button active classes and invokes catalog display filter
 * 
 * @param {string} category - Category tag name (chicken, pig, cow, all)
 * @param {Event} event - Javascript mouse click event context
 */
function filterCategory(category, event) {
    const pills = document.querySelectorAll('.category-pill');
    pills.forEach(p => p.classList.remove('active'));
    event.currentTarget.classList.add('active');
    activeCategory = category;
    filterProducts();
}

/**
 * Filters the product items in the grid container
 * Evaluates both the active category selection and the search text query parameters
 * to toggle item elements display.
 */
function filterProducts() {
    const searchQuery = document.getElementById('productSearch').value.toLowerCase();
    const cards = document.querySelectorAll('.product-card-wrapper');
    
    cards.forEach(card => {
        const productCategory = card.getAttribute('data-category');
        const productName = card.querySelector('h6').innerText.toLowerCase();
        const productDesc = card.querySelector('.card-text').innerText.toLowerCase();
        
        const matchesCategory = (activeCategory === 'all' || productCategory.includes(activeCategory));
        const matchesSearch = (productName.includes(searchQuery) || productDesc.includes(searchQuery));
        
        // Toggle item block display
        if (matchesCategory && matchesSearch) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
}

/**
 * AJAX Add Item to Session Cart
 * Sends a POST request payload containing product identification details to index.php.
 * Reloads page on successful responses.
 * 
 * @param {number} productId - ID index of the target item
 * @param {number} qty - Added quantity units count (default = 1)
 */
function addUnit(productId, qty = 1) {
    const formData = new FormData();
    formData.append('action', 'add_to_cart');
    formData.append('product_id', productId);
    formData.append('qty', qty);

    fetch('index.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if(data.status === 'success') {
            window.location.reload();
        }
    });
}

/**
 * Modal Info Hydration Utility
 * Extracts product structure parameters to load the details dialog view.
 * 
 * @param {Object} product - Product properties map (ID, name, type, unitprice, description)
 */
function viewDetails(product) {
    document.getElementById('modalProductID').value = product.productID;
    document.getElementById('modalProductName').innerText = product.name;
    document.getElementById('modalProductType').innerText = product.type || 'General Feed';
    document.getElementById('modalProductDesc').innerText = product.description || 'Wholesome formulation optimized for high performance and daily feed schedules.';
    document.getElementById('modalProductPrice').innerText = 'LKR ' + parseFloat(product.unitprice).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    
    // Resolve modal visual placeholder images
    let imgSrc = 'images/LOGO.png';
    const lowercaseName = product.name.toLowerCase();
    const lowercaseType = (product.type || '').toLowerCase();
    if (lowercaseName.includes('chicken') || lowercaseType.includes('chicken') || lowercaseType.includes('poultry')) {
        imgSrc = 'images/chickenfeed.jpg';
    } else if (lowercaseName.includes('pig') || lowercaseType.includes('pig')) {
        imgSrc = 'images/pigfeed.jpg';
    } else if (lowercaseName.includes('cow') || lowercaseName.includes('cattle') || lowercaseType.includes('cow') || lowercaseType.includes('cattle') || lowercaseName.includes('mash')) {
        imgSrc = 'images/cowfeed.png';
    }
    document.getElementById('modalProductImg').src = imgSrc;
    document.getElementById('modalProductQty').value = 1;
    
    // Open Bootstrap 5 Modal
    const myModal = new bootstrap.Modal(document.getElementById('productDetailsModal'));
    myModal.show();
}

/**
 * Handles adding item quantities from the details modal box
 */
function addProductFromModal() {
    const id = document.getElementById('modalProductID').value;
    const qty = parseInt(document.getElementById('modalProductQty').value) || 1;
    addUnit(id, qty);
}

/**
 * AJAX Remove Item entirely from Session Cart
 * Sends a POST request payload containing product identification details to index.php.
 * Reloads page on successful responses.
 * 
 * @param {number} productId - ID index of the target item
 */
function removeItem(productId) {
    const formData = new FormData();
    formData.append('action', 'remove_from_cart');
    formData.append('product_id', productId);

    fetch('index.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if(data.status === 'success') {
            window.location.reload();
        }
    });
}

/**
 * AJAX Decrease Item Quantity from Session Cart
 * Sends a POST request payload containing product identification details to index.php.
 * Reloads page on successful responses.
 * 
 * @param {number} productId - ID index of the target item
 */
function decreaseQty(productId) {
    const formData = new FormData();
    formData.append('action', 'decrease_quantity');
    formData.append('product_id', productId);

    fetch('index.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if(data.status === 'success') {
            window.location.reload();
        }
    });
}

/**
 * --- Interactive Particle Background Animation inspired by Google Antigravity ---
 * Spawns ambient floating nodes, trails mouse movements, and drives gravitational repulsion forces
 * on coordinate vectors to construct a smooth particle backdrop layout.
 */
class AntigravityEffect {
    constructor() {
        // Create full screen background canvas node
        this.canvas = document.createElement('canvas');
        this.ctx = this.canvas.getContext('2d');
        this.canvas.style.position = 'fixed';
        this.canvas.style.top = '0';
        this.canvas.style.left = '0';
        this.canvas.style.width = '100vw';
        this.canvas.style.height = '100vh';
        this.canvas.style.pointerEvents = 'none'; // Ensure canvas ignores clicks so user can click on standard buttons
        this.canvas.style.zIndex = '-1';          // Sits behind layout content
        document.body.appendChild(this.canvas);
        
        this.particles = [];
        this.mouse = { x: null, y: null, active: false, radius: 150 };
        
        // Multi-color palette for particles
        this.colors = [
            'rgba(16, 185, 129, 0.45)',  // Emerald Green
            'rgba(52, 211, 153, 0.45)',  // Mint Green
            'rgba(59, 130, 246, 0.45)',  // Electric Blue
            'rgba(139, 92, 246, 0.4)',   // Bright Purple
            'rgba(245, 158, 11, 0.4)',   // Amber Gold
            'rgba(239, 68, 68, 0.4)'     // Rose Red
        ];
        
        this.resize();
        
        // Listen to window size and mouse movements
        window.addEventListener('resize', () => this.resize());
        window.addEventListener('mousemove', (e) => this.handleMouseMove(e));
        window.addEventListener('mouseout', () => this.handleMouseOut());
        
        // Create initial ambient background particles
        this.initAmbientParticles(80);
        
        // Start animation processing loop
        this.animate();
    }
    
    /**
     * Updates canvas sizing on window resize events
     */
    resize() {
        this.canvas.width = window.innerWidth;
        this.canvas.height = window.innerHeight;
    }
    
    /**
     * Mouse move handler tracking coordinates and spawning active trails
     */
    handleMouseMove(e) {
        this.mouse.x = e.clientX;
        this.mouse.y = e.clientY;
        this.mouse.active = true;
        
        // Spawn active trail particles based on mouse movement coordinates
        if (Math.random() < 0.5) {
            this.spawnParticle(e.clientX, e.clientY, true);
        }
    }
    
    /**
     * Disables repulsion forces when the mouse leaves viewport bounds
     */
    handleMouseOut() {
        this.mouse.active = false;
        this.mouse.x = null;
        this.mouse.y = null;
    }
    
    /**
     * Instantiates a single particle profile map and pushes it to active list
     * 
     * @param {number} x - Horizontal start coordinate
     * @param {number} y - Vertical start coordinate
     * @param {boolean} isTrail - True if spawned dynamically from mouse movement
     */
    spawnParticle(x, y, isTrail = false) {
        const angle = Math.random() * Math.PI * 2;
        const speed = isTrail ? Math.random() * 2.5 + 0.5 : Math.random() * 0.6 + 0.2;
        const color = this.colors[Math.floor(Math.random() * this.colors.length)];
        
        this.particles.push({
            x: x,
            y: y,
            vx: Math.cos(angle) * speed,
            vy: Math.sin(angle) * speed - (isTrail ? 0.3 : 0.1), // upward drift velocity bias
            size: Math.random() * (isTrail ? 3.5 : 2.5) + 1.2,
            color: color,
            alpha: 1,
            decay: isTrail ? 0.015 : 0.006, // Decay transparency over time
            isTrail: isTrail
        });
    }
    
    /**
     * Distributes a set of particles uniformly inside the screen boundaries
     */
    initAmbientParticles(count) {
        for (let i = 0; i < count; i++) {
            const x = Math.random() * this.canvas.width;
            const y = Math.random() * this.canvas.height;
            this.spawnParticle(x, y, false);
        }
    }
    
    /**
     * Core Animation Loop updating coordinates, computing repulsion vector matrices,
     * fading transparency alpha, and rendering path circles on 2D context.
     */
    animate() {
        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
        
        // Maintain ambient background density
        if (this.particles.filter(p => !p.isTrail).length < 60) {
            this.spawnParticle(Math.random() * this.canvas.width, this.canvas.height + 10, false);
        }
        
        // Iterate backwards through active particles list to safely splice deleted ones
        for (let i = this.particles.length - 1; i >= 0; i--) {
            const p = this.particles[i];
            
            // Antigravity cursor interaction: apply outward repulsion vector
            if (this.mouse.active && this.mouse.x !== null) {
                const dx = p.x - this.mouse.x;
                const dy = p.y - this.mouse.y;
                const dist = Math.sqrt(dx * dx + dy * dy);
                if (dist < this.mouse.radius) {
                    const force = (this.mouse.radius - dist) / this.mouse.radius;
                    p.vx += (dx / dist) * force * 0.5;
                    p.vy += (dy / dist) * force * 0.5;
                }
            }
            
            // Update positioning vectors
            p.x += p.vx;
            p.y += p.vy;
            
            // Apply slight air friction dampening velocity
            p.vx *= 0.97;
            p.vy *= 0.97;
            
            // Progressively fade
            p.alpha -= p.decay;
            
            // Render on 2D context
            this.ctx.save();
            this.ctx.globalAlpha = p.alpha;
            this.ctx.fillStyle = p.color;
            this.ctx.beginPath();
            this.ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
            this.ctx.fill();
            this.ctx.restore();
            
            // Splice out dead particles
            if (p.alpha <= 0 || p.x < -10 || p.x > this.canvas.width + 10 || p.y < -10 || p.y > this.canvas.height + 10) {
                this.particles.splice(i, 1);
            }
        }
        
        // Call browser frame update loop
        requestAnimationFrame(() => this.animate());
    }
}

// Instantiate Antigravity canvas effect on load completion
window.addEventListener('DOMContentLoaded', () => {
    new AntigravityEffect();
});
</script>

<!-- Product Details Modal Component template -->
<div class="modal fade" id="productDetailsModal" tabindex="-1" aria-labelledby="productDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel border border-success rounded-4" style="background: rgba(255, 255, 255, 0.98) !important; backdrop-filter: blur(20px) !important;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-success" id="productDetailsModalLabel">Product Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Modal Body: Image Holder -->
                <div class="text-center mb-3">
                    <img id="modalProductImg" src="images/LOGO.png" alt="Product Image" class="img-fluid rounded-3 border" style="max-height: 220px; object-fit: cover; width: 100%;">
                </div>
                
                <!-- Modal Body: Details text and type badge -->
                <h4 id="modalProductName" class="fw-bold text-dark mb-1">Product Name</h4>
                <span id="modalProductType" class="badge bg-success-subtle text-success border border-success mb-3">Category</span>
                <p id="modalProductDesc" class="text-muted mb-4">Detailed description goes here...</p>
                
                <!-- Price info and purchase options with quantity fields -->
                <div class="d-flex justify-content-between align-items-center pt-3 border-top border-success-subtle">
                    <div>
                        <span class="small text-muted d-block">Unit Price</span>
                        <h4 id="modalProductPrice" class="fw-bold text-dark font-monospace mb-0">LKR 0.00</h4>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <label for="modalProductQty" class="small fw-bold text-dark mb-0">Qty:</label>
                        <input type="number" id="modalProductQty" class="form-control text-center" value="1" min="1" style="width: 70px; border-radius: 8px;">
                        <input type="hidden" id="modalProductID">
                        <button onclick="addProductFromModal()" class="btn btn-forest"><i class="bi bi-cart-plus me-1"></i> Add</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>