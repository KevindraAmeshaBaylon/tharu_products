<?php
// index.php
session_start();
require_once __DIR__ . '/config/database.example.php';

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$conn = getDBConnection();

// Fetch live products
$productQuery = "SELECT stockID, qtyavailable, unitprice FROM InventoryStock_tbl WHERE qtyavailable > 0";
$productsResult = $conn->query($productQuery);

// Handle AJAX Add to Cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_to_cart') {
        $id = intval($_POST['product_id']);
        $_SESSION['cart'][$id] = ($_SESSION['cart'][$id] ?? 0) + 1;
        echo json_encode(['status' => 'success', 'cart_count' => array_sum($_SESSION['cart'])]);
        exit;
    }
}
?>

<?php require_once __DIR__ . '/includes/header.php'; ?>

<style>
    :root {
        --forest-green: #2e7d32;
        --dark-forest: #1b5e20;
        --soft-bg: #f8f9fa;
    }
    body {
        background-color: var(--soft-bg);
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    /* Sticky Top Scroll Nav */
    .sticky-scroll-nav {
        position: fixed !important;
        top: 0; left: 0; width: 100%; z-index: 1050;
        transform: translateY(-100%); opacity: 0;
        transition: transform 0.3s ease-in-out, opacity 0.2s linear;
    }
    .sticky-scroll-nav.show-nav {
        transform: translateY(0); opacity: 1;
    }
    /* Hero Banner Layout */
    .hero-container {
        background-color: #0b1d12;
        color: #ffffff;
        padding: 5rem 0;
        overflow: hidden;
    }
    /* Section Headers */
    .section-title {
        color: var(--forest-green);
        font-weight: 700;
        text-transform: uppercase;
        font-size: 0.85rem;
        letter-spacing: 2px;
    }
    /* Deal of the week strip */
    .deal-strip {
        background: linear-gradient(rgba(27, 94, 32, 0.9), rgba(27, 94, 32, 0.9)), url('https://images.unsplash.com/photo-1595246140625-573b715d11dc?auto=format&fit=crop&w=1200&q=80');
        background-size: cover;
        background-position: center;
        color: white;
        padding: 3rem 0;
    }
    /* Product Grid Cards */
    .menu-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .menu-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.05);
    }
    .btn-forest {
        background-color: var(--forest-green);
        color: white;
        font-weight: 600;
        border-radius: 4px;
    }
    .btn-forest:hover {
        background-color: var(--dark-forest);
        color: white;
    }
    .category-pill {
        border: 1px solid #cbd5e1;
        background: white;
        color: #64748b;
        padding: 0.35rem 1rem;
        border-radius: 20px;
        font-size: 0.85rem;
        cursor: pointer;
        transition: all 0.2s;
    }
    .category-pill.active, .category-pill:hover {
        background-color: var(--forest-green);
        color: white;
        border-color: var(--forest-green);
    }
</style>

<!-- 1. SCROLL-ACTIVATED TOP BAR -->
<nav id="landing-scroll-nav" class="navbar navbar-expand-lg navbar-dark bg-forest shadow-sm sticky-scroll-nav py-2">
    <div class="container px-4">
        <a class="navbar-brand fw-bold font-monospace" href="#">🌾 THARU & PRODUCTS</a>
        <div class="ms-auto d-flex align-items-center gap-3">
            <a href="#menu-catalog" class="btn btn-sm btn-outline-light px-3">Browse Menu</a>
            <a href="auth/login.php" class="btn btn-sm btn-light text-success fw-bold px-3">Sign In</a>
        </div>
    </div>
</nav>

<!-- 2. IMAGE HERO BANNER (Left Content, Right Image) -->
<div class="hero-container">
    <div class="container px-4">
        <div class="row align-items-center g-5">
            <div class="col-12 col-md-6">
                <span class="text-success fw-bold tracking-wider font-monospace text-uppercase small">Welcome to Tharu Products</span>
                <h1 class="display-4 fw-bold mt-2 mb-3">Premium Quality Animal Feed</h1>
                <p class="opacity-75 lead mb-4">Optimizing agricultural yields with modern automated silo processing and verifiable feed distribution chains.</p>
                <a href="#menu-catalog" class="btn btn-forest px-4 py-2.5 rounded shadow">Order Online Now</a>
            </div>
            <div class="col-12 col-md-6 text-center">
                <img src="https://images.unsplash.com/photo-1516467508483-a7212febe31a?auto=format&fit=crop&w=600&q=80" alt="Livestock Feed" class="img-fluid rounded shadow-lg" style="max-height: 380px; object-fit: cover; width: 100%;">
            </div>
        </div>
    </div>
</div>

<!-- 3. FEATURE SEGMENT ("Why people love us") -->
<div class="container py-5 my-3 text-center">
    <span class="section-title">Features</span>
    <h2 class="fw-bold text-dark mt-1 mb-4">Why farmers trust our supply</h2>
    <div class="row g-4 mt-2">
        <div class="col-12 col-md-4">
            <div class="p-3">
                <div class="fs-1 text-success mb-2">🌱</div>
                <h5 class="fw-bold text-dark">Always Fresh Ingredients</h5>
                <p class="text-muted small">Strict raw silo evaluations ensure batch quality remains consistent across operations.</p>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="p-3">
                <div class="fs-1 text-success mb-2">📲</div>
                <h5 class="fw-bold text-dark">Easy Online Orders</h5>
                <p class="text-muted small">Registered corporate clients secure output batch allocations with real-time checkout pipelines.</p>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="p-3">
                <div class="fs-1 text-success mb-2">📋</div>
                <h5 class="fw-bold text-dark">Plenty to Choose From</h5>
                <p class="text-muted small">Our processing units handle mixed grain matrix ratios specialized for diverse livestock portfolios.</p>
            </div>
        </div>
    </div>
</div>
<!-- ========================================================================= -->
<!--                           ABOUT US SECTION                                -->
<!-- ========================================================================= -->
<section class="py-5" style="background-color: #2e7d32; color: #ffffff;">
    <div class="container text-center">
        <div class="row justify-content-center">
            <div class="col-lg-8 col-md-10">
                <!-- Swapped out 'Deal of the Week' heading for About Us -->
                <h2 class="fw-bold mb-3" style="letter-spacing: -0.5px;">About Us</h2>
                <div style="width: 60px; height: 3px; background-color: #81c784; margin: 0 auto 1.5rem auto; border-radius: 2px;"></div>
                
                <!-- Thematic corporate company description -->
                <p class="lead mb-0" style="font-size: 1.15rem; line-height: 1.7; opacity: 0.95;">
                    Welcome to <strong>Tharu Products</strong>. We are dedicated to pioneering the next generation of agricultural excellence by manufacturing and distributing premium, high-nutrition animal feed. By blending modern manufacturing techniques with wholesome, responsibly sourced grains, we empower farmers, distributors, and supply networks with the high-caliber resources needed to sustain healthy livestock and optimize yield operations.
                </p>
            </div>
        </div>
    </div>
</section>
<!-- 5. DUAL SIDE MENU DISPLAY (Left: Live Floating Cart / Right: Product Feed Filter Grid) -->
<div class="container py-5 my-4" id="menu-catalog">
    <div class="row g-4">

       <!-- LEFT COLUMN: Sticky Floating Cart (Smoothly follows screen scroll) -->
        <div class="col-12 col-lg-4">
            <!-- Changed top offset to ensure it locks neatly below your global navbar -->
            <div class="card border-0 shadow-sm p-4 bg-white rounded-3 sticky-top" style="top: 7rem; z-index: 10; position: -webkit-sticky; position: sticky;">
                <h5 class="fw-bold text-dark border-bottom pb-2 mb-3">🛒 Your Procurement Manifest</h5>
                <?php if (empty($_SESSION['cart'])): ?>
                    <p class="text-muted small mb-0 py-2">Your basket is empty. Select product bag variants from the menu matrix to populate.</p>
                <?php else: ?>
                    <div class="alert alert-success py-2 small text-center mb-3">✓ Dynamic Allocations Staged</div>
                    <a href="auth/checkout_guard.php" class="btn btn-forest btn-sm w-100 text-uppercase py-2 font-monospace">Proceed to Checkout</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT COLUMN: Filter Header + Product Feed Grid -->
        <div class="col-12 col-lg-8">
            <!-- Filter Pills Container -->
            <div class="bg-white p-4 rounded-3 shadow-sm border mb-4">
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <span class="small fw-bold text-secondary me-2 font-monospace">FILTER:</span>
                    <button class="category-pill active">All Products</button>
                    <button class="category-pill">Poultry Feed</button>
                    <button class="category-pill">Cattle Mash</button>
                    <button class="category-pill">Silo Grains</button>
                </div>
            </div>

            <!-- Products Loop Grid -->
            <div class="row g-3">
                <?php if ($productsResult && $productsResult->num_rows > 0): ?>
                    <?php while ($prod = $productsResult->fetch_assoc()): ?>
                        <div class="col-12 col-sm-6">
                            <div class="menu-card p-3 bg-white d-flex flex-column justify-content-between h-100 shadow-sm rounded-3 border">
                                <div>
                                    <div style="height: 160px; background-color: #f1f5f9;" class="rounded mb-3 d-flex align-items-center justify-content-center text-muted">
                                        <span class="fs-1">🌾</span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <h6 class="fw-bold text-dark mb-0">Premium Layer Feed Mix</h6>
                                        <span class="badge bg-light text-success border font-monospace small">#STK-<?= $prod['stockID'] ?></span>
                                    </div>
                                    <p class="text-muted card-text mb-3" style="font-size: 0.8rem;">Clean standard 50KG processing variant optimized for wholesale distribution parameters.</p>
                                    <p class="text-muted small mb-0">Available: <strong class="text-dark"><?= $prod['qtyavailable'] ?> units</strong></p>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
                                    <span class="fw-bold text-success fs-5">LKR <?= number_format($prod['unitprice'], 2) ?></span>
                                    <button onclick="addUnit(<?= $prod['stockID'] ?>)" class="btn btn-sm btn-forest px-3">+ Add Item</button>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="col-12 text-center py-5 text-muted bg-white rounded-3 border shadow-sm">
                        No processing product lots are currently registered active.
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<!-- 6. INLINE HTML FOOTER TAG -->
<footer class="bg-dark text-white-50 py-4 border-top border-secondary mt-5">
    <div class="container px-4 text-center text-md-start">
        <div class="row align-items-center g-3">
            <div class="col-12 col-md-6">
                <span class="text-white fw-bold font-monospace small">🌾 THARU SYSTEMS</span>
                <span class="mx-2">|</span>
                <span class="small">&copy; <?= date('Y'); ?> All Rights Reserved.</span>
            </div>
            <div class="col-12 col-md-6 text-center text-md-end">
                <a href="auth/login.php" class="text-white-50 text-decoration-none small hover-white">Management Portal Login</a>
            </div>
        </div>
    </div>
</footer>

<script>
window.addEventListener('scroll', function() {
    const scrollNav = document.getElementById('landing-scroll-nav');
    if (scrollNav && window.scrollY > 350) {
        scrollNav.classList.add('show-nav');
    } else if (scrollNav) {
        scrollNav.classList.remove('show-nav');
    }
});

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
</script>
</body>
</html>