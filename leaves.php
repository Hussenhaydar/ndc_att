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
        if ($action === 'approve_leave') {
            $leave_id = (int)$_POST['leave_id'];
            $admin_notes = Security::sanitizeInput($_POST['admin_notes'] ?? '');
            
            $db->beginTransaction();
            
            // تحديث حالة الإجازة
            $stmt = $db->prepare("
                UPDATE leaves SET 
                    status = 'approved', 
                    approved_by = ?, 
                    approved_at = NOW(),
                    admin_notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['admin_id'], $admin_notes, $leave_id]);
            
            // جلب بيانات الإجازة والموظف
            $leave_stmt = $db->prepare("
                SELECT l.*, e.full_name, e.email, lt.type_name_ar 
                FROM leaves l 
                JOIN employees e ON l.employee_id = e.id 
                LEFT JOIN leave_types lt ON l.leave_type_id = lt.id
                WHERE l.id = ?
            ");
            $leave_stmt->execute([$leave_id]);
            $leave_data = $leave_stmt->fetch(PDO::FETCH_ASSOC);
            
            // إنشاء إشعار للموظف
            createNotification('employee', $leave_data['employee_id'], 
                              'موافقة على الإجازة', 
                              "تمت الموافقة على طلب إجازتك من {$leave_data['start_date']} إلى {$leave_data['end_date']}", 
                              'success');
            
            // تسجيل النشاط
            logActivity('admin', $_SESSION['admin_id'], 'approve_leave', 
                       "الموافقة على إجازة الموظف: {$leave_data['full_name']}");
            
            $db->commit();
            $message = 'تمت الموافقة على الإجازة بنجاح';
            
        } elseif ($action === 'reject_leave') {
            $leave_id = (int)$_POST['leave_id'];
            $rejection_reason = Security::sanitizeInput($_POST['rejection_reason'] ?? '');
            
            $db->beginTransaction();
            
            // تحديث حالة الإجازة
            $stmt = $db->prepare("
                UPDATE leaves SET 
                    status = 'rejected', 
                    approved_by = ?, 
                    approved_at = NOW(),
                    rejection_reason = ?
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['admin_id'], $rejection_reason, $leave_id]);
            
            // جلب بيانات الإجازة والموظف
            $leave_stmt = $db->prepare("
                SELECT l.*, e.full_name, e.email, lt.type_name_ar 
                FROM leaves l 
                JOIN employees e ON l.employee_id = e.id 
                LEFT JOIN leave_types lt ON l.leave_type_id = lt.id
                WHERE l.id = ?
            ");
            $leave_stmt->execute([$leave_id]);
            $leave_data = $leave_stmt->fetch(PDO::FETCH_ASSOC);
            
            // إنشاء إشعار للموظف
            createNotification('employee', $leave_data['employee_id'], 
                              'رفض الإجازة', 
                              "تم رفض طلب إجازتك. السبب: $rejection_reason", 
                              'error');
            
            // تسجيل النشاط
            logActivity('admin', $_SESSION['admin_id'], 'reject_leave', 
                       "رفض إجازة الموظف: {$leave_data['full_name']}");
            
            $db->commit();
            $message = 'تم رفض الإجازة';
            
        } elseif ($action === 'delete_leave') {
            $leave_id = (int)$_POST['leave_id'];
            
            // جلب اسم الموظف قبل الحذف
            $name_stmt = $db->prepare("
                SELECT e.full_name 
                FROM leaves l 
                JOIN employees e ON l.employee_id = e.id 
                WHERE l.id = ?
            ");
            $name_stmt->execute([$leave_id]);
            $employee_name = $name_stmt->fetchColumn();
            
            $stmt = $db->prepare("DELETE FROM leaves WHERE id = ?");
            $stmt->execute([$leave_id]);
            
            logActivity('admin', $_SESSION['admin_id'], 'delete_leave', "حذف إجازة الموظف: $employee_name");
            $message = 'تم حذف الإجازة بنجاح';
            
        } elseif ($action === 'add_leave') {
            $employee_id = (int)$_POST['employee_id'];
            $leave_type_id = (int)$_POST['leave_type_id'];
            $start_date = $_POST['start_date'];
            $end_date = $_POST['end_date'];
            $reason = Security::sanitizeInput($_POST['reason']);
            $status = $_POST['status'] ?? 'pending';
            
            // حساب عدد الأيام
            $start = new DateTime($start_date);
            $end = new DateTime($end_date);
            $days_count = $end->diff($start)->days + 1;
            
            // التحقق من التداخل مع إجازات أخرى
            $overlap_stmt = $db->prepare("
                SELECT COUNT(*) FROM leaves 
                WHERE employee_id = ? AND status = 'approved'
                AND (
                    (start_date BETWEEN ? AND ?) OR 
                    (end_date BETWEEN ? AND ?) OR
                    (start_date <= ? AND end_date >= ?)
                )
            ");
            $overlap_stmt->execute([
                $employee_id, $start_date, $end_date, 
                $start_date, $end_date, $start_date, $end_date
            ]);
            
            if ($overlap_stmt->fetchColumn() > 0) {
                throw new Exception('توجد إجازة معتمدة متداخلة مع هذه الفترة');
            }
            
            $stmt = $db->prepare("
                INSERT INTO leaves (employee_id, leave_type_id, start_date, end_date, days_count, reason, status, approved_by, approved_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $approved_by = ($status === 'approved') ? $_SESSION['admin_id'] : null;
            $approved_at = ($status === 'approved') ? date('Y-m-d H:i:s') : null;
            
            $stmt->execute([
                $employee_id, $leave_type_id, $start_date, $end_date, 
                $days_count, $reason, $status, $approved_by, $approved_at
            ]);
            
            // إنشاء إشعار للموظف
            $emp_stmt = $db->prepare("SELECT full_name FROM employees WHERE id = ?");
            $emp_stmt->execute([$employee_id]);
            $employee_name = $emp_stmt->fetchColumn();
            
            createNotification('employee', $employee_id, 
                              'إضافة إجازة', 
                              "تم إضافة إجازة لك من $start_date إلى $end_date", 
                              'info');
            
            logActivity('admin', $_SESSION['admin_id'], 'add_leave', "إضافة إجازة للموظف: $employee_name");
            $message = 'تم إضافة الإجازة بنجاح';
        }
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        $error = $e->getMessage();
    }
}

// معالجة البحث والفلترة
$status_filter = $_GET['status'] ?? '';
$employee_filter = $_GET['employee'] ?? '';
$leave_type_filter = $_GET['leave_type'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// بناء استعلام البحث
$where_conditions = [];
$params = [];

if (!empty($status_filter)) {
    $where_conditions[] = "l.status = ?";
    $params[] = $status_filter;
}

if (!empty($employee_filter)) {
    $where_conditions[] = "(e.full_name LIKE ? OR e.employee_id LIKE ?)";
    $search_term = "%$employee_filter%";
    $params = array_merge($params, [$search_term, $search_term]);
}

if (!empty($leave_type_filter)) {
    $where_conditions[] = "l.leave_type_id = ?";
    $params[] = $leave_type_filter;
}

if (!empty($date_from)) {
    $where_conditions[] = "l.start_date >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "l.end_date <= ?";
    $params[] = $date_to;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// جلب طلبات الإجازات
$query = "
    SELECT l.*, e.full_name, e.employee_id, e.department, 
           lt.type_name_ar, lt.type_name,
           a.full_name as approved_by_name
    FROM leaves l 
    JOIN employees e ON l.employee_id = e.id 
    LEFT JOIN leave_types lt ON l.leave_type_id = lt.id
    LEFT JOIN admins a ON l.approved_by = a.id
    $where_clause 
    ORDER BY l.created_at DESC 
    LIMIT ? OFFSET ?
";

$params[] = $per_page;
$params[] = $offset;

$stmt = $db->prepare($query);
$stmt->execute($params);
$leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

// حساب العدد الكلي
$count_params = array_slice($params, 0, -2);
$count_query = "
    SELECT COUNT(*) 
    FROM leaves l 
    JOIN employees e ON l.employee_id = e.id 
    LEFT JOIN leave_types lt ON l.leave_type_id = lt.id
    $where_clause
";
$count_stmt = $db->prepare($count_query);
$count_stmt->execute($count_params);
$total_leaves = $count_stmt->fetchColumn();
$total_pages = ceil($total_leaves / $per_page);

// جلب قائمة الموظفين
$employees_stmt = $db->prepare("SELECT id, full_name, employee_id FROM employees WHERE is_active = 1 ORDER BY full_name");
$employees_stmt->execute();
$employees_list = $employees_stmt->fetchAll(PDO::FETCH_ASSOC);

// جلب أنواع الإجازات
$leave_types_stmt = $db->prepare("SELECT * FROM leave_types WHERE is_active = 1 ORDER BY type_name_ar");
$leave_types_stmt->execute();
$leave_types = $leave_types_stmt->fetchAll(PDO::FETCH_ASSOC);

// إحصائيات الإجازات
$stats_stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_requests,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_requests,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_requests,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_requests,
        SUM(CASE WHEN status = 'approved' THEN days_count ELSE 0 END) as total_approved_days
    FROM leaves l
    WHERE YEAR(l.created_at) = YEAR(CURDATE())
");
$stats_stmt->execute();
$leave_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$app_name = getSetting('company_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الإجازات - <?php echo htmlspecialchars($app_name); ?></title>
    
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
        
        .leaves-table {
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
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-approved {
            background: #d4edda;
            color: #155724;
        }
        
        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }
        
        .actions {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
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
        
        .action-approve {
            background: #d4edda;
            color: #155724;
        }
        
        .action-reject {
            background: #f8d7da;
            color: #721c24;
        }
        
        .action-delete {
            background: #ffebee;
            color: #d32f2f;
        }
        
        .action-view {
            background: #e3f2fd;
            color: #1976d2;
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
        
        .leave-details {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 10px 0;
        }
        
        .leave-details h4 {
            margin-bottom: 10px;
            color: #2c3e50;
        }
        
        .leave-details p {
            margin-bottom: 5px;
            color: #6c757d;
        }
        
        .duration-badge {
            background: #e3f2fd;
            color: #1976d2;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
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
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo-section">
                <div class="logo">🏖️</div>
                <div>
                    <div class="header-title">إدارة الإجازات</div>
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
                <li class="nav-item"><a href="leaves.php" class="active">الإجازات</a></li>
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
            <h1 class="page-title">إدارة الإجازات</h1>
            <button class="btn btn-primary" onclick="showAddModal()">
                ➕ إضافة إجازة جديدة
            </button>
        </div>
        
        <!-- إحصائيات الإجازات -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $leave_stats['total_requests']; ?></div>
                <div class="stat-label">إجمالي الطلبات</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $leave_stats['pending_requests']; ?></div>
                <div class="stat-label">طلبات معلقة</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $leave_stats['approved_requests']; ?></div>
                <div class="stat-label">طلبات موافق عليها</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $leave_stats['rejected_requests']; ?></div>
                <div class="stat-label">طلبات مرفوضة</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $leave_stats['total_approved_days']; ?></div>
                <div class="stat-label">إجمالي أيام الإجازة</div>
            </div>
        </div>
        
        <!-- فلاتر البحث -->
        <div class="filters-section">
            <form method="GET">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label>الحالة</label>
                        <select name="status">
                            <option value="">جميع الحالات</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>معلقة</option>
                            <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>موافق عليها</option>
                            <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>مرفوضة</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>الموظف</label>
                        <input type="text" name="employee" placeholder="اسم الموظف أو رقمه" 
                               value="<?php echo htmlspecialchars($employee_filter); ?>">
                    </div>
                    <div class="filter-group">
                        <label>نوع الإجازة</label>
                        <select name="leave_type">
                            <option value="">جميع الأنواع</option>
                            <?php foreach ($leave_types as $type): ?>
                                <option value="<?php echo $type['id']; ?>" 
                                        <?php echo $leave_type_filter == $type['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type['type_name_ar']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>من تاريخ</label>
                        <input type="date" name="date_from" value="<?php echo $date_from; ?>">
                    </div>
                    <div class="filter-group">
                        <label>إلى تاريخ</label>
                        <input type="date" name="date_to" value="<?php echo $date_to; ?>">
                    </div>
                    <button type="submit" class="btn btn-secondary">🔍 البحث</button>
                </div>
            </form>
        </div>
        
        <!-- جدول الإجازات -->
        <div class="leaves-table">
            <table class="table">
                <thead>
                    <tr>
                        <th>الموظف</th>
                        <th>نوع الإجازة</th>
                        <th>فترة الإجازة</th>
                        <th>عدد الأيام</th>
                        <th>تاريخ الطلب</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($leaves)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px;">
                                لا توجد طلبات إجازة مطابقة لمعايير البحث
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($leaves as $leave): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($leave['full_name']); ?></div>
                                    <div style="font-size: 12px; color: #7f8c8d;">
                                        <?php echo htmlspecialchars($leave['employee_id']); ?> | 
                                        <?php echo htmlspecialchars($leave['department'] ?: 'غير محدد'); ?>
                                    </div>
                                </td>
                                <td>
                                    <span style="font-weight: 600; color: #667eea;">
                                        <?php echo htmlspecialchars($leave['type_name_ar'] ?: 'غير محدد'); ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="font-weight: 600;">
                                        <?php echo formatArabicDate($leave['start_date']); ?>
                                    </div>
                                    <div style="font-size: 12px; color: #7f8c8d;">
                                        إلى <?php echo formatArabicDate($leave['end_date']); ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="duration-badge">
                                        <?php echo $leave['days_count']; ?> يوم
                                    </span>
                                </td>
                                <td><?php echo formatArabicDate($leave['created_at'], true); ?></td>
                                <td>
                                    <?php
                                    $status_class = 'status-' . $leave['status'];
                                    $status_text = [
                                        'pending' => 'معلقة',
                                        'approved' => 'موافق عليها',
                                        'rejected' => 'مرفوضة'
                                    ][$leave['status']] ?? $leave['status'];
                                    ?>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo $status_text; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="actions">
                                        <?php if ($leave['status'] === 'pending'): ?>
                                            <button class="action-btn action-approve" onclick="showApproveModal(<?php echo $leave['id']; ?>)">
                                                ✅ موافقة
                                            </button>
                                            <button class="action-btn action-reject" onclick="showRejectModal(<?php echo $leave['id']; ?>)">
                                                ❌ رفض
                                            </button>
                                        <?php endif; ?>
                                        <button class="action-btn action-view" onclick="showDetailsModal(<?php echo $leave['id']; ?>)">
                                            👁️ تفاصيل
                                        </button>
                                        <button class="action-btn action-delete" onclick="deleteLeave(<?php echo $leave['id']; ?>)">
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
                    <a href="?page=<?php echo $page-1; ?>&status=<?php echo urlencode($status_filter); ?>&employee=<?php echo urlencode($employee_filter); ?>&leave_type=<?php echo urlencode($leave_type_filter); ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>">السابق</a>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status_filter); ?>&employee=<?php echo urlencode($employee_filter); ?>&leave_type=<?php echo urlencode($leave_type_filter); ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page+1; ?>&status=<?php echo urlencode($status_filter); ?>&employee=<?php echo urlencode($employee_filter); ?>&leave_type=<?php echo urlencode($leave_type_filter); ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>">التالي</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>

    <!-- نموذج الموافقة على الإجازة -->
    <div id="approveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">الموافقة على الإجازة</h2>
                <button type="button" class="close-btn" onclick="closeApproveModal()">&times;</button>
            </div>
            
            <form id="approveForm" method="POST">
                <input type="hidden" name="action" value="approve_leave">
                <input type="hidden" name="leave_id" id="approveLeaveId">
                
                <div class="leave-details" id="approveLeaveDetails">
                    <!-- تفاصيل الإجازة ستُملأ بـ JavaScript -->
                </div>
                
                <div class="form-group">
                    <label for="admin_notes">ملاحظات الإدارة (اختيارية)</label>
                    <textarea id="admin_notes" name="admin_notes" rows="3" placeholder="أي ملاحظات إضافية..."></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeApproveModal()">إلغاء</button>
                    <button type="submit" class="btn btn-primary">✅ الموافقة على الإجازة</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- نموذج رفض الإجازة -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">رفض الإجازة</h2>
                <button type="button" class="close-btn" onclick="closeRejectModal()">&times;</button>
            </div>
            
            <form id="rejectForm" method="POST">
                <input type="hidden" name="action" value="reject_leave">
                <input type="hidden" name="leave_id" id="rejectLeaveId">
                
                <div class="leave-details" id="rejectLeaveDetails">
                    <!-- تفاصيل الإجازة ستُملأ بـ JavaScript -->
                </div>
                
                <div class="form-group">
                    <label for="rejection_reason">سبب الرفض *</label>
                    <textarea id="rejection_reason" name="rejection_reason" rows="3" required placeholder="يرجى ذكر سبب رفض الإجازة..."></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeRejectModal()">إلغاء</button>
                    <button type="submit" class="btn" style="background: #dc3545; color: white;">❌ رفض الإجازة</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- نموذج إضافة إجازة -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">إضافة إجازة جديدة</h2>
                <button type="button" class="close-btn" onclick="closeAddModal()">&times;</button>
            </div>
            
            <form id="addForm" method="POST">
                <input type="hidden" name="action" value="add_leave">
                
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
                        <label for="add_leave_type_id">نوع الإجازة *</label>
                        <select id="add_leave_type_id" name="leave_type_id" required>
                            <option value="">اختر نوع الإجازة</option>
                            <?php foreach ($leave_types as $type): ?>
                                <option value="<?php echo $type['id']; ?>">
                                    <?php echo htmlspecialchars($type['type_name_ar']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="add_start_date">تاريخ البداية *</label>
                        <input type="date" id="add_start_date" name="start_date" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="add_end_date">تاريخ النهاية *</label>
                        <input type="date" id="add_end_date" name="end_date" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="add_status">الحالة</label>
                        <select id="add_status" name="status">
                            <option value="pending">معلقة</option>
                            <option value="approved">موافق عليها</option>
                            <option value="rejected">مرفوضة</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>عدد الأيام</label>
                        <input type="text" id="calculated_days" readonly style="background: #f8f9fa;">
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="add_reason">السبب *</label>
                        <textarea id="add_reason" name="reason" rows="3" required placeholder="سبب طلب الإجازة..."></textarea>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeAddModal()">إلغاء</button>
                    <button type="submit" class="btn btn-primary">إضافة الإجازة</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- نموذج تفاصيل الإجازة -->
    <div id="detailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">تفاصيل الإجازة</h2>
                <button type="button" class="close-btn" onclick="closeDetailsModal()">&times;</button>
            </div>
            
            <div id="detailsContent">
                <!-- المحتوى سيُملأ بـ JavaScript -->
            </div>
            
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeDetailsModal()">إغلاق</button>
            </div>
        </div>
    </div>

    <script>
        // البيانات المحملة
        const leaves = <?php echo json_encode($leaves); ?>;
        
        function showApproveModal(leaveId) {
            const leave = leaves.find(l => l.id == leaveId);
            if (!leave) return;
            
            document.getElementById('approveLeaveId').value = leave.id;
            document.getElementById('approveLeaveDetails').innerHTML = generateLeaveDetailsHTML(leave);
            document.getElementById('approveModal').style.display = 'block';
        }
        
        function showRejectModal(leaveId) {
            const leave = leaves.find(l => l.id == leaveId);
            if (!leave) return;
            
            document.getElementById('rejectLeaveId').value = leave.id;
            document.getElementById('rejectLeaveDetails').innerHTML = generateLeaveDetailsHTML(leave);
            document.getElementById('rejectModal').style.display = 'block';
        }
        
        function showAddModal() {
            document.getElementById('addModal').style.display = 'block';
            calculateDays(); // حساب الأيام عند فتح النموذج
        }
        
        function showDetailsModal(leaveId) {
            const leave = leaves.find(l => l.id == leaveId);
            if (!leave) return;
            
            document.getElementById('detailsContent').innerHTML = generateFullDetailsHTML(leave);
            document.getElementById('detailsModal').style.display = 'block';
        }
        
        function generateLeaveDetailsHTML(leave) {
            return `
                <h4>تفاصيل طلب الإجازة</h4>
                <p><strong>الموظف:</strong> ${leave.full_name} (${leave.employee_id})</p>
                <p><strong>نوع الإجازة:</strong> ${leave.type_name_ar || 'غير محدد'}</p>
                <p><strong>الفترة:</strong> من ${formatDate(leave.start_date)} إلى ${formatDate(leave.end_date)}</p>
                <p><strong>عدد الأيام:</strong> ${leave.days_count} يوم</p>
                <p><strong>السبب:</strong> ${leave.reason || 'لم يذكر'}</p>
                <p><strong>تاريخ الطلب:</strong> ${formatDateTime(leave.created_at)}</p>
            `;
        }
        
        function generateFullDetailsHTML(leave) {
            let html = `
                <div class="leave-details">
                    <h4>معلومات الموظف</h4>
                    <p><strong>الاسم:</strong> ${leave.full_name}</p>
                    <p><strong>رقم الموظف:</strong> ${leave.employee_id}</p>
                    <p><strong>القسم:</strong> ${leave.department || 'غير محدد'}</p>
                </div>
                
                <div class="leave-details">
                    <h4>تفاصيل الإجازة</h4>
                    <p><strong>نوع الإجازة:</strong> ${leave.type_name_ar || 'غير محدد'}</p>
                    <p><strong>تاريخ البداية:</strong> ${formatDate(leave.start_date)}</p>
                    <p><strong>تاريخ النهاية:</strong> ${formatDate(leave.end_date)}</p>
                    <p><strong>عدد الأيام:</strong> ${leave.days_count} يوم</p>
                    <p><strong>السبب:</strong> ${leave.reason || 'لم يذكر'}</p>
                    <p><strong>الحالة:</strong> 
                        <span class="status-badge status-${leave.status}">
                            ${getStatusText(leave.status)}
                        </span>
                    </p>
                </div>
                
                <div class="leave-details">
                    <h4>معلومات الطلب</h4>
                    <p><strong>تاريخ الطلب:</strong> ${formatDateTime(leave.created_at)}</p>
            `;
            
            if (leave.approved_by_name) {
                html += `<p><strong>تمت المراجعة بواسطة:</strong> ${leave.approved_by_name}</p>`;
                html += `<p><strong>تاريخ المراجعة:</strong> ${formatDateTime(leave.approved_at)}</p>`;
            }
            
            if (leave.admin_notes) {
                html += `<p><strong>ملاحظات الإدارة:</strong> ${leave.admin_notes}</p>`;
            }
            
            if (leave.rejection_reason) {
                html += `<p><strong>سبب الرفض:</strong> ${leave.rejection_reason}</p>`;
            }
            
            html += '</div>';
            return html;
        }
        
        function deleteLeave(leaveId) {
            const leave = leaves.find(l => l.id == leaveId);
            if (!leave) return;
            
            if (confirm(`هل أنت متأكد من حذف إجازة ${leave.full_name}؟`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_leave">
                    <input type="hidden" name="leave_id" value="${leaveId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function calculateDays() {
            const startDate = document.getElementById('add_start_date').value;
            const endDate = document.getElementById('add_end_date').value;
            
            if (startDate && endDate) {
                const start = new Date(startDate);
                const end = new Date(endDate);
                const diffTime = Math.abs(end - start);
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
                
                document.getElementById('calculated_days').value = diffDays + ' يوم';
            } else {
                document.getElementById('calculated_days').value = '';
            }
        }
        
        // إضافة مستمعي الأحداث لحساب الأيام
        document.getElementById('add_start_date').addEventListener('change', calculateDays);
        document.getElementById('add_end_date').addEventListener('change', calculateDays);
        
        // دوال الإغلاق
        function closeApproveModal() {
            document.getElementById('approveModal').style.display = 'none';
        }
        
        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
        }
        
        function closeAddModal() {
            document.getElementById('addModal').style.display = 'none';
            document.getElementById('addForm').reset();
        }
        
        function closeDetailsModal() {
            document.getElementById('detailsModal').style.display = 'none';
        }
        
        // دوال مساعدة للتنسيق
        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('ar-SA');
        }
        
        function formatDateTime(dateString) {
            const date = new Date(dateString);
            return date.toLocaleString('ar-SA');
        }
        
        function getStatusText(status) {
            const statusTexts = {
                'pending': 'معلقة',
                'approved': 'موافق عليها', 
                'rejected': 'مرفوضة'
            };
            return statusTexts[status] || status;
        }
        
        // إغلاق النوافذ المنبثقة عند النقر خارجها
        window.addEventListener('click', function(event) {
            const modals = ['approveModal', 'rejectModal', 'addModal', 'detailsModal'];
            
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        });
        
        // التحقق من صحة النموذج
        document.getElementById('addForm').addEventListener('submit', function(e) {
            const startDate = new Date(document.getElementById('add_start_date').value);
            const endDate = new Date(document.getElementById('add_end_date').value);
            
            if (startDate > endDate) {
                e.preventDefault();
                alert('تاريخ النهاية يجب أن يكون بعد تاريخ البداية');
                return;
            }
            
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            
            if (startDate < today) {
                if (!confirm('تاريخ البداية في الماضي. هل تريد المتابعة؟')) {
                    e.preventDefault();
                    return;
                }
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