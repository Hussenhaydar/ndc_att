<?php
require_once 'config.php';
check_admin_login();

$database = new Database();
$db = $database->getConnection();

$message = '';
$error = '';

// معالجة الإجراءات
if ($_POST) {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'approve_leave':
                $result = updateLeaveStatus($_POST['leave_id'], 'approved');
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = $result['message'];
                }
                break;
                
            case 'reject_leave':
                $result = updateLeaveStatus($_POST['leave_id'], 'rejected');
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = $result['message'];
                }
                break;
                
            case 'add_leave':
                $result = addEmployeeLeave($_POST);
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = $result['message'];
                }
                break;
        }
    }
}

// فلترة البيانات
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$employee_filter = isset($_GET['employee']) ? $_GET['employee'] : 'all';
$month_filter = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

// بناء استعلام الإجازات
$where_conditions = [];
$params = [];

if ($status_filter !== 'all') {
    $where_conditions[] = "l.status = ?";
    $params[] = $status_filter;
}

if ($employee_filter !== 'all') {
    $where_conditions[] = "l.employee_id = ?";
    $params[] = $employee_filter;
}

if (!empty($month_filter)) {
    $where_conditions[] = "(DATE_FORMAT(l.start_date, '%Y-%m') = ? OR DATE_FORMAT(l.end_date, '%Y-%m') = ?)";
    $params[] = $month_filter;
    $params[] = $month_filter;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

$query = "SELECT l.*, e.full_name, e.employee_id as emp_id, 
          a.full_name as approved_by_name
          FROM leaves l
          JOIN employees e ON l.employee_id = e.id
          LEFT JOIN admins a ON l.approved_by = a.id
          $where_clause
          ORDER BY l.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

// جلب قائمة الموظفين للفلترة
$query = "SELECT id, full_name, employee_id FROM employees WHERE is_active = 1 ORDER BY full_name";
$stmt = $db->prepare($query);
$stmt->execute();
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// إحصائيات سريعة
$stats_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
    SUM(CASE WHEN status = 'approved' THEN days_count ELSE 0 END) as total_approved_days
    FROM leaves 
    WHERE DATE_FORMAT(start_date, '%Y-%m') = ?";
$stmt = $db->prepare($stats_query);
$stmt->execute([date('Y-m')]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// الوظائف المساعدة
function updateLeaveStatus($leave_id, $status) {
    global $db;
    
    try {
        // جلب بيانات الإجازة
        $query = "SELECT l.*, e.full_name FROM leaves l JOIN employees e ON l.employee_id = e.id WHERE l.id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$leave_id]);
        $leave = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$leave) {
            return ['success' => false, 'message' => 'الإجازة غير موجودة'];
        }
        
        if ($leave['status'] !== 'pending') {
            return ['success' => false, 'message' => 'تم اتخاذ قرار بشأن هذه الإجازة مسبقاً'];
        }
        
        // تحديث حالة الإجازة
        $query = "UPDATE leaves SET status = ?, approved_by = ? WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$status, $_SESSION['admin_id'], $leave_id]);
        
        $action_text = $status === 'approved' ? 'الموافقة على' : 'رفض';
        log_activity('admin', $_SESSION['admin_id'], $status . '_leave', 
                     $action_text . ' إجازة ' . $leave['full_name']);
        
        $message = $status === 'approved' ? 'تم الموافقة على الإجازة' : 'تم رفض الإجازة';
        return ['success' => true, 'message' => $message];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'حدث خطأ: ' . $e->getMessage()];
    }
}

function addEmployeeLeave($data) {
    global $db;
    
    try {
        // التحقق من البيانات
        $required_fields = ['employee_id', 'leave_type', 'start_date', 'end_date', 'reason'];
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                return ['success' => false, 'message' => "حقل $field مطلوب"];
            }
        }
        
        // حساب عدد الأيام
        $start_date = new DateTime($data['start_date']);
        $end_date = new DateTime($data['end_date']);
        $days_count = $start_date->diff($end_date)->days + 1;
        
        if ($days_count <= 0) {
            return ['success' => false, 'message' => 'تاريخ النهاية يجب أن يكون بعد تاريخ البداية'];
        }
        
        // إدراج الإجازة
        $query = "INSERT INTO leaves (employee_id, leave_type, start_date, end_date, days_count, reason, status, approved_by) 
                  VALUES (?, ?, ?, ?, ?, ?, 'approved', ?)";
        $stmt = $db->prepare($query);
        $stmt->execute([
            $data['employee_id'],
            $data['leave_type'],
            $data['start_date'],
            $data['end_date'],
            $days_count,
            $data['reason'],
            $_SESSION['admin_id']
        ]);
        
        // جلب اسم الموظف
        $query = "SELECT full_name FROM employees WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$data['employee_id']]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);
        
        log_activity('admin', $_SESSION['admin_id'], 'add_leave', 
                     'إضافة إجازة للموظف: ' . $employee['full_name']);
        
        return ['success' => true, 'message' => 'تم إضافة الإجازة بنجاح'];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'حدث خطأ: ' . $e->getMessage()];
    }
}

function getLeaveTypeText($type) {
    $types = [
        'sick' => 'مرضية',
        'annual' => 'سنوية',
        'emergency' => 'طارئة',
        'maternity' => 'أمومة',
        'other' => 'أخرى'
    ];
    return $types[$type] ?? $type;
}

function getStatusText($status) {
    $statuses = [
        'pending' => 'في الانتظار',
        'approved' => 'موافق عليها',
        'rejected' => 'مرفوضة'
    ];
    return $statuses[$status] ?? $status;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الإجازات - نظام بصمة الوجه</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f6fa;
            color: #2c3e50;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo-section {
            display: flex;
            align-items: center;
        }
        
        .logo {
            width: 50px;
            height: 50px;
            background: rgba(255,255,255,0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-left: 15px;
            font-size: 24px;
        }
        
        .header-title {
            font-size: 24px;
            font-weight: 600;
        }
        
        .nav-menu {
            background: white;
            padding: 15px 0;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .nav-content {
            max-width: 1200px;
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
            max-width: 1200px;
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
            border: none;
            padding: 12px 24px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .add-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(86,171,47,0.4);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            text-align: center;
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .stat-label {
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .stat-card.total .stat-number { color: #3498db; }
        .stat-card.pending .stat-number { color: #f39c12; }
        .stat-card.approved .stat-number { color: #27ae60; }
        .stat-card.rejected .stat-number { color: #e74c3c; }
        
        .filters-section {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .filters-form {
            display: flex;
            gap: 20px;
            align-items: end;
        }
        
        .form-group {
            flex: 1;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .form-group select,
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e1e5e9;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .form-group select:focus,
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .filter-btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
        }
        
        .leaves-table {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th,
        .table td {
            padding: 15px;
            text-align: right;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .table tr:hover {
            background: #f8f9fa;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-pending { background: #fff3cd; color: #856404; }
        .status-approved { background: #d4edda; color: #155724; }
        .status-rejected { background: #f8d7da; color: #721c24; }
        
        .leave-type-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .leave-sick { background: #ffebee; color: #c62828; }
        .leave-annual { background: #e8f5e8; color: #2e7d32; }
        .leave-emergency { background: #fff3e0; color: #ef6c00; }
        .leave-maternity { background: #f3e5f5; color: #7b1fa2; }
        .leave-other { background: #e3f2fd; color: #1976d2; }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn {
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-approve { background: #27ae60; color: white; }
        .btn-reject { background: #e74c3c; color: white; }
        
        .btn:hover {
            transform: scale(1.05);
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 600px;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        
        .modal-title {
            font-size: 24px;
            font-weight: 600;
        }
        
        .close {
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            color: #aaa;
        }
        
        .close:hover {
            color: black;
        }
        
        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .form-row .form-group {
            flex: 1;
        }
        
        .submit-btn {
            background: linear-gradient(135deg, #56ab2f, #a8e6cf);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            width: 100%;
            margin-top: 20px;
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
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #7f8c8d;
        }
        
        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .filters-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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
            <button class="add-btn" onclick="openAddModal()">➕ إضافة إجازة</button>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-number"><?php echo $stats['total']; ?></div>
                <div class="stat-label">إجمالي الطلبات</div>
            </div>
            
            <div class="stat-card pending">
                <div class="stat-number"><?php echo $stats['pending']; ?></div>
                <div class="stat-label">في الانتظار</div>
            </div>
            
            <div class="stat-card approved">
                <div class="stat-number"><?php echo $stats['approved']; ?></div>
                <div class="stat-label">موافق عليها</div>
            </div>
            
            <div class="stat-card rejected">
                <div class="stat-number"><?php echo $stats['rejected']; ?></div>
                <div class="stat-label">مرفوضة</div>
            </div>
        </div>
        
        <div class="filters-section">
            <form class="filters-form" method="GET">
                <div class="form-group">
                    <label>الحالة</label>
                    <select name="status">
                        <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>جميع الحالات</option>
                        <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>في الانتظار</option>
                        <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>موافق عليها</option>
                        <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>مرفوضة</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>الموظف</label>
                    <select name="employee">
                        <option value="all">جميع الموظفين</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?php echo $employee['id']; ?>" 
                                    <?php echo $employee_filter == $employee['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($employee['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>الشهر</label>
                    <input type="month" name="month" value="<?php echo $month_filter; ?>">
                </div>
                
                <button type="submit" class="filter-btn">🔍 فلترة</button>
            </form>
        </div>
        
        <div class="leaves-table">
            <?php if (empty($leaves)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">🏖️</div>
                    <h3>لا توجد إجازات</h3>
                    <p>لا توجد طلبات إجازة بالمعايير المحددة</p>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>الموظف</th>
                            <th>نوع الإجازة</th>
                            <th>من تاريخ</th>
                            <th>إلى تاريخ</th>
                            <th>عدد الأيام</th>
                            <th>السبب</th>
                            <th>الحالة</th>
                            <th>تاريخ الطلب</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leaves as $leave): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($leave['full_name']); ?></div>
                                    <div style="font-size: 12px; color: #7f8c8d;"><?php echo htmlspecialchars($leave['emp_id']); ?></div>
                                </td>
                                <td>
                                    <span class="leave-type-badge leave-<?php echo $leave['leave_type']; ?>">
                                        <?php echo getLeaveTypeText($leave['leave_type']); ?>
                                    </span>
                                </td>
                                <td><?php echo format_arabic_date($leave['start_date']); ?></td>
                                <td><?php echo format_arabic_date($leave['end_date']); ?></td>
                                <td><?php echo $leave['days_count']; ?> يوم</td>
                                <td style="max-width: 200px; word-wrap: break-word;">
                                    <?php echo htmlspecialchars(substr($leave['reason'], 0, 50)); ?>
                                    <?php if (strlen($leave['reason']) > 50): ?>...<?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $leave['status']; ?>">
                                        <?php echo getStatusText($leave['status']); ?>
                                    </span>
                                    <?php if ($leave['approved_by_name']): ?>
                                        <div style="font-size: 11px; color: #7f8c8d; margin-top: 2px;">
                                            بواسطة: <?php echo htmlspecialchars($leave['approved_by_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo format_arabic_date($leave['created_at']); ?></td>
                                <td>
                                    <?php if ($leave['status'] === 'pending'): ?>
                                        <div class="action-buttons">
                                            <button class="btn btn-approve" onclick="updateLeaveStatus(<?php echo $leave['id']; ?>, 'approve')">
                                                ✅ موافقة
                                            </button>
                                            <button class="btn btn-reject" onclick="updateLeaveStatus(<?php echo $leave['id']; ?>, 'reject')">
                                                ❌ رفض
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #7f8c8d; font-size: 12px;">تم اتخاذ القرار</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </main>

    <!-- مودال إضافة إجازة -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">إضافة إجازة جديدة</h2>
                <span class="close" onclick="closeModal('addModal')">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_leave">
                
                <div class="form-group">
                    <label>الموظف *</label>
                    <select name="employee_id" required>
                        <option value="">اختر الموظف</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?php echo $employee['id']; ?>">
                                <?php echo htmlspecialchars($employee['full_name'] . ' (' . $employee['employee_id'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>نوع الإجازة *</label>
                        <select name="leave_type" required>
                            <option value="sick">مرضية</option>
                            <option value="annual">سنوية</option>
                            <option value="emergency">طارئة</option>
                            <option value="maternity">أمومة</option>
                            <option value="other">أخرى</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>من تاريخ *</label>
                        <input type="date" name="start_date" required>
                    </div>
                    <div class="form-group">
                        <label>إلى تاريخ *</label>
                        <input type="date" name="end_date" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>سبب الإجازة *</label>
                    <textarea name="reason" rows="4" required placeholder="اكتب سبب الإجازة..."></textarea>
                </div>
                
                <button type="submit" class="submit-btn">إضافة الإجازة</button>
            </form>
        </div>
    </div>

    <script>
        function openAddModal() {
            document.getElementById('addModal').style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function updateLeaveStatus(leaveId, action) {
            const actionText = action === 'approve' ? 'الموافقة على' : 'رفض';
            
            if (confirm(`هل أنت متأكد من ${actionText} هذه الإجازة؟`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="${action}_leave">
                    <input type="hidden" name="leave_id" value="${leaveId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // إغلاق المودال عند النقر خارجه
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            });
        }

        // التحقق من صحة التواريخ
        document.addEventListener('DOMContentLoaded', function() {
            const startDateInput = document.querySelector('input[name="start_date"]');
            const endDateInput = document.querySelector('input[name="end_date"]');
            
            if (startDateInput && endDateInput) {
                startDateInput.addEventListener('change', function() {
                    endDateInput.min = this.value;
                    if (endDateInput.value && endDateInput.value < this.value) {
                        endDateInput.value = this.value;
                    }
                });
                
                endDateInput.addEventListener('change', function() {
                    if (this.value < startDateInput.value) {
                        alert('تاريخ النهاية يجب أن يكون بعد تاريخ البداية');
                        this.value = startDateInput.value;
                    }
                });
            }
        });
    </script>
</body>
</html>