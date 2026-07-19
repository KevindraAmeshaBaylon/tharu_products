<?php
// Get the current filename so we can highlight the active link in the menu
// this helps us know which page the user is currently looking at
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<style>
    /* Global CSS variables shared across all pages */
    :root {
        --sidebar-bg: #0b1a10;
        --sidebar-active: #1e3a24;
        --forest-main: #2e7d32;
        --mint-light: #e8f5e9;
        --canvas-bg: #f8faf9;
    }

    body {
        background-color: var(--canvas-bg);
        font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        margin: 0;
    }

    /* basic setup so our page takes up the full screen height */
    .dashboard-wrapper {
        display: flex;
        min-height: 100vh;
    }

    /* Sidebar Styling */
    /* this makes the dark green panel on the left side stay fixed and stretch top to bottom */
    .sidebar-panel {
        width: 260px;
        background-color: var(--sidebar-bg);
        color: #ffffff;
        padding: 2rem 1rem;
        flex-shrink: 0;
        height: 100vh;
        z-index: 1000;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        position: relative; /* Needed for the watermark positioning */
    }

    .sidebar-panel h5 {
        letter-spacing: 1px;
    }

    /* how each individual menu link looks normally */
    .nav-dash-link {
        display: flex;
        align-items: center;
        gap: 12px;
        color: #a3b899;
        text-decoration: none;
        padding: 0.85rem 1rem;
        border-radius: 12px;
        margin-bottom: 0.5rem;
        font-weight: 500;
        transition: all 0.2s ease;
        cursor: pointer;
        position: relative;
        overflow: hidden; /* keeps the cool hover animations inside the button */
        z-index: 1;
    }

    /* this creates the shiny sword slash effect that waits off-screen */
    .nav-dash-link::after {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 25%;
        height: 100%;
        /* Creates a subtle glowing gradient effect */
        background: linear-gradient(90deg, transparent, rgba(74, 222, 128, 0.3), rgba(255, 255, 255, 0.7), transparent);
        transform: skewX(-25deg);
    }
    
    .nav-dash-link:hover::after {
        animation: katanaGlint 0.4s ease-out forwards;
    }

    @keyframes katanaGlint {
        0% { left: -50%; }
        100% { left: 150%; }
    }

    /* when you hover over a link or when it's the active page */
    .nav-dash-link:hover, .nav-dash-link.active {
        background-color: var(--sidebar-active);
        color: #ffffff;
        border-left: 4px solid var(--forest-main);
        /* Subtle pulsating animation for active state */
        animation: hakiEmission 1.5s infinite alternate ease-in-out;
    }

    @keyframes hakiEmission {
        0% {
            border-left-color: #2e7d32;
            box-shadow: -2px 0 5px -2px rgba(46, 125, 50, 0);
        }
        50% {
            border-left-color: #4ade80; /* Active highlight color */
            box-shadow: -4px 0 15px -2px rgba(74, 222, 128, 0.4), inset 3px 0 8px -3px rgba(74, 222, 128, 0.2);
        }
        100% {
            border-left-color: #22c55e;
            box-shadow: -2px 0 8px -2px rgba(34, 197, 94, 0.1);
        }
    }

    /* the red warning text for signing out */
    .sign-out-text {
        position: relative;
        z-index: 2;
        transition: color 0.2s;
    }

    /* Highlights the sign-out text on hover for emphasis */
    .sign-out-text:hover {
        color: #ff3333 !important;
        text-shadow: 0 0 5px rgba(255, 0, 0, 0.6), 1px 1px 0px #000;
    }

    .sign-out-text::before, .sign-out-text::after {
        content: '';
        position: absolute;
        background: linear-gradient(90deg, transparent, #000, #ff0000, #000, transparent);
        height: 2px;
        width: 100%;
        left: 0;
        top: 50%;
        opacity: 0;
        pointer-events: none;
        box-shadow: 0 0 8px #ff0000;
        z-index: -1;
    }

    .sign-out-text:hover::before {
        animation: supremeKingLightning1 0.4s infinite;
    }

    .sign-out-text:hover::after {
        background: linear-gradient(90deg, transparent, #ff0000, #1a0000, #ff0000, transparent);
        animation: supremeKingLightning2 0.3s infinite reverse; /* Adds a dynamic visual effect */
    }

    @keyframes supremeKingLightning1 {
        0%, 100% { opacity: 0; transform: scaleX(0.8) rotate(0deg) translateY(0); }
        20% { opacity: 1; transform: scaleX(1.2) rotate(-4deg) translateY(-8px); }
        40% { opacity: 0; transform: scaleX(0.9) rotate(2deg) translateY(4px); }
        60% { opacity: 1; transform: scaleX(1.1) rotate(-2deg) translateY(-4px); }
        80% { opacity: 0; transform: scaleX(1) rotate(0deg) translateY(0); }
    }

    @keyframes supremeKingLightning2 {
        0%, 100% { opacity: 0; transform: scaleX(0.7) rotate(0deg) translateY(0); }
        15% { opacity: 1; transform: scaleX(1.3) rotate(5deg) translateY(10px); }
        35% { opacity: 0; transform: scaleX(0.8) rotate(-3deg) translateY(-6px); }
        55% { opacity: 1; transform: scaleX(1.1) rotate(3deg) translateY(4px); }
        75% { opacity: 0; transform: scaleX(0.9) rotate(0deg) translateY(0); }
    }

    .sidebar-profile-footer {
        padding: 1rem;
        background: rgba(255, 255, 255, 0.04);
        border-radius: 14px;
        margin-top: auto;
        position: relative;
        z-index: 1;
    }

    /* Main Content wrapper needed for all pages using this sidebar */
    .main-content {
        flex-grow: 1;
        padding: 2.5rem;
        width: calc(100% - 260px);
        overflow-y: auto;
        height: 100vh;
    }
</style>

<div class="sidebar-panel">
    <div style="position: relative; z-index: 1;">
        <div class="d-flex align-items-center gap-2 px-2 mb-4">
            <img src="../../images/LOGO.png" alt="Tharu Logo" style="height: 80px; width: auto; border-radius: 5px;">
            <h5 class="fw-bold mb-0 text-white font-monospace">THARU SALES SUPERVISOR</h5>
        </div>
        <hr style="border-color: rgba(255,255,255,0.1);">
        
        <div class="nav flex-column">
            <!-- creating all the navigation links and making them glow if we are on that page -->
            <a href="dashboard.php" class="nav-dash-link <?php echo ($currentPage == 'dashboard.php') ? 'active' : ''; ?>"><i class="bi bi-bar-chart-fill"></i> Dashboard</a>
            <a href="customers.php" class="nav-dash-link <?php echo ($currentPage == 'customers.php') ? 'active' : ''; ?>"><i class="bi bi-people-fill"></i> Customers</a>
            <a href="orders.php" class="nav-dash-link <?php echo ($currentPage == 'orders.php') ? 'active' : ''; ?>"><i class="bi bi-box-seam"></i> Orders</a>
            <a href="stock_levels.php" class="nav-dash-link <?php echo ($currentPage == 'stock_levels.php') ? 'active' : ''; ?>"><i class="bi bi-graph-up"></i> Stock Levels</a>
            <a href="sold_units.php" class="nav-dash-link <?php echo ($currentPage == 'sold_units.php') ? 'active' : ''; ?>"><i class="bi bi-tags-fill"></i> Sold Units</a>
            <a href="delivery_assignment.php" class="nav-dash-link <?php echo ($currentPage == 'delivery_assignment.php') ? 'active' : ''; ?>"><i class="bi bi-truck"></i> Delivery Assignment</a>
            <a href="drivers.php" class="nav-dash-link <?php echo ($currentPage == 'drivers.php') ? 'active' : ''; ?>"><i class="bi bi-bus-front"></i> Drivers</a>
            <a href="sales_reports.php" class="nav-dash-link <?php echo ($currentPage == 'sales_reports.php') ? 'active' : ''; ?>"><i class="bi bi-file-earmark-text"></i> Sales Reports</a>
        </div>
    </div>

    <div class="sidebar-profile-footer">
        <a href="../../auth/logout.php" class="text-danger text-decoration-none d-flex align-items-center gap-2 small fw-bold sign-out-text" style="letter-spacing: 0.3px; width: 100%;">
            <i class="bi bi-box-arrow-right"></i> Sign out 
        </a>
    </div>
</div>