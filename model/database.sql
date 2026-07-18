--insert the database query
-- ============================================================================
-- Tharu & Products - Database Build
-- Generated from: Relational Schema Tharu & Products (drawio/jpeg)
-- Engine: MySQL 8.0+
-- ============================================================================
-- DESIGN NOTES / ASSUMPTIONS (please review):
-- 1. User_tbl is the parent identity table. Owner/StockSuperviser/Accountant/
--    SalesSuperviser/Worker/Driver/Customer are "role" tables that share the
--    same userID as both PK and FK (table-per-type inheritance), exactly as
--    drawn. A user can only occupy ONE role in this design (userID is unique
--    across all role tables because it IS the User_tbl PK).
-- 2. Each role table also has its own "natural" business ID
--    (ownerID, stocksupID, accountantID, salessupID, workerID, driverID,
--    customerID). These are surrogate AUTO_INCREMENT columns, kept UNIQUE,
--    because other tables (Attendance, Salary, Order, Supplier, etc.) point
--    to THESE ids rather than to userID directly, matching the diagram.
-- 3. Attendance_tbl has 5 nullable FK columns (stocksupID, accountantID,
--    salessupID, driverID, workerID). Only one of the five should be
--    populated per row (i.e., an attendance record belongs to exactly one
--    employee, whose role determines which column is used). Enforced with a
--    CHECK constraint (MySQL 8.0.16+).
-- 4. UserContact_tbl is modelled 1:1 with User_tbl per the diagram (PK is
--    just userID). If you actually need MULTIPLE phone numbers per user,
--    tell me and I'll add a surrogate contactID and drop the PK-on-userID.
-- 5. "month" columns (report tables) are stored as CHAR(7) in 'YYYY-MM'
--    format. "daterange" in OrderHistory_tbl is stored as free text
--    (VARCHAR) since the diagram doesn't specify start/end columns.
-- 6. Boolean-ish flag columns (delivered, cancelled, pending, answered,
--    inproduction, completed, dispatched) are TINYINT(1).
-- 7. Money columns are DECIMAL(10,2). Adjust precision if you expect larger
--    values.
-- ============================================================================

DROP DATABASE IF EXISTS tharu_products;
CREATE DATABASE tharu_products CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tharu_products;

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- 1. CORE IDENTITY
-- ============================================================================

CREATE TABLE User_tbl (
    userID      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username    VARCHAR(50)  NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,   -- store a hash, never plaintext
    email       VARCHAR(100) NOT NULL UNIQUE,
    createdAt   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE UserContact_tbl (
    userID      INT UNSIGNED PRIMARY KEY,
    contact     VARCHAR(20) NOT NULL,
    CONSTRAINT fk_usercontact_user
        FOREIGN KEY (userID) REFERENCES User_tbl(userID)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ============================================================================
-- 2. ROLE / SUBTYPE TABLES (share PK with User_tbl)
-- ============================================================================

CREATE TABLE Owner_tbl (
    userID      INT UNSIGNED PRIMARY KEY,
    ownerID     INT UNSIGNED AUTO_INCREMENT UNIQUE,
    ownerDOB    DATE NOT NULL,
    ownerName   VARCHAR(100) NOT NULL,
    experiance  INT UNSIGNED DEFAULT 0,   -- years of experience
    CONSTRAINT fk_owner_user
        FOREIGN KEY (userID) REFERENCES User_tbl(userID)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE StockSuperviser_tbl (
    userID          INT UNSIGNED PRIMARY KEY,
    stocksupID      INT UNSIGNED AUTO_INCREMENT UNIQUE,
    stocksupDOB     DATE NOT NULL,
    stocksupname    VARCHAR(100) NOT NULL,
    base_salary     DECIMAL(10,2) NOT NULL DEFAULT 0,
    OT_rate         DECIMAL(10,2) NOT NULL DEFAULT 0,
    CONSTRAINT fk_stocksup_user
        FOREIGN KEY (userID) REFERENCES User_tbl(userID)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Accountant_tbl (
    userID          INT UNSIGNED PRIMARY KEY,
    accountantID    INT UNSIGNED AUTO_INCREMENT UNIQUE,
    accountantDOB   DATE NOT NULL,
    accountantname  VARCHAR(100) NOT NULL,
    base_salary     DECIMAL(10,2) NOT NULL DEFAULT 0,
    OT_rate         DECIMAL(10,2) NOT NULL DEFAULT 0,
    CONSTRAINT fk_accountant_user
        FOREIGN KEY (userID) REFERENCES User_tbl(userID)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE SalesSuperviser_tbl (
    userID          INT UNSIGNED PRIMARY KEY,
    salessupID      INT UNSIGNED AUTO_INCREMENT UNIQUE,
    salessupDOB     DATE NOT NULL,
    salessupname    VARCHAR(100) NOT NULL,
    base_salary     DECIMAL(10,2) NOT NULL DEFAULT 0,
    OT_rate         DECIMAL(10,2) NOT NULL DEFAULT 0,
    CONSTRAINT fk_salessup_user
        FOREIGN KEY (userID) REFERENCES User_tbl(userID)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Worker_tbl (
    userID      INT UNSIGNED PRIMARY KEY,
    workerID    INT UNSIGNED AUTO_INCREMENT UNIQUE,
    workerDOB   DATE NOT NULL,
    workername  VARCHAR(100) NOT NULL,
    hour_rate   DECIMAL(10,2) NOT NULL DEFAULT 0,
    CONSTRAINT fk_worker_user
        FOREIGN KEY (userID) REFERENCES User_tbl(userID)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Driver_tbl (
    userID          INT UNSIGNED PRIMARY KEY,
    driverID        INT UNSIGNED AUTO_INCREMENT UNIQUE,
    driverDOB       DATE NOT NULL,
    drivername      VARCHAR(100) NOT NULL,
    fixed_salary    DECIMAL(10,2) NOT NULL DEFAULT 0,
    CONSTRAINT fk_driver_user
        FOREIGN KEY (userID) REFERENCES User_tbl(userID)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Customer_tbl (
    userID          INT UNSIGNED PRIMARY KEY,
    customerID      INT UNSIGNED AUTO_INCREMENT UNIQUE,
    customerNIC     VARCHAR(20) NOT NULL UNIQUE,
    companyname     VARCHAR(100),
    address         VARCHAR(255),
    CONSTRAINT fk_customer_user
        FOREIGN KEY (userID) REFERENCES User_tbl(userID)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ============================================================================
-- 3. SUPPLY CHAIN
-- ============================================================================

CREATE TABLE Supplier_tbl (
    supplierID  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact     VARCHAR(20) NOT NULL,
    companyname VARCHAR(100) NOT NULL,
    email       VARCHAR(100),
    stocksupID  INT UNSIGNED NOT NULL,
    CONSTRAINT fk_supplier_stocksup
        FOREIGN KEY (stocksupID) REFERENCES StockSuperviser_tbl(stocksupID)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Rawmaterial_tbl (
    materialID  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    quantity    DECIMAL(10,2) NOT NULL DEFAULT 0,
    unitprice   DECIMAL(10,2) NOT NULL DEFAULT 0,
    totalcost   DECIMAL(10,2) GENERATED ALWAYS AS (quantity * unitprice) STORED,
    supplierID  INT UNSIGNED NOT NULL,
    CONSTRAINT fk_rawmaterial_supplier
        FOREIGN KEY (supplierID) REFERENCES Supplier_tbl(supplierID)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ============================================================================
-- 4. ATTENDANCE / SALARY / EXPENSES
-- ============================================================================

CREATE TABLE Attendance_tbl (
    attendanceID    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    date            DATE NOT NULL,
    login           TIME NOT NULL,
    logout          TIME NULL,
    stocksupID      INT UNSIGNED NULL,
    accountantID    INT UNSIGNED NULL,
    salessupID      INT UNSIGNED NULL,
    driverID        INT UNSIGNED NULL,
    workerID        INT UNSIGNED NULL,
    CONSTRAINT fk_att_stocksup   FOREIGN KEY (stocksupID)   REFERENCES StockSuperviser_tbl(stocksupID)   ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_att_accountant FOREIGN KEY (accountantID) REFERENCES Accountant_tbl(accountantID)      ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_att_salessup   FOREIGN KEY (salessupID)   REFERENCES SalesSuperviser_tbl(salessupID)   ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_att_driver     FOREIGN KEY (driverID)     REFERENCES Driver_tbl(driverID)              ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_att_worker     FOREIGN KEY (workerID)     REFERENCES Worker_tbl(workerID)              ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_att_one_employee CHECK (
        (stocksupID   IS NOT NULL) +
        (accountantID IS NOT NULL) +
        (salessupID   IS NOT NULL) +
        (driverID     IS NOT NULL) +
        (workerID     IS NOT NULL) = 1
    )
) ENGINE=InnoDB;

CREATE TABLE Salary_tbl (
    salaryID        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    paydate         DATE NOT NULL,
    totamtpaid      DECIMAL(10,2) NOT NULL,
    attendanceID    INT UNSIGNED NOT NULL,
    accountantID    INT UNSIGNED NOT NULL,
    CONSTRAINT fk_salary_attendance FOREIGN KEY (attendanceID) REFERENCES Attendance_tbl(attendanceID) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_salary_accountant FOREIGN KEY (accountantID) REFERENCES Accountant_tbl(accountantID) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Expense_tbl (
    expenseID       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type            VARCHAR(50) NOT NULL,
    amount          DECIMAL(10,2) NOT NULL,
    materialID      INT UNSIGNED NULL,
    accountantID    INT UNSIGNED NOT NULL,
    CONSTRAINT fk_expense_material   FOREIGN KEY (materialID)   REFERENCES Rawmaterial_tbl(materialID) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_expense_accountant FOREIGN KEY (accountantID) REFERENCES Accountant_tbl(accountantID) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE ExpenseReport_tbl (
    expenserepID    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    genaratedAt     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    month           CHAR(7) NOT NULL   -- 'YYYY-MM'
) ENGINE=InnoDB;

CREATE TABLE Exp_Report_tbl (   -- Expense <-> ExpenseReport (M:N)
    expenseID     INT UNSIGNED NOT NULL,
    expenserepID  INT UNSIGNED NOT NULL,
    PRIMARY KEY (expenseID, expenserepID),
    CONSTRAINT fk_expreport_expense FOREIGN KEY (expenseID)    REFERENCES Expense_tbl(expenseID)          ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_expreport_report  FOREIGN KEY (expenserepID) REFERENCES ExpenseReport_tbl(expenserepID) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE ProfitReport_tbl (
    profitrepID     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    genaratedAt     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    month           CHAR(7) NOT NULL,
    ownerID         INT UNSIGNED NOT NULL,
    CONSTRAINT fk_profitrep_owner FOREIGN KEY (ownerID) REFERENCES Owner_tbl(ownerID) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ============================================================================
-- 5. SALES: CUSTOMERS, ORDERS, PAYMENTS, INQUIRIES
-- ============================================================================

CREATE TABLE OrderHistory_tbl (
    orderhistoryID  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    genaratedAt     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    daterange       VARCHAR(50),
    customerID      INT UNSIGNED NOT NULL,
    CONSTRAINT fk_orderhist_customer FOREIGN KEY (customerID) REFERENCES Customer_tbl(customerID) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Order_tbl (
    orderID         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    date            DATE NOT NULL,
    totamt          DECIMAL(10,2) NOT NULL DEFAULT 0,
    delivered       TINYINT(1) NOT NULL DEFAULT 0,
    cancelled       TINYINT(1) NOT NULL DEFAULT 0,
    customerID      INT UNSIGNED NOT NULL,
    orderhistoryID  INT UNSIGNED NULL,
    salessupID      INT UNSIGNED NULL,
    driverID        INT UNSIGNED NULL,
    CONSTRAINT fk_order_customer  FOREIGN KEY (customerID)     REFERENCES Customer_tbl(customerID)          ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_order_history   FOREIGN KEY (orderhistoryID) REFERENCES OrderHistory_tbl(orderhistoryID)  ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_order_salessup  FOREIGN KEY (salessupID)     REFERENCES SalesSuperviser_tbl(salessupID)   ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_order_driver    FOREIGN KEY (driverID)       REFERENCES Driver_tbl(driverID)              ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Payment_tbl (
    paymentID   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    date        DATE NOT NULL,
    amount      DECIMAL(10,2) NOT NULL,
    chequenum   VARCHAR(30) NULL,
    orderID     INT UNSIGNED NOT NULL,
    CONSTRAINT fk_payment_order FOREIGN KEY (orderID) REFERENCES Order_tbl(orderID) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE SalesReport_tbl (
    salesrepID  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    genaratedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    month       CHAR(7) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE Payment_SalesReport_tbl (  -- Payment <-> SalesReport (M:N)
    paymentID   INT UNSIGNED NOT NULL,
    salesrepID  INT UNSIGNED NOT NULL,
    PRIMARY KEY (paymentID, salesrepID),
    CONSTRAINT fk_paysr_payment FOREIGN KEY (paymentID)  REFERENCES Payment_tbl(paymentID)      ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_paysr_report  FOREIGN KEY (salesrepID) REFERENCES SalesReport_tbl(salesrepID) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Inquiry_tbl (
    inquiryID   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message     TEXT NOT NULL,
    response    TEXT,
    pending     TINYINT(1) NOT NULL DEFAULT 1,
    answered    TINYINT(1) NOT NULL DEFAULT 0,
    customerID  INT UNSIGNED NOT NULL,
    CONSTRAINT fk_inquiry_customer FOREIGN KEY (customerID) REFERENCES Customer_tbl(customerID) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ============================================================================
-- 6. PRODUCTION
-- ============================================================================

CREATE TABLE ProductionBatch_tbl (
    batchID       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    date          DATE NOT NULL,
    outputqty     INT UNSIGNED NOT NULL DEFAULT 0,
    type          VARCHAR(50),
    inproduction  TINYINT(1) NOT NULL DEFAULT 1,
    completed     TINYINT(1) NOT NULL DEFAULT 0,
    dispatched    TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE Product_tbl (
    ProductID   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    type        VARCHAR(50),
    unitprice   DECIMAL(10,2) NOT NULL DEFAULT 0,
    description TEXT
) ENGINE=InnoDB;

CREATE TABLE ProductPerBatch_tbl (   -- ProductionBatch <-> Product (M:N)
    batchID     INT UNSIGNED NOT NULL,
    ProductID   INT UNSIGNED NOT NULL,
    PRIMARY KEY (batchID, ProductID),
    CONSTRAINT fk_ppb_batch   FOREIGN KEY (batchID)   REFERENCES ProductionBatch_tbl(batchID) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ppb_product FOREIGN KEY (ProductID) REFERENCES Product_tbl(ProductID)       ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE RawMaterial_Batch_tbl (  -- ProductionBatch <-> Rawmaterial (M:N)
    batchID     INT UNSIGNED NOT NULL,
    materialID  INT UNSIGNED NOT NULL,
    PRIMARY KEY (batchID, materialID),
    CONSTRAINT fk_rmb_batch    FOREIGN KEY (batchID)    REFERENCES ProductionBatch_tbl(batchID) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_rmb_material FOREIGN KEY (materialID) REFERENCES Rawmaterial_tbl(materialID)  ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Order_Batch_tbl (   -- ProductionBatch <-> Order (M:N)
    batchID   INT UNSIGNED NOT NULL,
    orderID   INT UNSIGNED NOT NULL,
    PRIMARY KEY (batchID, orderID),
    CONSTRAINT fk_ob_batch FOREIGN KEY (batchID) REFERENCES ProductionBatch_tbl(batchID) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ob_order FOREIGN KEY (orderID) REFERENCES Order_tbl(orderID)          ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- SAMPLE / TEST DATA
-- ============================================================================

-- Users (1 owner, 2 stock supervisors -> just 1, accountant, sales sup, 2 workers, 2 drivers, 3 customers)
INSERT INTO User_tbl (username, password, email) VALUES
('r.tharu',      'hash_owner001',    'owner@tharuproducts.lk'),
('s.kumara',     'hash_stocksup001', 'stocksup@tharuproducts.lk'),
('n.perera',     'hash_acc001',      'accountant@tharuproducts.lk'),
('d.silva',      'hash_salessup001', 'salessup@tharuproducts.lk'),
('k.bandara',    'hash_worker001',   'worker1@tharuproducts.lk'),
('l.wickrama',   'hash_worker002',   'worker2@tharuproducts.lk'),
('p.jayasuriya', 'hash_driver001',   'driver1@tharuproducts.lk'),
('m.gunasekara', 'hash_driver002',   'driver2@tharuproducts.lk'),
('acme_traders', 'hash_cust001',     'orders@acmetraders.lk'),
('kandy_grocers','hash_cust002',     'contact@kandygrocers.lk'),
('galle_mart',   'hash_cust003',     'info@gallemart.lk');

INSERT INTO UserContact_tbl (userID, contact) VALUES
(1,'0771234567'), (2,'0772234567'), (3,'0773234567'), (4,'0774234567'),
(5,'0775234567'), (6,'0776234567'), (7,'0777234567'), (8,'0778234567'),
(9,'0812234567'), (10,'0912234567'), (11,'0912345678');

INSERT INTO Owner_tbl (userID, ownerDOB, ownerName, experiance) VALUES
(1, '1975-03-14', 'Ranjith Tharu', 20);

INSERT INTO StockSuperviser_tbl (userID, stocksupDOB, stocksupname, base_salary, OT_rate) VALUES
(2, '1988-06-21', 'Sunil Kumara', 65000.00, 350.00);

INSERT INTO Accountant_tbl (userID, accountantDOB, accountantname, base_salary, OT_rate) VALUES
(3, '1990-11-02', 'Nadeesha Perera', 70000.00, 400.00);

INSERT INTO SalesSuperviser_tbl (userID, salessupDOB, salessupname, base_salary, OT_rate) VALUES
(4, '1985-01-30', 'Dinesh Silva', 68000.00, 375.00);

INSERT INTO Worker_tbl (userID, workerDOB, workername, hour_rate) VALUES
(5, '1995-07-19', 'Kasun Bandara', 450.00),
(6, '1997-09-05', 'Lasith Wickrama', 420.00);

INSERT INTO Driver_tbl (userID, driverDOB, drivername, fixed_salary) VALUES
(7, '1992-04-10', 'Pradeep Jayasuriya', 55000.00),
(8, '1993-12-25', 'Mahesh Gunasekara', 55000.00);

INSERT INTO Customer_tbl (userID, customerNIC, companyname, address) VALUES
(9,  '198855123456', 'Acme Traders',   '12 Main St, Colombo'),
(10, '199012345678', 'Kandy Grocers',  '45 Lake Rd, Kandy'),
(11, '199234567890', 'Galle Mart',     '7 Beach Ave, Galle');

-- Supply chain
INSERT INTO Supplier_tbl (contact, companyname, email, stocksupID) VALUES
('0711112222', 'Lanka Raw Materials Ltd', 'sales@lankaraw.lk', 1),
('0713334444', 'Ceylon Packaging Co',     'info@ceylonpack.lk', 1);

INSERT INTO Rawmaterial_tbl (name, quantity, unitprice, supplierID) VALUES
('Coconut Oil',   500.00, 320.00, 1),
('Sugar',         800.00, 210.00, 1),
('Packaging Box', 2000.00, 45.00, 2);

-- Attendance (one employee per row)
INSERT INTO Attendance_tbl (date, login, logout, stocksupID, accountantID, salessupID, driverID, workerID) VALUES
('2026-07-01', '08:00:00', '17:00:00', 1,    NULL, NULL, NULL, NULL),
('2026-07-01', '08:05:00', '17:10:00', NULL, 1,    NULL, NULL, NULL),
('2026-07-01', '08:10:00', '17:00:00', NULL, NULL, 1,    NULL, NULL),
('2026-07-01', '07:30:00', '16:30:00', NULL, NULL, NULL, 1,    NULL),
('2026-07-01', '08:00:00', '17:00:00', NULL, NULL, NULL, NULL, 1),
('2026-07-01', '08:15:00', '17:00:00', NULL, NULL, NULL, NULL, 2);

INSERT INTO Salary_tbl (paydate, totamtpaid, attendanceID, accountantID) VALUES
('2026-07-31', 65000.00, 1, 1),
('2026-07-31', 70000.00, 2, 1),
('2026-07-31', 68000.00, 3, 1);

-- Expenses & reports
INSERT INTO Expense_tbl (type, amount, materialID, accountantID) VALUES
('Raw Material Purchase', 160000.00, 1, 1),
('Utility Bill',           45000.00, NULL, 1);

INSERT INTO ExpenseReport_tbl (month) VALUES ('2026-07');

INSERT INTO Exp_Report_tbl (expenseID, expenserepID) VALUES
(1, 1), (2, 1);

INSERT INTO ProfitReport_tbl (month, ownerID) VALUES ('2026-07', 1);

-- Orders / payments / inquiries
INSERT INTO OrderHistory_tbl (daterange, customerID) VALUES
('2026-01-01 to 2026-07-15', 1),
('2026-01-01 to 2026-07-15', 2);

INSERT INTO Order_tbl (date, totamt, delivered, cancelled, customerID, orderhistoryID, salessupID, driverID) VALUES
('2026-07-05', 125000.00, 1, 0, 1, 1, 1, 1),
('2026-07-10',  48000.00, 0, 0, 2, 2, 1, 2),
('2026-07-12',  90000.00, 0, 1, 3, NULL, 1, NULL);

INSERT INTO Payment_tbl (date, amount, chequenum, orderID) VALUES
('2026-07-05', 125000.00, NULL,        1),
('2026-07-10',  24000.00, 'CHQ-10023', 2);

INSERT INTO SalesReport_tbl (month) VALUES ('2026-07');

INSERT INTO Payment_SalesReport_tbl (paymentID, salesrepID) VALUES
(1, 1), (2, 1);

INSERT INTO Inquiry_tbl (message, response, pending, answered, customerID) VALUES
('Do you offer bulk discounts on orders over 1000 units?', 'Yes, 5% off orders above 1000 units.', 0, 1, 1),
('Can delivery be expedited for order #2?', NULL, 1, 0, 2);

-- Production
INSERT INTO ProductionBatch_tbl (date, outputqty, type, inproduction, completed, dispatched) VALUES
('2026-07-02', 1000, 'Coconut Snack Pack', 0, 1, 1),
('2026-07-08',  600, 'Sugar Candy Box',    1, 0, 0);

INSERT INTO Product_tbl (name, type, unitprice, description) VALUES
('Coconut Snack Pack', 'Snack', 150.00, '200g coconut-based snack pack'),
('Sugar Candy Box',    'Confectionery', 80.00, 'Box of traditional sugar candy');

INSERT INTO ProductPerBatch_tbl (batchID, ProductID) VALUES
(1, 1), (2, 2);

INSERT INTO RawMaterial_Batch_tbl (batchID, materialID) VALUES
(1, 1), (1, 3), (2, 2), (2, 3);

INSERT INTO Order_Batch_tbl (batchID, orderID) VALUES
(1, 1), (2, 2);


--added role column to user_tbl and set roles for existing users
ALTER TABLE user_tbl ADD role VARCHAR(20) NOT NULL ;
UPDATE `user_tbl` SET `role` = 'owner' WHERE `user_tbl`.`userID` = 1; UPDATE `user_tbl` SET `role` = 'stocksup' WHERE `user_tbl`.`userID` = 2; UPDATE `user_tbl` SET `role` = 'accountant' WHERE `user_tbl`.`userID` = 3; UPDATE `user_tbl` SET `role` = 'salessup' WHERE `user_tbl`.`userID` = 4; UPDATE `user_tbl` SET `role` = 'worker' WHERE `user_tbl`.`userID` = 5; UPDATE `user_tbl` SET `role` = 'worker' WHERE `user_tbl`.`userID` = 6; UPDATE `user_tbl` SET `role` = 'driver' WHERE `user_tbl`.`userID` = 7; UPDATE `user_tbl` SET `role` = 'driver' WHERE `user_tbl`.`userID` = 8; UPDATE `user_tbl` SET `role` = 'cust' WHERE `user_tbl`.`userID` = 9; UPDATE `user_tbl` SET `role` = 'cust' WHERE `user_tbl`.`userID` = 10; UPDATE `user_tbl` SET `role` = 'cust' WHERE `user_tbl`.`userID` = 11;

-- ==========================================
-- THARU PRODUCTS DATABASE INITIALIZATION
-- ==========================================

-- ------------------------------------------
-- SAFE DATA CLEARING (RESET)
-- ------------------------------------------
SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE `user_tbl`;
TRUNCATE TABLE `usercontact_tbl`;
TRUNCATE TABLE `owner_tbl`;
TRUNCATE TABLE `accountant_tbl`;
TRUNCATE TABLE `stocksuperviser_tbl`;
TRUNCATE TABLE `salessuperviser_tbl`;
TRUNCATE TABLE `driver_tbl`;
TRUNCATE TABLE `worker_tbl`;
TRUNCATE TABLE `supplier_tbl`;
TRUNCATE TABLE `rawmaterial_tbl`;
TRUNCATE TABLE `productionbatch_tbl`;
TRUNCATE TABLE `product_tbl`;
TRUNCATE TABLE `productperbatch_tbl`;
TRUNCATE TABLE `rawmaterial_batch_tbl`;
TRUNCATE TABLE `customer_tbl`;
TRUNCATE TABLE `orderhistory_tbl`;
TRUNCATE TABLE `order_tbl`;
TRUNCATE TABLE `order_batch_tbl`;
TRUNCATE TABLE `inquiry_tbl`;
TRUNCATE TABLE `attendance_tbl`;
TRUNCATE TABLE `expense_tbl`;
TRUNCATE TABLE `payment_tbl`;
TRUNCATE TABLE `salary_tbl`;
TRUNCATE TABLE `expensereport_tbl`;
TRUNCATE TABLE `salesreport_tbl`;
TRUNCATE TABLE `profitreport_tbl`;
TRUNCATE TABLE `exp_report_tbl`;
TRUNCATE TABLE `payment_salesreport_tbl`;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------
-- 1. INSERT INTO user_tbl
-- ------------------------------------------
INSERT INTO `user_tbl` (`userID`, `username`, `password`, `email`, `createdAt`, `role`) VALUES
(1, 'owner', 'owner001', 'ajith@tharuproducts.com', NOW(), 'owner'),
(2, 'accountant', 'acc001', 'finance@tharuproducts.com', NOW(), 'accountant'),
(3, 'stocksup', 'stocksup001', 'stocks@tharuproducts.com', NOW(), 'stocksup'),
(4, 'salessup', 'salessup001', 'sales@tharuproducts.com', NOW(), 'salessup'),
(5, 'driver01', 'driver001', 'anura.d@tharuproducts.com', NOW(), 'driver'),
(6, 'driver02', 'driver002', 'sunil.s@tharuproducts.com', NOW(), 'driver'),
(7, 'driver03', 'driver003', 'nimal.p@tharuproducts.com', NOW(), 'driver'),
(8, 'worker01', 'worker001', 'worker1@tharuproducts.com', NOW(), 'worker'),
(9, 'worker02', 'worker002', 'worker2@tharuproducts.com', NOW(), 'worker'),
(10, 'worker03', 'worker003', 'worker3@tharuproducts.com', NOW(), 'worker'),
(11, 'delmo', 'customer001', 'orders@delmo.lk', NOW(), 'customer'),
(12, 'maxies', 'customer002', 'purchasing@maxies.lk', NOW(), 'customer'),
(13, 'ruwan', 'customer003', 'ruwan.farm@gmail.com', NOW(), 'customer');

-- ------------------------------------------
-- 2. INSERT INTO usercontact_tbl
-- ------------------------------------------
INSERT INTO `usercontact_tbl` (`userID`, `contact`) VALUES
(1, '0771112222'),
(2, '0772223333'),
(3, '0773334444'),
(4, '0774445555'),
(5, '0715556661'),
(6, '0715556662'),
(7, '0715556663'),
(8, '0781002001'),
(9, '0781002002'),
(10, '0781002003'),
(11, '0112950950'),
(12, '0112951951'),
(13, '0758889991');

-- ------------------------------------------
-- 3. INSERT INTO owner_tbl
-- ------------------------------------------
INSERT INTO `owner_tbl` (`userID`, `ownerID`, `ownerDOB`, `ownerName`, `experiance`) VALUES
(1, 1001, '1975-06-12', 'Ajith Pathirana', 15);

-- ------------------------------------------
-- 4. INSERT INTO accountant_tbl
-- ------------------------------------------
INSERT INTO `accountant_tbl` (`userID`, `accountantID`, `accountantDOB`, `accountantname`, `base_salary`, `OT_rate`) VALUES
(2, 2001, '1984-11-20', 'Kamal Perera', 85000.00, 375.00);

-- ------------------------------------------
-- 5. INSERT INTO stocksuperviser_tbl
-- ------------------------------------------
INSERT INTO `stocksuperviser_tbl` (`userID`, `stocksupID`, `stocksupDOB`, `stocksupname`, `base_salary`, `OT_rate`) VALUES
(3, 3001, '1988-04-15', 'Jagath Silva', 65000.00, 375.00);

-- ------------------------------------------
-- 6. INSERT INTO salessuperviser_tbl
-- ------------------------------------------
INSERT INTO `salessuperviser_tbl` (`userID`, `salessupID`, `salessupDOB`, `salessupname`, `base_salary`, `OT_rate`) VALUES
(4, 4001, '1990-09-05', 'Lalith De-Silva', 65000.00, 375.00);

-- ------------------------------------------
-- 7. INSERT INTO driver_tbl
-- ------------------------------------------
INSERT INTO `driver_tbl` (`userID`, `driverID`, `driverDOB`, `drivername`, `fixed_salary`) VALUES
(5, 5001, '1985-02-14', 'Anura Kumara', 45000.00),
(6, 5002, '1982-07-22', 'Sunil Shantha', 45000.00),
(7, 5003, '1989-12-10', 'Nimal Siri', 45000.00);

-- ------------------------------------------
-- 8. INSERT INTO worker_tbl
-- ------------------------------------------
INSERT INTO `worker_tbl` (`userID`, `workerID`, `workerDOB`, `workername`, `hour_rate`) VALUES
(8, 6001, '1993-01-10', 'Asela Kumara', 250.00),
(9, 6002, '1995-03-22', 'Roshan Peiris', 250.00),
(10, 6003, '1991-05-18', 'Ruwan Priyadarshana', 250.00);

-- ------------------------------------------
-- 9. INSERT INTO supplier_tbl
-- ------------------------------------------
INSERT INTO `supplier_tbl` (`supplierID`, `contact`, `companyname`, `email`, `stocksupID`) VALUES
(7001, '0312234123', 'Siri Rice Mill', 'siri.mills@gmail.com', 3001),
(7002, '0312234567', 'Maradagahamula Grains', 'info@maradagrain.lk', 3001),
(7003, '0112234987', 'Lanka Vit and Premix', 'sales@lankavit.lk', 3001);

-- ------------------------------------------
-- 10. INSERT INTO rawmaterial_tbl
-- ------------------------------------------
INSERT INTO `rawmaterial_tbl` (`materialID`, `name`, `quantity`, `unitprice`, `totalcost`, `supplierID`) VALUES
(8001, 'Rice Polish', 5000.00, 80.00, 400000.00, 7001),
(8002, 'Broken Rice', 3000.00, 95.00, 285000.00, 7001),
(8003, 'Maize Meal', 4000.00, 110.00, 440000.00, 7002),
(8004, 'Soya Bean Meal', 2000.00, 210.00, 420000.00, 7002),
(8005, 'Feed Vitamins', 500.00, 450.00, 225000.00, 7003);

-- ------------------------------------------
-- 11. INSERT INTO productionbatch_tbl
-- ------------------------------------------
INSERT INTO `productionbatch_tbl` (`batchID`, `date`, `outputqty`, `type`, `inproduction`, `completed`, `dispatched`) VALUES
(9001, '2026-07-01', 150, 'Chicken Feed', 0, 1, 1),
(9002, '2026-07-05', 100, 'Cow Feed', 0, 1, 1),
(9003, '2026-07-10', 80, 'Pig Feed', 0, 1, 1),
(9004, '2026-07-15', 200, 'Chicken Feed', 1, 0, 0);

-- ------------------------------------------
-- 12. INSERT INTO product_tbl
-- ------------------------------------------
INSERT INTO `product_tbl` (`ProductID`, `name`, `type`, `unitprice`, `description`) VALUES
(101, 'Tharu Chicken Broiler Feed', 'Chicken Feed', 4200.00, 'High-protein formulation for quick broiler growth and optimal health.'),
(102, 'Tharu Dairy Cow Max', 'Cow Feed', 3800.00, 'Fortified with calcium and vitamins to maximize daily milk yield.'),
(103, 'Tharu Pig Grower Gold', 'Pig Feed', 3500.00, 'Specially designed nutrient profile to achieve ideal pig weights safely.');

-- ------------------------------------------
-- 13. INSERT INTO productperbatch_tbl
-- ------------------------------------------
INSERT INTO `productperbatch_tbl` (`batchID`, `ProductID`) VALUES
(9001, 101),
(9002, 102),
(9003, 103),
(9004, 101);

-- ------------------------------------------
-- 14. INSERT INTO rawmaterial_batch_tbl
-- ------------------------------------------
INSERT INTO `rawmaterial_batch_tbl` (`batchID`, `materialID`) VALUES
(9001, 8001),
(9001, 8003),
(9001, 8005),
(9002, 8002),
(9002, 8003);

-- ------------------------------------------
-- 15. INSERT INTO customer_tbl
-- ------------------------------------------
INSERT INTO `customer_tbl` (`userID`, `customerID`, `customerNIC`, `companyname`, `address`) VALUES
(11, 10001, '199123450012', 'Delmo Chicken', 'Negombo Road, Gampaha'),
(12, 10002, '198754120015', 'Maxies Farms', 'Wennappuwa, Chilaw'),
(13, 10003, '198501221144', 'Ruwan Dairy', 'Maradagahamula');

-- ------------------------------------------
-- 16. INSERT INTO orderhistory_tbl
-- ------------------------------------------
INSERT INTO `orderhistory_tbl` (`orderhistoryID`, `genaratedAt`, `daterange`, `customerID`) VALUES
(501, '2026-07-01 10:00:00', '2026-07-01 to 2026-07-15', 10001),
(502, '2026-07-05 11:30:00', '2026-07-05 to 2026-07-15', 10002);

-- ------------------------------------------
-- 17. INSERT INTO order_tbl
-- ------------------------------------------
INSERT INTO `order_tbl` (`orderID`, `date`, `totamt`, `delivered`, `cancelled`, `customerID`, `orderhistoryID`, `salessupID`, `driverID`) VALUES
(12001, '2026-07-02', 420000.00, 1, 0, 10001, 501, 4001, 5001),
(12002, '2026-07-06', 380000.00, 1, 0, 10002, 502, 4001, 5002),
(12003, '2026-07-12', 35000.00, 0, 0, 10003, NULL, 4001, 5003);

-- ------------------------------------------
-- 18. INSERT INTO order_batch_tbl
-- ------------------------------------------
INSERT INTO `order_batch_tbl` (`batchID`, `orderID`) VALUES
(9001, 12001),
(9002, 12002);

-- ------------------------------------------
-- 19. INSERT INTO inquiry_tbl
-- ------------------------------------------
INSERT INTO `inquiry_tbl` (`inquiryID`, `message`, `response`, `pending`, `answered`, `customerID`) VALUES
(13001, 'Need to request delivery before 8 AM for our upcoming batch of broiler feed.', 'Noted. Lorry Driver 1 will be dispatched early morning.', 0, 1, 10001);

-- ------------------------------------------
-- 20. INSERT INTO attendance_tbl
-- ------------------------------------------
INSERT INTO `attendance_tbl` (`attendanceID`, `date`, `login`, `logout`, `stocksupID`, `accountantID`, `salessupID`, `driverID`, `workerID`) VALUES
(1001, '2026-07-01', '08:00:00', '17:00:00', NULL, 2001, NULL, NULL, NULL),
(1002, '2026-07-01', '07:45:00', '16:45:00', 3001, NULL, NULL, NULL, NULL),
(1003, '2026-07-01', '08:00:00', '17:00:00', NULL, NULL, 4001, NULL, NULL),
(1004, '2026-07-01', '06:00:00', '19:00:00', NULL, NULL, NULL, 5001, NULL),
(1005, '2026-07-01', '07:30:00', '16:30:00', NULL, NULL, NULL, NULL, 6001);

-- ------------------------------------------
-- 21. INSERT INTO expense_tbl
-- ------------------------------------------
INSERT INTO `expense_tbl` (`expenseID`, `type`, `amount`, `materialID`, `accountantID`) VALUES
(14001, 'Stock', 400000.00, 8001, 2001),
(14002, 'Utility', 15000.00, NULL, 2001),
(14003, 'Salary', 195000.00, NULL, 2001);

-- ------------------------------------------
-- 22. INSERT INTO payment_tbl
-- ------------------------------------------
INSERT INTO `payment_tbl` (`paymentID`, `date`, `amount`, `chequenum`, `orderID`) VALUES
(15001, '2026-07-03', 420000.00, 'CHQ9920114', 12001),
(15002, '2026-07-07', 380000.00, 'CHQ5562130', 12002);

-- ------------------------------------------
-- 23. INSERT INTO salary_tbl
-- ------------------------------------------
INSERT INTO `salary_tbl` (`salaryID`, `paydate`, `totamtpaid`, `attendanceID`, `accountantID`) VALUES
(16001, '2026-07-15', 45000.00, 1004, 2001);

-- ------------------------------------------
-- 24. INSERT INTO expensereport_tbl
-- ------------------------------------------
INSERT INTO `expensereport_tbl` (`expenserepID`, `genaratedAt`, `month`) VALUES
(17001, '2026-07-15 18:00:00', '2026-07');

-- ------------------------------------------
-- 25. INSERT INTO salesreport_tbl
-- ------------------------------------------
INSERT INTO `salesreport_tbl` (`salesrepID`, `genaratedAt`, `month`) VALUES
(18001, '2026-07-15 18:00:00', '2026-07');

-- ------------------------------------------
-- 26. INSERT INTO profitreport_tbl
-- ------------------------------------------
INSERT INTO `profitreport_tbl` (`profitrepID`, `genaratedAt`, `month`, `ownerID`) VALUES
(19001, '2026-07-15 18:30:00', '2026-07', 1001);

-- ------------------------------------------
-- 27. INSERT INTO exp_report_tbl
-- ------------------------------------------
INSERT INTO `exp_report_tbl` (`expenseID`, `expenserepID`) VALUES
(14001, 17001),
(14002, 17001);

-- ------------------------------------------
-- 28. INSERT INTO payment_salesreport_tbl
-- ------------------------------------------
INSERT INTO `payment_salesreport_tbl` (`paymentID`, `salesrepID`) VALUES
(15001, 18001),
(15002, 18001);

-- ====================================================================
-- THARU PRODUCTS DATABASE - CORE MONTHLY TRANSACTIONS (JULY 2026)
-- ====================================================================

-- --------------------------------------------------------------------
-- 1. order_tbl RECORDS
-- --------------------------------------------------------------------
-- Capturing standard, bulk, and minor orders across the entire month
INSERT INTO `order_tbl` (`orderID`, `date`, `totamt`, `delivered`, `cancelled`, `customerID`, `orderhistoryID`, `salessupID`, `driverID`) VALUES
(12004, '2026-07-05', 420000.00, 1, 0, 10001, 501, 4001, 5001), -- Delmo Bulk
(12005, '2026-07-07', 380000.00, 1, 0, 10002, 502, 4001, 5002), -- Maxies Bulk
(12006, '2026-07-10', 70000.00,  1, 0, 10003, NULL, 4001, 5003), -- Ruwan Standard
(12007, '2026-07-14', 420000.00, 1, 0, 10001, 501, 4001, 5001), -- Delmo Bulk
(12008, '2026-07-18', 380000.00, 1, 0, 10002, 502, 4001, 5002), -- Maxies Bulk
(12009, '2026-07-20', 35000.00,  1, 0, 10003, NULL, 4001, 5003), -- Ruwan Minor
(12010, '2026-07-24', 420000.00, 1, 0, 10001, 501, 4001, 5001), -- Delmo Bulk
(12011, '2026-07-26', 380000.00, 1, 0, 10002, 502, 4001, 5002), -- Maxies Bulk
(12012, '2026-07-29', 420000.00, 0, 0, 10001, 501, 4001, 5001), -- Delmo Pending
(12013, '2026-07-30', 35000.00,  1, 0, 10003, NULL, 4001, 5003); -- Ruwan Minor

-- --------------------------------------------------------------------
-- 2. attendance_tbl RECORDS
-- --------------------------------------------------------------------
-- Weekly operational shift logging for payroll tracking
INSERT INTO `attendance_tbl` (`attendanceID`, `date`, `login`, `logout`, `stocksupID`, `accountantID`, `salessupID`, `driverID`, `workerID`) VALUES
-- Week 2 Shifts
(1006, '2026-07-08', '08:00:00', '16:00:00', NULL, 2001, NULL, NULL, NULL),
(1007, '2026-07-08', '07:45:00', '16:45:00', 3001, NULL, NULL, NULL, NULL),
(1008, '2026-07-08', '08:00:00', '16:00:00', NULL, NULL, 4001, NULL, NULL),
(1009, '2026-07-08', '06:00:00', '18:00:00', NULL, NULL, NULL, 5001, NULL),
(1010, '2026-07-08', '07:30:00', '16:30:00', NULL, NULL, NULL, NULL, 6001),
-- Week 3 Shifts
(1011, '2026-07-15', '08:00:00', '16:00:00', NULL, 2001, NULL, NULL, NULL),
(1012, '2026-07-15', '07:50:00', '16:10:00', 3001, NULL, NULL, NULL, NULL),
(1013, '2026-07-15', '08:00:00', '16:00:00', NULL, NULL, 4001, NULL, NULL),
(1014, '2026-07-15', '06:15:00', '18:45:00', NULL, NULL, NULL, 5002, NULL),
(1015, '2026-07-15', '07:30:00', '19:30:00', NULL, NULL, NULL, NULL, 6001),
-- Week 4 Shifts
(1016, '2026-07-22', '08:00:00', '16:00:00', NULL, 2001, NULL, NULL, NULL),
(1017, '2026-07-22', '07:40:00', '16:45:00', 3001, NULL, NULL, NULL, NULL),
(1018, '2026-07-22', '07:55:00', '17:15:00', NULL, NULL, 4001, NULL, NULL),
(1019, '2026-07-22', '06:00:00', '18:00:00', NULL, NULL, NULL, 5003, NULL),
(1020, '2026-07-22', '07:30:00', '16:30:00', NULL, NULL, NULL, NULL, 6001),
-- Week 5 Shifts
(1021, '2026-07-29', '08:00:00', '17:00:00', NULL, 2001, NULL, NULL, NULL),
(1022, '2026-07-29', '07:45:00', '16:45:00', 3001, NULL, NULL, NULL, NULL),
(1023, '2026-07-29', '08:00:00', '17:00:00', NULL, NULL, 4001, NULL, NULL),
(1024, '2026-07-29', '05:30:00', '19:00:00', NULL, NULL, NULL, 5001, NULL),
(1025, '2026-07-29', '07:30:00', '18:00:00', NULL, NULL, NULL, NULL, 6001);

-- --------------------------------------------------------------------
-- 3. expense_tbl RECORDS
-- --------------------------------------------------------------------
-- Tracking operational costs, factory utilities, and raw material expenses
INSERT INTO `expense_tbl` (`expenseID`, `type`, `amount`, `materialID`, `accountantID`) VALUES
(14004, 'Stock',   285000.00, 8002, 2001), -- Broken Rice Stock
(14005, 'Stock',   440000.00, 8003, 2001), -- Maize Meal Stock
(14006, 'Stock',   420000.00, 8004, 2001), -- Soya Bean Meal Stock
(14007, 'Utility', 18400.00,  NULL, 2001), -- Commercial Water Bill
(14008, 'Utility', 12500.00,  NULL, 2001), -- Maintenance Costs
(14009, 'Utility', 9000.00,   NULL, 2001), -- Internet & Comm Lines
(14010, 'Salary',  195000.00, NULL, 2001); -- General Payroll Entry

-- ====================================================================
-- END OF SCRIPT
-- ====================================================================
