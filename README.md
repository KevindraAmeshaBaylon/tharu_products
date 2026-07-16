<div align="center">

<img src="./images/LOGO.png" alt="Tharu & Products Logo" width="140"/>

# 🌾 Tharu & Products
### Animal Feed Supply Management System

*Digitizing payroll, inventory, and sales for a growing animal feed business.*

![PHP](https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![HTML5](https://img.shields.io/badge/HTML5-E34F26?style=for-the-badge&logo=html5&logoColor=white)
![CSS3](https://img.shields.io/badge/CSS3-1572B6?style=for-the-badge&logo=css3&logoColor=white)
![Bootstrap](https://img.shields.io/badge/Bootstrap-7952B3?style=for-the-badge&logo=bootstrap&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black)

[Overview](#-overview) • [Features](#-features) • [Tech Stack](#-tech-stack) • [Database](#-database-design) • [Getting Started](#-getting-started) • [Team](#-team)

</div>

---

## 📖 Overview

**Tharu & Products** (Reg. No. WZ7213) is an animal feed supplier based in Maradagahamula, Sri Lanka, producing feed for chickens, cows, and pigs for both large client farms and independent small farms. Like many growing SMEs, the business has historically run on **paper logs and manual calculations** — a system prone to human error, data loss, and inefficiency as the business scales.

This project replaces that manual workflow with a **role-based web application** that centralizes:

- 👥 **Employee & payroll management** — attendance, salaries, OT, and holiday bonuses
- 📦 **Stock & production tracking** — raw materials, production batches, and finished goods
- 💰 **Sales & customer management** — orders, deliveries, income, and profit reporting

Built as a Final Year Diploma Project (DSE25.2F Batch — School of Computing and Engineering) using the **Agile development model**, with real-world requirements gathered directly from the business owner and staff.

---

## ✨ Features

The system is built around **six role-based dashboards**, each scoped to exactly what that user needs — nothing more.

| Role | Key Capabilities |
|---|---|
| 👑 **Owner** | Executive KPI dashboard, calculates the accountant's salary/OT/bonuses, assigns suppliers & customers to supervisors, oversees legal agreements and all employee records |
| 🧾 **Accountant** | Calculates salaries, OT, and bonuses for supervisors, workers & drivers; records operational costs; generates sales, expense, and profit reports |
| 📊 **Stock Supervisor** | Manages suppliers & raw material purchases, tracks materials sent to production, monitors finished-stock levels, and traces each production batch back to its source |
| 🚚 **Sales Supervisor** | Manages customer orders, sold units, driver/delivery assignments, income records, and daily sales summaries |
| 🏭 **Worker** | Views assigned production schedule, floor operations dashboard, and daily task status |
| 🚛 **Driver** | Views assigned deliveries and delivery status |
| 🛒 **Customer** | Public product catalog with live cart, order placement, checkout & payment, and order history — no office visit required |

**Cross-cutting features:**
- 🔐 Secure, **role-based authentication** — every login redirects to the correct dashboard, and sensitive data (e.g. salaries) is hidden from workers/drivers
- 🧮 Automatic calculation of salaries, OT, bonuses, and profit margins — no more manual arithmetic
- 📈 Auto-generated reports: sales, expenses, and monthly profit summaries
- 🗃️ Centralized MySQL database replacing paper-based records
- 🌐 Responsive UI (Bootstrap) usable across desktops, tablets, and phones

---

## 🛠 Tech Stack

| Layer | Technology |
|---|---|
| **Frontend** | HTML5, CSS3, JavaScript, Bootstrap |
| **Backend** | PHP (procedural, MySQLi) |
| **Database** | MySQL 8.0+ |
| **Local Environment** | XAMPP (Apache + MySQL + PHP) |
| **Version Control** | Git & GitHub (feature-branch workflow) |

---

## 🗄 Database Design

The schema (`model/database.sql`) follows a **table-per-type inheritance** design: a central `User_tbl` holds shared identity/auth data, and each role (`Owner_tbl`, `StockSuperviser_tbl`, `Accountant_tbl`, `SalesSuperviser_tbl`, `Worker_tbl`, `Driver_tbl`, `Customer_tbl`) extends it with role-specific fields.

Around this identity core, the schema models the full business domain:

- **Supply chain:** `Supplier_tbl`, `Rawmaterial_tbl`, `ProductionBatch_tbl`, `Product_tbl` (with M:N linking tables tracing which raw materials and suppliers contributed to which production batch)
- **Sales:** `Order_tbl`, `OrderHistory_tbl`, `Payment_tbl`, `SalesReport_tbl`, `Inquiry_tbl`
- **HR & Finance:** `Attendance_tbl`, `Salary_tbl`, `Expense_tbl`, `ExpenseReport_tbl`, `ProfitReport_tbl`

> 28 interconnected tables, full referential integrity via foreign keys, and `ENGINE=InnoDB` throughout for transactional safety.

---

## 🚀 Getting Started

### Prerequisites
- [XAMPP](https://www.apachefriends.org/) (or any Apache + PHP 8+ + MySQL stack)
- A browser

### Installation

1. **Clone the repository** into your XAMPP `htdocs` folder:
   ```bash
   cd C:/xampp/htdocs   # or /Applications/XAMPP/htdocs on macOS
   git clone https://github.com/KevindraAmeshaBaylon/tharu_products.git
   ```

2. **Start Apache and MySQL** from the XAMPP Control Panel.

3. **Import the database:**
   - Open `phpMyAdmin`
   - Import `model/database.sql` (this script creates the `tharu_products` database and all tables for you — no need to create it manually first)

4. **Configure the database connection:**
   Create `model/config/database.php` (this file is git-ignored so each developer keeps their own local credentials):
   ```php
   <?php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   define('DB_NAME', 'tharu_products');

   function getDBConnection() {
       $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
       if ($conn->connect_error) {
           die("Critical Database Connection Failure: " . $conn->connect_error);
       }
       return $conn;
   }
   ?>
   ```

5. **Launch the app:**
   ```
   http://localhost/tharu_products/
   ```

---

## 📁 Project Structure

```
tharu_products/
├── auth/                 # Login, logout, checkout guard
│   ├── login.php
│   ├── logout.php
│   └── checkout_guard.php
├── includes/             # Shared header & auth-check partials
│   ├── header.php
│   └── auth_check.php
├── images/                # Logo & static assets
├── model/
│   ├── config/
│   │   └── database.php  # DB connection (git-ignored, create locally)
│   └── database.sql      # Full schema (28 tables)
├── view/
│   ├── owner_dashboard.php
│   ├── acc_dashboard.php
│   ├── stocksup_dashboard.php
│   ├── worker_dashboard.php
│   ├── driver_dashboard.php
│   ├── cust_dashboard.php
│   └── salessup/          # Sales Supervisor module
│       ├── dashboard.php
│       ├── orders.php
│       ├── customers.php
│       ├── drivers.php
│       ├── delivery_assignment.php
│       ├── sold_units.php
│       ├── stock_levels.php
│       └── sales_reports.php
└── index.php              # Public storefront / product catalog
```

## 🧪 Testing Strategy

Developed following the **Agile methodology**, with testing woven throughout each iteration:

| Type | Purpose |
|---|---|
| Unit Testing | Verify isolated components work as intended |
| Integration Testing | Validate data flow between connected components |
| System Testing | Confirm the full system meets functional & non-functional requirements |
| User Acceptance Testing | Validate real-world readiness with actual staff |
| Performance Testing | Ensure responsiveness under realistic workloads |
| Regression Testing | Catch issues introduced by new changes |
| Compatibility Testing | Confirm consistent behavior across browsers, devices & OSes |

---

## 👥 Team

**Group 05 — DSE25.2F, School of Computing and Engineering**
Supervised by **Ms. Chandula Rajapaksa**

| Student ID | Name |
|---|---|
| CODSE25.2F-007 | Ranasinghe S. N. |
| CODSE25.2F-024 | Baylon K. A. |
| CODSE25.2F-015 | Pathirana M. T. T. |
| CODSE25.2F-037 | Ravi N. |

Built for **Tharu & Products**, owned by **Mr. M. P. Ajith Pathirana**.

---

<div align="center">

*Made with 🌾 for a business that deserved better than paper and a calculator.*

</div>
