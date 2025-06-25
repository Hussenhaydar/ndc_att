<?php
require_once 'config.php';
checkEmployeeLogin();

$database = Database::getInstance();
$db = $database->getConnection();

$employee_id = $_SESSION['employee_id'];
$message = '';
$error = '';

// معالجة طلب إجازة جديد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_leave') {
    try {
        $leave_type_id = (int)$_POST['leave_type_id'];
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $reason = Security::sanitizeInput($_POST['reason']);
        
        // التحقق من البيانات
        if (empty($start_date) || empty($end_date) || empty($reason)) {
            throw new Exception('يرجى ملء جميع الحقول المطلوبة');
        }
        
        if (strtotime($start_date) > strtotime($end_date)) {
            throw new Exception('تاريخ النهاية يجب أن يكون بعد تاريخ البداية');
        }
        
        if (strtotime($start_date) < strtotime(date('Y-m-d'))) {
            throw new Exception('لا يمكن طلب إجازة في الماضي');
        }
        
        // حساب عدد الأيام
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        $days_count = $end->diff($start)->days + 1;
        
        // التحقق من التداخل مع إجازات أخرى معتمدة
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
        
        // إدراج طلب الإجازة
        $stmt = $db->prepare("
            INSERT INTO leaves (employee_id, leave_type_id, start_date, end_date, days_count, reason, status) 
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([$employee_id, $leave_type_id, $start_date, $end_date, $days_count, $reason]);
        
        // تسجيل النشاط
        logActivity('employee', $employee_id, 'request_leave', "طلب إجازة من $start_date إلى $end_date");
        
        // إشعار للإدارة
        $admin_stmt = $db->prepare("SELECT id FROM admins WHERE is_active = 1");
        $admin_stmt->execute();
        $admins = $admin_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($admins as $admin) {
            createNotification('admin', $admin['id'], 
                              'طلب إجازة جديد', 
                              "طلب إجازة من الموظف {$_SESSION['employee_name']}", 
                              'info');
        }
        
        $message = 'تم إرسال طلب الإجازة بنجاح وهو قيد المراجعة';
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// جلب بيانات الموظف
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$employee_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

// جلب أنواع الإجازات
$leave_types_stmt = $db->prepare("SELECT * FROM leave_types WHERE is_active = 1 ORDER BY type_name_ar");
$leave_types_stmt->execute();
$leave_types = $leave_types_stmt->fetchAll(PDO::FETCH_ASSOC);

// جلب طلبات الإجازات
$leaves_stmt = $db->prepare("
    SELECT l.*, lt.type_name_ar, a.full_name as approved_by_name
    FROM leaves l 
    LEFT JOIN leave_types lt ON l.leave_type_id = lt.id
    LEFT JOIN admins a ON l.approved_by = a.id
    WHERE l.employee_id = ? 
    ORDER BY l.created_at DESC
");
$leaves_stmt->execute([$employee_id]);
$leaves = $leaves_stmt->fetchAll(PDO::FETCH_ASSOC);

// إحصائيات الإجازات
$current_year = date('Y');
$leave_stats = [
    'total_requests' => count($leaves),
    'pending_requests' => count(array_filter($leaves, fn($l) => $l['status'] === 'pending')),
    'approved_requests' => count(array_filter($leaves, fn($l) => $l['status'] === 'approved')),
    'rejected_requests' => count(array_filter($leaves, fn($l) => $l['status'] === 'rejected')),
    'total_days_this_year' => array_sum(array_map(fn($l) => 
        (date('Y', strtotime($l['start_date'])) == $current_year && $l['status'] === 'approved') ? $l['days_count'] : 0, 
        $leaves
    ))
];

$app_name = getSetting('company_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>إداره الإجازات - <?php echo htmlspecialchars($employee['full_name']); ?></title>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #2c3e50;
            padding: 10px;
        }
        
        .container {
            max-width: 500px;
            margin: 0 auto;
            padding-bottom: 100px;
        }
        
        .header {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 20px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .title {
            font-size: 24px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .employee-info {
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(20px);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .stat-number {
            font-size: 24px;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #7f8c8d;
            font-size: 12px;
        }
        
        .action-card {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .card-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e1e5e9;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .btn {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #56ab2f, #a8e6cf);
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.2);
        }
        
        .leaves-list {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .leave-item {
            padding: 15px;
            border-bottom: 1px solid #f1f3f4;
            display: block;
        }
        
        .leave-item:last-child {
            border-bottom: none;
        }
        
        .leave-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        
        .leave-type {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .leave-dates {
            font-size: 14px;
            color: #6c757d;
            margin-bottom: 8px;
        }
        
        .leave-reason {
            font-size: 13px;
            color: #495057;
            background: #f8f9fa;
            padding: 8px;
            border-radius: 6px;
            margin-bottom: 10px;
        }
        
        .leave-status {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
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
        
        .leave-days {
            font-size: 12px;
            color: #667eea;
            font-weight: 600;
        }
        
        .leave-details {
            font-size: 12px;
            color: #6c757d;
            margin-top: 8px;
        }
        
        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #7f8c8d;
        }
        
        .empty-icon {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .navbar {
            position: fixed;
            bottom: 10px;
            left: 10px;
            right: 10px;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            z-index: 1000;
        }
        
        .nav-items {
            display: flex;
            justify-content: space-around;
        }
        
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            color: #7f8c8d;
            font-size: 12px;
            padding: 8px;
            border-radius: 10px;
            transition: all 0.3s ease;
        }
        
        .nav-item.active {
            color: #667eea;
            background: rgba(102,126,234,0.1);
        }
        
        .nav-icon {
            font-size: 20px;
            margin-bottom: 5px;
        }
        
        .days-calculator {
            background: #e3f2fd;
            border: 1px solid #2196f3;
            border-radius: 8px;
            padding: 10px;
            margin-top: 10px;
            text-align: center;
            font-size: 14px;
            font-weight: 600;
            color: #1976d2;
        }
        
        .balance-info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .balance-title {
            font-weight: 600;
            margin-bottom: 10px;
            color: #2c3e50;
        }
        
        .balance-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 14px;
        }
        
        .balance-item .label {
            color: #6c757d;
        }
        
        .balance-item .value {
            color: #2c3e50;
            font-weight: 600;
        }
        
        @media (max-width: 480px) {
            .container {
                padding: 5px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 class="title">إدارة الإجازات</h1>
            <div class="employee-info">
                <?php echo htmlspecialchars($employee['full_name']); ?> - <?php echo htmlspecialchars($employee['employee_id']); ?>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
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
                <div class="stat-number"><?php echo $leave_stats['total_days_this_year']; ?></div>
                <div class="stat-label">أيام الإجازة هذا العام</div>
            </div>
        </div>
        
        <!-- رصيد الإجازات -->
        <div class="balance-info">
            <div class="balance-title">رصيد الإجازات لعام <?php echo $current_year; ?></div>
            <?php 
            // حساب رصيد الإجازات لكل نوع
            $leave_balances = [];
            foreach ($leave_types as $type) {
                $used_days = array_sum(array_map(fn($l) => 
                    (date('Y', strtotime($l['start_date'])) == $current_year && 
                     $l['status'] === 'approved' && 
                     $l['leave_type_id'] == $type['id']) ? $l['days_count'] : 0, 
                    $leaves
                ));
                
                $remaining = max(0, $type['max_days_per_year'] - $used_days);
                $leave_balances[] = [
                    'type' => $type['type_name_ar'],
                    'total' => $type['max_days_per_year'],
                    'used' => $used_days,
                    'remaining' => $remaining
                ];
            }
            ?>
            
            <?php foreach ($leave_balances as $balance): ?>
                <div class="balance-item">
                    <span class="label"><?php echo htmlspecialchars($balance['type']); ?>:</span>
                    <span class="value">
                        <?php echo $balance['remaining']; ?> من <?php echo $balance['total']; ?> يوم
                        <?php if ($balance['used'] > 0): ?>
                            (استُخدم <?php echo $balance['used']; ?>)
                        <?php endif; ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- نموذج طلب إجازة جديدة -->
        <div class="action-card">
            <h2 class="card-title">🏖️ طلب إجازة جديدة</h2>
            
            <form method="POST">
                <input type="hidden" name="action" value="request_leave">
                
                <div class="form-group">
                    <label for="leave_type_id">نوع الإجازة *</label>
                    <select id="leave_type_id" name="leave_type_id" required>
                        <option value="">اختر نوع الإجازة</option>
                        <?php foreach ($leave_types as $type): ?>
                            <option value="<?php echo $type['id']; ?>" data-max-days="<?php echo $type['max_days_per_year']; ?>">
                                <?php echo htmlspecialchars($type['type_name_ar']); ?>
                                (الحد الأقصى: <?php echo $type['max_days_per_year']; ?> يوم سنوياً)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="start_date">تاريخ البداية *</label>
                        <input type="date" id="start_date" name="start_date" required min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="end_date">تاريخ النهاية *</label>
                        <input type="date" id="end_date" name="end_date" required min="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
                
                <div class="days-calculator" id="daysCalculator" style="display: none;">
                    عدد الأيام: <span id="calculatedDays">0</span> يوم
                </div>
                
                <div class="form-group">
                    <label for="reason">سبب الإجازة *</label>
                    <textarea id="reason" name="reason" rows="4" required placeholder="اكتب سبب طلب الإجازة..."></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    📤 إرسال طلب الإجازة
                </button>
            </form>
        </div>
        
        <!-- قائمة الإجازات -->
        <div class="leaves-list">
            <h3 class="card-title">📋 سجل الإجازات</h3>
            
            <?php if (empty($leaves)): ?>
                <div class="empty-state">
                    <div class="empty-icon">🏖️</div>
                    <h3>لا توجد إجازات</h3>
                    <p>لم تطلب أي إجازات بعد</p>
                </div>
            <?php else: ?>
                <?php foreach ($leaves as $leave): ?>
                    <div class="leave-item">
                        <div class="leave-header">
                            <div>
                                <div class="leave-type">
                                    <?php echo htmlspecialchars($leave['type_name_ar'] ?: 'غير محدد'); ?>
                                </div>
                                <div class="leave-dates">
                                    من <?php echo formatArabicDate($leave['start_date']); ?> 
                                    إلى <?php echo formatArabicDate($leave['end_date']); ?>
                                </div>
                            </div>
                            <div class="leave-days">
                                <?php echo $leave['days_count']; ?> يوم
                            </div>
                        </div>
                        
                        <?php if ($leave['reason']): ?>
                            <div class="leave-reason">
                                <strong>السبب:</strong> <?php echo htmlspecialchars($leave['reason']); ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="leave-status">
                            <?php
                            $status_class = 'status-' . $leave['status'];
                            $status_texts = [
                                'pending' => 'معلقة',
                                'approved' => 'موافق عليها',
                                'rejected' => 'مرفوضة'
                            ];
                            ?>
                            <span class="status-badge <?php echo $status_class; ?>">
                                <?php echo $status_texts[$leave['status']] ?? $leave['status']; ?>
                            </span>
                            
                            <div class="leave-details">
                                طُلبت في <?php echo formatArabicDate($leave['created_at']); ?>
                            </div>
                        </div>
                        
                        <?php if ($leave['status'] === 'approved' && $leave['approved_by_name']): ?>
                            <div class="leave-details">
                                وافق عليها: <?php echo htmlspecialchars($leave['approved_by_name']); ?>
                                في <?php echo formatArabicDate($leave['approved_at']); ?>
                                <?php if ($leave['admin_notes']): ?>
                                    <br><strong>ملاحظات:</strong> <?php echo htmlspecialchars($leave['admin_notes']); ?>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($leave['status'] === 'rejected'): ?>
                            <div class="leave-details" style="color: #dc3545;">
                                <?php if ($leave['rejection_reason']): ?>
                                    <strong>سبب الرفض:</strong> <?php echo htmlspecialchars($leave['rejection_reason']); ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- شريط التنقل -->
    <div class="navbar">
        <div class="nav-items">
            <a href="employee_dashboard.php" class="nav-item">
                <div class="nav-icon">🏠</div>
                <div>الرئيسية</div>
            </a>
            <a href="employee_face_setup.php" class="nav-item">
                <div class="nav-icon">👤</div>
                <div>بصمة الوجه</div>
            </a>
            <a href="employee_attendance.php" class="nav-item">
                <div class="nav-icon">📊</div>
                <div>سجل الحضور</div>
            </a>
            <a href="employee_leaves.php" class="nav-item active">
                <div class="nav-icon">🏖️</div>
                <div>الإجازات</div>
            </a>
            <a href="employee_profile.php" class="nav-item">
                <div class="nav-icon">⚙️</div>
                <div>الملف الشخصي</div>
            </a>
        </div>
    </div>

    <script>
        // حساب عدد الأيام تلقائياً
        function calculateDays() {
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            const calculator = document.getElementById('daysCalculator');
            const daysSpan = document.getElementById('calculatedDays');
            
            if (startDate && endDate) {
                const start = new Date(startDate);
                const end = new Date(endDate);
                
                if (end >= start) {
                    const diffTime = Math.abs(end - start);
                    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
                    
                    daysSpan.textContent = diffDays;
                    calculator.style.display = 'block';
                    
                    // فحص الرصيد المتبقي
                    checkLeaveBalance(diffDays);
                } else {
                    calculator.style.display = 'none';
                }
            } else {
                calculator.style.display = 'none';
            }
        }
        
        function checkLeaveBalance(requestedDays) {
            const leaveTypeSelect = document.getElementById('leave_type_id');
            const selectedOption = leaveTypeSelect.options[leaveTypeSelect.selectedIndex];
            
            if (selectedOption && selectedOption.value) {
                const maxDays = parseInt(selectedOption.dataset.maxDays);
                const calculator = document.getElementById('daysCalculator');
                
                if (requestedDays > maxDays) {
                    calculator.style.backgroundColor = '#f8d7da';
                    calculator.style.color = '#721c24';
                    calculator.innerHTML = `عدد الأيام: ${requestedDays} يوم<br><small>⚠️ يتجاوز الحد الأقصى (${maxDays} يوم)</small>`;
                } else {
                    calculator.style.backgroundColor = '#e3f2fd';
                    calculator.style.color = '#1976d2';
                    calculator.innerHTML = `عدد الأيام: ${requestedDays} يوم`;
                }
            }
        }
        
        // إضافة مستمعي الأحداث
        document.getElementById('start_date').addEventListener('change', calculateDays);
        document.getElementById('end_date').addEventListener('change', calculateDays);
        document.getElementById('leave_type_id').addEventListener('change', calculateDays);
        
        // التحقق من صحة النموذج
        document.querySelector('form').addEventListener('submit', function(e) {
            const startDate = new Date(document.getElementById('start_date').value);
            const endDate = new Date(document.getElementById('end_date').value);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            
            if (startDate < today) {
                e.preventDefault();
                alert('لا يمكن طلب إجازة في الماضي');
                return;
            }
            
            if (startDate > endDate) {
                e.preventDefault();
                alert('تاريخ النهاية يجب أن يكون بعد تاريخ البداية');
                return;
            }
            
            const reason = document.getElementById('reason').value.trim();
            if (reason.length < 10) {
                e.preventDefault();
                alert('يرجى كتابة سبب مفصل للإجازة (10 أحرف على الأقل)');
                document.getElementById('reason').focus();
                return;
            }
            
            // تأكيد الإرسال
            const diffTime = Math.abs(endDate - startDate);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
            
            if (!confirm(`هل أنت متأكد من طلب إجازة لمدة ${diffDays} يوم؟`)) {
                e.preventDefault();
            }
        });
        
        // تحديث تاريخ النهاية تلقائياً عند تغيير تاريخ البداية
        document.getElementById('start_date').addEventListener('change', function() {
            const endDateInput = document.getElementById('end_date');
            if (!endDateInput.value || new Date(endDateInput.value) < new Date(this.value)) {
                endDateInput.value = this.value;
                endDateInput.min = this.value;
            }
        });
    </script>
</body>
</html>