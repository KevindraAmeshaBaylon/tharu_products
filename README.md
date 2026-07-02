# Tharu & Products - Animal Feed Supply Management System

A web-based management system built for Tharu & Products, replacing their
paper-based workflow for employee payroll, stock/inventory, and sales
management.

## Tech Stack
- **Frontend:** HTML, CSS, JavaScript
- **Backend:** PHP
- **Database:** MySQL
- **Local environment:** XAMPP (Apache + MySQL)

## User Roles
- Owner
- Accountant
- Stock Supervisor
- Sales Supervisor
- Customer

## Project Structure

    tharu-products/
      config/         -> DB connection config (not committed - see setup)
      database/       -> SQL schema and seed data
      includes/       -> Shared header/footer/auth-check files
      assets/         -> css, js, images
      owner/          -> Owner role pages
      accountant/     -> Accountant role pages
      stocksup/       -> Stock Supervisor role pages
      salessup/       -> Sales Supervisor role pages
      customer/       -> Customer role pages
      auth/           -> Login/logout

## Local Setup

1. Clone the repo and place it inside your XAMPP `htdocs` folder.
2. Start Apache and MySQL from the XAMPP control panel.
3. Import the schema: open phpMyAdmin -> create a database
   named `tharu_products` -> import `database/schema.sql`.
4. Copy `config/database.example.php` to `config/database.php`
   and fill in your local MySQL username/password.
5. Visit `http://localhost/tharu-products/` in your browser.

## Git Workflow

- Never commit directly to `main`.
- Create a branch per feature: `feature/<your-name-or-role>-<what>`
- Open a Pull Request into `main` when ready.

## Team
- [Add names + roles/modules owned here]