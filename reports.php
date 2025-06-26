<?php
require_once 'config.php';
checkAdminLogin();

$database = Database::getInstance();
$db = $database->getConnection();

// متغيرات التقرير
$report_type = $_GET['type'] ?? 'attendance';
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // بداية الشهر
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // اليوم
$employee_filter = $_GET['employee'] ?? '';
$department_filter = $_GET['department'] ?? '';

// جلب قائمة الأقسام
$departments_stmt = $db->prepare("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department != '' ORDER BY department");
$departments_stmt->execute();
$departments = $departments_stmt->fetchAll(PDO::FETCH_COLUMN);

// جلب قائمة الموظفين
$employees_stmt = $db->prepare("SELECT id, full_name, employee_id, department FROM employees WHERE is_active = 1 ORDER BY full_name");
$employees_stmt->execute();
$employees_list = $employees_stmt->fetchAll(PDO::FETCH_ASSOC);

$app_name = getSetting('company_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>التقارير - <?php echo htmlspecialchars($app_name); ?></title>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            color: #2c3e50;
            line-height: 1.6;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 0;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo-section {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .logo {
            width: 55px;
            height: 55px;
            background: rgba(255,255,255,0.2);
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
        }
        
        .header-title {
            font-size: 28px;
            font-weight: 700;
        }
        
        .nav-menu {
            background: white;
            padding: 15px 0;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .nav-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .nav-items {
            display: flex;
            gap: 30px;
            list-style: none;
        }
        
        .nav-item a {
            color: #2c3e50;
            text-decoration: none;
            font-weight: 500;
            padding: 10px 15px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .nav-item a:hover,
        .nav-item a.active {
            background: #667eea;
            color: white;
        }
        
        .main-content {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 20px;
        }
        
        .page-header {
            margin-bottom: 30px;
        }
        
        .page-title {
            font-size: 32px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .page-description {
            color: #7f8c8d;
            font-size: 16px;
        }
        
        .report-controls {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }
        
        .controls-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            align-items: end;
        }
        
        .control-group {
            display: flex;
            flex-direction: column;
        }
        
        .control-group label {
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .control-group select,
        .control-group input {
            padding: 12px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .control-group select:focus,
        .control-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #56ab2f, #a8e6cf);
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .report-tabs {
            display: flex;
            background: white;
            border-radius: 15px;
            padding: 5px;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        
        .tab-btn {
            flex: 1;
            padding: 15px;
            text-align: center;
            border: none;
            background: none;
            cursor: pointer;
            font-weight: 600;
            border-radius: 10px;
            transition: all 0.3s ease;
            color: #7f8c8d;
        }
        
        .tab-btn.active {
            background: #667eea;
            color: white;
        }
        
        .report-content {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            padding: 25px;
        }
        
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            border-left: 4px solid #667eea;
        }
        
        .summary-number {
            font-size: 24px;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 5px;
        }
        
        .summary-label {
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .chart-container {
            margin: 30px 0;
            height: 400px;
            background: #f8f9fa;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .table th,
        .table td {
            padding: 12px;
            text-align: right;
            border-bottom: 1px solid #dee2e6;
        }
        
        .table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }
        
        .table tr:hover {
            background: #f8f9fa;
        }
        
        .export-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 0 15px;
            }
            
            .controls-grid {
                grid-template-columns: 1fr;
            }
            
            .report-tabs {
                flex-direction: column;
            }
            
            .summary-cards {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo-section">
                <div class="logo">📈</div>
                <div>
                    <div class="header-title">التقارير والإحصائيات</div>
                </div>
            </div>
        </div>
    </header>
    
    <nav class="nav-menu">
        <div class="nav-content">
            <ul class="nav-items">
                <li class="nav-item"><a href="admin_dashboard.php">الرئيسية</a></li>
                <li class="nav-item"><a href="employees.php">الموظفين</a></li>
                <li class="nav-item"><a href="attendance.php">الحضور</a></li>
                <li class="nav-item"><a href="leaves.php">الإجازات</a></li>
                <li class="nav-item"><a href="reports.php" class="active">التقارير</a></li>
                <li class="nav-item"><a href="settings.php">الإعدادات</a></li>
            </ul>
        </div>
    </nav>
    
    <main class="main-content">
        <div class="page-header">
            <h1 class="page-title">التقارير والإحصائيات</h1>
            <p class="page-description">تقارير شاملة ومفصلة عن الحضور والأداء</p>
        </div>
        
        <!-- أدوات التحكم في التقرير -->
        <div class="report-controls">
            <form method="GET" id="reportForm">
                <div class="controls-grid">
                    <div class="control-group">
                        <label>نوع التقرير</label>
                        <select name="type" onchange="updateReportType()">
                            <option value="attendance" <?php echo $report_type === 'attendance' ? 'selected' : ''; ?>>تقرير الحضور</option>
                            <option value="employee_summary" <?php echo $report_type === 'employee_summary' ? 'selected' : ''; ?>>ملخص الموظفين</option>
                            <option value="department" <?php echo $report_type === 'department' ? 'selected' : ''; ?>>تقرير الأقسام</option>
                            <option value="leaves" <?php echo $report_type === 'leaves' ? 'selected' : ''; ?>>تقرير الإجازات</option>
                            <option value="late_arrivals" <?php echo $report_type === 'late_arrivals' ? 'selected' : ''; ?>>التأخير</option>
                        </select>
                    </div>
                    
                    <div class="control-group">
                        <label>من تاريخ</label>
                        <input type="date" name="date_from" value="<?php echo $date_from; ?>">
                    </div>
                    
                    <div class="control-group">
                        <label>إلى تاريخ</label>
                        <input type="date" name="date_to" value="<?php echo $date_to; ?>">
                    </div>
                    
                    <div class="control-group">
                        <label>الموظف</label>
                        <select name="employee">
                            <option value="">جميع الموظفين</option>
                            <?php foreach ($employees_list as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>" 
                                        <?php echo $employee_filter == $emp['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($emp['full_name'] . ' - ' . $emp['employee_id']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="control-group">
                        <label>القسم</label>
                        <select name="department">
                            <option value="">جميع الأقسام</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept); ?>" 
                                        <?php echo $department_filter === $dept ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        🔍 إنشاء التقرير
                    </button>
                </div>
            </form>
        </div>
        
        <!-- إجراءات التصدير -->
        <div class="export-actions">
            <button class="btn btn-success" onclick="exportReport('excel')">
                📊 تصدير Excel
            </button>
            <button class="btn btn-success" onclick="exportReport('pdf')">
                📄 تصدير PDF
            </button>
            <button class="btn btn-primary" onclick="printReport()">
                🖨️ طباعة
            </button>
        </div>
        
        <!-- محتوى التقرير -->
        <div class="report-content">
            <div id="reportResults">
                <!-- النتائج ستُحمل هنا بـ JavaScript -->
                <div style="text-align: center; padding: 40px; color: #6c757d;">
                    <div style="font-size: 48px; margin-bottom: 20px;">📊</div>
                    <h3>اختر معايير التقرير واضغط "إنشاء التقرير"</h3>
                    <p>سيتم عرض النتائج والإحصائيات هنا</p>
                </div>
            </div>
        </div>
    </main>

    <script>
        function updateReportType() {
            // يمكن إضافة منطق لتحديث الحقول بناءً على نوع التقرير
            console.log('Report type updated');
        }
        
        function exportReport(format) {
            const form = document.getElementById('reportForm');
            const formData = new FormData(form);
            formData.append('export', format);
            
            // إنشاء رابط للتصدير
            const params = new URLSearchParams();
            for (let [key, value] of formData) {
                params.append(key, value);
            }
            
            window.open(`export_report.php?${params.toString()}`, '_blank');
        }
        
        function printReport() {
            const printContent = document.getElementById('reportResults').innerHTML;
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>تقرير - <?php echo htmlspecialchars($app_name); ?></title>
                    <style>
                        body { font-family: Arial, sans-serif; direction: rtl; }
                        .print-header { text-align: center; margin-bottom: 30px; }
                        .print-date { color: #666; margin-bottom: 20px; }
                        table { width: 100%; border-collapse: collapse; }
                        th, td { padding: 8px; border: 1px solid #ddd; text-align: right; }
                        th { background: #f5f5f5; }
                        @media print { .no-print { display: none; } }
                    </style>
                </head>
                <body>
                    <div class="print-header">
                        <h1><?php echo htmlspecialchars($app_name); ?></h1>
                        <h2>تقرير الحضور والانصراف</h2>
                        <div class="print-date">تاريخ الطباعة: ${new Date().toLocaleDateString('ar-SA')}</div>
                    </div>
                    ${printContent}
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }
        
        // تحميل التقرير تلقائياً إذا كانت المعايير محددة
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('type') && urlParams.get('date_from')) {
                loadReport();
            }
        });
        
        // تحميل التقرير عبر AJAX
        function loadReport() {
            const form = document.getElementById('reportForm');
            const formData = new FormData(form);
            formData.append('ajax', '1');
            
            const resultsDiv = document.getElementById('reportResults');
            resultsDiv.innerHTML = '<div style="text-align: center; padding: 40px;"><div class="spinner"></div><p>جاري تحميل التقرير...</p></div>';
            
            fetch('generate_report.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    resultsDiv.innerHTML = data.html;
                } else {
                    resultsDiv.innerHTML = `<div style="text-align: center; padding: 40px; color: #dc3545;"><h3>خطأ في التقرير</h3><p>${data.message}</p></div>`;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                resultsDiv.innerHTML = '<div style="text-align: center; padding: 40px; color: #dc3545;"><h3>خطأ في الاتصال</h3><p>تعذر تحميل التقرير</p></div>';
            });
        }
        
        // إرسال النموذج عبر AJAX
        document.getElementById('reportForm').addEventListener('submit', function(e) {
            e.preventDefault();
            loadReport();
        });
        
        // إضافة spinner CSS
        const style = document.createElement('style');
        style.textContent = `
            .spinner {
                border: 3px solid #f3f3f3;
                border-top: 3px solid #667eea;
                border-radius: 50%;
                width: 30px;
                height: 30px;
                animation: spin 1s linear infinite;
                margin: 0 auto 20px;
            }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>