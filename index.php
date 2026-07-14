<?php
// index.php
session_start();
require_once __DIR__ . '/model/config/database.php';

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$conn = getDBConnection();

// SELECT matching your exact database schema columns!
$productQuery = "SELECT productID, name, unitprice, description, chickenfeed, pigfeed, cowfeed, batchID FROM product_tbl";
$productsResult = $conn->query($productQuery);

// --- Handle AJAX Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $id = intval($_POST['product_id']);

    // Action 1: Add/Increase Item quantity by 1
    if ($_POST['action'] === 'add_to_cart') {
        $_SESSION['cart'][$id] = ($_SESSION['cart'][$id] ?? 0) + 1;
        echo json_encode(['status' => 'success', 'cart_count' => array_sum($_SESSION['cart'])]);
        exit;
    }

    // Action 2: Decrease Item quantity by 1 (New!)
    if ($_POST['action'] === 'decrease_quantity') {
        if (isset($_SESSION['cart'][$id])) {
            $_SESSION['cart'][$id]--;
            // If the quantity drops to 0 or below, remove the item completely from the cart
            if ($_SESSION['cart'][$id] <= 0) {
                unset($_SESSION['cart'][$id]);
            }
        }
        echo json_encode(['status' => 'success', 'cart_count' => array_sum($_SESSION['cart'])]);
        exit;
    }

    // Action 3: Completely Purge/Remove Item (Updated!)
    if ($_POST['action'] === 'remove_from_cart') {
        if (isset($_SESSION['cart'][$id])) {
            unset($_SESSION['cart'][$id]); // Removes the item completely from the session
        }
        echo json_encode(['status' => 'success', 'cart_count' => array_sum($_SESSION['cart'])]);
        exit;
    }
}
?>

<?php require_once __DIR__ . '/includes/header.php'; ?>

<!-- Google Fonts for sharp UI look -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">

<style>
    :root {
        --forest-glow: #10b981;
        --deep-forest: #064e3b;
        --emerald-glass: rgba(16, 185, 129, 0.15);
        --dark-bg: #09130e;
        --card-bg-glass: rgba(255, 255, 255, 0.04);
        --card-border-glass: rgba(255, 255, 255, 0.08);
        --font-primary: 'Plus Jakarta Sans', sans-serif;
        --font-mono: 'Space Grotesk', sans-serif;
    }

    body {
        background: radial-gradient(circle at top right, #0d2919, var(--dark-bg) 60%);
        color: #e2e8f0;
        font-family: var(--font-primary);
        overflow-x: hidden;
    }

    /* GLASSMORPHIC CONTAINER GLOBAL BASE */
    .glass-panel {
        background: var(--card-bg-glass);
        backdrop-filter: blur(16px) saturate(120%);
        -webkit-backdrop-filter: blur(16px) saturate(120%);
        border: 1px solid var(--card-border-glass);
        border-radius: 24px;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    /* CONTRAST FIXES FOR THEMED PARAGRAPHS */
    .glass-panel p,
    .menu-card p {
        color: #cbd5e1 !important;
    }

    .glass-panel .lead,
    .glass-panel p.lead {
        color: #e2e8f0 !important;
    }

    /* 1. STICKY TOP SCROLL NAV (GLASS STYLE) */
    .sticky-scroll-nav {
        position: fixed !important;
        top: 0; left: 0; width: 100%; z-index: 1050;
        transform: translateY(-100%); opacity: 0;
        transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.3s ease;
        background: rgba(9, 19, 14, 0.7) !important;
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    }
    .sticky-scroll-nav.show-nav {
        transform: translateY(0); opacity: 1;
    }

    /* 2. HERO BANNER DESIGN */
    .hero-container {
        position: relative;
        padding: 8rem 0 6rem 0;
        background: transparent;
    }
    .hero-badge {
        font-family: var(--font-mono);
        letter-spacing: 2px;
        background: rgba(16, 185, 129, 0.12);
        color: var(--forest-glow);
        border: 1px solid rgba(16, 185, 129, 0.3);
        padding: 8px 16px;
        border-radius: 50px;
        font-size: 0.75rem;
        display: inline-block;
    }
    .hero-title {
        font-family: var(--font-primary);
        font-weight: 800;
        font-size: 3.5rem;
        line-height: 1.15;
        letter-spacing: -2px;
        background: linear-gradient(135deg, #ffffff 40%, #a7f3d0 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }

    /* 3. IMAGES GLOW WRAP */
    .hero-image-wrap {
        position: relative;
    }
    .hero-image-wrap::after {
        content: '';
        position: absolute;
        top: 10%; left: 10%; width: 80%; height: 80%;
        background: var(--forest-glow);
        filter: blur(80px);
        opacity: 0.25;
        z-index: -1;
    }
    .hero-img {
        border-radius: 32px;
        border: 1px solid rgba(255, 255, 255, 0.15);
    }

    /* 4. SECTIONS HEADINGS */
    .section-title {
        font-family: var(--font-mono);
        color: var(--forest-glow);
        font-weight: 700;
        text-transform: uppercase;
        font-size: 0.8rem;
        letter-spacing: 3px;
        display: block;
    }

    /* 5. DYNAMIC TACTILE GLASS BUTTONS */
    .btn-forest {
        background: rgba(16, 185, 129, 0.1);
        color: #ffffff;
        border: 1px solid rgba(16, 185, 129, 0.4);
        font-weight: 600;
        border-radius: 14px;
        padding: 10px 24px;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        font-family: var(--font-primary);
    }
    .btn-forest:hover {
        background: rgba(16, 185, 129, 0.25);
        color: #ffffff;
        border-color: var(--forest-glow);
        box-shadow: 0 0 20px rgba(16, 185, 129, 0.2);
    }

    /* 6. COHESIVE CATEGORY PILLS */
    .category-pill {
        border: 1px solid var(--card-border-glass);
        background: rgba(255, 255, 255, 0.03);
        color: #94a3b8;
        padding: 0.5rem 1.25rem;
        border-radius: 50px;
        font-size: 0.85rem;
        cursor: pointer;
        transition: all 0.2s ease;
        font-family: var(--font-mono);
    }
    .category-pill.active, .category-pill:hover {
        background: var(--forest-glow);
        color: var(--dark-bg);
        border-color: var(--forest-glow);
        font-weight: bold;
        box-shadow: 0 0 15px rgba(16, 185, 129, 0.35);
    }

    /* 7. HIGH-END GLASS CATALOG PRODUCT CARDS */
    .menu-card {
        background: rgba(255, 255, 255, 0.02);
        border: 1px solid var(--card-border-glass);
        border-radius: 24px;
        transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        overflow: hidden;
    }
    .menu-card:hover {
        transform: translateY(-8px);
        background: rgba(255, 255, 255, 0.04);
        border-color: rgba(16, 185, 129, 0.4);
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4), 
                    0 0 30px rgba(16, 185, 129, 0.05);
    }
    .product-img-holder {
        height: 180px;
        background: radial-gradient(circle, rgba(16,185,129,0.08) 0%, rgba(0,0,0,0.15) 100%);
        border-radius: 18px;
        border: 1px solid rgba(255,255,255,0.03);
    }

    /* 8. FEATURE SEGMENT BLOCK */
    .feature-icon-wrapper {
        width: 60px;
        height: 60px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(16, 185, 129, 0.08);
        border: 1px solid rgba(16, 185, 129, 0.2);
        border-radius: 18px;
        font-size: 1.5rem;
        margin: 0 auto 1.25rem auto;
    }

    /* 9. STICKY CART STYLE */
    .sticky-cart-card {
        top: 7rem;
        z-index: 10;
        position: -webkit-sticky;
        position: sticky;
    }
    
    .cart-item-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 0;
        border-bottom: 1px solid rgba(255,255,255,0.05);
        font-size: 0.9rem;
    }
</style>

<!-- 1. SCROLL-ACTIVATED TOP BAR -->
<nav id="landing-scroll-nav" class="navbar navbar-expand-lg navbar-dark shadow-sm sticky-scroll-nav py-2">
    <div class="container px-4">
        <a class="navbar-brand fw-bold font-monospace text-white" href="#" style="font-family: var(--font-mono);">🌾 THARU & PRODUCTS</a>
        <div class="ms-auto d-flex align-items-center gap-3">
            <a href="#menu-catalog" class="btn btn-sm btn-outline-light px-3" style="border-radius: 10px;">Browse Menu</a>
            <a href="auth/login.php" class="btn btn-sm btn-forest px-3">Sign In</a>
        </div>
    </div>
</nav>

<!-- 2. IMAGE HERO BANNER -->
<div class="hero-container">
    <div class="container px-4">
        <div class="row align-items-center g-5">
            <div class="col-12 col-md-6">
                <span class="hero-badge mb-3">SYSTEM PROTOCOL DEPLOYED</span>
                <h1 class="hero-title mt-2 mb-3">Premium Quality Animal Feed</h1>
                <p class="lead mb-4" style="color: #cbd5e1;">Optimizing agricultural yields with modern automated silo processing and verifiable feed distribution chains.</p>
                <a href="#menu-catalog" class="btn btn-forest px-4 py-3 shadow">Order Online Now</a>
            </div>
            <div class="col-12 col-md-6 text-center">
                <div class="hero-image-wrap">
                    <img src="https://images.unsplash.com/photo-1516467508483-a7212febe31a?auto=format&fit=crop&w=600&q=80" alt="Livestock Feed" class="img-fluid hero-img shadow-lg" style="max-height: 380px; object-fit: cover; width: 100%;">
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 3. FEATURE SEGMENT -->
<div class="container py-5 my-3 text-center">
    <span class="section-title">Features</span>
    <h2 class="fw-bold text-white mt-1 mb-5" style="letter-spacing: -1px;">Why farmers trust our supply</h2>
    <div class="row g-4 mt-2">
        <div class="col-12 col-md-4">
            <div class="glass-panel p-4 h-100">
                <div class="feature-icon-wrapper">🌱</div>
                <h5 class="fw-bold text-white">Always Fresh Ingredients</h5>
                <p class="mb-0">Strict raw silo evaluations ensure batch quality remains consistent across operations.</p>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="glass-panel p-4 h-100">
                <div class="feature-icon-wrapper">📲</div>
                <h5 class="fw-bold text-white">Easy Online Orders</h5>
                <p class="mb-0">Registered corporate clients secure output batch allocations with real-time checkout pipelines.</p>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="glass-panel p-4 h-100">
                <div class="feature-icon-wrapper">📋</div>
                <h5 class="fw-bold text-white">Plenty to Choose From</h5>
                <p class="mb-0">Our processing units handle mixed grain matrix ratios specialized for diverse livestock portfolios.</p>
            </div>
        </div>
    </div>
</div>

<!-- 4. ABOUT US GLASS SECTION -->
<section class="py-5" style="position: relative;">
    <div class="container text-center">
        <div class="row justify-content-center">
            <div class="col-lg-8 col-md-10 glass-panel p-5" style="background: rgba(16, 185, 129, 0.03); border-color: rgba(16,185,129,0.15);">
                <h2 class="fw-bold text-white mb-3" style="letter-spacing: -0.5px;">About Us</h2>
                <div style="width: 60px; height: 3px; background-color: var(--forest-glow); margin: 0 auto 1.5rem auto; border-radius: 2px;"></div>
                <p class="lead mb-0" style="font-size: 1.1rem; line-height: 1.8;">
                    Welcome to <strong class="text-white">Tharu Products</strong>. We are dedicated to pioneering the next generation of agricultural excellence by manufacturing and distributing premium, high-nutrition animal feed. By blending modern manufacturing techniques with wholesome, responsibly sourced grains, we empower farmers, distributors, and supply networks with the high-caliber resources needed to sustain healthy livestock and optimize yield operations.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- 5. DUAL SIDE MENU DISPLAY -->
<div class="container py-5 my-4" id="menu-catalog">
    <div class="row g-4">

        <!-- LEFT COLUMN: Sticky Floating Cart (With Live Running Totals, Minus, & Purge Options) -->
        <div class="col-12 col-lg-4">
            <div class="glass-panel p-4 sticky-cart-card">
                <h5 class="fw-bold text-white border-bottom pb-3 mb-3" style="font-family: var(--font-mono);">🛒 Your Cart</h5>
                <?php if (empty($_SESSION['cart'])): ?>
                    <p class="mb-0 py-2 text-white-50">Your Cart is empty. Select product bag variants from the menu matrix to populate.</p>
                <?php else: ?>
                    <div class="mb-3">
                        <?php 
                        $cartGrandTotal = 0; // Initialize total sum
                        foreach($_SESSION['cart'] as $cartItemID => $quantity): 
                            // Fetch item details instantly using correct primary key column: productID
                            $itemStmt = $conn->prepare("SELECT name, unitprice FROM product_tbl WHERE productID = ?");
                            $itemStmt->bind_param("i", $cartItemID);
                            $itemStmt->execute();
                            $itemRes = $itemStmt->get_result()->fetch_assoc();
                            
                            $itemNameResolved = $itemRes['name'] ?? "Product #$cartItemID";
                            $itemPrice = $itemRes['unitprice'] ?? 0.00;
                            $subtotal = $itemPrice * $quantity;
                            $cartGrandTotal += $subtotal; // Add to running grand total
                        ?>
                            <div class="cart-item-row text-white-50 d-flex justify-content-between align-items-center py-2" style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                <div style="max-width: 55%;">
                                    <span class="text-white d-block fw-semibold" style="font-size: 0.9rem;"><?= htmlspecialchars($itemNameResolved) ?></span>
                                    <span class="small" style="color: var(--forest-glow); font-family: var(--font-mono);">LKR <?= number_format($itemPrice, 2) ?> × <?= $quantity ?></span>
                                </div>
                                <div class="d-flex align-items-center gap-1">
                                    <span class="badge bg-dark border border-secondary me-1" style="font-family: var(--font-mono);">LKR <?= number_format($subtotal, 2) ?></span>
                                    
                                    <!-- 1. Minus Button (Decrease quantity by 1) -->
                                    <button onclick="decreaseQty(<?= $cartItemID ?>)" class="btn btn-sm btn-outline-warning border-0 p-1 px-2" title="Decrease by 1" style="transition: transform 0.2s; font-size: 0.75rem;">
                                        ➖
                                    </button>
                                    
                                    <!-- 2. Trash Button (Purge item completely) -->
                                    <button onclick="removeItem(<?= $cartItemID ?>)" class="btn btn-sm btn-outline-danger border-0 p-1" title="Remove completely" style="transition: transform 0.2s; font-size: 0.85rem;">
                                        🗑️
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Live Running Grand Total Box -->
                    <div class="p-3 rounded mb-3" style="background: rgba(255, 255, 255, 0.03); border: 1px solid var(--card-border-glass);">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small text-white-50 font-monospace text-uppercase">Subtotal Balance:</span>
                            <span class="fw-bold fs-5" style="color: var(--forest-glow); font-family: var(--font-mono);">LKR <?= number_format($cartGrandTotal, 2) ?></span>
                        </div>
                    </div>

                    <div class="alert mb-3 text-center py-2 small" style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); color: var(--forest-glow);">
                        ✓ Total Items: <?= array_sum($_SESSION['cart']) ?> Staged
                    </div>

                    <?php if (!isset($_SESSION['user_id'])): ?>
                        <!-- If not logged in, send them straight to the Register/Sign Up tab on the login page -->
                        <a href="auth/login.php?action=signup" class="btn btn-forest w-100 text-uppercase py-2.5 font-monospace">Proceed to Checkout</a>
                    <?php else: ?>
                        <!-- If logged in, let them proceed normally -->
                        <a href="auth/checkout_guard.php" class="btn btn-forest w-100 text-uppercase py-2.5 font-monospace">Proceed to Checkout</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT COLUMN: Filter Header + Product Feed Grid -->
        <div class="col-12 col-lg-8">
            <!-- Dynamic Category Filters -->
            <div class="glass-panel p-4 mb-4">
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <span class="small fw-bold text-white-50 me-2" style="font-family: var(--font-mono); letter-spacing: 1px;">FILTER:</span>
                    <button class="category-pill active" onclick="filterCategory('all')">All Products</button>
                    <button class="category-pill" onclick="filterCategory('chicken')">Poultry Feed</button>
                    <button class="category-pill" onclick="filterCategory('pig')">Pig Feed</button>
                    <button class="category-pill" onclick="filterCategory('cow')">Cattle Mash</button>
                </div>
            </div>

            <!-- Products Dynamic Grid -->
            <div class="row g-3" id="products-grid">
                <?php if ($productsResult && $productsResult->num_rows > 0): ?>
                    <?php while ($prod = $productsResult->fetch_assoc()): ?>
                        <!-- We use data attributes to store the category flags from your DB! -->
                        <div class="col-12 col-sm-6 product-card-wrapper" 
                             data-chicken="<?= $prod['chickenfeed'] ?>" 
                             data-pig="<?= $prod['pigfeed'] ?>" 
                             data-cow="<?= $prod['cowfeed'] ?>">
                            
                            <div class="menu-card p-3 d-flex flex-column justify-content-between h-100">
                                <div>
                                    <div class="product-img-holder rounded mb-3 d-flex align-items-center justify-content-center text-muted">
                                        <span class="display-3" style="filter: drop-shadow(0 0 10px rgba(16, 185, 129, 0.4));">🌾</span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h6 class="fw-bold text-white mb-0"><?= htmlspecialchars($prod['name']) ?></h6>
                                        <span class="badge border font-monospace small" style="background: rgba(255,255,255,0.04); border-color: rgba(255,255,255,0.1) !important; color: var(--forest-glow);">#PROD-<?= $prod['productID'] ?></span>
                                    </div>
                                    <p class="card-text mb-3" style="font-size: 0.8rem; line-height: 1.5;">
                                        <?= htmlspecialchars($prod['description'] ?? 'Wholesome formulation optimized for high performance and daily feed schedules.') ?>
                                    </p>
                                    <p class="text-white-50 small mb-0">Batch ID: <strong class="text-white">#<?= htmlspecialchars($prod['batchID']) ?></strong></p>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mt-3 pt-3" style="border-top: 1px solid rgba(255,255,255,0.05);">
                                    <span class="fw-bold fs-5" style="color: var(--forest-glow); font-family: var(--font-mono);">LKR <?= number_format($prod['unitprice'], 2) ?></span>
                                    <button onclick="addUnit(<?= $prod['productID'] ?>)" class="btn btn-sm btn-forest">+ Add Item</button>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="col-12 text-center py-5 text-muted glass-panel">
                        No processing product lots are currently registered active in `product_tbl`.
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<!-- 6. INLINE HTML FOOTER TAG -->
<footer class="py-4 mt-5" style="background: rgba(5, 11, 8, 0.9); border-top: 1px solid rgba(255,255,255,0.06);">
    <div class="container px-4 text-center text-md-start">
        <div class="row align-items-center g-3">
            <div class="col-12 col-md-6">
                <span class="text-white fw-bold small" style="font-family: var(--font-mono); letter-spacing: 1px;">🌾 THARU SYSTEMS</span>
                <span class="mx-2 text-white-50">|</span>
                <span class="small text-white-50">&copy; <?= date('Y'); ?> All Rights Reserved.</span>
            </div>
            <div class="col-12 col-md-6 text-center text-md-end">
                <a href="auth/login.php" class="text-white-50 text-decoration-none small hover-white" style="transition: color 0.2s;">Management Portal Login</a>
            </div>
        </div>
    </div>
</footer>

<script>
// Sticky Top scroll navigation active check
window.addEventListener('scroll', function() {
    const scrollNav = document.getElementById('landing-scroll-nav');
    if (scrollNav && window.scrollY > 350) {
        scrollNav.classList.add('show-nav');
    } else if (scrollNav) {
        scrollNav.classList.remove('show-nav');
    }
});

// Real-time client-side filter engine matching your exact database schema flags!
function filterCategory(category) {
    // Update active visual styles on filter pills
    const pills = document.querySelectorAll('.category-pill');
    pills.forEach(p => p.classList.remove('active'));
    
    // Set clicked pill active
    event.currentTarget.classList.add('active');

    const cards = document.querySelectorAll('.product-card-wrapper');
    cards.forEach(card => {
        if (category === 'all') {
            card.style.display = 'block';
        } else if (category === 'chicken' && card.getAttribute('data-chicken') === '1') {
            card.style.display = 'block';
        } else if (category === 'pig' && card.getAttribute('data-pig') === '1') {
            card.style.display = 'block';
        } else if (category === 'cow' && card.getAttribute('data-cow') === '1') {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
}

// Quick-Add Cart Execution via AJAX
function addUnit(productId) {
    const formData = new FormData();
    formData.append('action', 'add_to_cart');
    formData.append('product_id', productId);

    fetch('index.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if(data.status === 'success') {
            window.location.reload(); // Instantly syncs the UI and cart counters
        }
    })
    .catch(err => {
        console.error('Error executing cart transaction:', err);
    });
}

// Quick-Remove Cart Execution via AJAX
function removeItem(productId) {
    const formData = new FormData();
    formData.append('action', 'remove_from_cart');
    formData.append('product_id', productId);

    fetch('index.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if(data.status === 'success') {
            window.location.reload(); // Instantly reloads page to update running totals & display
        }
    })
    .catch(err => {
        console.error('Error executing cart removal:', err);
    });
}

// Quick-Decrease Cart Quantity by 1 via AJAX
function decreaseQty(productId) {
    const formData = new FormData();
    formData.append('action', 'decrease_quantity');
    formData.append('product_id', productId);

    fetch('index.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if(data.status === 'success') {
            window.location.reload(); // Instantly syncs UI layout
        }
    })
    .catch(err => {
        console.error('Error executing quantity decrease:', err);
    });
}

// Complete Purge of Cart Item via AJAX
function removeItem(productId) {
    const formData = new FormData();
    formData.append('action', 'remove_from_cart');
    formData.append('product_id', productId);

    fetch('index.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if(data.status === 'success') {
            window.location.reload(); // Instantly clears item from UI
        }
    })
    .catch(err => {
        console.error('Error executing complete cart purge:', err);
    });
}
</script>
</body>
</html>