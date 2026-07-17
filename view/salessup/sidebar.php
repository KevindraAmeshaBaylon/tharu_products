<?php
// Get the current filename so we can highlight the active link
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

    .dashboard-wrapper {
        display: flex;
        min-height: 100vh;
    }

    /* Sidebar Styling */
    .sidebar-panel {
        width: 260px;
        background-color: var(--sidebar-bg);
        color: #ffffff;
        padding: 2rem 1rem;
        flex-shrink: 0;
        position: fixed;
        top: 0;
        left: 0;
        height: 100vh;
        z-index: 1000;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }

    .sidebar-panel h5 {
        letter-spacing: 1px;
    }

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
    }

    .nav-dash-link:hover, .nav-dash-link.active {
        background-color: var(--sidebar-active);
        color: #ffffff;
        border-left: 4px solid var(--forest-main);
    }

    .sidebar-profile-footer {
        padding: 1rem;
        background: rgba(255, 255, 255, 0.04);
        border-radius: 14px;
        margin-top: auto;
    }

    /* Main Content wrapper needed for all pages using this sidebar */
    .main-content {
        flex-grow: 1;
        margin-left: 260px;
        padding: 2.5rem;
        width: calc(100% - 260px);
        overflow-y: auto;
        height: 100vh;
    }
</style>

<div class="sidebar-panel">
    <div>
        <div class="d-flex align-items-center gap-2 px-2 mb-4">
            <img src="../../images/LOGO.png" alt="Tharu Logo" style="height: 80px; width: auto; border-radius: 5px;">
            <h5 class="fw-bold mb-0 text-white font-monospace">SALES SUPERVISOR</h5>
        </div>
        <hr style="border-color: rgba(255,255,255,0.1);">
        
        <div class="nav flex-column">
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
        <a href="../../auth/logout.php" class="text-danger text-decoration-none d-flex align-items-center gap-2 small fw-bold" style="letter-spacing: 0.3px;">
            <i class="bi bi-box-arrow-right"></i> Sign out 
        </a>
    </div>
</div>