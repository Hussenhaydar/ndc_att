<?php
require_once 'config.php';
checkAdminLogin();

$database = Database::getInstance();
$db = $database->getConnection();

$message = '';
$error = '';

// معالجة الإجراءات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'edit_attendance') {
            $id = (int)$_POST['id'];
            $check_in_time = $_POST['check_in_time'] ?? null;
            $check_out_time = $_POST['check_out_time'] ?? null;
            $notes = Security::sanitizeInput($_POST['notes'] ?? '');
            
            // حساب ساعات العمل والتأخير
            $work_hours = 0;
            $is_late = false;
            $late_minutes = 0;
            
            if ($check_in_time && $check_out_time) {
                $work_hours = calculateWorkHours($check_in_time, $check_out_time);
            }
            
            if ($check_in_time) {
                // جلب وقت بداية العمل للموظف
                $emp_stmt = $db->prepare("SELECT e.work_start_time FROM employees e JOIN attendance a ON e.id = a.employee_id WHERE a.id = ?");
                $emp_stmt->execute([$id]);
                $work_start_time = $emp_stmt->fetchColumn() ?: getSetting('work_start_time', '08:00:00');
                
                $is_late = isLate($check_in_time, $work_start_time);
                $late_minutes = getLateMinutes($check_in_time, $work_start_time);
            }
            
            $stmt = $db->prepare("
                UPDATE attendance SET 
                    check_in_time = ?, check_out_time = ?, work_hours = ?, 
                    is_late = ?, late_minutes = ?, notes = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $check_in_time, $check_out_time, $work_hours,
                $is_late, $late_minutes, $notes, $id
            ]);
            
            logActivity('admin', $_SESSION['admin_id'], 'edit_attendance', "تعديل سجل حضور - رقم السجل: $id");
            $message = 'تم تحديث سجل الحضور بنجاح';
            
        } elseif ($action === 'delete_attendance') {
            $id = (int)$_POST['id'];
            
            $stmt = $db->prepare("DELETE FROM attendance WHERE id = ?");
            $stmt->execute([$id]);
            
            logActivity('admin', $_SESSION['admin_id'], 'delete_attendance', "حذف سجل حضور - رقم السجل: $id");
            $message = 'تم حذف سجل الحضور بنجاح';
            
        } elseif ($action === 'add_manual_attendance') {
            $employee_id = (int)$_POST['employee_id'];
            $attendance_date = $_POST['attendance_date'];
            $check_in_time = $_POST['check_in_time'];
            $check_out_time = $_POST['check_out_time'] ?? null;
            $notes = Security::sanitizeInput($_POST['notes'] ?? '');
            
            // التحقق من عدم وجود سجل مسبق
            $check_stmt = $db->prepare("SELECT id FROM attendance WHERE employee_id = ? AND attendance_date = ?");
            $check_stmt->execute([$employee_id, $attendance_date]);
            if ($check_stmt->rowCount() > 0) {
                throw new Exception('يوجد سجل حضور مسبق لهذا الموظف في نفس التاريخ');
            }
            
            // حساب البيانات
            $work_hours = 0;
            $is_late = false;
            $late_minutes = 0;
            
            if ($check_in_time && $check_out_time) {
                $work_hours = calculateWorkHours($attendance_date . ' ' . $check_in_time, $attendance_date . ' ' . $check_out_time);
            }
            
            if ($check_in_time) {
                $emp_stmt = $db->prepare("SELECT work_start_time FROM employees WHERE id = ?");
                $emp_stmt->execute([$employee_id]);
                $work_start_time = $emp_stmt->fetchColumn() ?: getSetting('work_start_time', '08:00:00');
                
                $is_late = isLate($check_in_time, $work_start_time);
                $late_minutes = getLateMinutes($check_in_time, $work_start_time);
            }
            
            $stmt = $db->prepare("
                INSERT INTO attendance (employee_id, attendance_date, check_in_time, check_out_time, 
                                       work_hours, is_late, late_minutes, status, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $check_in_datetime = $check_in_time ? $attendance_date . ' ' . $check_in_time : null;
            $check_out_datetime = $check_out_time ? $attendance_date . ' ' . $check_out_time : null;
            $status = $is_late ? 'late' : 'present';
            
            $stmt->execute([
                $employee_id, $attendance_date, $check_in_datetime, $check_out_datetime,
                $work_hours, $is_late, $late_minutes, $status, $notes
            ]);
            
            logActivity('admin', $_SESSION['admin_id'], 'add_manual_attendance', "إضافة سجل حضور يدوي - الموظف: $employee_id");
            $message = 'تم إضافة سجل الحضور بنجاح';
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// معالجة البحث والفلترة
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$employee_filter = $_GET['employee'] ?? '';
$department_filter = $_GET['department'] ?? '';
$status_filter = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

// بناء استعلام البحث
$where_conditions = ["a.attendance_date BETWEEN ? AND ?"];
$params = [$date_from, $date_to];

if (!empty($employee_filter)) {
    $where_conditions[] = "(e.full_name LIKE ? OR e.employee_id LIKE ?)";
    $search_term = "%$employee_filter%";
    $params = array_merge($params, [$search_term, $search_term]);
}

if (!empty($department_filter)) {
    $where_conditions[] = "e.department = ?";
    $params[] = $department_filter;
}

if (!empty($status_filter)) {
    switch ($status_filter) {
        case 'present':
            $where_conditions[] = "a.check_in_time IS NOT NULL AND a.is_late = 0";
            break;
        case 'late':
            $where_conditions[] = "a.is_late = 1";
            break;
        case 'absent':
            $where_conditions[] = "a.check_in_time IS NULL";
            break;
        case 'incomplete':
            $where_conditions[] = "a.check_in_time IS NOT NULL AND a.check_out_time IS NULL";
            break;
    }
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// جلب سجلات الحضور
$query = "
    SELECT a.*, e.full_name, e.employee_id, e.department, e.position
    FROM attendance a 
    JOIN employees e ON a.employee_id = e.id 
    $where_clause 
    ORDER BY a.attendance_date DESC, a.check_in_time DESC 
    LIMIT ? OFFSET ?
";

$params[] = $per_page;
$params[] = $offset;

$stmt = $db->prepare($query);
$stmt->execute($params);
$attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// حساب العدد الكلي
$count_params = array_slice($params, 0, -2);
$count_query = "
    SELECT COUNT(*) 
    FROM attendance a 
    JOIN employees e ON a.employee_id = e.id 
    $where_clause
";
$count_stmt = $db->prepare($count_query);
$count_stmt->execute($count_params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

// جلب قائمة الموظفين للفلتر
$employees_stmt = $db->prepare("SELECT id, full_name, employee_id FROM employees WHERE is_active = 1 ORDER BY full_name");
$employees_stmt->execute();
$employees_list = $employees_stmt->fetchAll(PDO::FETCH_ASSOC);

// جلب قائمة الأقسام
$departments_stmt = $db->prepare("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department != '' ORDER BY department");
$departments_stmt->execute();
$departments = $departments_stmt->fetchAll(PDO::FETCH_COLUMN);

// إحصائيات الفترة المحددة
$stats_stmt = $db->prepare("
    SELECT 
        COUNT(DISTINCT a.employee_id) as unique_employees,
        COUNT(a.id) as total_records,
        SUM(CASE WHEN a.check_in_time IS NOT NULL THEN 1 ELSE 0 END) as present_days,
        SUM(CASE WHEN a.is_late = 1 THEN 1 ELSE 0 END) as late_days,
        AVG(a.work_hours) as avg_work_hours,
        SUM(a.work_hours) as total_work_hours
    FROM attendance a 
    JOIN employees e ON a.employee_id = e.id 
    $where_clause
");

$stats_params = array_slice($params, 0, -2);
$stats_stmt->execute($stats_params);
$period_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$app_name = getSetting('company_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الحضور - <?php echo htmlspecialchars($app_name); ?></title>
    
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .page-title {
            font-size: 32px;
            font-weight: 700;
            color: #2c3e50;
        }
        
        .header-actions {
            display: flex;
            gap: 10px;
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
            background: linear-gradient(135deg, #56ab2f, #a8e6cf);
            color: white;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            text-align: center;
            border: 1px solid #f1f3f4;
        }
        
        .stat-number {
            font-size: 24px;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .filters-section {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-group label {
            margin-bottom: 5px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .filter-group input,
        .filter-group select {
            padding: 10px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .attendance-table {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th,
        .table td {
            padding: 15px 10px;
            text-align: right;
            border-bottom: 1px solid #f1f3f4;
        }
        
        .table th {
            background: #f8fafc;
            font-weight: 600;
            color: #475569;
            font-size: 14px;
        }
        
        .table tr:hover {
            background: #f8fafc;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
        }
        
        .status-present {
            background: #d4edda;
            color: #155724;
        }
        
        .status-late {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-absent {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-incomplete {
            background: #cce5ff;
            color: #004085;
        }
        
        .actions {
            display: flex;
            gap: 5px;
        }
        
        .action-btn {
            padding: 6px 8px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .action-edit {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .action-delete {
            background: #ffebee;
            color: #d32f2f;
        }
        
        .action-view {
            background: #f3e5f5;
            color: #7b1fa2;
        }
        
        .time-display {
            font-family: 'Courier New', monospace;
            font-weight: 600;
        }
        
        .work-hours {
            font-weight: 600;
            color: #2e7d32;
        }
        
        .late-indicator {
            color: #d32f2f;
            font-size: 11px;
            font-weight: 600;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
        }
        
        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-title {
            font-size: 24px;
            font-weight: 700;
            color: #2c3e50;
        }
        
        .close-btn {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #7f8c8d;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        .form-group label {
            margin-bottom: 5px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 12px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 16px;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            margin: 20px 0;
            gap: 10px;
        }
        
        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            text-decoration: none;
            color: #495057;
        }
        
        .pagination .current {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        .notification {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .notification.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .notification.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .export-section {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        
        .export-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .btn-export {
            background: #17a2b8;
            color: white;
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 0 15px;
            }
            
            .page-header {
                flex-direction: column;
                gap: 15px;
                align-items: stretch;
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .table {
                font-size: 12px;
            }
            
            .table th,
            .table td {
                padding: 8px 5px;
            }
            
            .header-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo-section">
                <div class="logo">📊</div>
                <div>
                    <div class="header-title">إدارة الحضور</div>
                </div>
            </div>
        </div>
    </header>
    
    <nav class="nav-menu">
        <div class="nav-content">
            <ul class="nav-items">
                <li class="nav-item"><a href="admin_dashboard.php">الرئيسية</a></li>
                <li class="nav-item"><a href="employees.php">الموظفين</a></li>
                <li class="nav-item"><a href="attendance.php" class="active">الحضور</a></li>
                <li class="nav-item"><a href="leaves.php">الإجازات</a></li>
                <li class="nav-item"><a href="reports.php">التقارير</a></li>
                <li class="nav-item"><a href="settings.php">الإعدادات</a></li>
            </ul>
        </div>
    </nav>
    
    <main class="main-content">
        <?php if ($message): ?>
            <div class="notification success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="notification error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="page-header">
            <h1 class="page-title">إدارة الحضور والانصراف</h1>
            <div class="header-actions">
                <button class="btn btn-primary" onclick="showAddModal()">
                    ➕ إضافة سجل يدوي
                </button>
                <button class="btn btn-secondary" onclick="showExportModal()">
                    📤 تصدير البيانات
                </button>
            </div>
        </div>
        
        <!-- إحصائيات الفترة -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $period_stats['unique_employees'] ?? 0; ?></div>
                <div class="stat-label">موظف فريد</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $period_stats['present_days'] ?? 0; ?></div>
                <div class="stat-label">يوم حضور</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $period_stats['late_days'] ?? 0; ?></div>
                <div class="stat-label">يوم تأخير</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo round($period_stats['avg_work_hours'] ?? 0, 1); ?></div>
                <div class="stat-label">متوسط ساعات العمل</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo round($period_stats['total_work_hours'] ?? 0); ?></div>
                <div class="stat-label">إجمالي الساعات</div>
            </div>
        </div>
        
        <!-- فلاتر البحث -->
        <div class="filters-section">
            <form method="GET">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label>من تاريخ</label>
                        <input type="date" name="date_from" value="<?php echo $date_from; ?>">
                    </div>
                    <div class="filter-group">
                        <label>إلى تاريخ</label>
                        <input type="date" name="date_to" value="<?php echo $date_to; ?>">
                    </div>
                    <div class="filter-group">
                        <label>الموظف</label>
                        <input type="text" name="employee" placeholder="اسم الموظف أو رقمه" 
                               value="<?php echo htmlspecialchars($employee_filter); ?>">
                    </div>
                    <div class="filter-group">
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
                    <div class="filter-group">
                        <label>الحالة</label>
                        <select name="status">
                            <option value="">جميع الحالات</option>
                            <option value="present" <?php echo $status_filter === 'present' ? 'selected' : ''; ?>>حاضر</option>
                            <option value="late" <?php echo $status_filter === 'late' ? 'selected' : ''; ?>>متأخر</option>
                            <option value="absent" <?php echo $status_filter === 'absent' ? 'selected' : ''; ?>>غائب</option>
                            <option value="incomplete" <?php echo $status_filter === 'incomplete' ? 'selected' : ''; ?>>لم يسجل خروج</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-secondary">🔍 البحث</button>
                </div>
            </form>
        </div>
        
        <!-- جدول الحضور -->
        <div class="attendance-table">
            <table class="table">
                <thead>
                    <tr>
                        <th>التاريخ</th>
                        <th>الموظف</th>
                        <th>القسم</th>
                        <th>وقت الحضور</th>
                        <th>وقت الانصراف</th>
                        <th>ساعات العمل</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($attendance_records)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px;">
                                لا توجد سجلات حضور للفترة المحددة
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($attendance_records as $record): ?>
                            <tr>
                                <td style="font-weight: 600;">
                                    <?php echo formatArabicDate($record['attendance_date']); ?>
                                </td>
                                <td>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($record['full_name']); ?></div>
                                    <div style="font-size: 12px; color: #7f8c8d;"><?php echo htmlspecialchars($record['employee_id']); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($record['department'] ?: '-'); ?></td>
                                <td>
                                    <?php if ($record['check_in_time']): ?>
                                        <div class="time-display"><?php echo formatArabicTime($record['check_in_time']); ?></div>
                                        <?php if ($record['is_late']): ?>
                                            <div class="late-indicator">متأخر <?php echo $record['late_minutes']; ?> دقيقة</div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #dc3545;">غير مسجل</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($record['check_out_time']): ?>
                                        <div class="time-display"><?php echo formatArabicTime($record['check_out_time']); ?></div>
                                    <?php elseif ($record['check_in_time']): ?>
                                        <span style="color: #ffc107;">لم يسجل خروج</span>
                                    <?php else: ?>
                                        <span style="color: #dc3545;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($record['work_hours'] > 0): ?>
                                        <span class="work-hours"><?php echo round($record['work_hours'], 1); ?> ساعة</span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    if (!$record['check_in_time']) {
                                        echo '<span class="status-badge status-absent">غائب</span>';
                                    } elseif (!$record['check_out_time']) {
                                        echo '<span class="status-badge status-incomplete">لم يسجل خروج</span>';
                                    } elseif ($record['is_late']) {
                                        echo '<span class="status-badge status-late">متأخر</span>';
                                    } else {
                                        echo '<span class="status-badge status-present">حاضر</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <div class="actions">
                                        <button class="action-btn action-edit" onclick="showEditModal(<?php echo $record['id']; ?>)">
                                            ✏️ تعديل
                                        </button>
                                        <?php if ($record['check_in_image'] || $record['check_out_image']): ?>
                                            <button class="action-btn action-view" onclick="viewImages(<?php echo $record['id']; ?>)">
                                                🖼️ الصور
                                            </button>
                                        <?php endif; ?>
                                        <button class="action-btn action-delete" onclick="deleteRecord(<?php echo $record['id']; ?>)">
                                            🗑️ حذف
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- صفحات -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page-1; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&employee=<?php echo urlencode($employee_filter); ?>&department=<?php echo urlencode($department_filter); ?>&status=<?php echo urlencode($status_filter); ?>">السابق</a>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?page=<?php echo $i; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&employee=<?php echo urlencode($employee_filter); ?>&department=<?php echo urlencode($department_filter); ?>&status=<?php echo urlencode($status_filter); ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page+1; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&employee=<?php echo urlencode($employee_filter); ?>&department=<?php echo urlencode($department_filter); ?>&status=<?php echo urlencode($status_filter); ?>">التالي</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>

    <!-- نموذج تعديل الحضور -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">تعديل سجل الحضور</h2>
                <button type="button" class="close-btn" onclick="closeEditModal()">&times;</button>
            </div>
            
            <form id="editForm" method="POST">
                <input type="hidden" name="action" value="edit_attendance">
                <input type="hidden" name="id" id="editId">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="edit_check_in_time">وقت الحضور</label>
                        <input type="datetime-local" id="edit_check_in_time" name="check_in_time">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_check_out_time">وقت الانصراف</label>
                        <input type="datetime-local" id="edit_check_out_time" name="check_out_time">
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="edit_notes">ملاحظات</label>
                        <textarea id="edit_notes" name="notes" rows="3"></textarea>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ التغييرات</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- نموذج إضافة سجل يدوي -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">إضافة سجل حضور يدوي</h2>
                <button type="button" class="close-btn" onclick="closeAddModal()">&times;</button>
            </div>
            
            <form id="addForm" method="POST">
                <input type="hidden" name="action" value="add_manual_attendance">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="add_employee_id">الموظف *</label>
                        <select id="add_employee_id" name="employee_id" required>
                            <option value="">اختر الموظف</option>
                            <?php foreach ($employees_list as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>">
                                    <?php echo htmlspecialchars($emp['full_name'] . ' - ' . $emp['employee_id']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="add_attendance_date">التاريخ *</label>
                        <input type="date" id="add_attendance_date" name="attendance_date" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="add_check_in_time">وقت الحضور *</label>
                        <input type="time" id="add_check_in_time" name="check_in_time" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="add_check_out_time">وقت الانصراف</label>
                        <input type="time" id="add_check_out_time" name="check_out_time">
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="add_notes">ملاحظات</label>
                        <textarea id="add_notes" name="notes" rows="3" placeholder="سبب الإضافة اليدوية..."></textarea>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeAddModal()">إلغاء</button>
                    <button type="submit" class="btn btn-primary">إضافة السجل</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // البيانات المحملة
        const attendanceRecords = <?php echo json_encode($attendance_records); ?>;
        
        function showEditModal(recordId) {
            const record = attendanceRecords.find(r => r.id == recordId);
            if (!record) return;
            
            document.getElementById('editId').value = record.id;
            
            // تحويل التواريخ للتنسيق المطلوب
            if (record.check_in_time) {
                const checkInDate = new Date(record.check_in_time);
                document.getElementById('edit_check_in_time').value = 
                    checkInDate.toISOString().slice(0, 16);
            }
            
            if (record.check_out_time) {
                const checkOutDate = new Date(record.check_out_time);
                document.getElementById('edit_check_out_time').value = 
                    checkOutDate.toISOString().slice(0, 16);
            }
            
            document.getElementById('edit_notes').value = record.notes || '';
            document.getElementById('editModal').style.display = 'block';
        }
        
        function showAddModal() {
            document.getElementById('add_attendance_date').value = new Date().toISOString().split('T')[0];
            document.getElementById('addModal').style.display = 'block';
        }
        
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }
        
        function closeAddModal() {
            document.getElementById('addModal').style.display = 'none';
        }
        
        function deleteRecord(recordId) {
            const record = attendanceRecords.find(r => r.id == recordId);
            if (!record) return;
            
            if (confirm(`هل أنت متأكد من حذف سجل حضور ${record.full_name} بتاريخ ${record.attendance_date}؟`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_attendance">
                    <input type="hidden" name="id" value="${recordId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function viewImages(recordId) {
            window.open(`view_attendance_images.php?id=${recordId}`, '_blank', 'width=800,height=600');
        }
        
        function showExportModal() {
            const params = new URLSearchParams(window.location.search);
            const exportUrl = `export_attendance.php?${params.toString()}`;
            window.open(exportUrl, '_blank');
        }
        
        // إغلاق النوافذ المنبثقة عند النقر خارجها
        window.addEventListener('click', function(event) {
            const editModal = document.getElementById('editModal');
            const addModal = document.getElementById('addModal');
            
            if (event.target === editModal) {
                closeEditModal();
            }
            if (event.target === addModal) {
                closeAddModal();
            }
        });
        
        // البحث التلقائي
        let searchTimeout;
        document.querySelector('input[name="employee"]').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                this.form.submit();
            }, 500);
        });
    </script>
</body>
</html>