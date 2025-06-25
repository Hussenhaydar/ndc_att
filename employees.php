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
        if ($action === 'add') {
            // إضافة موظف جديد
            $employee_id = $_POST['employee_id'] ?? generateEmployeeId();
            $username = Security::sanitizeInput($_POST['username']);
            $password = $_POST['password'];
            $full_name = Security::sanitizeInput($_POST['full_name']);
            $email = Security::sanitizeInput($_POST['email']);
            $phone = Security::sanitizeInput($_POST['phone']);
            $department = Security::sanitizeInput($_POST['department']);
            $position = Security::sanitizeInput($_POST['position']);
            $hire_date = $_POST['hire_date'];
            
            // التحقق من صحة البيانات
            if (empty($username) || empty($password) || empty($full_name)) {
                throw new Exception('يرجى ملء جميع الحقول المطلوبة');
            }
            
            if (!Security::validatePassword($password)) {
                throw new Exception('كلمة المرور يجب أن تكون 6 أحرف على الأقل وتحتوي على أرقام وحروف');
            }
            
            if (!empty($email) && !Security::validateEmail($email)) {
                throw new Exception('البريد الإلكتروني غير صحيح');
            }
            
            // التحقق من عدم تكرار اسم المستخدم أو رقم الموظف
            $check_stmt = $db->prepare("SELECT id FROM employees WHERE username = ? OR employee_id = ?");
            $check_stmt->execute([$username, $employee_id]);
            if ($check_stmt->rowCount() > 0) {
                throw new Exception('اسم المستخدم أو رقم الموظف موجود مسبقاً');
            }
            
            // إدراج الموظف الجديد
            $stmt = $db->prepare("
                INSERT INTO employees (employee_id, username, password, full_name, email, phone, department, position, hire_date) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $hashed_password = Security::hashPassword($password);
            $stmt->execute([
                $employee_id, $username, $hashed_password, $full_name, 
                $email, $phone, $department, $position, $hire_date
            ]);
            
            logActivity('admin', $_SESSION['admin_id'], 'add_employee', "إضافة موظف جديد: $full_name");
            $message = 'تم إضافة الموظف بنجاح';
            
        } elseif ($action === 'edit') {
            // تعديل موظف
            $id = (int)$_POST['id'];
            $employee_id = Security::sanitizeInput($_POST['employee_id']);
            $username = Security::sanitizeInput($_POST['username']);
            $full_name = Security::sanitizeInput($_POST['full_name']);
            $email = Security::sanitizeInput($_POST['email']);
            $phone = Security::sanitizeInput($_POST['phone']);
            $department = Security::sanitizeInput($_POST['department']);
            $position = Security::sanitizeInput($_POST['position']);
            $hire_date = $_POST['hire_date'];
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            // التحقق من عدم تكرار البيانات
            $check_stmt = $db->prepare("SELECT id FROM employees WHERE (username = ? OR employee_id = ?) AND id != ?");
            $check_stmt->execute([$username, $employee_id, $id]);
            if ($check_stmt->rowCount() > 0) {
                throw new Exception('اسم المستخدم أو رقم الموظف موجود مسبقاً');
            }
            
            $stmt = $db->prepare("
                UPDATE employees SET 
                    employee_id = ?, username = ?, full_name = ?, email = ?, 
                    phone = ?, department = ?, position = ?, hire_date = ?, is_active = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $employee_id, $username, $full_name, $email, 
                $phone, $department, $position, $hire_date, $is_active, $id
            ]);
            
            logActivity('admin', $_SESSION['admin_id'], 'update_employee', "تحديث بيانات الموظف: $full_name");
            $message = 'تم تحديث بيانات الموظف بنجاح';
            
        } elseif ($action === 'delete') {
            // حذف موظف
            $id = (int)$_POST['id'];
            
            // جلب اسم الموظف قبل الحذف
            $name_stmt = $db->prepare("SELECT full_name FROM employees WHERE id = ?");
            $name_stmt->execute([$id]);
            $employee_name = $name_stmt->fetchColumn();
            
            $stmt = $db->prepare("DELETE FROM employees WHERE id = ?");
            $stmt->execute([$id]);
            
            logActivity('admin', $_SESSION['admin_id'], 'delete_employee', "حذف الموظف: $employee_name");
            $message = 'تم حذف الموظف بنجاح';
            
        } elseif ($action === 'reset_password') {
            // إعادة تعيين كلمة المرور
            $id = (int)$_POST['id'];
            $new_password = $_POST['new_password'];
            
            if (!Security::validatePassword($new_password)) {
                throw new Exception('كلمة المرور يجب أن تكون 6 أحرف على الأقل');
            }
            
            $stmt = $db->prepare("UPDATE employees SET password = ? WHERE id = ?");
            $stmt->execute([Security::hashPassword($new_password), $id]);
            
            $name_stmt = $db->prepare("SELECT full_name FROM employees WHERE id = ?");
            $name_stmt->execute([$id]);
            $employee_name = $name_stmt->fetchColumn();
            
            logActivity('admin', $_SESSION['admin_id'], 'reset_password', "إعادة تعيين كلمة مرور: $employee_name");
            $message = 'تم إعادة تعيين كلمة المرور بنجاح';
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// معالجة البحث والفلترة
$search = $_GET['search'] ?? '';
$department_filter = $_GET['department'] ?? '';
$status_filter = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// بناء استعلام البحث
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(full_name LIKE ? OR employee_id LIKE ? OR username LIKE ? OR email LIKE ?)";
    $search_term = "%$search%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
}

if (!empty($department_filter)) {
    $where_conditions[] = "department = ?";
    $params[] = $department_filter;
}

if ($status_filter !== '') {
    $where_conditions[] = "is_active = ?";
    $params[] = (int)$status_filter;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// جلب الموظفين
$query = "SELECT * FROM employees $where_clause ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;

$stmt = $db->prepare($query);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// حساب العدد الكلي للصفحات
$count_params = array_slice($params, 0, -2); // إزالة LIMIT و OFFSET
$count_query = "SELECT COUNT(*) FROM employees $where_clause";
$count_stmt = $db->prepare($count_query);
$count_stmt->execute($count_params);
$total_employees = $count_stmt->fetchColumn();
$total_pages = ceil($total_employees / $per_page);

// جلب قائمة الأقسام
$departments_stmt = $db->prepare("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department != '' ORDER BY department");
$departments_stmt->execute();
$departments = $departments_stmt->fetchAll(PDO::FETCH_COLUMN);

// إحصائيات سريعة
$stats_stmt = $db->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
        COUNT(DISTINCT department) as departments
    FROM employees
");
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$app_name = getSetting('company_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الموظفين - <?php echo htmlspecialchars($app_name); ?></title>
    
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
        
        .add-btn {
            background: linear-gradient(135deg, #56ab2f, #a8e6cf);
            color: white;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .add-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(86,171,47,0.3);
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
            grid-template-columns: 2fr 1fr 1fr auto;
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
        
        .filter-btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }
        
        .employees-table {
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
            padding: 15px;
            text-align: right;
            border-bottom: 1px solid #f1f3f4;
        }
        
        .table th {
            background: #f8fafc;
            font-weight: 600;
            color: #475569;
        }
        
        .table tr:hover {
            background: #f8fafc;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        
        .status-inactive {
            background: #f8d7da;
            color: #721c24;
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
        
        .action-reset {
            background: #fff3e0;
            color: #f57c00;
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
        .form-group select {
            padding: 12px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 16px;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
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
        
        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
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
                font-size: 14px;
            }
            
            .table th,
            .table td {
                padding: 10px 8px;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo-section">
                <div class="logo">👥</div>
                <div>
                    <div class="header-title">إدارة الموظفين</div>
                </div>
            </div>
        </div>
    </header>
    
    <nav class="nav-menu">
        <div class="nav-content">
            <ul class="nav-items">
                <li class="nav-item"><a href="admin_dashboard.php">الرئيسية</a></li>
                <li class="nav-item"><a href="employees.php" class="active">الموظفين</a></li>
                <li class="nav-item"><a href="attendance.php">الحضور</a></li>
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
            <h1 class="page-title">إدارة الموظفين</h1>
            <a href="#" class="add-btn" onclick="showAddModal()">
                ➕ إضافة موظف جديد
            </a>
        </div>
        
        <!-- إحصائيات سريعة -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total']; ?></div>
                <div class="stat-label">إجمالي الموظفين</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['active']; ?></div>
                <div class="stat-label">موظف نشط</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['inactive']; ?></div>
                <div class="stat-label">موظف غير نشط</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['departments']; ?></div>
                <div class="stat-label">قسم</div>
            </div>
        </div>
        
        <!-- فلاتر البحث -->
        <div class="filters-section">
            <form method="GET">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label>البحث</label>
                        <input type="text" name="search" placeholder="اسم الموظف، رقم الموظف، اسم المستخدم..." 
                               value="<?php echo htmlspecialchars($search); ?>">
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
                            <option value="1" <?php echo $status_filter === '1' ? 'selected' : ''; ?>>نشط</option>
                            <option value="0" <?php echo $status_filter === '0' ? 'selected' : ''; ?>>غير نشط</option>
                        </select>
                    </div>
                    <button type="submit" class="filter-btn">🔍 البحث</button>
                </div>
            </form>
        </div>
        
        <!-- جدول الموظفين -->
        <div class="employees-table">
            <table class="table">
                <thead>
                    <tr>
                        <th>الموظف</th>
                        <th>رقم الموظف</th>
                        <th>القسم</th>
                        <th>المنصب</th>
                        <th>تاريخ التوظيف</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($employees)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px;">
                                لا توجد موظفين مطابقة لمعايير البحث
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($employees as $employee): ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div class="avatar">
                                            <?php echo strtoupper(substr($employee['full_name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($employee['full_name']); ?></div>
                                            <div style="font-size: 12px; color: #7f8c8d;"><?php echo htmlspecialchars($employee['email'] ?: 'لا يوجد بريد'); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($employee['employee_id']); ?></td>
                                <td><?php echo htmlspecialchars($employee['department'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($employee['position'] ?: '-'); ?></td>
                                <td><?php echo $employee['hire_date'] ? formatArabicDate($employee['hire_date']) : '-'; ?></td>
                                <td>
                                    <span class="status-badge <?php echo $employee['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                        <?php echo $employee['is_active'] ? 'نشط' : 'غير نشط'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="actions">
                                        <button class="action-btn action-edit" onclick="showEditModal(<?php echo $employee['id']; ?>)">
                                            ✏️ تعديل
                                        </button>
                                        <button class="action-btn action-reset" onclick="showResetPasswordModal(<?php echo $employee['id']; ?>)">
                                            🔑 كلمة المرور
                                        </button>
                                        <button class="action-btn action-delete" onclick="deleteEmployee(<?php echo $employee['id']; ?>)">
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
                    <a href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&department=<?php echo urlencode($department_filter); ?>&status=<?php echo urlencode($status_filter); ?>">السابق</a>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&department=<?php echo urlencode($department_filter); ?>&status=<?php echo urlencode($status_filter); ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&department=<?php echo urlencode($department_filter); ?>&status=<?php echo urlencode($status_filter); ?>">التالي</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>

    <!-- نموذج إضافة/تعديل موظف -->
    <div id="employeeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle">إضافة موظف جديد</h2>
                <button type="button" class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            
            <form id="employeeForm" method="POST">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="employeeId">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="employee_id">رقم الموظف *</label>
                        <input type="text" id="employee_id" name="employee_id" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="username">اسم المستخدم *</label>
                        <input type="text" id="username" name="username" required>
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="full_name">الاسم الكامل *</label>
                        <input type="text" id="full_name" name="full_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="email">البريد الإلكتروني</label>
                        <input type="email" id="email" name="email">
                    </div>
                    
                    <div class="form-group">
                        <label for="phone">رقم الهاتف</label>
                        <input type="text" id="phone" name="phone">
                    </div>
                    
                    <div class="form-group">
                        <label for="department">القسم</label>
                        <input type="text" id="department" name="department">
                    </div>
                    
                    <div class="form-group">
                        <label for="position">المنصب</label>
                        <input type="text" id="position" name="position">
                    </div>
                    
                    <div class="form-group">
                        <label for="hire_date">تاريخ التوظيف</label>
                        <input type="date" id="hire_date" name="hire_date">
                    </div>
                    
                    <div class="form-group" id="passwordGroup">
                        <label for="password">كلمة المرور *</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    
                    <div class="form-group" id="statusGroup" style="display: none;">
                        <label>
                            <input type="checkbox" id="is_active" name="is_active" checked>
                            حساب نشط
                        </label>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">إلغاء</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">حفظ</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- نموذج إعادة تعيين كلمة المرور -->
    <div id="resetPasswordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">إعادة تعيين كلمة المرور</h2>
                <button type="button" class="close-btn" onclick="closeResetModal()">&times;</button>
            </div>
            
            <form id="resetPasswordForm" method="POST">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="id" id="resetEmployeeId">
                
                <div class="form-group">
                    <label for="new_password">كلمة المرور الجديدة *</label>
                    <input type="password" id="new_password" name="new_password" required minlength="6">
                    <small>يجب أن تكون 6 أحرف على الأقل</small>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeResetModal()">إلغاء</button>
                    <button type="submit" class="btn btn-primary">تحديث كلمة المرور</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // البيانات المحملة
        const employees = <?php echo json_encode($employees); ?>;
        
        function showAddModal() {
            document.getElementById('modalTitle').textContent = 'إضافة موظف جديد';
            document.getElementById('formAction').value = 'add';
            document.getElementById('employeeId').value = '';
            document.getElementById('submitBtn').textContent = 'إضافة الموظف';
            document.getElementById('passwordGroup').style.display = 'block';
            document.getElementById('statusGroup').style.display = 'none';
            
            // مسح النموذج
            document.getElementById('employeeForm').reset();
            
            // إنشاء رقم موظف جديد
            generateEmployeeId();
            
            document.getElementById('employeeModal').style.display = 'block';
        }
        
        function showEditModal(employeeId) {
            const employee = employees.find(emp => emp.id == employeeId);
            if (!employee) return;
            
            document.getElementById('modalTitle').textContent = 'تعديل بيانات الموظف';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('employeeId').value = employee.id;
            document.getElementById('submitBtn').textContent = 'حفظ التغييرات';
            document.getElementById('passwordGroup').style.display = 'none';
            document.getElementById('statusGroup').style.display = 'block';
            
            // ملء البيانات
            document.getElementById('employee_id').value = employee.employee_id;
            document.getElementById('username').value = employee.username;
            document.getElementById('full_name').value = employee.full_name;
            document.getElementById('email').value = employee.email || '';
            document.getElementById('phone').value = employee.phone || '';
            document.getElementById('department').value = employee.department || '';
            document.getElementById('position').value = employee.position || '';
            document.getElementById('hire_date').value = employee.hire_date || '';
            document.getElementById('is_active').checked = employee.is_active == 1;
            
            document.getElementById('employeeModal').style.display = 'block';
        }
        
        function showResetPasswordModal(employeeId) {
            document.getElementById('resetEmployeeId').value = employeeId;
            document.getElementById('resetPasswordModal').style.display = 'block';
        }
        
        function closeModal() {
            document.getElementById('employeeModal').style.display = 'none';
        }
        
        function closeResetModal() {
            document.getElementById('resetPasswordModal').style.display = 'none';
        }
        
        function deleteEmployee(employeeId) {
            const employee = employees.find(emp => emp.id == employeeId);
            if (!employee) return;
            
            if (confirm(`هل أنت متأكد من حذف الموظف "${employee.full_name}"؟\n\nسيتم حذف جميع بيانات الحضور والإجازات المرتبطة بهذا الموظف.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="${employeeId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function generateEmployeeId() {
            // إنشاء رقم موظف تلقائي
            fetch('generate_employee_id.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('employee_id').value = data.employee_id;
                    }
                })
                .catch(error => {
                    console.error('Error generating employee ID:', error);
                });
        }
        
        // إغلاق النوافذ المنبثقة عند النقر خارجها
        window.addEventListener('click', function(event) {
            const employeeModal = document.getElementById('employeeModal');
            const resetModal = document.getElementById('resetPasswordModal');
            
            if (event.target === employeeModal) {
                closeModal();
            }
            if (event.target === resetModal) {
                closeResetModal();
            }
        });
        
        // التحقق من صحة النموذج
        document.getElementById('employeeForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password');
            const action = document.getElementById('formAction').value;
            
            if (action === 'add' && password.value.length < 6) {
                e.preventDefault();
                alert('كلمة المرور يجب أن تكون 6 أحرف على الأقل');
                password.focus();
                return;
            }
            
            const username = document.getElementById('username').value;
            if (username.length < 3) {
                e.preventDefault();
                alert('اسم المستخدم يجب أن يكون 3 أحرف على الأقل');
                document.getElementById('username').focus();
                return;
            }
        });
        
        // البحث التلقائي
        let searchTimeout;
        document.querySelector('input[name="search"]').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                this.form.submit();
            }, 500);
        });
    </script>
</body>
</html>