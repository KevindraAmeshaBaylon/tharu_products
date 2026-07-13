-- =====================================================================
--  Tharu & Products — Sales Management System
--  MySQL schema generated from "Relational Schema Tharu & Products"
--  Engine: InnoDB | Charset: utf8mb4
-- =====================================================================
--
--  DESIGN NOTES / DEVIATIONS FROM THE RAW DIAGRAM
--  (read this before you show it to your supervisor — you should be
--   able to explain every one of these choices)
--
--  1) password columns are VARCHAR(255) — that's the standard length
--     for a bcrypt/argon2 hash. NEVER store plaintext passwords.
--     Your app layer should hash on signup/login (e.g. PHP password_hash()).
--
--  2) Every boolean-looking flag (pending, processed, delivered,
--     cancelled, answered, incoming, outgoing, cleared, paid,
--     inproduction, completed, dispatched, instock, outofstock) is
--     TINYINT(1), kept as SEPARATE columns exactly as drawn (you asked
--     to keep them this way rather than collapsing into ENUM status).
--
--  3) Attendance_tbl in the diagram shows a composite PK of
--     (salaryID, attendanceID). MySQL can't AUTO_INCREMENT a column
--     that isn't the leading column of its own key when mixed into a
--     composite PK like that in a clean way, and semantically one
--     attendance record doesn't need a salary record to exist (only
--     Accountant/Owner attendance ties into Salary_tbl). So:
--       -> attendanceID is the sole PRIMARY KEY (AUTO_INCREMENT)
--       -> salaryID is a normal NULLABLE foreign key column
--     This preserves every relationship in the diagram, just with a
--     cleaner key structure.
--
--  4) Similarly, OrderDetails_tbl, SalesReport_tbl, and
--     ExpenseReport_tbl show a composite PK of (parentID, ownID).
--     I made the child's own ID (detailID / salesrepID / exprepID)
--     the sole AUTO_INCREMENT PRIMARY KEY, and the parent ID
--     (orderID / salesID / expenseID) a plain FK column. Same reason:
--     MySQL AUTO_INCREMENT needs to be the leading column of its index.
--
--  5) Subtype tables (Accountant_tbl, Owner_tbl, StockSupervisor_tbl,
--     Worker_tbl, SalesSupervisor_tbl, Driver_tbl, Customer_tbl) use
--     userID as PRIMARY KEY + FK to User_tbl (classic 1-parent-table-
--     per-role / table-per-subtype pattern). Their own business IDs
--     (acountantID, ownerID, stockSupID, workerID, saleSupID,
--     driverID, customerID) are kept as separate UNIQUE AUTO_INCREMENT
--     columns because other tables (Expense_tbl, Order_tbl, etc.)
--     reference THOSE ids, not userID directly — exactly as drawn.
--
--  6) Accountant_tbl <-> Attendance_tbl <-> Salary_tbl form a genuine
--     circular reference (Accountant needs an attendanceID, Attendance
--     needs a salaryID, Salary needs an accountantID). All three
--     tables are created first with NO foreign keys, then ALL foreign
--     keys for the whole schema are added afterwards in one block.
--     That's why table creation and constraint creation are split into
--     two separate parts below — it's the standard way to handle
--     circular references in SQL.
--
--  7) Product_tbl's chickenfeed/pigfeed/cowfeed columns are treated as
--     boolean "this product belongs to this feed category" flags,
--     while the same-named columns in ProductionBatch_tbl are treated
--     as DECIMAL quantities produced in that batch (they're the same
--     column names in the diagram but clearly mean different things
--     in each context).
--
-- =====================================================================

DROP DATABASE IF EXISTS tharu_products_db;
CREATE DATABASE tharu_products_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE tharu_products_db;

SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================================
--  PART 1 — TABLE CREATION (no foreign keys yet)
-- =====================================================================

-- ---------------------------------------------------------------
-- Supplier & raw materials & production chain
-- ---------------------------------------------------------------
CREATE TABLE Supplier_tbl (
    supplierID    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    companyname   VARCHAR(100) NOT NULL,
    contact       VARCHAR(20),
    email         VARCHAR(100),
    bankdetails   VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE RawMaterial_tbl (
    materialID    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    quantity      DECIMAL(10,2) DEFAULT 0,
    unit          VARCHAR(20),
    buyingprice   DECIMAL(10,2),
    supplierID    INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ProductionBatch_tbl (
    batchID       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    date          DATE,
    chickenfeed   DECIMAL(10,2) DEFAULT 0 COMMENT 'quantity produced in this batch',
    pigfeed       DECIMAL(10,2) DEFAULT 0,
    cowfeed       DECIMAL(10,2) DEFAULT 0,
    outputqty     DECIMAL(10,2) DEFAULT 0,
    inproduction  TINYINT(1) DEFAULT 0,
    completed     TINYINT(1) DEFAULT 0,
    dispatched    TINYINT(1) DEFAULT 0,
    materialID    INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE Product_tbl (
    productID     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    chickenfeed   TINYINT(1) DEFAULT 0 COMMENT 'category flag: is this a chicken feed product',
    pigfeed       TINYINT(1) DEFAULT 0,
    cowfeed       TINYINT(1) DEFAULT 0,
    unitprice     DECIMAL(10,2),
    description   TEXT,
    batchID       INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE Stock_tbl (
    stockID         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    qtyavailable    DECIMAL(10,2) DEFAULT 0,
    unitprice       DECIMAL(10,2),
    beginingstock   DECIMAL(10,2) DEFAULT 0,
    weeklypurchase  DECIMAL(10,2) DEFAULT 0,
    weeklysales     DECIMAL(10,2) DEFAULT 0,
    closingstock    DECIMAL(10,2) DEFAULT 0,
    productID       INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE PublicCatalog_tbl (
    publicCatalogID  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    productname      VARCHAR(100),
    producttype      VARCHAR(50),
    unitprice        DECIMAL(10,2),
    instock          TINYINT(1) DEFAULT 1,
    outofstock       TINYINT(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Scheduling helper tables
-- ---------------------------------------------------------------
CREATE TABLE ProductionSchedule_tbl (
    productionID      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    productionstatus  VARCHAR(50)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE DeliverySchedule_tbl (
    deliveryID      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    deliverystatus  VARCHAR(50)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Circular trio: Attendance <-> Salary <-> Accountant
-- (created here with plain nullable FK columns; constraints added later)
-- ---------------------------------------------------------------
CREATE TABLE Attendance_tbl (
    attendanceID  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salaryID      INT UNSIGNED NULL,
    date          DATE,
    leavetype     VARCHAR(50),
    login         TIME,
    logout        TIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE Salary_tbl (
    salaryID     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    baseamt      DECIMAL(10,2),
    totamt       DECIMAL(10,2),
    January      DECIMAL(10,2) COMMENT 'as drawn on diagram',
    December     DECIMAL(10,2) COMMENT 'as drawn on diagram',
    OThrs        DECIMAL(5,2) DEFAULT 0,
    OTpayment    DECIMAL(10,2) DEFAULT 0,
    paid         TINYINT(1) DEFAULT 0,
    pending      TINYINT(1) DEFAULT 1,
    attendanceID INT UNSIGNED NULL,
    accountantID INT UNSIGNED NULL,
    ownerID      INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- User & role subtype tables
-- ---------------------------------------------------------------
CREATE TABLE User_tbl (
    userID           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username         VARCHAR(50) NOT NULL UNIQUE,
    password         VARCHAR(255) NOT NULL COMMENT 'bcrypt/argon2 hash — never plaintext',
    email            VARCHAR(100) UNIQUE,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    publicCatalogID  INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE Accountant_tbl (
    userID        INT UNSIGNED PRIMARY KEY,
    acountantID   INT UNSIGNED UNIQUE AUTO_INCREMENT,
    name          VARCHAR(100),
    monthly_sal   DECIMAL(10,2),
    attendanceID  INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE Owner_tbl (
    userID   INT UNSIGNED PRIMARY KEY,
    ownerID  INT UNSIGNED UNIQUE AUTO_INCREMENT,
    name     VARCHAR(100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE StockSupervisor_tbl (
    userID        INT UNSIGNED PRIMARY KEY,
    stockSupID    INT UNSIGNED UNIQUE AUTO_INCREMENT,
    name          VARCHAR(100),
    attendanceID  INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE Worker_tbl (
    userID        INT UNSIGNED PRIMARY KEY,
    workerID      INT UNSIGNED UNIQUE AUTO_INCREMENT,
    name          VARCHAR(100),
    attendanceID  INT UNSIGNED NULL,
    stockSupID    INT UNSIGNED NULL,
    productionID  INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE SalesSupervisor_tbl (
    userID        INT UNSIGNED PRIMARY KEY,
    saleSupID     INT UNSIGNED UNIQUE AUTO_INCREMENT,
    name          VARCHAR(100),
    attendanceID  INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE Driver_tbl (
    userID        INT UNSIGNED PRIMARY KEY,
    driverID      INT UNSIGNED UNIQUE AUTO_INCREMENT,
    name          VARCHAR(100),
    attendanceID  INT UNSIGNED NULL,
    salesSupID    INT UNSIGNED NULL,
    deliveryID    INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE Customer_tbl (
    userID       INT UNSIGNED PRIMARY KEY,
    customerID   INT UNSIGNED UNIQUE AUTO_INCREMENT,
    companyname  VARCHAR(100),
    contact      VARCHAR(20),
    address      VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Customer-facing / sales / orders
-- ---------------------------------------------------------------
CREATE TABLE Inquiry_tbl (
    inquiryID   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message     TEXT,
    response    TEXT,
    pending     TINYINT(1) DEFAULT 1,
    answered    TINYINT(1) DEFAULT 0,
    customerID  INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE Payment_tbl (
    paymentID  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    date       DATE,
    amount     DECIMAL(10,2),
    checknum   VARCHAR(50),
    incoming   TINYINT(1) DEFAULT 0,
    outgoing   TINYINT(1) DEFAULT 0,
    pending    TINYINT(1) DEFAULT 1,
    cleared    TINYINT(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE Order_tbl (
    orderID     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    date        DATE,
    totamt      DECIMAL(10,2),
    pending     TINYINT(1) DEFAULT 1,
    processed   TINYINT(1) DEFAULT 0,
    delivered   TINYINT(1) DEFAULT 0,
    cancelled   TINYINT(1) DEFAULT 0,
    paymentID   INT UNSIGNED NULL,
    customerID  INT UNSIGNED NULL,
    salesSupID  INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE OrderDetails_tbl (
    detailID          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    orderID           INT UNSIGNED NOT NULL,
    quantity          INT DEFAULT 1,
    unitSellingPrice  DECIMAL(10,2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE Expense_tbl (
    expenseID     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type          VARCHAR(50),
    amount        DECIMAL(10,2),
    accountantID  INT UNSIGNED NULL,
    stockSupID    INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE Sales_tbl (
    salesID       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dateofsales   DATE,
    dailyincome   DECIMAL(10,2),
    salesSupID    INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE WageRecord_tbl (
    wageID      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    totwage     DECIMAL(10,2),
    dailyrate   DECIMAL(10,2),
    workinghrs  DECIMAL(5,2),
    wagestatus  VARCHAR(50),
    salaryID      INT UNSIGNED NULL,
    accountantID  INT UNSIGNED NULL,
    driverID      INT UNSIGNED NULL,
    workerID      INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Guests / registration / agreements / reports
-- ---------------------------------------------------------------
CREATE TABLE Guest_tbl (
    guestID    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    visitdate  DATE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE Registration_tbl (
    registerID   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username     VARCHAR(50),
    password     VARCHAR(255) COMMENT 'bcrypt/argon2 hash — never plaintext',
    companyname  VARCHAR(100),
    contact      VARCHAR(20),
    email        VARCHAR(100),
    address      VARCHAR(255),
    guestID      INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE Agreement_tbl (
    agreementID  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type         VARCHAR(50),
    filepath     VARCHAR(255),
    enddate      DATE,
    startdate    DATE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE SalesReport_tbl (
    salesrepID     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salesID        INT UNSIGNED NOT NULL,
    month          VARCHAR(20),
    generateddate  DATE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ExpenseReport_tbl (
    exprepID       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    expenseID      INT UNSIGNED NOT NULL,
    month          VARCHAR(20),
    generateddate  DATE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE GuestViewPublicCattalog_tbl (
    guestID          INT UNSIGNED NOT NULL,
    publicCatalogID  INT UNSIGNED NOT NULL,
    PRIMARY KEY (guestID, publicCatalogID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Per-role contact extension tables (1:1 with each subtype)
-- ---------------------------------------------------------------
CREATE TABLE AccountantContact_tbl (
    accountantID  INT UNSIGNED PRIMARY KEY,
    contact       VARCHAR(20)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE OwnerContact_tbl (
    ownerID  INT UNSIGNED PRIMARY KEY,
    contact  VARCHAR(20)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE StockSupContact_tbl (
    stockSupID  INT UNSIGNED PRIMARY KEY,
    contact     VARCHAR(20)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE WorkerContact_tbl (
    workerID  INT UNSIGNED PRIMARY KEY,
    contact   VARCHAR(20)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE SalesSupContact_tbl (
    saleSupID  INT UNSIGNED PRIMARY KEY,
    contact    VARCHAR(20)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE DriverContact_tbl (
    driverID  INT UNSIGNED PRIMARY KEY,
    contact   VARCHAR(20)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Owner relationship / junction tables
-- ---------------------------------------------------------------
CREATE TABLE OwnerAssignStockSupervisor_tbl (
    ownerID     INT UNSIGNED NOT NULL,
    stockSupID  INT UNSIGNED NOT NULL,
    supplierID  INT UNSIGNED NOT NULL,
    PRIMARY KEY (ownerID, stockSupID, supplierID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE OwnerCalculatesAccountantSalary_tbl (
    ownerID       INT UNSIGNED NOT NULL,
    accountantID  INT UNSIGNED NOT NULL,
    salaryID      INT UNSIGNED NOT NULL,
    PRIMARY KEY (ownerID, accountantID, salaryID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE OwnerMaintainsAgreementwithCustomer_tbl (
    ownerID      INT UNSIGNED NOT NULL,
    customerID   INT UNSIGNED NOT NULL,
    agreementID  INT UNSIGNED NOT NULL,
    PRIMARY KEY (ownerID, customerID, agreementID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE OwnerMaintainsAgreementwithSupplier_tbl (
    ownerID      INT UNSIGNED NOT NULL,
    supplierID   INT UNSIGNED NOT NULL,
    agreementID  INT UNSIGNED NOT NULL,
    PRIMARY KEY (ownerID, supplierID, agreementID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =====================================================================
--  PART 2 — FOREIGN KEY CONSTRAINTS (added after every table exists,
--  so circular references between tables are never a problem)
-- =====================================================================

ALTER TABLE RawMaterial_tbl
  ADD CONSTRAINT fk_rawmaterial_supplier
  FOREIGN KEY (supplierID) REFERENCES Supplier_tbl(supplierID)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE ProductionBatch_tbl
  ADD CONSTRAINT fk_batch_material
  FOREIGN KEY (materialID) REFERENCES RawMaterial_tbl(materialID)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE Product_tbl
  ADD CONSTRAINT fk_product_batch
  FOREIGN KEY (batchID) REFERENCES ProductionBatch_tbl(batchID)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE Stock_tbl
  ADD CONSTRAINT fk_stock_product
  FOREIGN KEY (productID) REFERENCES Product_tbl(productID)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE User_tbl
  ADD CONSTRAINT fk_user_publiccatalog
  FOREIGN KEY (publicCatalogID) REFERENCES PublicCatalog_tbl(publicCatalogID)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE Salary_tbl
  ADD CONSTRAINT fk_salary_attendance
  FOREIGN KEY (attendanceID) REFERENCES Attendance_tbl(attendanceID)
  ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_salary_accountant
  FOREIGN KEY (accountantID) REFERENCES Accountant_tbl(acountantID)
  ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_salary_owner
  FOREIGN KEY (ownerID) REFERENCES Owner_tbl(ownerID)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE Attendance_tbl
  ADD CONSTRAINT fk_attendance_salary
  FOREIGN KEY (salaryID) REFERENCES Salary_tbl(salaryID)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE Accountant_tbl
  ADD CONSTRAINT fk_accountant_user
  FOREIGN KEY (userID) REFERENCES User_tbl(userID)
  ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_accountant_attendance
  FOREIGN KEY (attendanceID) REFERENCES Attendance_tbl(attendanceID)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE Owner_tbl
  ADD CONSTRAINT fk_owner_user
  FOREIGN KEY (userID) REFERENCES User_tbl(userID)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE StockSupervisor_tbl
  ADD CONSTRAINT fk_stocksup_user
  FOREIGN KEY (userID) REFERENCES User_tbl(userID)
  ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_stocksup_attendance
  FOREIGN KEY (attendanceID) REFERENCES Attendance_tbl(attendanceID)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE Worker_tbl
  ADD CONSTRAINT fk_worker_user
  FOREIGN KEY (userID) REFERENCES User_tbl(userID)
  ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_worker_attendance
  FOREIGN KEY (attendanceID) REFERENCES Attendance_tbl(attendanceID)
  ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_worker_stocksup
  FOREIGN KEY (stockSupID) REFERENCES StockSupervisor_tbl(stockSupID)
  ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_worker_production
  FOREIGN KEY (productionID) REFERENCES ProductionSchedule_tbl(productionID)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE SalesSupervisor_tbl
  ADD CONSTRAINT fk_salessup_user
  FOREIGN KEY (userID) REFERENCES User_tbl(userID)
  ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_salessup_attendance
  FOREIGN KEY (attendanceID) REFERENCES Attendance_tbl(attendanceID)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE Driver_tbl
  ADD CONSTRAINT fk_driver_user
  FOREIGN KEY (userID) REFERENCES User_tbl(userID)
  ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_driver_attendance
  FOREIGN KEY (attendanceID) REFERENCES Attendance_tbl(attendanceID)
  ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_driver_salessup
  FOREIGN KEY (salesSupID) REFERENCES SalesSupervisor_tbl(saleSupID)
  ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_driver_delivery
  FOREIGN KEY (deliveryID) REFERENCES DeliverySchedule_tbl(deliveryID)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE Customer_tbl
  ADD CONSTRAINT fk_customer_user
  FOREIGN KEY (userID) REFERENCES User_tbl(userID)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE Inquiry_tbl
  ADD CONSTRAINT fk_inquiry_customer
  FOREIGN KEY (customerID) REFERENCES Customer_tbl(customerID)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE Order_tbl
  ADD CONSTRAINT fk_order_payment
  FOREIGN KEY (paymentID) REFERENCES Payment_tbl(paymentID)
  ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_order_customer
  FOREIGN KEY (customerID) REFERENCES Customer_tbl(customerID)
  ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_order_salessup
  FOREIGN KEY (salesSupID) REFERENCES SalesSupervisor_tbl(saleSupID)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE OrderDetails_tbl
  ADD CONSTRAINT fk_orderdetails_order
  FOREIGN KEY (orderID) REFERENCES Order_tbl(orderID)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE Expense_tbl
  ADD CONSTRAINT fk_expense_accountant
  FOREIGN KEY (accountantID) REFERENCES Accountant_tbl(acountantID)
  ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_expense_stocksup
  FOREIGN KEY (stockSupID) REFERENCES StockSupervisor_tbl(stockSupID)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE Sales_tbl
  ADD CONSTRAINT fk_sales_salessup
  FOREIGN KEY (salesSupID) REFERENCES SalesSupervisor_tbl(saleSupID)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE WageRecord_tbl
  ADD CONSTRAINT fk_wage_salary
  FOREIGN KEY (salaryID) REFERENCES Salary_tbl(salaryID)
  ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_wage_accountant
  FOREIGN KEY (accountantID) REFERENCES Accountant_tbl(acountantID)
  ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_wage_driver
  FOREIGN KEY (driverID) REFERENCES Driver_tbl(driverID)
  ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_wage_worker
  FOREIGN KEY (workerID) REFERENCES Worker_tbl(workerID)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE Registration_tbl
  ADD CONSTRAINT fk_registration_guest
  FOREIGN KEY (guestID) REFERENCES Guest_tbl(guestID)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE SalesReport_tbl
  ADD CONSTRAINT fk_salesreport_sales
  FOREIGN KEY (salesID) REFERENCES Sales_tbl(salesID)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE ExpenseReport_tbl
  ADD CONSTRAINT fk_expensereport_expense
  FOREIGN KEY (expenseID) REFERENCES Expense_tbl(expenseID)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE GuestViewPublicCattalog_tbl
  ADD CONSTRAINT fk_guestview_guest
  FOREIGN KEY (guestID) REFERENCES Guest_tbl(guestID)
  ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_guestview_catalog
  FOREIGN KEY (publicCatalogID) REFERENCES PublicCatalog_tbl(publicCatalogID)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE AccountantContact_tbl
  ADD CONSTRAINT fk_accountantcontact
  FOREIGN KEY (accountantID) REFERENCES Accountant_tbl(acountantID)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE OwnerContact_tbl
  ADD CONSTRAINT fk_ownercontact
  FOREIGN KEY (ownerID) REFERENCES Owner_tbl(ownerID)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE StockSupContact_tbl
  ADD CONSTRAINT fk_stocksupcontact
  FOREIGN KEY (stockSupID) REFERENCES StockSupervisor_tbl(stockSupID)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE WorkerContact_tbl
  ADD CONSTRAINT fk_workercontact
  FOREIGN KEY (workerID) REFERENCES Worker_tbl(workerID)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE SalesSupContact_tbl
  ADD CONSTRAINT fk_salessupcontact
  FOREIGN KEY (saleSupID) REFERENCES SalesSupervisor_tbl(saleSupID)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE DriverContact_tbl
  ADD CONSTRAINT fk_drivercontact
  FOREIGN KEY (driverID) REFERENCES Driver_tbl(driverID)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE OwnerAssignStockSupervisor_tbl
  ADD CONSTRAINT fk_oass_owner
  FOREIGN KEY (ownerID) REFERENCES Owner_tbl(ownerID)
  ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_oass_stocksup
  FOREIGN KEY (stockSupID) REFERENCES StockSupervisor_tbl(stockSupID)
  ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_oass_supplier
  FOREIGN KEY (supplierID) REFERENCES Supplier_tbl(supplierID)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE OwnerCalculatesAccountantSalary_tbl
  ADD CONSTRAINT fk_ocas_owner
  FOREIGN KEY (ownerID) REFERENCES Owner_tbl(ownerID)
  ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_ocas_accountant
  FOREIGN KEY (accountantID) REFERENCES Accountant_tbl(acountantID)
  ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_ocas_salary
  FOREIGN KEY (salaryID) REFERENCES Salary_tbl(salaryID)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE OwnerMaintainsAgreementwithCustomer_tbl
  ADD CONSTRAINT fk_omac_owner
  FOREIGN KEY (ownerID) REFERENCES Owner_tbl(ownerID)
  ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_omac_customer
  FOREIGN KEY (customerID) REFERENCES Customer_tbl(customerID)
  ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_omac_agreement
  FOREIGN KEY (agreementID) REFERENCES Agreement_tbl(agreementID)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE OwnerMaintainsAgreementwithSupplier_tbl
  ADD CONSTRAINT fk_omas_owner
  FOREIGN KEY (ownerID) REFERENCES Owner_tbl(ownerID)
  ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_omas_supplier
  FOREIGN KEY (supplierID) REFERENCES Supplier_tbl(supplierID)
  ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_omas_agreement
  FOREIGN KEY (agreementID) REFERENCES Agreement_tbl(agreementID)
  ON DELETE CASCADE ON UPDATE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;


-- =====================================================================
--  PART 3 — SAMPLE SEED DATA
--  Passwords below are already-hashed placeholders (bcrypt-style
--  strings) — NOT real hashes of the word "password". Replace with
--  real password_hash() output from your app in production.
-- =====================================================================

-- Suppliers & production chain
INSERT INTO Supplier_tbl (companyname, contact, email, bankdetails) VALUES
('Lanka Grain Suppliers', '0112233445', 'sales@lankagrain.lk', 'BOC-0011223344'),
('Green Valley Feeds Ltd', '0117766554', 'info@greenvalley.lk', 'HNB-0099887766');

INSERT INTO RawMaterial_tbl (name, quantity, unit, buyingprice, supplierID) VALUES
('Maize', 5000.00, 'kg', 85.00, 1),
('Soya Meal', 3000.00, 'kg', 130.00, 1),
('Fish Meal', 1500.00, 'kg', 210.00, 2);

INSERT INTO ProductionBatch_tbl (date, chickenfeed, pigfeed, cowfeed, outputqty, inproduction, completed, dispatched, materialID) VALUES
('2026-06-01', 500.00, 200.00, 0.00, 700.00, 0, 1, 1, 1),
('2026-06-15', 300.00, 0.00, 400.00, 700.00, 1, 0, 0, 2);

INSERT INTO Product_tbl (name, chickenfeed, pigfeed, cowfeed, unitprice, description, batchID) VALUES
('Layer Mash 25kg', 1, 0, 0, 2450.00, 'High-protein layer feed for poultry', 1),
('Grower Pig Feed 25kg', 0, 1, 0, 2650.00, 'Balanced grower feed for pigs', 1),
('Dairy Cow Feed 25kg', 0, 0, 1, 2300.00, 'Nutrient-rich feed for dairy cattle', 2);

INSERT INTO Stock_tbl (qtyavailable, unitprice, beginingstock, weeklypurchase, weeklysales, closingstock, productID) VALUES
(150.00, 2450.00, 100.00, 80.00, 30.00, 150.00, 1),
(90.00, 2650.00, 60.00, 50.00, 20.00, 90.00, 2),
(120.00, 2300.00, 100.00, 40.00, 20.00, 120.00, 3);

-- Public catalog (guest-visible, independent of internal Product_tbl)
INSERT INTO PublicCatalog_tbl (productname, producttype, unitprice, instock, outofstock) VALUES
('Layer Mash 25kg', 'Poultry Feed', 2450.00, 1, 0),
('Grower Pig Feed 25kg', 'Pig Feed', 2650.00, 1, 0),
('Dairy Cow Feed 25kg', 'Cattle Feed', 2300.00, 0, 1);

-- Scheduling helpers
INSERT INTO ProductionSchedule_tbl (productionstatus) VALUES
('Scheduled'), ('In Progress'), ('Completed');

INSERT INTO DeliverySchedule_tbl (deliverystatus) VALUES
('Pending'), ('Out for Delivery'), ('Delivered');

-- Agreements
INSERT INTO Agreement_tbl (type, filepath, enddate, startdate) VALUES
('Customer Supply Agreement', '/docs/agreements/cust_001.pdf', '2027-01-01', '2026-01-01'),
('Supplier Purchase Agreement', '/docs/agreements/supp_001.pdf', '2027-06-01', '2026-06-01');

-- Payments
INSERT INTO Payment_tbl (date, amount, checknum, incoming, outgoing, pending, cleared) VALUES
('2026-06-10', 24500.00, 'CHK1001', 1, 0, 0, 1),
('2026-06-20', 13250.00, 'CHK1002', 1, 0, 1, 0);

-- Users (one per role, hashed placeholder passwords)
INSERT INTO User_tbl (username, password, email, publicCatalogID) VALUES
('owner01',      '$2y$10$examplehashplaceholder0000000000000000000000000000001', 'owner01@tharu.lk', NULL),
('accountant01', '$2y$10$examplehashplaceholder0000000000000000000000000000002', 'acc01@tharu.lk', NULL),
('stocksup01',   '$2y$10$examplehashplaceholder0000000000000000000000000000003', 'stock01@tharu.lk', NULL),
('worker01',     '$2y$10$examplehashplaceholder0000000000000000000000000000004', 'worker01@tharu.lk', NULL),
('salessup01',   '$2y$10$examplehashplaceholder0000000000000000000000000000005', 'salessup01@tharu.lk', NULL),
('driver01',     '$2y$10$examplehashplaceholder0000000000000000000000000000006', 'driver01@tharu.lk', NULL),
('customer01',   '$2y$10$examplehashplaceholder0000000000000000000000000000007', 'cust01@abccompany.lk', 1);

-- Owner (no cycle, straightforward)
INSERT INTO Owner_tbl (userID, name) VALUES (1, 'S. Perera');

-- Circular trio: Accountant -> Attendance -> Salary -> Accountant
-- Step 1: accountant row with attendanceID left NULL for now
INSERT INTO Accountant_tbl (userID, name, monthly_sal, attendanceID) VALUES
(2, 'N. Fernando', 85000.00, NULL);

-- Step 2: attendance row for that accountant, salaryID left NULL for now
INSERT INTO Attendance_tbl (salaryID, date, leavetype, login, logout) VALUES
(NULL, '2026-07-01', NULL, '08:00:00', '17:00:00');

-- Step 3: salary row referencing both accountant and its attendance record, and the owner
INSERT INTO Salary_tbl (baseamt, totamt, January, December, OThrs, OTpayment, paid, pending, attendanceID, accountantID, ownerID) VALUES
(85000.00, 90000.00, NULL, NULL, 5.00, 5000.00, 1, 0, 1, 1, 1);

-- Step 4: close the loop
UPDATE Attendance_tbl SET salaryID = 1 WHERE attendanceID = 1;
UPDATE Accountant_tbl SET attendanceID = 1 WHERE acountantID = 1;

-- More attendance rows (no salary linkage needed for these roles)
INSERT INTO Attendance_tbl (salaryID, date, leavetype, login, logout) VALUES
(NULL, '2026-07-01', NULL, '08:00:00', '17:00:00'),  -- attendanceID 2, for stock supervisor
(NULL, '2026-07-01', NULL, '07:30:00', '16:30:00'),  -- attendanceID 3, for worker
(NULL, '2026-07-01', NULL, '08:00:00', '17:00:00'),  -- attendanceID 4, for sales supervisor
(NULL, '2026-07-01', 'Half Day', '08:00:00', '12:00:00'); -- attendanceID 5, for driver

-- Remaining role subtypes
INSERT INTO StockSupervisor_tbl (userID, name, attendanceID) VALUES
(3, 'K. Silva', 2);

INSERT INTO Worker_tbl (userID, name, attendanceID, stockSupID, productionID) VALUES
(4, 'A. Bandara', 3, 1, 1);

INSERT INTO SalesSupervisor_tbl (userID, name, attendanceID) VALUES
(5, 'R. Jayawardena', 4);

INSERT INTO Driver_tbl (userID, name, attendanceID, salesSupID, deliveryID) VALUES
(6, 'M. Weerasinghe', 5, 1, 1);

INSERT INTO Customer_tbl (userID, companyname, contact, address) VALUES
(7, 'ABC Poultry Farm', '0771234567', '123 Farm Road, Kandy');

-- Inquiries
INSERT INTO Inquiry_tbl (message, response, pending, answered, customerID) VALUES
('Do you deliver to Matale?', 'Yes, we deliver islandwide.', 0, 1, 1),
('Can I get a bulk discount on layer mash?', NULL, 1, 0, 1);

-- Orders
INSERT INTO Order_tbl (date, totamt, pending, processed, delivered, cancelled, paymentID, customerID, salesSupID) VALUES
('2026-06-10', 24500.00, 0, 1, 1, 0, 1, 1, 1),
('2026-06-20', 13250.00, 1, 0, 0, 0, 2, 1, 1);

INSERT INTO OrderDetails_tbl (orderID, quantity, unitSellingPrice) VALUES
(1, 10, 2450.00),
(2, 5, 2650.00);

-- Expenses & sales
INSERT INTO Expense_tbl (type, amount, accountantID, stockSupID) VALUES
('Raw Material Purchase', 45000.00, 1, 1),
('Utility Bill', 12000.00, 1, NULL);

INSERT INTO Sales_tbl (dateofsales, dailyincome, salesSupID) VALUES
('2026-06-10', 24500.00, 1),
('2026-06-20', 13250.00, 1);

-- Wage record for driver/worker (paid hourly, not via Salary_tbl)
INSERT INTO WageRecord_tbl (totwage, dailyrate, workinghrs, wagestatus, salaryID, accountantID, driverID, workerID) VALUES
(2500.00, 2500.00, 8.00, 'Paid', NULL, 1, 1, NULL),
(2000.00, 2000.00, 8.00, 'Pending', NULL, 1, NULL, 1);

-- Guests & registration
INSERT INTO Guest_tbl (visitdate) VALUES ('2026-07-05'), ('2026-07-08');

INSERT INTO Registration_tbl (username, password, companyname, contact, email, address, guestID) VALUES
('guestco1', '$2y$10$examplehashplaceholder0000000000000000000000000000008', 'XYZ Dairy', '0719988776', 'xyz@dairy.lk', '45 Lake Road, Gampola', 1);

-- Reports
INSERT INTO SalesReport_tbl (salesID, month, generateddate) VALUES
(1, 'June', '2026-07-01');

INSERT INTO ExpenseReport_tbl (expenseID, month, generateddate) VALUES
(1, 'June', '2026-07-01');

-- Guest views public catalog
INSERT INTO GuestViewPublicCattalog_tbl (guestID, publicCatalogID) VALUES
(1, 1), (1, 2), (2, 3);

-- Per-role contact extension tables
INSERT INTO AccountantContact_tbl (accountantID, contact) VALUES (1, '0711112222');
INSERT INTO OwnerContact_tbl (ownerID, contact) VALUES (1, '0713334444');
INSERT INTO StockSupContact_tbl (stockSupID, contact) VALUES (1, '0715556666');
INSERT INTO WorkerContact_tbl (workerID, contact) VALUES (1, '0717778888');
INSERT INTO SalesSupContact_tbl (saleSupID, contact) VALUES (1, '0719990000');
INSERT INTO DriverContact_tbl (driverID, contact) VALUES (1, '0721112222');

-- Owner relationship / junction tables
INSERT INTO OwnerAssignStockSupervisor_tbl (ownerID, stockSupID, supplierID) VALUES (1, 1, 1);
INSERT INTO OwnerCalculatesAccountantSalary_tbl (ownerID, accountantID, salaryID) VALUES (1, 1, 1);
INSERT INTO OwnerMaintainsAgreementwithCustomer_tbl (ownerID, customerID, agreementID) VALUES (1, 1, 1);
INSERT INTO OwnerMaintainsAgreementwithSupplier_tbl (ownerID, supplierID, agreementID) VALUES (1, 1, 2);

-- =====================================================================
--  END OF FILE
-- =====================================================================
