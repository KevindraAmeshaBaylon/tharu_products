<?php
session_start();
require_once __DIR__ . '/../model/config/database.php';

// Access Control Validation Strategy
if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'accountant' || !isset($_SESSION['accountant_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$conn = getDBConnection();
$accountantID = $_SESSION['accountant_id'];

// Canonical tab configuration and helpers
$dashboardTabs = ['attendanceTab', 'payroll', 'expenses', 'salaryLedger', 'expenseLedger', 'reports'];
$dashboardTabAliases = [
    'attendance' => 'attendanceTab',
    'attendance_tab' => 'attendanceTab',
    'expense' => 'expenses',
    'manual_expenses' => 'expenses',
    'expense_ledger' => 'expenseLedger',
    'salary_ledger' => 'salaryLedger'
];
$dashboardActionTabMap = [
    'create_attendance' => 'attendanceTab',
    'update_attendance' => 'attendanceTab',
    'delete_attendance' => 'attendanceTab',
    'calculate_salary' => 'payroll',
    'update_salary' => 'salaryLedger',
    'delete_salary' => 'salaryLedger',
    'log_expense' => 'expenses',
    'delete_expense' => 'expenseLedger',
    'generate_report' => 'reports',
    'export_report' => 'reports'
];

function normalizeDashboardTab($tabId, $validTabs, $aliases, $fallbackTab = 'attendanceTab') {
    if (!is_string($tabId) || $tabId === '') {
        return $fallbackTab;
    }

    $tabId = trim($tabId);
    if (in_array($tabId, $validTabs, true)) {
        return $tabId;
    }

    $aliasKey = strtolower($tabId);
    if (isset($aliases[$aliasKey])) {
        return $aliases[$aliasKey];
    }

    return $fallbackTab;
}

function dashboardSetFlash($type, $message, $tabId = null) {
    $_SESSION['dashboard_flash'] = [
        'type' => $type,
        'message' => $message,
        'tab_id' => $tabId
    ];
}

function dashboardConsumeFlash() {
    if (!isset($_SESSION['dashboard_flash']) || !is_array($_SESSION['dashboard_flash'])) {
        return null;
    }

    $flash = $_SESSION['dashboard_flash'];
    unset($_SESSION['dashboard_flash']);
    return $flash;
}

function dashboardRedirectWithFlash($type, $message, $tabId) {
    dashboardSetFlash($type, $message, $tabId);
    header('Location: acc_dashboard.php?tab_id=' . urlencode($tabId));
    exit;
}

$error_message = '';
$success_message = '';
$action = $_POST['action'] ?? '';
$requestedTab = $_GET['tab_id'] ?? ($_POST['tab_id'] ?? '');
$defaultTab = $dashboardActionTabMap[$action] ?? 'attendanceTab';
$active_tab = normalizeDashboardTab($requestedTab, $dashboardTabs, $dashboardTabAliases, $defaultTab);

if ($action !== '' && isset($dashboardActionTabMap[$action])) {
    $active_tab = $dashboardActionTabMap[$action];
}

$flash = dashboardConsumeFlash();
if (is_array($flash) && !empty($flash['message'])) {
    if (($flash['type'] ?? '') === 'success') {
        $success_message = $flash['message'];
    } else {
        $error_message = $flash['message'];
    }

    if (!empty($flash['tab_id'])) {
        $active_tab = normalizeDashboardTab((string)$flash['tab_id'], $dashboardTabs, $dashboardTabAliases, $active_tab);
    }
}

$report_generated = false;
$current_report_data = null;

// Check if a report is already in session
if (isset($_SESSION['report_data'])) {
    $report_generated = true;
    $current_report_data = $_SESSION['report_data'];
}

// ========================================================
// REPORT GENERATION HANDLER
// ========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_report') {
    $report_year = intval($_POST['report_year'] ?? 0);
    $report_month = intval($_POST['report_month'] ?? 0);
    
    if ($report_year <= 0 || $report_month <= 0 || $report_month > 12) {
        dashboardRedirectWithFlash('error', 'Please select valid year and month for the report.', 'reports');
    } else {
        $month_string = $report_year . '-' . str_pad($report_month, 2, '0', STR_PAD_LEFT);
        $month_start = $report_year . '-' . str_pad($report_month, 2, '0', STR_PAD_LEFT) . '-01';
        $month_end = date('Y-m-t', strtotime($month_start));
        
        try {
            $conn->begin_transaction();
            
            $reportData = [
                'month_display' => date('F Y', strtotime($month_start)),
                'month_string' => $month_string,
                'generated_date' => date('Y-m-d H:i:s'),
                'report_id' => 'EXP-' . date('Ymd') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT),
                'expenses' => [
                    'sales_supervisor_salary' => 0,
                    'stock_supervisor_salary' => 0,
                    'worker_wages' => 0,
                    'driver_wages' => 0,
                    'raw_materials' => 0,
                    'machine_maintenance' => 0,
                    'utility_bills' => 0
                ]
            ];
            
            // Query 1: Sales Supervisor Total Salary
            $stmt = $conn->prepare("SELECT COALESCE(SUM(s.totamtpaid), 0) as total FROM salary_tbl s INNER JOIN attendance_tbl a ON s.attendanceID = a.attendanceID INNER JOIN SalesSuperviser_tbl ss ON a.salessupID = ss.salessupID WHERE s.accountantID = ? AND YEAR(s.paydate) = ? AND MONTH(s.paydate) = ?");
            $stmt->bind_param('iii', $accountantID, $report_year, $report_month);
            $stmt->execute();
            $reportData['expenses']['sales_supervisor_salary'] = (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();
            
            // Query 2: Stock Supervisor Total Salary
            $stmt = $conn->prepare("SELECT COALESCE(SUM(s.totamtpaid), 0) as total FROM salary_tbl s INNER JOIN attendance_tbl a ON s.attendanceID = a.attendanceID INNER JOIN StockSuperviser_tbl st ON a.stocksupID = st.stocksupID WHERE s.accountantID = ? AND YEAR(s.paydate) = ? AND MONTH(s.paydate) = ?");
            $stmt->bind_param('iii', $accountantID, $report_year, $report_month);
            $stmt->execute();
            $reportData['expenses']['stock_supervisor_salary'] = (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();
            
            // Query 3: Worker Total Wages
            $stmt = $conn->prepare("SELECT COALESCE(SUM(s.totamtpaid), 0) as total FROM salary_tbl s INNER JOIN attendance_tbl a ON s.attendanceID = a.attendanceID INNER JOIN Worker_tbl w ON a.workerID = w.workerID WHERE s.accountantID = ? AND YEAR(s.paydate) = ? AND MONTH(s.paydate) = ?");
            $stmt->bind_param('iii', $accountantID, $report_year, $report_month);
            $stmt->execute();
            $reportData['expenses']['worker_wages'] = (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();
            
            // Query 4: Driver Total Wages
            $stmt = $conn->prepare("SELECT COALESCE(SUM(s.totamtpaid), 0) as total FROM salary_tbl s INNER JOIN attendance_tbl a ON s.attendanceID = a.attendanceID INNER JOIN Driver_tbl d ON a.driverID = d.driverID WHERE s.accountantID = ? AND YEAR(s.paydate) = ? AND MONTH(s.paydate) = ?");
            $stmt->bind_param('iii', $accountantID, $report_year, $report_month);
            $stmt->execute();
            $reportData['expenses']['driver_wages'] = (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();
            
            // Query 5: Raw Materials
            $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM expense_tbl WHERE accountantID = ? AND (type LIKE '%material%' OR type LIKE '%purchase%' OR type LIKE '%raw%')");
            $stmt->bind_param('i', $accountantID);
            $stmt->execute();
            $reportData['expenses']['raw_materials'] = (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();
            
            // Query 6: Machine Maintenance
            $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM expense_tbl WHERE accountantID = ? AND (type LIKE '%maintenance%' OR type LIKE '%repair%' OR type LIKE '%machine%')");
            $stmt->bind_param('i', $accountantID);
            $stmt->execute();
            $reportData['expenses']['machine_maintenance'] = (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();
            
            // Query 7: Utility Bills
            $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM expense_tbl WHERE accountantID = ? AND (type LIKE '%utility%' OR type LIKE '%electricity%' OR type LIKE '%water%' OR type LIKE '%bill%')");
            $stmt->bind_param('i', $accountantID);
            $stmt->execute();
            $reportData['expenses']['utility_bills'] = (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();
            
            // Insert report record
            $stmt = $conn->prepare("INSERT INTO ExpenseReport_tbl (month, genaratedAt) VALUES (?, NOW())");
            $stmt->bind_param('s', $month_string);
            $stmt->execute();
            $stmt->close();
            
            $conn->commit();
            
            $_SESSION['report_data'] = $reportData;
            dashboardRedirectWithFlash('success', 'Monthly expense report generated successfully for ' . $reportData['month_display'] . '.', 'reports');
            
        } catch (Exception $e) {
            $conn->rollback();
            dashboardRedirectWithFlash('error', 'Failed to generate report: ' . $e->getMessage(), 'reports');
        }
    }
}

// ========================================================
// EXPORT HANDLER
// ========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'export_report') {
    if (!isset($_SESSION['report_data']) || !isset($_POST['export_format'])) {
        header('HTTP/1.1 400 Bad Request');
        echo 'Invalid export request';
        exit;
    }
    
    $reportData = $_SESSION['report_data'];
    $format = $_POST['export_format'];
    $categories = [
        'sales_supervisor_salary' => 'Sales Supervisor Salaries',
        'stock_supervisor_salary' => 'Stock Supervisor Salaries',
        'worker_wages' => 'Worker Wages',
        'driver_wages' => 'Driver Wages',
        'raw_materials' => 'Raw Material Purchases',
        'machine_maintenance' => 'Machine Maintenance',
        'utility_bills' => 'Utility Bills'
    ];
    
    $total = array_sum($reportData['expenses']);
    $basefilename = 'THARU_EXPENSE_REPORT_' . str_replace('-', '', $reportData['month_string']);
    
    if ($format === 'excel') {
        // XLSX export
        $filename = $basefilename . '.xlsx';
        $worksheetData = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n<worksheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\"><sheetData>\n";
        $worksheetData .= "<row r=\"1\"><c r=\"A1\" t=\"str\"><v>THARU PRODUCTS MONTHLY EXPENSE REPORT</v></c></row>\n";
        $worksheetData .= "<row r=\"2\"></row>\n";
        $worksheetData .= "<row r=\"3\"><c r=\"A3\" t=\"str\"><v>Report ID</v></c><c r=\"B3\" t=\"str\"><v>" . htmlspecialchars($reportData['report_id']) . "</v></c></row>\n";
        $worksheetData .= "<row r=\"4\"><c r=\"A4\" t=\"str\"><v>Generated Date</v></c><c r=\"B4\" t=\"str\"><v>" . date('d M Y H:i', strtotime($reportData['generated_date'])) . "</v></c></row>\n";
        $worksheetData .= "<row r=\"5\"><c r=\"A5\" t=\"str\"><v>Period</v></c><c r=\"B5\" t=\"str\"><v>" . htmlspecialchars($reportData['month_display']) . "</v></c></row>\n";
        $worksheetData .= "<row r=\"6\"></row>\n";
        $worksheetData .= "<row r=\"7\"><c r=\"A7\" t=\"str\"><v>Expense Type</v></c><c r=\"B7\" t=\"str\"><v>Amount (LKR)</v></c><c r=\"C7\" t=\"str\"><v>Percentage</v></c></row>\n";
        
        $rowNum = 8;
        foreach ($categories as $key => $label) {
            $amount = $reportData['expenses'][$key];
            $percentage = $total > 0 ? ($amount / $total) * 100 : 0;
            $worksheetData .= "<row r=\"" . $rowNum . "\"><c r=\"A" . $rowNum . "\" t=\"str\"><v>" . htmlspecialchars($label) . "</v></c><c r=\"B" . $rowNum . "\" t=\"n\"><v>" . number_format($amount, 2, '.', '') . "</v></c><c r=\"C" . $rowNum . "\" t=\"n\"><v>" . number_format($percentage, 1, '.', '') . "</v></c></row>\n";
            $rowNum++;
        }
        
        $worksheetData .= "<row r=\"" . $rowNum . "\"><c r=\"A" . $rowNum . "\" t=\"str\"><v>TOTAL MONTHLY EXPENSES</v></c><c r=\"B" . $rowNum . "\" t=\"n\"><v>" . number_format($total, 2, '.', '') . "</v></c><c r=\"C" . $rowNum . "\" t=\"n\"><v>100.0</v></c></row>\n";
        $worksheetData .= "</sheetData></worksheet>\n";
        
        $workbook = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n<workbook xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\"><sheets><sheet name=\"Expenses\" sheetId=\"1\" r:id=\"rId1\"/></sheets></workbook>\n";
        $workbookRels = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n<Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\"><Relationship Id=\"rId1\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet1.xml\"/></Relationships>\n";
        $contentTypes = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n<Types xmlns=\"http://schemas.openxmlformats.org/package/2006/content-types\"><Default Extension=\"rels\" ContentType=\"application/vnd.openxmlformats-package.relationships+xml\"/><Default Extension=\"xml\" ContentType=\"application/xml\"/><Override PartName=\"/xl/workbook.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml\"/><Override PartName=\"/xl/worksheets/sheet1.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/></Types>\n";
        $rels = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n<Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\"><Relationship Id=\"rId1\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument\" Target=\"xl/workbook.xml\"/></Relationships>\n";
        
        $tempZip = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new ZipArchive();
        if ($zip->open($tempZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            $zip->addFromString('[Content_Types].xml', $contentTypes);
            $zip->addFromString('_rels/.rels', $rels);
            $zip->addFromString('xl/workbook.xml', $workbook);
            $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
            $zip->addFromString('xl/worksheets/sheet1.xml', $worksheetData);
            $zip->close();
            
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($tempZip));
            readfile($tempZip);
            unlink($tempZip);
            exit;
        }
        exit;
    } else {
        // PDF and JPEG export - return HTML page with export buttons
        $tableRows = '';
        foreach ($categories as $key => $label) {
            $amount = $reportData['expenses'][$key];
            $percentage = $total > 0 ? ($amount / $total) * 100 : 0;
            $tableRows .= '<tr><td style="padding: 10px; border-bottom: 1px solid #ddd;">' . htmlspecialchars($label) . '</td>';
            $tableRows .= '<td style="padding: 10px; border-bottom: 1px solid #ddd; text-align: right;">LKR ' . number_format($amount, 2) . '</td>';
            $tableRows .= '<td style="padding: 10px; border-bottom: 1px solid #ddd; text-align: right;">' . number_format($percentage, 1) . '%</td></tr>';
        }
        
        $chartHTML = '';
        $maxAmount = max($reportData['expenses']);
        $chartWidth = 300;
        $colors = ['#667eea', '#764ba2', '#f093fb', '#4facfe', '#00f2fe', '#43e97b', '#fa709a'];
        $colorIndex = 0;
        foreach ($categories as $key => $label) {
            $amount = $reportData['expenses'][$key];
            $percentage = $total > 0 ? ($amount / $total) * 100 : 0;
            $barWidth = ($amount / $maxAmount) * $chartWidth;
            $color = $colors[$colorIndex++ % count($colors)];
            $chartHTML .= '<div style="margin-bottom: 12px;"><div style="font-size: 11px; margin-bottom: 3px;">' . htmlspecialchars($label) . ' - LKR ' . number_format($amount, 2) . ' (' . number_format($percentage, 1) . '%)</div><div style="height: 25px; background-color: #e8e8e8; border-radius: 3px; overflow: hidden;"><div style="width: ' . $barWidth . 'px; height: 100%; background-color: ' . $color . ';"></div></div></div>';
        }
        
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>THARU Expense Report</title><script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script><script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script><style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:Arial,sans-serif;background:#fff;padding:40px 30px}#reportContent{max-width:900px;margin:0 auto;background:#fff}.header{text-align:center;margin-bottom:30px;border-bottom:3px solid #667eea;padding-bottom:20px}.header h1{font-size:24px;color:#333;margin-bottom:5px}.header p{font-size:13px;color:#666}.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:30px;margin:25px 0}.info-box{padding:12px;background:#f8f9fa;border-left:4px solid #667eea;font-size:12px}.info-box strong{display:block;color:#667eea;margin-bottom:5px}.section-title{font-size:14px;font-weight:bold;margin:25px 0 15px 0;color:#333;border-bottom:2px solid #e0e0e0;padding-bottom:5px}table{width:100%;border-collapse:collapse;margin:15px 0}th{background:#4a5568;color:#fff;padding:12px;text-align:left;font-size:12px}td{padding:10px;font-size:12px}tbody tr:nth-child(even){background:#f9f9f9}.chart-section{margin:30px 0;padding:20px;background:#f8f9fa;border-radius:5px}.footer{margin-top:40px;padding-top:20px;border-top:1px solid #ddd;text-align:center;font-size:10px;color:#999}.controls{text-align:center;margin-bottom:20px}.controls button{padding:10px 20px;margin:5px;background:#667eea;color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:bold}.controls button:hover{background:#764ba2}@media print{.controls{display:none}}</style></head><body><div class="controls"><button onclick="exportAs(\'pdf\')"  >Save as PDF</button><button onclick="exportAs(\'jpeg\')" >Save as JPEG</button><button onclick="window.close()">Close</button></div><div id="reportContent" class="container"><div class="header"><h1>THARU PRODUCTS</h1><p>Monthly Expense Report</p></div><div class="info-grid"><div class="info-box"><strong>Report ID</strong>' . htmlspecialchars($reportData['report_id']) . '</div><div class="info-box"><strong>Period</strong>' . htmlspecialchars($reportData['month_display']) . '</div></div><div class="info-grid"><div class="info-box"><strong>Generated Date</strong>' . date('d M Y, H:i', strtotime($reportData['generated_date'])) . '</div><div class="info-box"><strong>Total Expenses</strong>LKR ' . number_format($total, 2) . '</div></div><div class="section-title">Expense Summary</div><table><thead><tr><th>Expense Category</th><th style="text-align:right;">Amount (LKR)</th><th style="text-align:right;">Percentage</th></tr></thead><tbody>' . $tableRows . '<tr style="background:#667eea;color:#fff;font-weight:bold;"><td>TOTAL MONTHLY EXPENSES</td><td style="text-align:right;">LKR ' . number_format($total, 2) . '</td><td style="text-align:right;">100.0%</td></tr></tbody></table><div class="section-title">Expense Distribution Chart</div><div class="chart-section">' . $chartHTML . '</div><div class="footer">Generated by THARU Products Accountant Dashboard | ' . date('d M Y \\a\\t H:i') . '</div></div><script>function exportAs(fmt){const elem=document.getElementById("reportContent");const ctrl=document.querySelector(".controls");ctrl.style.display="none";html2canvas(elem,{scale:2,backgroundColor:"#ffffff",useCORS:true,allowTaint:true}).then(function(canvas){ctrl.style.display="block";if(fmt==="jpeg"){const link=document.createElement("a");link.href=canvas.toDataURL("image/jpeg",0.95);link.download="' . $basefilename . '.jpeg";link.click()}else if(fmt==="pdf"){const {jsPDF}=window.jspdf;const imgData=canvas.toDataURL("image/png");const pdf=new jsPDF("p","mm","a4");const pw=pdf.internal.pageSize.getWidth();const ph=pdf.internal.pageSize.getHeight();const iw=pw-20;const ih=(canvas.height*iw)/canvas.width;let pos=10;let left=ih;pdf.addImage(imgData,"PNG",10,pos,iw,ih);left-=ph-20;while(left>0){pos=left-ih+10;pdf.addPage();pdf.addImage(imgData,"PNG",10,pos,iw,ih);left-=ph-20}pdf.save("' . $basefilename . '.pdf")}}).catch(function(err){ctrl.style.display="block";alert("Export failed: "+err.message)})}window.addEventListener("load",function(){setTimeout(function(){exportAs("' . $format . '")},500)});</script></body></html>';
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: inline');
        echo $html;
        exit;
    }
}

// Attendance Helper: resolve role-specific foreign keys from a selected user
function resolveAttendanceEmployeeReference($conn, $userID) {
    $stmt = $conn->prepare(
        "SELECT u.userID, u.role,
                s.stocksupID, ss.salessupID, w.workerID, d.driverID, ac.accountantID
         FROM user_tbl u
         LEFT JOIN StockSuperviser_tbl s ON u.userID = s.userID
         LEFT JOIN SalesSuperviser_tbl ss ON u.userID = ss.userID
         LEFT JOIN Worker_tbl w ON u.userID = w.userID
         LEFT JOIN Driver_tbl d ON u.userID = d.userID
         LEFT JOIN Accountant_tbl ac ON u.userID = ac.userID
         WHERE u.userID = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $userID);
    $stmt->execute();
    $result = $stmt->get_result();
    $employee = $result->fetch_assoc();
    $stmt->close();
    return $employee;
}

//SALARY RUN CALCULATION 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'calculate_salary') {
    $attendanceID = intval($_POST['attendance_id'] ?? 0);
    $employeeID = intval($_POST['employee_id'] ?? 0);
    $paydate = trim($_POST['paydate'] ?? '');
    $base_pay = floatval($_POST['base_pay'] ?? 0);
    $ot_pay = floatval($_POST['ot_pay'] ?? 0);
    $bonus_pay = floatval($_POST['bonus_pay'] ?? 0);
    $total_amount_paid = $base_pay + $ot_pay + $bonus_pay;

    if ($attendanceID <= 0 || $employeeID <= 0 || empty($paydate)) {
        $error_message = "Please select a supervisor, attendance record, and payment date.";
    } elseif ($base_pay < 0 || $ot_pay < 0 || $bonus_pay < 0) {
        $error_message = "Salary values must be valid non-negative numbers.";
    } else {
        $query = "
            SELECT a.attendanceID,
                   COALESCE(s.userID, ss.userID, w.userID, d.userID) AS userID,
                   COALESCE(s.base_salary, ss.base_salary, 0) AS base_salary,
                   COALESCE(s.OT_rate, ss.OT_rate, 0) AS ot_rate,
                   w.hour_rate AS hour_rate,
                   d.fixed_salary AS fixed_salary,
                   COALESCE(su.username, ssu.username, wu.username, du.username) AS emp_name,
                   CASE 
                     WHEN s.stocksupID IS NOT NULL THEN 'Stock Supervisor' 
                     WHEN ss.salessupID IS NOT NULL THEN 'Sales Supervisor' 
                     WHEN w.workerID IS NOT NULL THEN 'Worker' 
                     WHEN d.driverID IS NOT NULL THEN 'Driver' 
                   END AS emp_role
            FROM attendance_tbl a
            LEFT JOIN StockSuperviser_tbl s ON a.stocksupID = s.stocksupID
            LEFT JOIN user_tbl su ON s.userID = su.userID
            LEFT JOIN SalesSuperviser_tbl ss ON a.salessupID = ss.salessupID
            LEFT JOIN user_tbl ssu ON ss.userID = ssu.userID
            LEFT JOIN Worker_tbl w ON a.workerID = w.workerID
            LEFT JOIN user_tbl wu ON w.userID = wu.userID
            LEFT JOIN Driver_tbl d ON a.driverID = d.driverID
            LEFT JOIN user_tbl du ON d.userID = du.userID
            WHERE a.attendanceID = ? AND COALESCE(s.userID, ss.userID, w.userID, d.userID) = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("ii", $attendanceID, $employeeID);
        $stmt->execute();
        $employee = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$employee) {
            $error_message = "Selected attendance does not belong to the chosen supervisor.";
        } else {
            $emp_display_name = $employee['emp_name'] ?: 'Supervisor';
            $emp_role_label = $employee['emp_role'] ?: 'Supervisor';
            $employeeUserID = $employee['userID'];
            $emp_base_salary = $employee['base_salary'];

            // For supervisors: check if already paid this month; if so, exclude base_salary
            $adjusted_base_pay = $base_pay;
            $baseSalaryExcluded = false;
            if (in_array($emp_role_label, ['Stock Supervisor', 'Sales Supervisor'], true)) {
                // Check for existing salary payment this month for this employee
                $currentMonthStart = date('Y-m-01');
                $currentMonthEnd = date('Y-m-t');
                $checkStmt = $conn->prepare(
                    "SELECT COUNT(*) as count FROM salary_tbl s
                     JOIN attendance_tbl a ON s.attendanceID = a.attendanceID
                     WHERE s.paydate BETWEEN ? AND ?
                     AND (a.stocksupID = (SELECT stocksupID FROM StockSuperviser_tbl WHERE userID = ?)
                          OR a.salessupID = (SELECT salessupID FROM SalesSuperviser_tbl WHERE userID = ?))"
                );
                $checkStmt->bind_param("ssii", $currentMonthStart, $currentMonthEnd, $employeeUserID, $employeeUserID);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result()->fetch_assoc();
                $checkStmt->close();

                // If already paid this month, exclude base salary
                if ($checkResult['count'] > 0) {
                    $adjusted_base_pay = 0;
                    $baseSalaryExcluded = true;
                }
            }

            $total_amount_paid = $adjusted_base_pay + $ot_pay + $bonus_pay;

            $conn->begin_transaction();
            try {
                $salStmt = $conn->prepare("INSERT INTO salary_tbl (paydate, totamtpaid, attendanceID, accountantID) VALUES (?, ?, ?, ?)");
                $salStmt->bind_param("sdii", $paydate, $total_amount_paid, $attendanceID, $accountantID);
                $salStmt->execute();
                $salStmt->close();

                // Format expense type based on payment components
                $expenseTypeLabel = "";
                if ($adjusted_base_pay > 0) {
                    $expenseTypeLabel = "salary-" . $emp_role_label;
                } elseif ($ot_pay > 0) {
                    $expenseTypeLabel = "OT-" . $emp_role_label;
                } else {
                    $expenseTypeLabel = "bonus-" . $emp_role_label;
                }
                
                // If bonus is included, append bonus indicator
                if ($bonus_pay > 0 && $adjusted_base_pay > 0) {
                    $expenseTypeLabel = "salary-bonus-" . $emp_role_label;
                } elseif ($bonus_pay > 0 && $ot_pay > 0) {
                    $expenseTypeLabel = "OT-bonus-" . $emp_role_label;
                }

                $expStmt = $conn->prepare("INSERT INTO expense_tbl (type, amount, accountantID, materialID) VALUES (?, ?, ?, NULL)");
                $expStmt->bind_param("sdi", $expenseTypeLabel, $total_amount_paid, $accountantID);
                $expStmt->execute();
                $expStmt->close();

                $conn->commit();
                $payTypeNote = ($baseSalaryExcluded) ? " [OT + Bonuses only — base salary paid earlier this month]" : "";
                dashboardRedirectWithFlash('success', 'Payroll allocated cleanly! ' . number_format($total_amount_paid, 2) . ' LKR disbursed and tracked in operational expenses.' . $payTypeNote, 'payroll');
            } catch (Exception $e) {
                $conn->rollback();
                dashboardRedirectWithFlash('error', 'Relational engine rejected insert operation: ' . $e->getMessage(), 'payroll');
            }
        }
    }
}
//ADDING MANUAL EXPENSES
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'log_expense') {
    $expense_type = trim($_POST['expense_type']);
    $custom_type = trim($_POST['custom_expense_type'] ?? '');
    $amount = floatval($_POST['amount']);

    $final_type = ($expense_type === 'Other' && !empty($custom_type)) ? $custom_type : $expense_type;

    if (!empty($final_type) && $amount > 0) {
        $stmt = $conn->prepare("INSERT INTO expense_tbl (type, amount, accountantID, materialID) VALUES (?, ?, ?, NULL)");
        $stmt->bind_param("sdi", $final_type, $amount, $accountantID);
        if ($stmt->execute()) {
            dashboardRedirectWithFlash('success', 'Manual overhead profile parsed and indexed correctly.', 'expenses');
        } else {
            dashboardRedirectWithFlash('error', 'Fault in manual ledger population sequence execution.', 'expenses');
        }
        $stmt->close();
    } else {
        dashboardRedirectWithFlash('error', 'Please complete all core description metrics correctly.', 'expenses');
    }
}

//ATTENDANCE CRUD OPERATIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_attendance') {
    $attDate = trim($_POST['attendance_date'] ?? '');
    $loginTime = trim($_POST['login_time'] ?? '');
    $logoutTime = trim($_POST['logout_time'] ?? '');
    $selectedUserID = intval($_POST['attendance_user_id'] ?? 0);

    if (empty($attDate) || empty($loginTime) || empty($logoutTime) || $selectedUserID <= 0) {
        $error_message = "Please fill in date, login, logout and select an employee.";
    } else {
        $employeeRef = resolveAttendanceEmployeeReference($conn, $selectedUserID);
        if (!$employeeRef) {
            $error_message = "Selected employee is not valid for attendance tracking.";
        } else {
            $stocksupID = null;
            $salessupID = null;
            $workerID = null;
            $driverID = null;
            $acctID = null;

            switch (strtolower($employeeRef['role'])) {
                case 'stocksup':
                    $stocksupID = $employeeRef['stocksupID'];
                    break;
                case 'salessup':
                    $salessupID = $employeeRef['salessupID'];
                    break;
                case 'worker':
                    $workerID = $employeeRef['workerID'];
                    break;
                case 'driver':
                    $driverID = $employeeRef['driverID'];
                    break;
                case 'accountant':
                    $acctID = $employeeRef['accountantID'];
                    break;
                default:
                    $error_message = "Selected employee role cannot be assigned attendance.";
            }

            if (empty($error_message)) {
                $stmt = $conn->prepare(
                    "INSERT INTO attendance_tbl (date, login, logout, stocksupID, salessupID, workerID, driverID, accountantID) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param('sssiiiii', $attDate, $loginTime, $logoutTime, $stocksupID, $salessupID, $workerID, $driverID, $acctID);
                if ($stmt->execute()) {
                    dashboardRedirectWithFlash('success', 'Attendance record added successfully.', 'attendanceTab');
                } else {
                    dashboardRedirectWithFlash('error', 'Failed to insert attendance record: ' . $stmt->error, 'attendanceTab');
                }
                $stmt->close();
            }
        }
    }

    if (!empty($error_message)) {
        dashboardRedirectWithFlash('error', $error_message, 'attendanceTab');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_attendance') {
    $attendanceID = intval($_POST['attendance_id'] ?? 0);
    $attDate = trim($_POST['attendance_date'] ?? '');
    $loginTime = trim($_POST['login_time'] ?? '');
    $logoutTime = trim($_POST['logout_time'] ?? '');
    $selectedUserID = intval($_POST['attendance_user_id'] ?? 0);

    if ($attendanceID <= 0 || empty($attDate) || empty($loginTime) || empty($logoutTime) || $selectedUserID <= 0) {
        $error_message = "Please provide valid attendance details for update.";
    } else {
        $employeeRef = resolveAttendanceEmployeeReference($conn, $selectedUserID);
        if (!$employeeRef) {
            $error_message = "Selected employee is not valid for attendance tracking.";
        } else {
            $stocksupID = null;
            $salessupID = null;
            $workerID = null;
            $driverID = null;
            $acctID = null;

            switch (strtolower($employeeRef['role'])) {
                case 'stocksup':
                    $stocksupID = $employeeRef['stocksupID'];
                    break;
                case 'salessup':
                    $salessupID = $employeeRef['salessupID'];
                    break;
                case 'worker':
                    $workerID = $employeeRef['workerID'];
                    break;
                case 'driver':
                    $driverID = $employeeRef['driverID'];
                    break;
                case 'accountant':
                    $acctID = $employeeRef['accountantID'];
                    break;
                default:
                    $error_message = "Selected employee role cannot be assigned attendance.";
            }

            if (empty($error_message)) {
                $stmt = $conn->prepare(
                    "UPDATE attendance_tbl SET date = ?, login = ?, logout = ?, stocksupID = ?, salessupID = ?, workerID = ?, driverID = ?, accountantID = ? WHERE attendanceID = ?"
                );
                $stmt->bind_param('sssiiiiii', $attDate, $loginTime, $logoutTime, $stocksupID, $salessupID, $workerID, $driverID, $acctID, $attendanceID);
                if ($stmt->execute()) {
                    dashboardRedirectWithFlash('success', 'Attendance record updated successfully.', 'attendanceTab');
                } else {
                    dashboardRedirectWithFlash('error', 'Failed to update attendance record: ' . $stmt->error, 'attendanceTab');
                }
                $stmt->close();
            }
        }
    }

    if (!empty($error_message)) {
        dashboardRedirectWithFlash('error', $error_message, 'attendanceTab');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_attendance') {
    $attendanceID = intval($_POST['attendance_id'] ?? 0);
    if ($attendanceID <= 0) {
        dashboardRedirectWithFlash('error', 'Invalid attendance record selected for deletion.', 'attendanceTab');
    } else {
        $salaryCheckStmt = $conn->prepare("SELECT COUNT(*) AS linked_salary_count FROM salary_tbl WHERE attendanceID = ?");
        $salaryCheckStmt->bind_param('i', $attendanceID);
        $salaryCheckStmt->execute();
        $salaryCheckResult = $salaryCheckStmt->get_result()->fetch_assoc();
        $salaryCheckStmt->close();

        $linkedSalaryCount = intval($salaryCheckResult['linked_salary_count'] ?? 0);
        if ($linkedSalaryCount > 0) {
            dashboardRedirectWithFlash('error', 'Cannot delete this attendance record because it is linked to ' . $linkedSalaryCount . ' salary payment record(s). Delete the linked salary record(s) first, then retry.', 'attendanceTab');
        }

        $stmt = $conn->prepare("DELETE FROM attendance_tbl WHERE attendanceID = ?");
        $stmt->bind_param('i', $attendanceID);
        if ($stmt->execute()) {
            dashboardRedirectWithFlash('success', 'Attendance record deleted successfully.', 'attendanceTab');
        } else {
            dashboardRedirectWithFlash('error', 'Unable to delete attendance record: ' . $stmt->error, 'attendanceTab');
        }
        $stmt->close();
    }
}

//SALARY RECORD EDIT / DELETE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_salary') {
    $salaryID = intval($_POST['salary_id'] ?? 0);
    $paydate = trim($_POST['paydate'] ?? '');
    $amount = floatval($_POST['amount']);

    if ($salaryID > 0 && !empty($paydate) && $amount > 0) {
        $stmt = $conn->prepare("UPDATE salary_tbl SET paydate = ?, totamtpaid = ? WHERE salaryID = ? AND accountantID = ?");
        $stmt->bind_param("sdii", $paydate, $amount, $salaryID, $accountantID);
        if ($stmt->execute()) {
            dashboardRedirectWithFlash('success', 'Salary record updated successfully.', 'salaryLedger');
        } else {
            dashboardRedirectWithFlash('error', 'Unable to update salary record: ' . $conn->error, 'salaryLedger');
        }
        $stmt->close();
    } else {
        dashboardRedirectWithFlash('error', 'Please provide a valid date and amount for salary update.', 'salaryLedger');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_salary') {
    $salaryID = intval($_POST['salary_id'] ?? 0);
    if ($salaryID <= 0) {
        dashboardRedirectWithFlash('error', 'Invalid salary record selected for deletion.', 'salaryLedger');
    }
    $stmt = $conn->prepare("DELETE FROM salary_tbl WHERE salaryID = ? AND accountantID = ?");
    $stmt->bind_param("ii", $salaryID, $accountantID);
    if ($stmt->execute()) {
        dashboardRedirectWithFlash('success', 'Salary record deleted successfully.', 'salaryLedger');
    } else {
        dashboardRedirectWithFlash('error', 'Unable to delete salary record: ' . $conn->error, 'salaryLedger');
    }
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_expense') {
    $expenseID = intval($_POST['expense_id'] ?? 0);
    if ($expenseID <= 0) {
        dashboardRedirectWithFlash('error', 'Invalid expense record selected for deletion.', 'expenseLedger');
    }
    $stmt = $conn->prepare("DELETE FROM expense_tbl WHERE expenseID = ? AND accountantID = ?");
    $stmt->bind_param("ii", $expenseID, $accountantID);
    if ($stmt->execute()) {
        dashboardRedirectWithFlash('success', 'Expense record deleted successfully.', 'expenseLedger');
    } else {
        dashboardRedirectWithFlash('error', 'Unable to delete expense record: ' . $conn->error, 'expenseLedger');
    }
    $stmt->close();
}

// Fetch active payroll items awaiting processing
$attendanceList = $conn->query("
    SELECT a.attendanceID, a.date, a.login, a.logout,
           COALESCE(wu.username, su.username, ssu.username, du.username) as emp_name,
           CASE 
             WHEN w.workerID IS NOT NULL THEN 'Worker'
             WHEN s.stocksupID IS NOT NULL THEN 'Stock Supervisor'
             WHEN ss.salessupID IS NOT NULL THEN 'Sales Supervisor'
             WHEN d.driverID IS NOT NULL THEN 'Driver'
           END as emp_role
    FROM attendance_tbl a
        LEFT JOIN Worker_tbl w ON a.workerID = w.workerID LEFT JOIN user_tbl wu ON w.userID = wu.userID
    LEFT JOIN StockSuperviser_tbl s ON a.stocksupID = s.stocksupID LEFT JOIN user_tbl su ON s.userID = su.userID
    LEFT JOIN SalesSuperviser_tbl ss ON a.salessupID = ss.salessupID LEFT JOIN user_tbl ssu ON ss.userID = ssu.userID
        LEFT JOIN Driver_tbl d ON a.driverID = d.driverID LEFT JOIN user_tbl du ON d.userID = du.userID
    LEFT JOIN salary_tbl sal ON a.attendanceID = sal.attendanceID
    WHERE sal.salaryID IS NULL 
      AND (w.workerID IS NOT NULL OR s.stocksupID IS NOT NULL OR ss.salessupID IS NOT NULL OR d.driverID IS NOT NULL)
    ORDER BY a.date DESC
");

// Fetch processed Salary ledger grouped by role for current month
$currentMonthStart = date('Y-m-01');
$currentMonthEnd = date('Y-m-t');

// Stock Supervisors - monthly salary with OT breakdown and bonus check
$stockSupLedger = $conn->query("
    SELECT 
        st.stocksupID as emp_id, 
        su.username, 
        su.userID,
        MIN(s.paydate) as base_paid_date,
        COUNT(DISTINCT s.salaryID) as payment_count,
        COALESCE(SUM(s.totamtpaid), 0) as total_paid,
        COALESCE(SUM(
            CASE 
                WHEN (TIME_TO_SEC(TIMEDIFF(a.logout, a.login)) / 3600) > 8 
                THEN ((TIME_TO_SEC(TIMEDIFF(a.logout, a.login)) / 3600) - 8) * st.OT_rate
                ELSE 0
            END
        ), 0) as ot_paid,
        (CASE WHEN EXISTS (
            SELECT 1 FROM expense_tbl e 
            WHERE e.type LIKE '%bonus%' AND e.accountantID = $accountantID
        ) THEN 'Yes' ELSE 'No' END) as has_bonus
    FROM salary_tbl s
    INNER JOIN attendance_tbl a ON s.attendanceID = a.attendanceID
    INNER JOIN StockSuperviser_tbl st ON a.stocksupID = st.stocksupID
    INNER JOIN user_tbl su ON st.userID = su.userID
    WHERE s.accountantID = $accountantID AND s.paydate BETWEEN '$currentMonthStart' AND '$currentMonthEnd'
    GROUP BY st.stocksupID, su.userID, su.username
    ORDER BY su.username
");

// Sales Supervisors - monthly salary with OT breakdown and bonus check
$salesSupLedger = $conn->query("
    SELECT 
        ss.salessupID as emp_id, 
        ssu.username, 
        ssu.userID,
        MIN(s.paydate) as base_paid_date,
        COUNT(DISTINCT s.salaryID) as payment_count,
        COALESCE(SUM(s.totamtpaid), 0) as total_paid,
        COALESCE(SUM(
            CASE 
                WHEN (TIME_TO_SEC(TIMEDIFF(a.logout, a.login)) / 3600) > 8 
                THEN ((TIME_TO_SEC(TIMEDIFF(a.logout, a.login)) / 3600) - 8) * ss.OT_rate
                ELSE 0
            END
        ), 0) as ot_paid,
        (CASE WHEN EXISTS (
            SELECT 1 FROM expense_tbl e 
            WHERE e.type LIKE '%bonus%' AND e.accountantID = $accountantID
        ) THEN 'Yes' ELSE 'No' END) as has_bonus
    FROM salary_tbl s
    INNER JOIN attendance_tbl a ON s.attendanceID = a.attendanceID
    INNER JOIN SalesSuperviser_tbl ss ON a.salessupID = ss.salessupID
    INNER JOIN user_tbl ssu ON ss.userID = ssu.userID
    WHERE s.accountantID = $accountantID AND s.paydate BETWEEN '$currentMonthStart' AND '$currentMonthEnd'
    GROUP BY ss.salessupID, ssu.userID, ssu.username
    ORDER BY ssu.username
");

// Workers - daily salary with days worked and bonus check
$workerLedger = $conn->query("
    SELECT 
        w.workerID as emp_id, 
        wu.username, 
        wu.userID,
        COUNT(DISTINCT a.attendanceID) as days_worked,
        COALESCE(SUM(s.totamtpaid), 0) as total_paid,
        (CASE WHEN EXISTS (
            SELECT 1 FROM expense_tbl e 
            WHERE e.type LIKE '%bonus%' AND e.accountantID = $accountantID
        ) THEN 'Yes' ELSE 'No' END) as has_bonus
    FROM salary_tbl s
    INNER JOIN attendance_tbl a ON s.attendanceID = a.attendanceID
    INNER JOIN Worker_tbl w ON a.workerID = w.workerID
    INNER JOIN user_tbl wu ON w.userID = wu.userID
    WHERE s.accountantID = $accountantID AND s.paydate BETWEEN '$currentMonthStart' AND '$currentMonthEnd'
    GROUP BY w.workerID, wu.userID, wu.username
    ORDER BY wu.username
");

// Drivers - daily salary with days worked and bonus check
$driverLedger = $conn->query("
    SELECT 
        d.driverID as emp_id, 
        du.username, 
        du.userID,
        COUNT(DISTINCT a.attendanceID) as days_worked,
        COALESCE(SUM(s.totamtpaid), 0) as total_paid,
        (CASE WHEN EXISTS (
            SELECT 1 FROM expense_tbl e 
            WHERE e.type LIKE '%bonus%' AND e.accountantID = $accountantID
        ) THEN 'Yes' ELSE 'No' END) as has_bonus
    FROM salary_tbl s
    INNER JOIN attendance_tbl a ON s.attendanceID = a.attendanceID
    INNER JOIN Driver_tbl d ON a.driverID = d.driverID
    INNER JOIN user_tbl du ON d.userID = du.userID
    WHERE s.accountantID = $accountantID AND s.paydate BETWEEN '$currentMonthStart' AND '$currentMonthEnd'
    GROUP BY d.driverID, du.userID, du.username
    ORDER BY du.username
");

//Fetch combined organizational layout expense structures
$expenseLedger = $conn->query("SELECT * FROM expense_tbl WHERE accountantID = $accountantID ORDER BY expenseID DESC");

//Fetch employee roster for attendance CRUD (exclude customer accounts)
$attendanceEmployees = $conn->query("SELECT u.userID, u.username,
       CASE
         WHEN LOWER(u.role) = 'stocksup' THEN 'Stock Supervisor'
         WHEN LOWER(u.role) = 'salessup' THEN 'Sales Supervisor'
         WHEN LOWER(u.role) = 'worker' THEN 'Worker'
         WHEN LOWER(u.role) = 'driver' THEN 'Driver'
         WHEN LOWER(u.role) = 'accountant' THEN 'Accountant'
         ELSE 'Employee'
       END AS display_role,
       s.stocksupID, ss.salessupID, w.workerID, d.driverID, ac.accountantID
    FROM user_tbl u
    LEFT JOIN StockSuperviser_tbl s ON u.userID = s.userID
    LEFT JOIN SalesSuperviser_tbl ss ON u.userID = ss.userID
    LEFT JOIN Worker_tbl w ON u.userID = w.userID
    LEFT JOIN Driver_tbl d ON u.userID = d.userID
    LEFT JOIN Accountant_tbl ac ON u.userID = ac.userID
    WHERE LOWER(u.role) IN ('stocksup','salessup','worker','driver','accountant')
    ORDER BY u.username");

$attendanceEmployeeRows = [];
if ($attendanceEmployees && $attendanceEmployees->num_rows > 0) {
    while ($row = $attendanceEmployees->fetch_assoc()) {
        $attendanceEmployeeRows[] = $row;
    }
}

//Fetch attendance records for the attendance CRUD tab
$attendanceCRUD = $conn->query("SELECT a.attendanceID, a.date, a.login, a.logout,
    COALESCE(s.userID, ss.userID, w.userID, d.userID, ac.accountantID) AS userID,
    COALESCE(u_stock.username, u_sales.username, u_worker.username, u_driver.username, u_accountant.username) AS emp_name,
       CASE
         WHEN a.stocksupID IS NOT NULL THEN 'Stock Supervisor'
         WHEN a.salessupID IS NOT NULL THEN 'Sales Supervisor'
         WHEN a.workerID IS NOT NULL THEN 'Worker'
         WHEN a.driverID IS NOT NULL THEN 'Driver'
         WHEN a.accountantID IS NOT NULL THEN 'Accountant'
       END AS emp_role,
       a.stocksupID, a.salessupID, a.workerID, a.driverID, a.accountantID
    FROM attendance_tbl a
    LEFT JOIN StockSuperviser_tbl s ON a.stocksupID = s.stocksupID
    LEFT JOIN user_tbl u_stock ON s.userID = u_stock.userID
    LEFT JOIN SalesSuperviser_tbl ss ON a.salessupID = ss.salessupID
    LEFT JOIN user_tbl u_sales ON ss.userID = u_sales.userID
    LEFT JOIN Worker_tbl w ON a.workerID = w.workerID
    LEFT JOIN user_tbl u_worker ON w.userID = u_worker.userID
    LEFT JOIN Driver_tbl d ON a.driverID = d.driverID
    LEFT JOIN user_tbl u_driver ON d.userID = u_driver.userID
    LEFT JOIN Accountant_tbl ac ON a.accountantID = ac.accountantID
    LEFT JOIN user_tbl u_accountant ON ac.userID = u_accountant.userID
    ORDER BY a.date DESC");

// Salary portal data for stock supervisors, sales supervisors, drivers, and workers
$salaryEmployees = $conn->query("SELECT u.userID, u.username, 'Stock Supervisor' AS role, s.base_salary, s.OT_rate, NULL AS hour_rate, NULL AS fixed_salary FROM StockSuperviser_tbl s JOIN user_tbl u ON s.userID = u.userID UNION SELECT u.userID, u.username, 'Sales Supervisor' AS role, ss.base_salary, ss.OT_rate, NULL AS hour_rate, NULL AS fixed_salary FROM SalesSuperviser_tbl ss JOIN user_tbl u ON ss.userID = u.userID UNION SELECT u.userID, u.username, 'Worker' AS role, NULL AS base_salary, NULL AS OT_rate, w.hour_rate, NULL AS fixed_salary FROM Worker_tbl w JOIN user_tbl u ON w.userID = u.userID UNION SELECT u.userID, u.username, 'Driver' AS role, NULL AS base_salary, NULL AS OT_rate, NULL AS hour_rate, d.fixed_salary FROM Driver_tbl d JOIN user_tbl u ON d.userID = u.userID");
$salaryAttendance = $conn->query("SELECT a.attendanceID, a.date, a.login, a.logout, COALESCE(s.userID, ss.userID, w.userID, d.userID) AS userID, COALESCE(su.username, ssu.username, wu.username, du.username) AS username, COALESCE(s.base_salary, ss.base_salary, 0) AS base_salary, COALESCE(s.OT_rate, ss.OT_rate, 0) AS ot_rate, w.hour_rate AS hour_rate, d.fixed_salary AS fixed_salary, CASE WHEN s.stocksupID IS NOT NULL THEN 'Stock Supervisor' WHEN ss.salessupID IS NOT NULL THEN 'Sales Supervisor' WHEN w.workerID IS NOT NULL THEN 'Worker' WHEN d.driverID IS NOT NULL THEN 'Driver' END AS role FROM attendance_tbl a LEFT JOIN StockSuperviser_tbl s ON a.stocksupID = s.stocksupID LEFT JOIN user_tbl su ON s.userID = su.userID LEFT JOIN SalesSuperviser_tbl ss ON a.salessupID = ss.salessupID LEFT JOIN user_tbl ssu ON ss.userID = ssu.userID LEFT JOIN Worker_tbl w ON a.workerID = w.workerID LEFT JOIN user_tbl wu ON w.userID = wu.userID LEFT JOIN Driver_tbl d ON a.driverID = d.driverID LEFT JOIN user_tbl du ON d.userID = du.userID LEFT JOIN salary_tbl sal ON a.attendanceID = sal.attendanceID WHERE sal.salaryID IS NULL AND (s.stocksupID IS NOT NULL OR ss.salessupID IS NOT NULL OR w.workerID IS NOT NULL OR d.driverID IS NOT NULL) ORDER BY a.date DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Accountant Dashboard - Tharu & Products Systems</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --sidebar-bg: #112a1b;
            --sidebar-active: #1e3a24;
            --sidebar-text: #d6e8d8;
            --sidebar-muted: #8fae95;
            --surface-bg: #f7faf9;
            --panel-bg: #ffffff;
            --panel-border: rgba(15, 23, 42, 0.08);
            --success: #16a34a;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--surface-bg);
            color: #0f172a;
            min-height: 100vh;
            margin: 0;
        }

        .dashboard-shell {
            display: flex;
            min-height: 100vh;
        }

        .dashboard-sidebar {
            width: 300px;
            background: var(--sidebar-bg);
            color: white;
            padding: 2rem 1.75rem;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            overflow-y: auto;
            box-shadow: 4px 0 30px rgba(0,0,0,0.12);
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            flex: 0 0 auto;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 2rem;
        }

        .dashboard-sidebar img {
            height: 44px;
            width: auto;
            display: block;
            flex-shrink: 0;
        }

        .sidebar-title {
            font-size: 1.25rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: white;
            margin: 0;
            white-space: nowrap;
            font-family: monospace;
            font-weight: bold;
        }

        .sidebar-nav {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            flex: 1;
        }

        .sidebar-nav button {
            border: none;
            width: 100%;
            text-align: left;
            border-radius: 14px;
            padding: 0.95rem 1rem;
            color: var(--sidebar-text);
            background: transparent;
            transition: background 0.25s ease, color 0.25s ease;
        }

        .sidebar-nav button.active,
        .sidebar-nav button:hover {
            background: var(--sidebar-active);
            color: white;
        }

        .dashboard-main {
            margin-left: 300px;
            width: calc(100% - 300px);
            padding: 2rem 2.5rem;
        }

        .page-heading {
            margin-bottom: 1.25rem;
        }

        .page-heading h1 {
            font-size: 2rem;
            letter-spacing: -0.04em;
        }

        .page-heading p {
            color: #64748b;
        }

        .card {
            border-radius: 22px;
            border: 1px solid var(--panel-border);
            background: var(--panel-bg);
            box-shadow: 0 18px 50px rgba(15, 23, 42, 0.05);
        }

        .table {
            vertical-align: middle;
        }

        .btn-success {
            background-color: var(--success);
            border-color: var(--success);
            border-radius: 12px;
            font-weight: 600;
        }

        .btn-success:hover {
            background-color: #15803d;
        }

        .form-check-input {
            cursor: pointer;
        }

        .content-section {
            margin-bottom: 1.75rem;
        }

        @media (max-width: 1199px) {
            .dashboard-sidebar {
                position: relative;
                width: 100%;
                height: auto;
                box-shadow: none;
            }

            .dashboard-main {
                margin-left: 0;
                width: 100%;
                padding: 1.5rem;
            }
        }
    </style>
    <!-- Chart.js Library for Advanced Charts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
</head>
<body>
<div class="dashboard-shell">
    <aside class="dashboard-sidebar">
        <div class="sidebar-header">
            <img src="../images/LOGO.png" alt="Tharu Products" />
            <div class="sidebar-title">Tharu Accountant</div>
        </div>
        <div class="sidebar-nav" role="tablist" aria-orientation="vertical">
            <button class="<?= $active_tab === 'attendanceTab' ? 'active' : '' ?>" id="attendanceTab-tab" data-bs-toggle="pill" data-bs-target="#attendanceTab" type="button" role="tab" aria-controls="attendanceTab" aria-selected="<?= $active_tab === 'attendanceTab' ? 'true' : 'false' ?>"><i class="bi bi-calendar-check"></i> Attendance Tracking</button>
            <button class="<?= $active_tab === 'payroll' ? 'active' : '' ?>" id="payroll-tab" data-bs-toggle="pill" data-bs-target="#payroll" type="button" role="tab" aria-controls="payroll" aria-selected="<?= $active_tab === 'payroll' ? 'true' : 'false' ?>"><i class="bi bi-calculator"></i> Payroll</button>
            <button class="<?= $active_tab === 'expenses' ? 'active' : '' ?>" id="expenses-tab" data-bs-toggle="pill" data-bs-target="#expenses" type="button" role="tab" aria-controls="expenses" aria-selected="<?= $active_tab === 'expenses' ? 'true' : 'false' ?>"><i class="bi bi-receipt"></i> Manual Expenses</button>
            <button class="<?= $active_tab === 'salaryLedger' ? 'active' : '' ?>" id="salaryLedger-tab" data-bs-toggle="pill" data-bs-target="#salaryLedger" type="button" role="tab" aria-controls="salaryLedger" aria-selected="<?= $active_tab === 'salaryLedger' ? 'true' : 'false' ?>"><i class="bi bi-book"></i> Salary Ledger</button>
            <button class="<?= $active_tab === 'expenseLedger' ? 'active' : '' ?>" id="expenseLedger-tab" data-bs-toggle="pill" data-bs-target="#expenseLedger" type="button" role="tab" aria-controls="expenseLedger" aria-selected="<?= $active_tab === 'expenseLedger' ? 'true' : 'false' ?>"><i class="bi bi-list-check"></i> Expense Ledger</button>
            <button class="<?= $active_tab === 'reports' ? 'active' : '' ?>" id="reports-tab" data-bs-toggle="pill" data-bs-target="#reports" type="button" role="tab" aria-controls="reports" aria-selected="<?= $active_tab === 'reports' ? 'true' : 'false' ?>"><i class="bi bi-bar-chart"></i> Reports</button>
        </div>
        <a href="../auth/logout.php" class="btn btn-danger btn-sm mt-auto w-100">Sign out user</a>
    </aside>

    <main class="dashboard-main">
        <?php if(!empty($error_message)): ?>
            <div class="alert alert-danger border-0 shadow-sm mb-4" style="border-radius:16px;"><i class="bi bi-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>
        <?php if(!empty($success_message)): ?>
            <div class="alert alert-success border-0 shadow-sm mb-4" style="border-radius:16px;"><i class="bi bi-check-circle"></i> <?= htmlspecialchars($success_message) ?></div>
        <?php endif; ?>

        <section class="page-heading">
            <h1>Accountant Dashboard</h1>
            <p class="small">Manage attendance, payroll, expenses, and ledgers.</p>
        </section>

        <div class="tab-content" id="acctTabContent">
            <div class="tab-pane fade <?= $active_tab === 'payroll' ? 'show active' : '' ?>" id="payroll" role="tabpanel" aria-labelledby="payroll-tab">
                <div class="card border-0 shadow-sm p-4 rounded-4 mb-4">
                    <h4 class="fw-bold text-dark mb-1"><i class="bi bi-calculator-fill me-2 text-success"></i>Salary Calculator Portal</h4>
                    <p class="text-muted small mb-4">Add salary and salary-related payments (OT, holiday bonuses) for permanent and daily workers.</p>

                    <form action="" method="POST" id="salaryForm">
                        <input type="hidden" name="action" value="calculate_salary">
                        <input type="hidden" name="tab_id" value="payroll">

                        <div class="row g-3">
                            <div class="col-md-12 mb-2">
                                <label class="form-label small fw-bold text-dark">Select an employee to calculate salary payments for:</label>
                                <select class="form-select border" id="employeeSelect" name="employee_id" onchange="loadEmployeeData()" required>
                                    <option value="">-- Choose employee --</option>
                                    <?php if ($salaryEmployees && $salaryEmployees->num_rows > 0): ?>
                                        <?php while ($sup = $salaryEmployees->fetch_assoc()): ?>
                                            <option value="<?= htmlspecialchars($sup['userID']) ?>"
                                                    data-role="<?= htmlspecialchars($sup['role']) ?>"
                                                    data-base-salary="<?= number_format((float)($sup['base_salary'] ?? 0), 2, '.', '') ?>"
                                                    data-ot-rate="<?= number_format((float)($sup['OT_rate'] ?? 0), 2, '.', '') ?>"
                                                    data-hour-rate="<?= number_format((float)($sup['hour_rate'] ?? 0), 2, '.', '') ?>"
                                                    data-fixed-salary="<?= number_format((float)($sup['fixed_salary'] ?? 0), 2, '.', '') ?>">
                                                <?= htmlspecialchars($sup['username']) ?> (<?= htmlspecialchars($sup['role']) ?>)
                                            </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Employee attendance record/s:</label>
                                <select class="form-select border" id="attendanceSelect" name="attendance_id" onchange="loadAttendanceData()" required>
                                    <option value="">-- Choose attendance record --</option>
                                    <?php if ($salaryAttendance && $salaryAttendance->num_rows > 0): ?>
                                        <?php while ($att = $salaryAttendance->fetch_assoc()): ?>
                                            <option value="<?= htmlspecialchars($att['attendanceID']) ?>"
                                                    data-user-id="<?= htmlspecialchars($att['userID']) ?>"
                                                    data-login="<?= htmlspecialchars($att['login']) ?>"
                                                    data-logout="<?= htmlspecialchars($att['logout']) ?>"
                                                    data-date="<?= htmlspecialchars($att['date']) ?>"
                                                    data-role="<?= htmlspecialchars($att['role']) ?>"
                                                    data-base-salary="<?= number_format((float)($att['base_salary'] ?? 0), 2, '.', '') ?>"
                                                    data-ot-rate="<?= number_format((float)($att['ot_rate'] ?? 0), 2, '.', '') ?>"
                                                    data-hour-rate="<?= number_format((float)($att['hour_rate'] ?? 0), 2, '.', '') ?>"
                                                    data-fixed-salary="<?= number_format((float)($att['fixed_salary'] ?? 0), 2, '.', '') ?>">
                                                <?= htmlspecialchars($att['date']) ?> | <?= htmlspecialchars($att['username']) ?> (<?= htmlspecialchars($att['role']) ?>)
                                            </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Login / logout times:</label>
                                <input type="text" id="attendanceSummary" class="form-control bg-light" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Settlement pay date:</label>
                                <input type="date" name="paydate" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-secondary">Base salary (LKR):</label>
                                <input type="number" id="baseSalary" class="form-control bg-light" value="0" readonly>
                            </div>
                            <input type="hidden" id="otRate" value="0">
                            <input type="hidden" id="basePayHidden" name="base_pay" value="0">
                            <input type="hidden" id="otPayHidden" name="ot_pay" value="0">

                            <div class="col-12 my-3"><hr></div>

                            <div class="col-md-6">
                                <label class="form-label small text-muted">OT hours worked:</label>
                                <input type="number" id="otHours" class="form-control" value="0" step="0.01" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted">OT rate per hour (LKR):</label>
                                <input type="number" id="otRateDisplay" class="form-control" value="0" step="0.01" readonly>
                            </div>

                            <div class="col-12 my-3"><hr></div>

                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="bonusToggle" onclick="toggleBonusField()">
                                    <label class="form-check-label fw-bold text-dark" for="bonusToggle">Apply holiday bonus:</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted">Bonus (LKR):</label>
                                <input type="number" id="bonusAmount" class="form-control" name="bonus_pay" value="0" disabled oninput="calculateTotalSalary()">
                            </div>

                            <div class="col-12 mt-4">
                                <div class="p-4 rounded-3 text-start d-flex justify-content-between align-items-center" style="background-color: #e8f5e9;">
                                    <div>
                                        <h6 class="text-success fw-bold text-uppercase mb-1 small">Total Calculated Salary Payment</h6>
                                        <div class="fs-2 fw-bold text-dark" id="displayTotalPayout">LKR 0.00</div>
                                    </div>
                                    <button type="submit" class="btn btn-success px-4 py-2 fw-bold rounded-pill shadow-sm">Authorize Salary Payment</button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="tab-pane fade <?= $active_tab === 'attendanceTab' ? 'show active' : '' ?>" id="attendanceTab" role="tabpanel" aria-labelledby="attendanceTab-tab">
                <div class="card p-4 content-section">
                    <h4 class="fw-bold mb-3 text-dark">Attendance Tracking Portal</h4>
                    <p class="text-muted small mb-4">Add, update, delete, and review attendance records for all employees.</p>

                    <form action="" method="POST" class="row g-3 mb-4">
                        <input type="hidden" name="action" value="create_attendance">
                        <input type="hidden" name="tab_id" value="attendanceTab">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Employee</label>
                            <select class="form-select" name="attendance_user_id" required>
                                <option value="">-- Select Employee --</option>
                                <?php if (!empty($attendanceEmployeeRows)): ?>
                                    <?php foreach ($attendanceEmployeeRows as $user): ?>
                                        <option value="<?= htmlspecialchars($user['userID']) ?>"><?= htmlspecialchars($user['username']) ?> (<?= htmlspecialchars($user['display_role']) ?>)</option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold">Date</label>
                            <input type="date" name="attendance_date" class="form-control" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold">Login Time</label>
                            <input type="time" name="login_time" class="form-control" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold">Logout Time</label>
                            <input type="time" name="logout_time" class="form-control" required>
                        </div>
                        <div class="col-12 text-end">
                            <button type="submit" class="btn btn-success rounded-pill px-4">Create Attendance</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-hover border-top align-middle small">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Employee</th>
                                    <th>Role</th>
                                    <th>Login</th>
                                    <th>Logout</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($attendanceCRUD && $attendanceCRUD->num_rows > 0): ?>
                                    <?php while ($att = $attendanceCRUD->fetch_assoc()): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($att['date']) ?></td>
                                            <td><?= htmlspecialchars($att['emp_name']) ?></td>
                                            <td><span class="badge border border-dark text-dark rounded-pill"><?= htmlspecialchars($att['emp_role']) ?></span></td>                                            <td><?= htmlspecialchars($att['login']) ?></td>
                                            <td><?= htmlspecialchars($att['login']) ?></td>
                                            <td><?= htmlspecialchars($att['logout']) ?></td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-outline-primary btn-sm me-1" onclick='openAttendanceEditModal(<?= $att['attendanceID'] ?>, <?= json_encode($att['date']) ?>, <?= json_encode($att['login']) ?>, <?= json_encode($att['logout']) ?>, <?= $att['userID'] ?? 'null' ?>)'>Edit</button>
                                                <form action="" method="POST" class="d-inline" onsubmit="return confirm('Delete this attendance record?');">
                                                    <input type="hidden" name="action" value="delete_attendance">
                                                    <input type="hidden" name="attendance_id" value="<?= htmlspecialchars($att['attendanceID']) ?>">
                                                    <input type="hidden" name="tab_id" value="attendanceTab">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" class="text-center text-muted py-4">No attendance records have been entered yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade <?= $active_tab === 'expenses' ? 'show active' : '' ?>" id="expenses" role="tabpanel" aria-labelledby="expenses-tab">
                <div class="card p-4 content-section">
                    <h4 class="fw-bold mb-3 text-dark">Manual Operating Expenses Registry</h4>
                    <p class="text-muted small mb-3">Create new manual expenses here. Use the Expense Ledger tab for edit and delete operations.</p>
                    <form action="" method="POST">
                        <input type="hidden" name="action" value="log_expense">
                        <input type="hidden" name="tab_id" value="expenses">
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Expense Type Profile</label>
                            <select id="expenseTypeSelect" name="expense_type" class="form-select form-select-md" onchange="toggleCustomExpenseBox(this.value)" required>
                                <option value="Machine Maintainance Costs">Machine Maintainance Costs</option>
                                <option value="Utility Bills">Utility Bills</option>
                                <option value="Raw Material Purchases">Raw Material Purchases</option>
                                <option value="Other">Other / Custom Description Override</option>
                            </select>
                        </div>

                        <div id="customExpenseWrapper" class="mb-3 d-none">
                            <label class="form-label small fw-semibold">Specify Custom Description</label>
                            <input type="text" name="custom_expense_type" class="form-control" placeholder="e.g. Factory Office Repairs">
                        </div>

                        <div class="mb-4">
                            <label class="form-label small fw-semibold">Amount Disbursed (LKR)</label>
                            <input type="number" step="0.01" min="1" name="amount" class="form-control" placeholder="0.00" required>
                        </div>

                        <button type="submit" class="btn btn-dark w-100 rounded-3 py-2 fw-bold">Log Financial Expense</button>
                    </form>
                    <div class="mt-3 text-end">
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('expenseLedger-tab').click()">Go to Expense Ledger (Edit/Delete)</button>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade <?= $active_tab === 'salaryLedger' ? 'show active' : '' ?>" id="salaryLedger" role="tabpanel" aria-labelledby="salaryLedger-tab">
                <div class="card p-4 content-section">
                    <h4 class="fw-bold mb-4 text-success">Salary Disbursements Summary Ledger</h4>
                    <p class="text-muted small mb-4">Monthly salary overview for all employees, grouped by role.</p>

                    <!-- Stock Supervisors Section -->
                    <div class="mb-5">
                        <h5 class="fw-bold text-secondary mb-3">Stock Supervisors (Monthly Salary)</h5>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover border-top">
                                <thead class="table-light">
                                    <tr>
                                        <th>Employee ID</th>
                                        <th>Name</th>
                                        <th>Base Salary Paid On</th>
                                        <th>Total OT Payments</th>
                                        <th>Holiday Bonus</th>
                                        <th>Total Salary (Month)</th>
                                    </tr>
                                </thead>
                                <tbody class="small">
                                    <?php if($stockSupLedger && $stockSupLedger->num_rows > 0): ?>
                                        <?php while($row = $stockSupLedger->fetch_assoc()): ?>
                                            <tr>
                                                <td class="fw-semibold"><?= htmlspecialchars($row['emp_id']) ?></td>
                                                <td><?= htmlspecialchars($row['username']) ?></td>
                                                <td><?= htmlspecialchars($row['base_paid_date']) ?></td>
                                                <td class="text-info fw-semibold"><?= number_format($row['ot_paid'], 2) ?> LKR</td>
                                                <td><span class="badge <?= $row['has_bonus'] === 'Yes' ? 'bg-success' : 'bg-secondary' ?>"><?= htmlspecialchars($row['has_bonus']) ?></span></td>
                                                <td class="text-success fw-bold"><?= number_format($row['total_paid'], 2) ?> LKR</td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="6" class="text-muted py-3">No stock supervisor payments this month.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Sales Supervisors Section -->
                    <div class="mb-5">
                        <h5 class="fw-bold text-secondary mb-3">Sales Supervisors (Monthly Salary)</h5>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover border-top">
                                <thead class="table-light">
                                    <tr>
                                        <th>Employee ID</th>
                                        <th>Name</th>
                                        <th>Base Salary Paid On</th>
                                        <th>Total OT Payments</th>
                                        <th>Holiday Bonus</th>
                                        <th>Total Salary (Month)</th>
                                    </tr>
                                </thead>
                                <tbody class="small">
                                    <?php if($salesSupLedger && $salesSupLedger->num_rows > 0): ?>
                                        <?php while($row = $salesSupLedger->fetch_assoc()): ?>
                                            <tr>
                                                <td class="fw-semibold"><?= htmlspecialchars($row['emp_id']) ?></td>
                                                <td><?= htmlspecialchars($row['username']) ?></td>
                                                <td><?= htmlspecialchars($row['base_paid_date']) ?></td>
                                                <td class="text-info fw-semibold"><?= number_format($row['ot_paid'], 2) ?> LKR</td>
                                                <td><span class="badge <?= $row['has_bonus'] === 'Yes' ? 'bg-success' : 'bg-secondary' ?>"><?= htmlspecialchars($row['has_bonus']) ?></span></td>
                                                <td class="text-success fw-bold"><?= number_format($row['total_paid'], 2) ?> LKR</td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="6" class="text-muted py-3">No sales supervisor payments this month.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Workers Section -->
                    <div class="mb-5">
                        <h5 class="fw-bold text-secondary mb-3">Workers (Daily Salary)</h5>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover border-top">
                                <thead class="table-light">
                                    <tr>
                                        <th>Employee ID</th>
                                        <th>Name</th>
                                        <th>Days Worked (Month)</th>
                                        <th>Holiday Bonus</th>
                                        <th>Total Salary (Month)</th>
                                    </tr>
                                </thead>
                                <tbody class="small">
                                    <?php if($workerLedger && $workerLedger->num_rows > 0): ?>
                                        <?php while($row = $workerLedger->fetch_assoc()): ?>
                                            <tr>
                                                <td class="fw-semibold"><?= htmlspecialchars($row['emp_id']) ?></td>
                                                <td><?= htmlspecialchars($row['username']) ?></td>
                                                <td class="text-center"><?= htmlspecialchars($row['days_worked']) ?> days</td>
                                                <td><span class="badge <?= $row['has_bonus'] === 'Yes' ? 'bg-success' : 'bg-secondary' ?>"><?= htmlspecialchars($row['has_bonus']) ?></span></td>
                                                <td class="text-success fw-bold"><?= number_format($row['total_paid'], 2) ?> LKR</td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="5" class="text-muted py-3">No worker payments this month.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Drivers Section -->
                    <div class="mb-5">
                        <h5 class="fw-bold text-secondary mb-3">Drivers (Daily Salary)</h5>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover border-top">
                                <thead class="table-light">
                                    <tr>
                                        <th>Employee ID</th>
                                        <th>Name</th>
                                        <th>Days Worked (Month)</th>
                                        <th>Holiday Bonus</th>
                                        <th>Total Salary (Month)</th>
                                    </tr>
                                </thead>
                                <tbody class="small">
                                    <?php if($driverLedger && $driverLedger->num_rows > 0): ?>
                                        <?php while($row = $driverLedger->fetch_assoc()): ?>
                                            <tr>
                                                <td class="fw-semibold"><?= htmlspecialchars($row['emp_id']) ?></td>
                                                <td><?= htmlspecialchars($row['username']) ?></td>
                                                <td class="text-center"><?= htmlspecialchars($row['days_worked']) ?> days</td>
                                                <td><span class="badge <?= $row['has_bonus'] === 'Yes' ? 'bg-success' : 'bg-secondary' ?>"><?= htmlspecialchars($row['has_bonus']) ?></span></td>
                                                <td class="text-success fw-bold"><?= number_format($row['total_paid'], 2) ?> LKR</td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="5" class="text-muted py-3">No driver payments this month.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade <?= $active_tab === 'expenseLedger' ? 'show active' : '' ?>" id="expenseLedger" role="tabpanel" aria-labelledby="expenseLedger-tab">
                <div class="card p-4 content-section">
                    <h4 class="fw-bold mb-3 text-dark">Combined General Expense Ledger</h4>
                    <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                        <table class="table table-sm text-center border-top">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Expense Classification Type</th>
                                    <th>Disbursed Amount</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="small">
                                <?php if($expenseLedger && $expenseLedger->num_rows > 0): ?>
                                    <?php while($el = $expenseLedger->fetch_assoc()): ?>
                                        <tr>
                                            <td>#<?= htmlspecialchars($el['expenseID']) ?></td>
                                            <td class="text-start"><?= htmlspecialchars($el['type']) ?></td>
                                            <td class="fw-semibold text-danger"><?= number_format($el['amount'], 2) ?> LKR</td>
                                            <td class="text-center">
                                                <form action="" method="POST" class="d-inline" onsubmit="return confirm('Delete this expense record?');">
                                                    <input type="hidden" name="action" value="delete_expense">
                                                    <input type="hidden" name="expense_id" value="<?= htmlspecialchars($el['expenseID']) ?>">
                                                    <input type="hidden" name="tab_id" value="expenseLedger">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" class="text-muted py-3">No overhead metrics allocated.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Reports Tab -->
            <div class="tab-pane fade <?= $active_tab === 'reports' ? 'show active' : '' ?>" id="reports" role="tabpanel" aria-labelledby="reports-tab">
                <div class="card p-4 content-section">
                    <h4 class="fw-bold mb-3 text-dark"><i class="bi bi-graph-up me-2"></i>Monthly Expense Report Generator</h4>
                    <p class="text-muted small mb-4">Generate comprehensive monthly expense reports with breakdown and export options.</p>

                    <!-- Report Generator Form -->
                    <form action="" method="POST" class="row g-3 mb-4 p-3 bg-light rounded-3">
                        <input type="hidden" name="action" value="generate_report">
                        <input type="hidden" name="tab_id" value="reports">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Select Year</label>
                            <select name="report_year" class="form-select" required>
                                <option value="">-- Choose Year --</option>
                                <?php 
                                    $currentYear = date('Y');
                                    for ($y = $currentYear - 2; $y <= $currentYear; $y++):
                                ?>
                                    <option value="<?= $y ?>" <?= $y == $currentYear ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Select Month</label>
                            <select name="report_month" class="form-select" required>
                                <option value="">-- Choose Month --</option>
                                <?php 
                                    $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                                    $currentMonth = date('n');
                                    for ($m = 1; $m <= 12; $m++):
                                ?>
                                    <option value="<?= $m ?>" <?= $m == $currentMonth ? 'selected' : '' ?>><?= $months[$m-1] ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-success w-100 shadow-sm fw-semibold">
                                <i class="bi bi-file-earmark-text me-2"></i>Generate Report
                            </button>
                        </div>
                    </form>

                    <!-- Report Display Section -->
                    <?php if ($report_generated && $current_report_data): ?>
                    <div id="reportContainer" class="mt-5">
                        <!-- Report Header -->
                        <div class="row mb-4 p-4 rounded-3" style="background: linear-gradient(135deg, #14532d 0%, #166534 52%, #15803d 100%); color: white;">
                            <div class="col-md-6">
                                <h3 class="fw-bold mb-1">THARU PRODUCTS</h3>
                                <p class="mb-0 small">Monthly Expense Report</p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <p class="mb-1 small"><strong>Report ID:</strong> <?= htmlspecialchars($current_report_data['report_id']) ?></p>
                                <p class="mb-1 small"><strong>Generated:</strong> <?= date('d M Y, H:i', strtotime($current_report_data['generated_date'])) ?></p>
                                <p class="mb-0 small"><strong>Period:</strong> <?= htmlspecialchars($current_report_data['month_display']) ?></p>
                            </div>
                        </div>

                        <!-- Expense Summary Table -->
                        <div class="table-responsive mb-4">
                            <table class="table table-striped table-bordered align-middle">
                                <thead class="table-dark">
                                    <tr>
                                        <th class="fw-bold">Expense Type</th>
                                        <th class="fw-bold text-end">Amount (LKR)</th>
                                        <th class="fw-bold text-end">% of Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                        $expenseCategories = [
                                            'sales_supervisor_salary' => 'Sales Supervisor Salaries',
                                            'stock_supervisor_salary' => 'Stock Supervisor Salaries',
                                            'worker_wages' => 'Worker Wages',
                                            'driver_wages' => 'Driver Wages',
                                            'raw_materials' => 'Raw Material Purchases',
                                            'machine_maintenance' => 'Machine Maintenance',
                                            'utility_bills' => 'Utility Bills'
                                        ];
                                        
                                        $total = array_sum($current_report_data['expenses']);
                                    ?>
                                    <?php foreach ($expenseCategories as $key => $label): ?>
                                        <?php
                                            $amount = $current_report_data['expenses'][$key];
                                            $percentage = $total > 0 ? ($amount / $total) * 100 : 0;
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($label) ?></td>
                                            <td class="text-end fw-semibold">LKR <?= number_format($amount, 2) ?></td>
                                            <td class="text-end"><?= number_format($percentage, 1) ?>%</td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="fw-bold" style="background-color: #f9f9f9;">
                                        <td>TOTAL MONTHLY EXPENSES</td>
                                        <td class="text-end text-success">LKR <?= number_format($total, 2) ?></td>
                                        <td class="text-end">100.0%</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Bar Chart - Responsive with Chart.js -->
                        <div class="card border-0 mb-4 p-4" style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border-radius: 12px;">
                            <h5 class="fw-bold mb-1 text-dark"><i class="bi bi-bar-chart-fill me-2" style="color: #667eea;"></i>Expense Distribution</h5>
                            <p class="text-muted small mb-3">Visual breakdown of monthly expenses by category</p>
                            <div style="position: relative; height: 350px; width: 100%;">
                                <canvas id="expenseChartCanvas"></canvas>
                            </div>
                        </div>

                        <!-- Export Buttons -->
                        <div class="d-flex gap-2 justify-content-center mb-4 flex-wrap">
                            <form action="" method="POST" class="d-inline" target="_blank">
                                <input type="hidden" name="action" value="export_report">
                                <input type="hidden" name="export_format" value="pdf">
                                <button type="submit" class="btn btn-primary btn-sm fw-semibold shadow-sm">
                                    <i class="bi bi-file-pdf me-2"></i>Download PDF
                                </button>
                            </form>
                            <form action="" method="POST" class="d-inline" target="_blank">
                                <input type="hidden" name="action" value="export_report">
                                <input type="hidden" name="export_format" value="excel">
                                <button type="submit" class="btn btn-success btn-sm fw-semibold shadow-sm">
                                    <i class="bi bi-file-earmark-excel me-2"></i>Download XLSX
                                </button>
                            </form>
                            <form action="" method="POST" class="d-inline" target="_blank">
                                <input type="hidden" name="action" value="export_report">
                                <input type="hidden" name="export_format" value="jpeg">
                                <button type="submit" class="btn btn-info btn-sm fw-semibold shadow-sm">
                                    <i class="bi bi-image me-2"></i>Download JPEG
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info border-0 mt-4">
                        <i class="bi bi-info-circle me-2"></i>Select a month and click "Generate Report" to view expense breakdown.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<div class="modal fade" id="payrollProcessingModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form action="" method="POST" class="modal-content border-0 shadow-lg" style="border-radius:20px;">
            <input type="hidden" name="action" value="calculate_salary">
            <input type="hidden" id="modalAttendanceID" name="attendance_id" value="">
            
            <div class="modal-header border-0 bg-light px-4 pt-4">
                <div>
                    <h5 class="modal-title fw-bold" id="modalTargetName">Calculate Salary Profile</h5>
                    <span class="badge bg-dark rounded-pill" id="modalTargetRole">Role</span>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <div class="modal-body px-4 py-3">
                
                <div class="mb-3">
                    <label class="form-label small fw-bold">Select Settlement Pay Day</label>
                    <input type="date" name="paydate" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="form-check form-switch p-0 mb-3 d-flex align-items-center justify-content-between border rounded p-3 bg-light">
                    <div>
                        <label class="form-check-label small fw-bold text-dark d-block">Holiday Bonus Condition</label>
                        <small class="text-muted d-block" style="font-size:0.75rem;">Toggle to manually overlay specialized adjustments.</small>
                    </div>
                    <input class="form-check-input me-0" type="checkbox" id="holidayBonusToggle" name="holiday_bonus_toggle" onchange="toggleBonusInputBox(this.checked)">
                </div>

                <div id="bonusInputContainer" class="mb-2 d-none">
                    <label class="form-label small fw-bold text-success">Manual Holiday Premium Amount (LKR)</label>
                    <input type="number" step="0.01" min="0" id="bonusAmountField" name="bonus_amount" class="form-control" value="0.00">
                </div>

            </div>
            
            <div class="modal-footer border-0 bg-light p-3 px-4">
                <button type="button" class="btn btn-secondary border-0 text-dark bg-transparent fw-semibold" data-bs-dismiss="modal">Abort</button>
                <button type="submit" class="btn btn-success px-4 shadow-sm">Process & Record Payout</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="salaryEditModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form action="" method="POST" class="modal-content border-0 shadow-lg" style="border-radius:20px;">
            <input type="hidden" name="action" value="update_salary">
            <input type="hidden" name="tab_id" value="salaryLedger">
            <input type="hidden" id="salaryEditID" name="salary_id" value="">
            <div class="modal-header border-0 bg-light px-4 pt-4">
                <h5 class="modal-title fw-bold">Edit Salary Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body px-4 py-3">
                <div class="mb-3">
                    <label class="form-label small fw-bold">Payment Date</label>
                    <input type="date" id="salaryEditPaydate" name="paydate" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Total Amount Paid (LKR)</label>
                    <input type="number" step="0.01" min="0" id="salaryEditAmount" name="amount" class="form-control" required>
                </div>
            </div>
            <div class="modal-footer border-0 bg-light p-3 px-4">
                <button type="button" class="btn btn-secondary border-0 text-dark bg-transparent fw-semibold" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary px-4 shadow-sm">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="attendanceEditModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form action="" method="POST" class="modal-content border-0 shadow-lg" style="border-radius:20px;">
            <input type="hidden" name="action" value="update_attendance">
            <input type="hidden" name="tab_id" value="attendanceTab">
            <input type="hidden" id="attendanceEditID" name="attendance_id" value="">
            <div class="modal-header border-0 bg-light px-4 pt-4">
                <h5 class="modal-title fw-bold">Edit Attendance Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body px-4 py-3">
                <div class="mb-3">
                    <label class="form-label small fw-bold">Employee</label>
                    <select class="form-select" id="attendanceEditUser" name="attendance_user_id" required>
                        <option value="">-- Select Employee --</option>
                        <?php if (!empty($attendanceEmployeeRows)): ?>
                            <?php foreach ($attendanceEmployeeRows as $user): ?>
                                <option value="<?= htmlspecialchars($user['userID']) ?>"><?= htmlspecialchars($user['username']) ?> (<?= htmlspecialchars($user['display_role']) ?>)</option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Date</label>
                    <input type="date" id="attendanceEditDate" name="attendance_date" class="form-control" required>
                </div>
                <div class="mb-3 row gx-2">
                    <div class="col-6">
                        <label class="form-label small fw-bold">Login Time</label>
                        <input type="time" id="attendanceEditLogin" name="login_time" class="form-control" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-bold">Logout Time</label>
                        <input type="time" id="attendanceEditLogout" name="logout_time" class="form-control" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 bg-light p-3 px-4">
                <button type="button" class="btn btn-secondary border-0 text-dark bg-transparent fw-semibold" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary px-4 shadow-sm">Save Attendance</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Handle Custom Dropdown Selector Displays
    function toggleCustomExpenseBox(val) {
        const wrap = document.getElementById('customExpenseWrapper');
        if (val === 'Other') {
            wrap.classList.remove('d-none');
            wrap.querySelector('input').setAttribute('required', 'true');
        } else {
            wrap.classList.add('d-none');
            wrap.querySelector('input').removeAttribute('required');
        }
    }

    // Modal Interaction Handlers
    let myModal;
    document.addEventListener("DOMContentLoaded", function() {
        myModal = new bootstrap.Modal(document.getElementById('payrollProcessingModal'));
    });

    function openPayrollModal(attId, empName, empRole) {
        document.getElementById('modalAttendanceID').value = attId;
        document.getElementById('modalTargetName').innerText = "Process Run: " + empName;
        document.getElementById('modalTargetRole').innerText = empRole;
        
        // Reset dynamic elements cleanly on form load initialization
        document.getElementById('holidayBonusToggle').checked = false;
        const container = document.getElementById('bonusInputContainer');
        const field = document.getElementById('bonusAmountField');
        container.classList.add('d-none');
        field.value = "0.00";
        field.removeAttribute('required');
        
        myModal.show();
    }

    function openSalaryEditModal(salaryId, paydate, amount) {
        document.getElementById('salaryEditID').value = salaryId;
        document.getElementById('salaryEditPaydate').value = paydate;
        document.getElementById('salaryEditAmount').value = parseFloat(amount).toFixed(2);
        new bootstrap.Modal(document.getElementById('salaryEditModal')).show();
    }

    function openAttendanceEditModal(attendanceId, date, login, logout, userID) {
        document.getElementById('attendanceEditID').value = attendanceId;
        document.getElementById('attendanceEditDate').value = date;
        document.getElementById('attendanceEditLogin').value = login;
        document.getElementById('attendanceEditLogout').value = logout;

        const editUserSelect = document.getElementById('attendanceEditUser');
        // userID is the user_tbl.userID for the employee; set it directly
        editUserSelect.value = userID || '';
        new bootstrap.Modal(document.getElementById('attendanceEditModal')).show();
    }

    // Javascript Toggle Listener revealing or hiding the Manual Holiday Box component
    function toggleBonusInputBox(isChecked) {
        const container = document.getElementById('bonusInputContainer');
        const field = document.getElementById('bonusAmountField');
        if (isChecked) {
            container.classList.remove('d-none');
            field.setAttribute('required', 'true');
            field.value = "";
            field.focus();
        } else {
            container.classList.add('d-none');
            field.value = "0.00";
            field.removeAttribute('required');
        }
    }

    function parseTimeValue(inputValue) {
        if (!inputValue) {
            return null;
        }
        const parts = String(inputValue).split(':');
        if (parts.length < 2) {
            return null;
        }
        const hours = parseInt(parts[0], 10) || 0;
        const minutes = parseInt(parts[1], 10) || 0;
        const seconds = parseInt(parts[2], 10) || 0;
        return new Date(2000, 0, 1, hours, minutes, seconds);
    }

    function calculateOvertimeHours(loginValue, logoutValue) {
        const loginTime = parseTimeValue(loginValue);
        const logoutTime = parseTimeValue(logoutValue);
        if (!loginTime || !logoutTime) {
            return 0;
        }
        let workedMs = logoutTime.getTime() - loginTime.getTime();
        if (workedMs < 0) {
            workedMs += 24 * 60 * 60 * 1000;
        }
        const workedHours = workedMs / (1000 * 60 * 60);
        return workedHours > 8 ? workedHours - 8 : 0;
    }

    function loadEmployeeData() {
        const select = document.getElementById('employeeSelect');
        const selectedOption = select.options[select.selectedIndex];
        const baseSalaryInput = document.getElementById('baseSalary');
        const otRateInput = document.getElementById('otRate');
        const otRateDisplayInput = document.getElementById('otRateDisplay');
        const bonusAmount = document.getElementById('bonusAmount');
        const attendanceSelect = document.getElementById('attendanceSelect');

        if (!selectedOption || !selectedOption.value) {
            baseSalaryInput.value = 0;
            otRateInput.value = 0;
            otRateDisplayInput.value = 0;
            bonusAmount.value = 0;
            calculateTotalSalary();
            filterAttendanceRecords('');
            attendanceSelect.value = '';
            return;
        }

        const baseSalary = parseFloat(selectedOption.getAttribute('data-base-salary')) || 0;
        const otRate = parseFloat(selectedOption.getAttribute('data-ot-rate')) || 0;
        baseSalaryInput.value = baseSalary.toFixed(2);
        otRateInput.value = otRate.toFixed(2);
        otRateDisplayInput.value = otRate.toFixed(2);
        document.getElementById('basePayHidden').value = baseSalary.toFixed(2);
        filterAttendanceRecords(selectedOption.value);

        const firstVisibleOption = Array.from(attendanceSelect.options).find(option => option.value && !option.hidden && !option.disabled);
        if (firstVisibleOption) {
            attendanceSelect.value = firstVisibleOption.value;
            loadAttendanceData();
        } else {
            attendanceSelect.value = '';
            calculateTotalSalary();
        }
    }

    function loadAttendanceData() {
        const select = document.getElementById('attendanceSelect');
        const selectedOption = select.options[select.selectedIndex];
        const attendanceSummary = document.getElementById('attendanceSummary');
        const baseSalaryInput = document.getElementById('baseSalary');
        const otRateInput = document.getElementById('otRate');
        const otRateDisplayInput = document.getElementById('otRateDisplay');
        const otHoursInput = document.getElementById('otHours');

        if (!selectedOption || !selectedOption.value) {
            attendanceSummary.value = '';
            baseSalaryInput.value = 0;
            otRateInput.value = 0;
            otRateDisplayInput.value = 0;
            otHoursInput.value = 0;
            document.getElementById('basePayHidden').value = 0;
            document.getElementById('otPayHidden').value = 0;
            calculateTotalSalary();
            return;
        }

        const login = selectedOption.getAttribute('data-login') || '';
        const logout = selectedOption.getAttribute('data-logout') || '';
        const date = selectedOption.getAttribute('data-date') || '';
        const role = selectedOption.getAttribute('data-role') || 'Supervisor';
        const baseSalary = parseFloat(selectedOption.getAttribute('data-base-salary')) || 0;
        const otRate = parseFloat(selectedOption.getAttribute('data-ot-rate')) || 0;
        const hourRate = parseFloat(selectedOption.getAttribute('data-hour-rate')) || 0;
        const fixedSalary = parseFloat(selectedOption.getAttribute('data-fixed-salary')) || 0;

        attendanceSummary.value = date + ' | ' + login + ' - ' + logout;
        let effectiveBase = 0;
        let effectiveOtRate = 0;
        let otHours = 0;

        if (role === 'Worker') {
            const workedHours = calculateWorkedHours(login, logout);
            effectiveBase = workedHours * hourRate;
            effectiveOtRate = 0;
            otHours = 0;
        } else if (role === 'Driver') {
            effectiveBase = fixedSalary;
            effectiveOtRate = 0;
            otHours = 0;
        } else {
            effectiveBase = baseSalary;
            effectiveOtRate = otRate;
            otHours = calculateOvertimeHours(login, logout).toFixed(2);
        }

        baseSalaryInput.value = effectiveBase.toFixed(2);
        otRateInput.value = effectiveOtRate.toFixed(2);
        otRateDisplayInput.value = effectiveOtRate.toFixed(2);
        document.getElementById('basePayHidden').value = effectiveBase.toFixed(2);
        document.getElementById('otPayHidden').value = (otHours * effectiveOtRate).toFixed(2);
        otHoursInput.value = otHours;
        calculateTotalSalary();
    }

    function calculateWorkedHours(loginValue, logoutValue) {
        const loginTime = parseTimeValue(loginValue);
        const logoutTime = parseTimeValue(logoutValue);
        if (!loginTime || !logoutTime) {
            return 0;
        }
        let workedMs = logoutTime.getTime() - loginTime.getTime();
        if (workedMs < 0) {
            workedMs += 24 * 60 * 60 * 1000;
        }
        return workedMs / (1000 * 60 * 60);
    }

    function filterAttendanceRecords(selectedEmployeeId) {
        const attendanceSelect = document.getElementById('attendanceSelect');
        const attendanceSummary = document.getElementById('attendanceSummary');
        const selectedValue = attendanceSelect.value;
        let preservedSelectionValid = false;
        let visibleCount = 0;

        for (let i = 0; i < attendanceSelect.options.length; i++) {
            const option = attendanceSelect.options[i];
            if (!option.value) {
                option.hidden = false;
                option.disabled = false;
                option.style.display = '';
                continue;
            }
            const optionUserId = (option.getAttribute('data-user-id') || '').trim();
            const shouldShow = !selectedEmployeeId || optionUserId === selectedEmployeeId;
            option.hidden = !shouldShow;
            option.disabled = !shouldShow;
            option.style.display = shouldShow ? '' : 'none';
            if (shouldShow) {
                visibleCount++;
            }
            if (option.value === selectedValue && shouldShow) {
                preservedSelectionValid = true;
            }
        }

        if (!preservedSelectionValid) {
            attendanceSelect.value = '';
            attendanceSummary.value = '';
            document.getElementById('basePayHidden').value = 0;
            document.getElementById('otPayHidden').value = 0;
            calculateTotalSalary();
        }

        const placeholder = attendanceSelect.querySelector('option[value=""]');
        if (placeholder) {
            placeholder.textContent = visibleCount === 0
                ? '-- No attendance record for selected supervisor --'
                : '-- Choose Attendance Record --';
        }

        console.debug('Attendance filter', { selectedEmployeeId, visibleCount });
    }

    function toggleBonusField() {
        const bonusToggle = document.getElementById('bonusToggle');
        const bonusInput = document.getElementById('bonusAmount');
        bonusInput.disabled = !bonusToggle.checked;
        if (!bonusToggle.checked) {
            bonusInput.value = 0;
        }
        calculateTotalSalary();
    }

    function calculateTotalSalary() {
        const baseSalary = parseFloat(document.getElementById('baseSalary').value) || 0;
        const otHours = parseFloat(document.getElementById('otHours').value) || 0;
        const otRate = parseFloat(document.getElementById('otRateDisplay').value) || parseFloat(document.getElementById('otRate').value) || 0;
        const bonus = parseFloat(document.getElementById('bonusAmount').value) || 0;

        const basePay = baseSalary;
        const otPay = otHours * otRate;
        document.getElementById('basePayHidden').value = basePay.toFixed(2);
        document.getElementById('otPayHidden').value = otPay.toFixed(2);

        const totalPayout = basePay + otPay + bonus;
        document.getElementById('displayTotalPayout').innerText = 'LKR ' + totalPayout.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function resetSupervisorSalaryPortal() {
        document.getElementById('employeeSelect').selectedIndex = 0;
        document.getElementById('attendanceSelect').selectedIndex = 0;
        document.getElementById('attendanceSummary').value = '';
        document.getElementById('baseSalary').value = 0;
        document.getElementById('otRate').value = 0;
        document.getElementById('otRateDisplay').value = 0;
        document.getElementById('otHours').value = 0;
        document.getElementById('bonusAmount').value = 0;
        document.getElementById('bonusToggle').checked = false;
        document.getElementById('basePayHidden').value = 0;
        document.getElementById('otPayHidden').value = 0;
        document.getElementById('displayTotalPayout').innerText = 'LKR 0.00';
    }

    // ===========================
    // EXPENSE REPORT CHART VISUALIZATION with Chart.js
    // ===========================
    
    let expenseChartInstance = null;
    
    function drawExpenseChart() {
        const canvas = document.getElementById('expenseChartCanvas');
        if (!canvas) return;
        
        // Extract expense data from the table
        const tableRows = document.querySelectorAll('#reportContainer table tbody tr');
        const labels = [];
        const amounts = [];
        
        tableRows.forEach((row) => {
            // Skip the total row
            if (row.classList.contains('fw-bold')) return;
            
            const cells = row.querySelectorAll('td');
            if (cells.length >= 2) {
                const label = cells[0].textContent.trim().substring(0, 20); // Shorten labels
                const amountText = cells[1].textContent.replace('LKR', '').trim();
                const amount = parseFloat(amountText.replace(/,/g, ''));
                
                if (!isNaN(amount)) {
                    labels.push(label);
                    amounts.push(amount);
                }
            }
        });
        
        if (labels.length === 0) return;
        
        // Destroy previous chart if exists
        if (expenseChartInstance) {
            expenseChartInstance.destroy();
        }
        
        const ctx = canvas.getContext('2d');
        
        // Create gradient colors
        const colors = [
            '#667eea', '#764ba2', '#f093fb', '#4facfe', 
            '#00f2fe', '#43e97b', '#fa709a'
        ];
        
        const backgroundColors = colors.slice(0, amounts.length).map(c => c + '40'); // Add transparency
        const borderColors = colors.slice(0, amounts.length);
        
        expenseChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Amount (LKR)',
                    data: amounts,
                    backgroundColor: backgroundColors,
                    borderColor: borderColors,
                    borderWidth: 2,
                    borderRadius: 8,
                    borderSkipped: false,
                    hoverBackgroundColor: borderColors,
                    hoverBorderWidth: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0,0,0,0.8)',
                        padding: 12,
                        titleFont: { size: 13, weight: 'bold' },
                        bodyFont: { size: 12 },
                        callbacks: {
                            label: function(context) {
                                return 'LKR ' + context.parsed.y.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'LKR ' + value.toLocaleString();
                            },
                            font: { size: 11 }
                        },
                        grid: {
                            color: 'rgba(0,0,0,0.05)'
                        }
                    },
                    x: {
                        ticks: {
                            font: { size: 11 }
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }

    // Draw chart when reports page loads or when tab is shown
    window.addEventListener('load', function() {
        setTimeout(drawExpenseChart, 100);
    });
    
    // Redraw chart when tab is shown (handles visibility changes)
    const reportsTab = document.getElementById('reports-tab');
    if (reportsTab) {
        reportsTab.addEventListener('shown.bs.tab', function() {
            setTimeout(drawExpenseChart, 100);
        });
    }
</script>
</body>
</html>
