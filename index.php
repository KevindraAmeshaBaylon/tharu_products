<?php
// index.php
session_start();
require_once __DIR__ . '/model/config/database.php';

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$conn = getDBConnection();

$productQuery = "SELECT productID, name, type, unitprice, description FROM product_tbl";
$productsResult = $conn->query($productQuery);

// --- Handle AJAX Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $id = intval($_POST['product_id']);

    if ($_POST['action'] === 'add_to_cart') {
        $_SESSION['cart'][$id] = ($_SESSION['cart'][$id] ?? 0) + 1;
        echo json_encode(['status' => 'success', 'cart_count' => array_sum($_SESSION['cart'])]);
        exit;
    }

    if ($_POST['action'] === 'decrease_quantity') {
        if (isset($_SESSION['cart'][$id])) {
            $_SESSION['cart'][$id]--;
            if ($_SESSION['cart'][$id] <= 0) {
                unset($_SESSION['cart'][$id]);
            }
        }
        echo json_encode(['status' => 'success', 'cart_count' => array_sum($_SESSION['cart'])]);
        exit;
    }

    if ($_POST['action'] === 'remove_from_cart') {
        if (isset($_SESSION['cart'][$id])) {
            unset($_SESSION['cart'][$id]);
        }
        echo json_encode(['status' => 'success', 'cart_count' => array_sum($_SESSION['cart'])]);
        exit;
    }
}
?>

<?php require_once __DIR__ . '/includes/header.php'; ?>

<!-- Google Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

<style>
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

    html {
        scroll-behavior: smooth;
    }

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

    /* Custom Logo Sizing */
    .brand-logo-img {
        height: 42px;
        width: auto;
        object-fit: contain;
    }

    /* 1. SCROLL-ONLY DYNAMIC HEADER (DARK FOREST GREEN GLASS) */
    .scroll-navbar {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        z-index: 1050;
        transform: translateY(-100%);
        opacity: 0;
        transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.3s ease;
        background: rgba(2, 44, 34, 0.92) !important; /* Deep forest green */
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border-bottom: 2px solid var(--mint-highlight);
        box-shadow: 0 10px 30px rgba(2, 44, 34, 0.15);
    }
    
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

    /* 2. FROSTED GLASS PANELS ON LIGHT BG */
    .glass-panel {
        background: linear-gradient(135deg, rgba(255,255,255,0.74), rgba(240,253,244,0.62));
        backdrop-filter: blur(18px);
        -webkit-backdrop-filter: blur(18px);
        border: 1px solid var(--glass-border);
        border-radius: 24px;
        box-shadow:
            0 16px 36px rgba(2, 44, 34, 0.08),
            inset 0 1px 0 rgba(255,255,255,0.75);
        transition: all 0.3s ease;
    }
    
    .glass-panel p {
        color: var(--text-muted) !important;
    }
    
    .glass-panel h2, .glass-panel h3, .glass-panel h5, .glass-panel h6 {
        color: var(--deep-forest) !important;
        font-weight: 700;
    }

    /* HERO STYLING */
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

    /* DYNAMIC INTERACTION BUTTONS */
    .btn-forest {
        background: linear-gradient(135deg, var(--forest-brand), var(--mint-highlight));
        color: #ffffff !important;
        font-weight: 700;
        border-radius: 12px;
        padding: 10px 24px;
        border: none;
        transition: all 0.2s ease;
        box-shadow: 0 8px 24px rgba(15, 118, 110, 0.22);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }
    
    .btn-forest:hover {
        background: linear-gradient(135deg, var(--deep-forest), var(--forest-brand));
        box-shadow: 0 12px 28px rgba(5, 46, 43, 0.24);
        transform: translateY(-2px);
    }

    /* FILTER PILLS */
    .category-pill {
        border: 1px solid rgba(5, 150, 105, 0.18);
        background: linear-gradient(135deg, rgba(255,255,255,0.76), rgba(236,253,245,0.7));
        color: var(--text-muted);
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
        background: linear-gradient(135deg, rgba(6, 95, 70, 0.95), rgba(16, 185, 129, 0.9));
        color: #ffffff;
        border-color: rgba(16, 185, 129, 0.6);
        font-weight: bold;
        box-shadow: 0 10px 24px rgba(6, 95, 70, 0.22);
    }

    /* PRODUCT CATALOG CARDS */
    .menu-card {
        background: linear-gradient(135deg, rgba(255,255,255,0.78), rgba(229,248,238,0.7));
        border: 1px solid var(--glass-border);
        border-radius: 24px;
        transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        box-shadow: 0 10px 28px rgba(2, 44, 34, 0.06);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
    }
    
    .menu-card:hover {
        transform: translateY(-8px);
        border-color: var(--forest-brand);
        box-shadow: 0 16px 38px rgba(5, 150, 105, 0.16);
    }
    
    .product-img-holder {
        height: 180px;
        background: linear-gradient(135deg, rgba(240,253,244,0.94), rgba(220,252,231,0.7));
        border-radius: 18px;
        border: 1px dashed var(--glass-border);
    }

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

    .sticky-cart-card {
        position: -webkit-sticky;
        position: sticky;
        top: 7rem;
        z-index: 10;
        border: 1px solid var(--glass-border);
    }

    /* 3. HIGH CONTRAST FOOTER & CONTACT INFO */
    .footer-dark {
        background: var(--deep-forest);
        border-top: 3px solid var(--mint-highlight);
        color: #e2e8f0 !important; /* Forces bright grey readability for body text */
    }
    
    .footer-dark p, .footer-dark span {
        color: #cbd5e1 !important; /* Absolute visibility guarantee */
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
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold font-monospace" href="#">
            <img src="images/LOGO.png" alt="Tharu Logo" class="brand-logo-img rounded">
            <span>THARU & PRODUCTS</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navContent">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse justify-content-end" id="navContent">
            <ul class="navbar-nav mb-2 mb-lg-0 gap-3 align-items-center me-3">
                <li class="nav-item"><a class="nav-link" href="#menu-catalog">Menu</a></li>
                <li class="nav-item"><a class="nav-link" href="#about-section">About Us</a></li>
                <li class="nav-item"><a class="nav-link" href="#contact-section">Contact Us</a></li>
            </ul>
            <div class="d-flex align-items-center">
                <a href="auth/login.php" class="btn btn-sm btn-forest px-3">Sign In</a>
            </div>
        </div>
    </div>
</nav>

<!-- 2. HERO BANNER -->
<div class="hero-container">
    <div class="container px-4">
        <div class="row align-items-center g-5">
            <div class="col-12 col-md-6">
                
                <span class="hero-badge mb-3">SYSTEM PROTOCOL DEPLOYED</span>
                <h1 class="hero-title mt-2 mb-3">Premium Quality Animal Feed</h1>
                <p class="lead mb-4" style="color: var(--text-muted);">Optimizing agricultural yields with modern automated silo processing and verifiable feed distribution chains.</p>
                <a href="#menu-catalog" class="btn btn-forest px-4 py-3 shadow">Order Online Now</a>
            </div>
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
    <!-- WHY FARMERS TRUST -->
    <div class="container text-center mb-5">
        <h2 class="fw-bold mb-5" style="letter-spacing: -1px; color: var(--deep-forest);">Why Farmers Trust Our Supply</h2>
        <div class="row g-4 mt-2">
            <div class="col-12 col-md-4">
                <div class="glass-panel p-4 h-100">
                    <div class="feature-icon-wrapper"><i class="bi bi-flower1"></i></div>
                    <h5 class="fw-bold">Always Fresh Ingredients</h5>
                    <p class="mb-0">Strict raw silo evaluations ensure batch quality remains consistent across operations.</p>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="glass-panel p-4 h-100">
                    <div class="feature-icon-wrapper"><i class="bi bi-cart3"></i></div>
                    <h5 class="fw-bold">Easy Online Orders</h5>
                    <p class="mb-0">Registered corporate clients secure output batch allocations with real-time checkout pipelines.</p>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="glass-panel p-4 h-100">
                    <div class="feature-icon-wrapper"><i class="bi bi-list-check"></i></div>
                    <h5 class="fw-bold">Plenty to Choose From</h5>
                    <p class="mb-0">Our processing units handle mixed grain matrix ratios specialized for diverse livestock portfolios.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. ABOUT US SECTION (SMOOTH SCROLL TARGET) -->
    <section class="py-5 mt-5" id="about-section">
        <div class="container text-center">
            <div class="row justify-content-center">
                <div class="col-lg-8 col-md-10 glass-panel p-5">
                    <h2 class="fw-bold mb-3" style="letter-spacing: -0.5px;">About Us</h2>
                    <div style="width: 60px; height: 3px; background-color: var(--forest-brand); margin: 0 auto 1.5rem auto; border-radius: 2px;"></div>
                    <p class="lead mb-0" style="font-size: 1.1rem; line-height: 1.8; color: var(--text-muted) !important;">
                        Welcome to <strong class="text-dark">Tharu Products</strong>. We are dedicated to pioneering the next generation of agricultural excellence by manufacturing and distributing premium, high-nutrition animal feed.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- 4. PRODUCT CATALOG GRID -->
    <div class="container py-5" id="menu-catalog">
        <div class="row g-4">
            <!-- Left Sticky Cart -->
            <div class="col-12 col-lg-4">
                <div class="glass-panel p-4 sticky-cart-card">
                    <h5 class="fw-bold border-bottom pb-3 mb-3" style="font-family: var(--font-mono);"><i class="bi bi-cart3 me-2"></i>Your Cart</h5>
                    <?php if (empty($_SESSION['cart'])): ?>
                        <p class="mb-0 py-2">Your Cart is empty. Select product variants to populate.</p>
                    <?php else: ?>
                        <div class="mb-3">
                            <?php 
                            $cartGrandTotal = 0;
                            foreach($_SESSION['cart'] as $cartItemID => $quantity): 
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
                                    <div class="d-flex align-items-center gap-1">
                                        <button onclick="decreaseQty(<?= $cartItemID ?>)" class="icon-glass-btn btn btn-sm p-0" title="Decrease by 1"><i class="bi bi-dash"></i></button>
                                        <button onclick="removeItem(<?= $cartItemID ?>)" class="icon-glass-btn btn btn-sm p-0" title="Remove completely"><i class="bi bi-trash"></i></button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="p-3 rounded mb-3 bg-light border border-success">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="small text-muted font-monospace text-uppercase">Subtotal:</span>
                                <span class="fw-bold fs-5 text-success" style="font-family: var(--font-mono);">LKR <?= number_format($cartGrandTotal, 2) ?></span>
                            </div>
                        </div>

                        <?php if (!isset($_SESSION['user_id'])): ?>
                            <a href="auth/login.php?action=signup" class="btn btn-forest w-100 text-uppercase py-2.5 font-monospace">Proceed to Checkout</a>
                        <?php else: ?>
                            <a href="auth/checkout_guard.php" class="btn btn-forest w-100 text-uppercase py-2.5 font-monospace">Proceed to Checkout</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Catalog List -->
            <div class="col-12 col-lg-8">
                <div class="glass-panel p-4 mb-4">
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <span class="small fw-bold text-dark me-2" style="font-family: var(--font-mono); letter-spacing: 1px;">FILTER:</span>
                        <button class="category-pill active" onclick="filterCategory('all', event)">All Products</button>
                        <button class="category-pill" onclick="filterCategory('chicken', event)">Poultry Feed</button>
                        <button class="category-pill" onclick="filterCategory('pig', event)">Pig Feed</button>
                        <button class="category-pill" onclick="filterCategory('cow', event)">Cattle Mash</button>
                    </div>
                </div>

                <div class="row g-3" id="products-grid">
                    <?php if ($productsResult && $productsResult->num_rows > 0): ?>
                        <?php while ($prod = $productsResult->fetch_assoc()): ?>
                            <div class="col-12 col-sm-6 product-card-wrapper" data-category="<?= strtolower(htmlspecialchars($prod['type'] ?? 'all')) ?>">
                                <div class="menu-card p-3 d-flex flex-column justify-content-between h-100">
                                    <div>
                                        <div class="product-img-holder rounded mb-3 d-flex align-items-center justify-content-center text-muted">
                                            <i class="bi bi-flower2 display-3" style="filter: drop-shadow(0 4px 10px rgba(5, 150, 105, 0.15)); color: var(--forest-brand);"></i>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <h6 class="fw-bold text-dark mb-0"><?= htmlspecialchars($prod['name']) ?></h6>
                                            <span class="badge bg-transparent text-success border border-success font-monospace small">#PROD-<?= $prod['productID'] ?></span>
                                        </div>
                                        <p class="card-text mb-3 text-muted" style="font-size: 0.8rem; line-height: 1.5;">
                                            <?= htmlspecialchars($prod['description'] ?? 'Wholesome formulation optimized for high performance and daily feed schedules.') ?>
                                        </p>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center mt-3 pt-3" style="border-top: 1px solid rgba(5, 150, 105, 0.15);">
                                        <span class="fw-bold fs-5 text-dark" style="font-family: var(--font-mono);">LKR <?= number_format($prod['unitprice'], 2) ?></span>
                                        <button onclick="addUnit(<?= $prod['productID'] ?>)" class="btn btn-sm btn-forest"><i class="bi bi-plus-lg me-1"></i>Add Item</button>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
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
            <!-- Footer Branding & Logo -->
            <div class="col-12 col-md-4 text-center text-md-start">
                <div class="d-flex align-items-center justify-content-center justify-content-md-start gap-2 mb-3">
                    <img src="images/LOGO.png" alt="Tharu Logo" class="brand-logo-img rounded">
                    <span class="text-white fw-bold font-monospace" style="letter-spacing: 1px;">THARU PRODUCTS</span>
                </div>
                <p class="small mb-0">&copy; <?= date('Y'); ?> Tharu & Products. All Rights Reserved. Engineered for excellence.</p>
            </div>

            <!-- Contact Information Pane (Highly Visible Text) -->
            <div class="col-12 col-md-4 footer-contact-col">
                <h6 class="text-white fw-bold font-monospace mb-3">CONTACT US</h6>
                <p class="small mb-1"><i class="bi bi-geo-alt-fill me-2"></i>128/A, Feed Mill Complex, Colombo, Sri Lanka</p>
                <p class="small mb-1"><i class="bi bi-envelope-fill me-2"></i>support@tharufeedproducts.com</p>
                <p class="small mb-0"><i class="bi bi-telephone-fill me-2"></i>+94 11 2345 678</p>
            </div>

            <!-- Portal Shortcuts -->
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
// Header visibility rule: Show scroll nav only after passing 250px on scroll down
window.addEventListener('scroll', function() {
    const scrollNav = document.getElementById('landing-scroll-nav');
    if (scrollNav) {
        if (window.scrollY > 250) {
            scrollNav.classList.add('show-nav');
        } else {
            scrollNav.classList.remove('show-nav');
        }
    }
});

function scrollToTop() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function filterCategory(category, event) {
    const pills = document.querySelectorAll('.category-pill');
    pills.forEach(p => p.classList.remove('active'));
    event.currentTarget.classList.add('active');

    const cards = document.querySelectorAll('.product-card-wrapper');
    cards.forEach(card => {
        const productCategory = card.getAttribute('data-category');
        if (category === 'all') {
            card.style.display = 'block';
        } else if (productCategory.includes(category)) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
}

function addUnit(productId) {
    const formData = new FormData();
    formData.append('action', 'add_to_cart');
    formData.append('product_id', productId);

    fetch('index.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if(data.status === 'success') {
            window.location.reload();
        }
    });
}

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
</script>