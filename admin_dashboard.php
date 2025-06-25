<?php
require_once 'config.php';


$database = Database::getInstance();
$db = $database->getConnection();

// جلب الإحصائيات العامة
$today = date('Y-m-d');
$current_month = date('Y-m');
$current_year = date('Y');

try {
    // إحصائيات الموظفين
    $employees_stats = $db->prepare(
        "SELECT 
            COUNT(*) as total_employees,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_employees,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_employees,
            SUM(CASE WHEN last_login >= CURDATE() - INTERVAL 7 DAY THEN 1 ELSE 0 END) as active_last_week
         FROM employees"
    );
    $employees_stats->execute();
    $emp_stats = $employees_stats->fetch(PDO::FETCH_ASSOC);

    // إحصائيات الحضور اليوم
    $attendance_today = $db->prepare(
        "SELECT 
            COUNT(DISTINCT a.employee_id) as present_today,
            SUM(CASE WHEN a.is_late = 1 THEN 1 ELSE 0 END) as late_today,
            SUM(CASE WHEN a.check_out_time IS NULL AND a.check_in_time IS NOT NULL THEN 1 ELSE 0 END) as still_working,
            AVG(a.work_hours) as avg_work_hours_today
         FROM attendance a 
         WHERE a.attendance_date = ?"
    );
    $attendance_today->execute([$today]);
    $att_today = $attendance_today->fetch(PDO::FETCH_ASSOC);

    // إحصائيات الإجازات
    $leaves_stats = $db->prepare(
        "SELECT 
            COUNT(*) as total_requests,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_requests,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_requests,
            SUM(CASE WHEN status = 'approved' AND MONTH(start_date) = MONTH(CURDATE()) THEN days_count ELSE 0 END) as days_this_month
         FROM leaves 
         WHERE YEAR(created_at) = ?"
    );
    $leaves_stats->execute([$current_year]);
    $leave_stats = $leaves_stats->fetch(PDO::FETCH_ASSOC);

    // إحصائيات الحضور الشهرية
    $monthly_attendance = $db->prepare(
        "SELECT 
            COUNT(DISTINCT a.employee_id) as unique_employees,
            COUNT(a.id) as total_records,
            SUM(CASE WHEN a.check_in_time IS NOT NULL THEN 1 ELSE 0 END) as present_days,
            SUM(CASE WHEN a.is_late = 1 THEN 1 ELSE 0 END) as late_days,
            AVG(a.work_hours) as avg_monthly_hours,
            SUM(a.work_hours) as total_work_hours
         FROM attendance a 
         WHERE DATE_FORMAT(a.attendance_date, '%Y-%m') = ?"
    );
    $monthly_attendance->execute([$current_month]);
    $monthly_stats = $monthly_attendance->fetch(PDO::FETCH_ASSOC);

    // حضور اليوم - آخر 10 سجلات
    $recent_attendance = $db->prepare(
        "SELECT e.full_name, e.employee_id, e.department, a.check_in_time, a.check_out_time, 
                a.is_late, a.late_minutes, a.work_hours, a.status
         FROM attendance a 
         JOIN employees e ON a.employee_id = e.id 
         WHERE a.attendance_date = ? 
         ORDER BY a.check_in_time DESC 
         LIMIT 10"
    );
    $recent_attendance->execute([$today]);
    $recent_records = $recent_attendance->fetchAll(PDO::FETCH_ASSOC);

    // آخر الأنشطة
    $activity_log = $db->prepare(
        "SELECT al.*, e.full_name as employee_name, a.full_name as admin_name
         FROM activity_log al 
         LEFT JOIN employees e ON al.user_id = e.id AND al.user_type = 'employee'
         LEFT JOIN admins a ON al.user_id = a.id AND al.user_type = 'admin'
         ORDER BY al.created_at DESC 
         LIMIT 15"
    );
    $activity_log->execute();
    $activities = $activity_log->fetchAll(PDO::FETCH_ASSOC);

    // الإجازات المعلقة
    $pending_leaves = $db->prepare(
        "SELECT l.*, e.full_name, e.employee_id, e.department, 
                lt.type_name_ar as leave_type_name
         FROM leaves l
         JOIN employees e ON l.employee_id = e.id
         LEFT JOIN leave_types lt ON l.leave_type_id = lt.id
         WHERE l.status = 'pending'
         ORDER BY l.created_at ASC
         LIMIT 5"
    );
    $pending_leaves->execute();
    $pending_leave_requests = $pending_leaves->fetchAll(PDO::FETCH_ASSOC);

    // إحصائيات الأقسام
    $department_stats = $db->prepare(
        "SELECT 
            e.department,
            COUNT(e.id) as total_employees,
            SUM(CASE WHEN a.attendance_date = ? AND a.check_in_time IS NOT NULL THEN 1 ELSE 0 END) as present_today,
            AVG(a.work_hours) as avg_work_hours
         FROM employees e
         LEFT JOIN attendance a ON e.id = a.employee_id AND a.attendance_date = ?
         WHERE e.is_active = 1 AND e.department IS NOT NULL AND e.department != ''
         GROUP BY e.department
         ORDER BY total_employees DESC"
    );
    $department_stats->execute([$today, $today]);
    $dept_stats = $department_stats->fetchAll(PDO::FETCH_ASSOC);

    // إشعارات لم تُقرأ
    $unread_notifications = getUnreadNotifications('admin', $_SESSION['admin_id']);

} catch (Exception $e) {
    error_log("Dashboard error: " . $e->getMessage());
    $emp_stats = $att_today = $leave_stats = $monthly_stats = [];
    $recent_records = $activities = $pending_leave_requests = $dept_stats = [];
    $unread_notifications = [];
}

$app_name = getSetting('company_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التحكم - <?php echo htmlspecialchars($app_name); ?></title>
    <meta name="description" content="لوحة تحكم الإدارة لنظام بصمة الوجه">
    
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
        
        /* Header */
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
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
            backdrop-filter: blur(10px);
        }
        
        .header-title {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .header-subtitle {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .notifications {
            position: relative;
        }
        
        .notification-bell {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            padding: 12px;
            border-radius: 12px;
            cursor: pointer;
            font-size: 20px;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .notification-bell:hover {
            background: rgba(255,255,255,0.3);
            transform: scale(1.05);
        }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #ff4757;
            color: white;
            border-radius: 50%;
            padding: 4px 8px;
            font-size: 12px;
            font-weight: bold;
            min-width: 20px;
            text-align: center;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        
        .user-details {
            text-align: right;
        }
        
        .user-name {
            font-weight: 600;
            font-size: 16px;
        }
        
        .user-role {
            font-size: 12px;
            opacity: 0.8;
        }
        
        .logout-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .logout-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
        
        /* Navigation */
        .nav-menu {
            background: white;
            border-bottom: 1px solid #e1e8ed;
            padding: 0;
        }
        
        .nav-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .nav-items {
            display: flex;
            list-style: none;
            margin: 0;
            padding: 0;
        }
        
        .nav-item {
            position: relative;
        }
        
        .nav-item a {
            display: block;
            color: #2c3e50;
            text-decoration: none;
            font-weight: 600;
            padding: 18px 24px;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
        }
        
        .nav-item a:hover,
        .nav-item a.active {
            color: #667eea;
            border-bottom-color: #667eea;
            background: rgba(102,126,234,0.05);
        }
        
        .nav-item .badge {
            position: absolute;
            top: 8px;
            right: 8px;
            background: #ff4757;
            color: white;
            border-radius: 10px;
            padding: 2px 6px;
            font-size: 10px;
            font-weight: bold;
        }
        
        /* Main Content */
        .main-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        
        .dashboard-header {
            margin-bottom: 30px;
        }
        
        .welcome-message {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 20px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }
        
        .welcome-message::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="80" cy="20" r="20" fill="rgba(255,255,255,0.1)"/><circle cx="20" cy="80" r="15" fill="rgba(255,255,255,0.05)"/></svg>');
            opacity: 0.3;
        }
        
        .welcome-content {
            position: relative;
            z-index: 1;
        }
        
        .welcome-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .welcome-subtitle {
            opacity: 0.9;
            font-size: 16px;
        }
        
        .current-time {
            position: absolute;
            top: 25px;
            left: 25px;
            background: rgba(255,255,255,0.2);
            padding: 10px 15px;
            border-radius: 10px;
            font-weight: 600;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: white;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border: 1px solid #f1f3f4;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--card-color, #667eea);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.15);
        }
        
        .stat-card.employees { --card-color: #667eea; }
        .stat-card.attendance { --card-color: #56ab2f; }
        .stat-card.leaves { --card-color: #f39c12; }
        .stat-card.performance { --card-color: #e74c3c; }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            color: white;
            background: var(--card-color, #667eea);
        }
        
        .stat-trend {
            background: #e8f5e8;
            color: #2e7d32;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .stat-trend.down {
            background: #ffebee;
            color: #c62828;
        }
        
        .stat-number {
            font-size: 36px;
            font-weight: 800;
            color: #2c3e50;
            margin-bottom: 8px;
            display: block;
        }
        
        .stat-label {
            color: #64748b;
            font-size: 16px;
            font-weight: 500;
        }
        
        .stat-details {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #f1f3f4;
            font-size: 14px;
            color: #64748b;
        }
        
        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 40px;
        }
        
        .section {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
            border: 1px solid #f1f3f4;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid #f1f3f4;
        }
        
        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-action {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            padding: 8px 16px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .section-action:hover {
            background: rgba(102,126,234,0.1);
        }
        
        /* Tables */
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th,
        .table td {
            padding: 12px;
            text-align: right;
            border-bottom: 1px solid #f1f3f4;
        }
        
        .table th {
            background: #f8fafc;
            font-weight: 600;
            color: #475569;
            font-size: 14px;
        }
        
        .table td {
            color: #64748b;
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
        
        .status-present { background: #dcfce7; color: #166534; }
        .status-late { background: #fef3c7; color: #92400e; }
        .status-absent { background: #fee2e2; color: #991b1b; }
        .status-working { background: #dbeafe; color: #1e40af; }
        
        /* Activity List */
        .activity-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 15px 0;
            border-bottom: 1px solid #f1f3f4;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            color: white;
            flex-shrink: 0;
        }
        
        .activity-icon.login { background: #10b981; }
        .activity-icon.logout { background: #f59e0b; }
        .activity-icon.check_in { background: #3b82f6; }
        .activity-icon.check_out { background: #8b5cf6; }
        .activity-icon.default { background: #6b7280; }
        
        .activity-content {
            flex: 1;
            min-width: 0;
        }
        
        .activity-title {
            font-weight: 600;
            color: #374151;
            margin-bottom: 4px;
            font-size: 14px;
        }
        
        .activity-description {
            color: #6b7280;
            font-size: 13px;
            margin-bottom: 4px;
        }
        
        .activity-time {
            color: #9ca3af;
            font-size: 12px;
        }
        
        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .quick-action {
            background: white;
            border: 2px solid #f1f3f4;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            text-decoration: none;
            color: #2c3e50;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }
        
        .quick-action:hover {
            border-color: #667eea;
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(102,126,234,0.15);
        }
        
        .quick-action-icon {
            font-size: 24px;
            margin-bottom: 5px;
        }
        
        .quick-action-title {
            font-weight: 600;
            font-size: 14px;
        }
        
        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #64748b;
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .empty-state h3 {
            margin-bottom: 8px;
            color: #374151;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 20px 15px;
            }
            
            .header-content {
                padding: 15px;
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .nav-items {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .nav-item a {
                padding: 12px 16px;
                font-size: 14px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
            
            .stat-card {
                padding: 20px;
            }
            
            .section {
                padding: 20px;
            }
            
            .welcome-message {
                padding: 20px;
                text-align: center;
            }
            
            .current-time {
                position: static;
                margin-top: 15px;
                display: inline-block;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .quick-actions {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .table {
                font-size: 12px;
            }
            
            .table th,
            .table td {
                padding: 8px 4px;
            }
        }
        
        /* Loading Animation */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }
        
        .spinner {
            border: 2px solid #f3f3f3;
            border-top: 2px solid #667eea;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Chart Placeholder */
        .chart-placeholder {
            height: 200px;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo-section">
                <div class="logo">🏢</div>
                <div>
                    <div class="header-title"><?php echo htmlspecialchars($app_name); ?></div>
                    <div class="header-subtitle">لوحة تحكم الإدارة</div>
                </div>
            </div>
            
            <div class="header-right">
                <div class="notifications">
                    <button class="notification-bell" onclick="toggleNotifications()">
                        🔔
                        <?php if (count($unread_notifications) > 0): ?>
                            <span class="notification-badge"><?php echo count($unread_notifications); ?></span>
                        <?php endif; ?>
                    </button>
                </div>
                
                <div class="user-info">
                    <div class="user-avatar">👤</div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['admin_name']); ?></div>
                        <div class="user-role">مدير النظام</div>
                    </div>
                </div>
                
                <a href="logout.php" class="logout-btn">تسجيل خروج</a>
            </div>
        </div>
    </header>
    
    <nav class="nav-menu">
        <div class="nav-content">
            <ul class="nav-items">
                <li class="nav-item">
                    <a href="admin_dashboard.php" class="active">🏠 الرئيسية</a>
                </li>
                <li class="nav-item">
                    <a href="employees.php">👥 الموظفين</a>
                </li>
                <li class="nav-item">
                    <a href="attendance.php">📊 الحضور</a>
                </li>
                <li class="nav-item">
                    <a href="leaves.php">🏖️ الإجازات
                        <?php if ($leave_stats['pending_requests'] > 0): ?>
                            <span class="badge"><?php echo $leave_stats['pending_requests']; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="reports.php">📈 التقارير</a>
                </li>
                <li class="nav-item">
                    <a href="settings.php">⚙️ الإعدادات</a>
                </li>
            </ul>
        </div>
    </nav>
    
    <main class="main-content">
        <!-- Welcome Message -->
        <div class="welcome-message">
            <div class="welcome-content">
                <div class="welcome-title">
                    مرحباً بك، <?php echo htmlspecialchars($_SESSION['admin_name']); ?>
                </div>
                <div class="welcome-subtitle">
                    <?php echo formatArabicDate($today); ?> - لوحة تحكم شاملة لإدارة نظام الحضور والانصراف
                </div>
            </div>
            <div class="current-time" id="currentTime"></div>
        </div>
        
        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="employees.php?action=add" class="quick-action">
                <div class="quick-action-icon">➕</div>
                <div class="quick-action-title">إضافة موظف</div>
            </a>
            <a href="attendance.php?date=<?php echo $today; ?>" class="quick-action">
                <div class="quick-action-icon">📋</div>
                <div class="quick-action-title">حضور اليوم</div>
            </a>
            <a href="leaves.php?status=pending" class="quick-action">
                <div class="quick-action-icon">📝</div>
                <div class="quick-action-title">طلبات الإجازة</div>
            </a>
            <a href="reports.php" class="quick-action">
                <div class="quick-action-icon">📊</div>
                <div class="quick-action-title">التقارير</div>
            </a>
        </div>
        
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card employees">
                <div class="stat-header">
                    <div class="stat-icon">👥</div>
                    <div class="stat-trend">
                        +<?php echo $emp_stats['active_last_week'] ?? 0; ?> هذا الأسبوع
                    </div>
                </div>
                <span class="stat-number"><?php echo $emp_stats['active_employees'] ?? 0; ?></span>
                <div class="stat-label">موظف نشط</div>
                <div class="stat-details">
                    إجمالي الموظفين: <?php echo $emp_stats['total_employees'] ?? 0; ?> | 
                    غير نشط: <?php echo $emp_stats['inactive_employees'] ?? 0; ?>
                </div>
            </div>
            
            <div class="stat-card attendance">
                <div class="stat-header">
                    <div class="stat-icon">📊</div>
                    <div class="stat-trend">
                        <?php 
                        $attendance_rate = ($emp_stats['active_employees'] > 0) ? 
                            round(($att_today['present_today'] / $emp_stats['active_employees']) * 100, 1) : 0;
                        echo $attendance_rate; ?>%
                    </div>
                </div>
                <span class="stat-number"><?php echo $att_today['present_today'] ?? 0; ?></span>
                <div class="stat-label">حاضر اليوم</div>
                <div class="stat-details">
                    متأخرين: <?php echo $att_today['late_today'] ?? 0; ?> | 
                    لا يزالون في العمل: <?php echo $att_today['still_working'] ?? 0; ?>
                </div>
            </div>
            
            <div class="stat-card leaves">
                <div class="stat-header">
                    <div class="stat-icon">🏖️</div>
                    <div class="stat-trend">
                        <?php echo $leave_stats['pending_requests'] ?? 0; ?> معلق
                    </div>
                </div>
                <span class="stat-number"><?php echo $leave_stats['days_this_month'] ?? 0; ?></span>
                <div class="stat-label">يوم إجازة هذا الشهر</div>
                <div class="stat-details">
                    إجمالي الطلبات: <?php echo $leave_stats['total_requests'] ?? 0; ?> | 
                    موافق عليها: <?php echo $leave_stats['approved_requests'] ?? 0; ?>
                </div>
            </div>
            
            <div class="stat-card performance">
                <div class="stat-header">
                    <div class="stat-icon">⏱️</div>
                    <div class="stat-trend">
                        <?php echo round($monthly_stats['avg_monthly_hours'] ?? 0, 1); ?>س شهرياً
                    </div>
                </div>
                <span class="stat-number"><?php echo round($att_today['avg_work_hours_today'] ?? 0, 1); ?></span>
                <div class="stat-label">متوسط ساعات العمل اليوم</div>
                <div class="stat-details">
                    إجمالي الساعات الشهرية: <?php echo round($monthly_stats['total_work_hours'] ?? 0); ?> ساعة
                </div>
            </div>
        </div>
        
        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Today's Attendance -->
            <div class="section">
                <div class="section-header">
                    <h2 class="section-title">
                        📊 حضور اليوم - <?php echo formatArabicDate($today); ?>
                    </h2>
                    <a href="attendance.php?date=<?php echo $today; ?>" class="section-action">
                        عرض الكل ←
                    </a>
                </div>
                
                <?php if (empty($recent_records)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📅</div>
                        <h3>لا يوجد حضور اليوم</h3>
                        <p>لم يسجل أي موظف حضوره بعد</p>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>الموظف</th>
                                    <th>القسم</th>
                                    <th>وقت الحضور</th>
                                    <th>وقت الانصراف</th>
                                    <th>ساعات العمل</th>
                                    <th>الحالة</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_records as $record): ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($record['full_name']); ?></div>
                                            <div style="font-size: 12px; color: #64748b;"><?php echo htmlspecialchars($record['employee_id']); ?></div>
                                        </td>
                                        <td><?php echo htmlspecialchars($record['department'] ?: '-'); ?></td>
                                        <td>
                                            <?php echo $record['check_in_time'] ? formatArabicTime($record['check_in_time']) : '-'; ?>
                                            <?php if ($record['is_late']): ?>
                                                <br><small style="color: #dc2626;">متأخر <?php echo $record['late_minutes']; ?> دقيقة</small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $record['check_out_time'] ? formatArabicTime($record['check_out_time']) : '-'; ?></td>
                                        <td><?php echo $record['work_hours'] ? round($record['work_hours'], 1) . ' ساعة' : '-'; ?></td>
                                        <td>
                                            <?php
                                            if (!$record['check_out_time'] && $record['check_in_time']) {
                                                echo '<span class="status-badge status-working">في العمل</span>';
                                            } elseif ($record['is_late']) {
                                                echo '<span class="status-badge status-late">متأخر</span>';
                                            } else {
                                                echo '<span class="status-badge status-present">حاضر</span>';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Recent Activities -->
            <div class="section">
                <div class="section-header">
                    <h2 class="section-title">🔔 آخر الأنشطة</h2>
                    <a href="activity_log.php" class="section-action">عرض الكل ←</a>
                </div>
                
                <div class="activity-list">
                    <?php if (empty($activities)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">📝</div>
                            <h3>لا توجد أنشطة</h3>
                            <p>لا توجد أنشطة حديثة</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($activities as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-icon <?php echo $activity['action']; ?>">
                                    <?php
                                    $icons = [
                                        'login' => '🔓',
                                        'logout' => '🔒',
                                        'check_in' => '✅',
                                        'check_out' => '🚪',
                                        'add_employee' => '👤',
                                        'update_employee' => '✏️',
                                        'approve_leave' => '✅',
                                        'reject_leave' => '❌'
                                    ];
                                    echo $icons[$activity['action']] ?? '📝';
                                    ?>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">
                                        <?php 
                                        $user_name = $activity['user_type'] == 'employee' ? 
                                            $activity['employee_name'] : $activity['admin_name'];
                                        echo htmlspecialchars($user_name ?: 'مستخدم محذوف'); 
                                        ?>
                                    </div>
                                    <div class="activity-description">
                                        <?php echo htmlspecialchars($activity['description']); ?>
                                    </div>
                                    <div class="activity-time">
                                        <?php echo formatArabicDate($activity['created_at'], true); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Department Statistics -->
        <?php if (!empty($dept_stats)): ?>
        <div class="section">
            <div class="section-header">
                <h2 class="section-title">🏢 إحصائيات الأقسام</h2>
            </div>
            
            <div style="overflow-x: auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>القسم</th>
                            <th>إجمالي الموظفين</th>
                            <th>حاضرين اليوم</th>
                            <th>معدل الحضور</th>
                            <th>متوسط ساعات العمل</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dept_stats as $dept): ?>
                            <tr>
                                <td style="font-weight: 600;"><?php echo htmlspecialchars($dept['department']); ?></td>
                                <td><?php echo $dept['total_employees']; ?></td>
                                <td><?php echo $dept['present_today']; ?></td>
                                <td>
                                    <?php 
                                    $dept_rate = ($dept['total_employees'] > 0) ? 
                                        round(($dept['present_today'] / $dept['total_employees']) * 100, 1) : 0;
                                    echo $dept_rate . '%';
                                    ?>
                                </td>
                                <td><?php echo round($dept['avg_work_hours'] ?? 0, 1); ?> ساعة</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Pending Leave Requests -->
        <?php if (!empty($pending_leave_requests)): ?>
        <div class="section">
            <div class="section-header">
                <h2 class="section-title">⏳ طلبات الإجازة المعلقة</h2>
                <a href="leaves.php?status=pending" class="section-action">عرض الكل ←</a>
            </div>
            
            <div style="overflow-x: auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>الموظف</th>
                            <th>نوع الإجازة</th>
                            <th>من تاريخ</th>
                            <th>إلى تاريخ</th>
                            <th>عدد الأيام</th>
                            <th>تاريخ الطلب</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_leave_requests as $leave): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($leave['full_name']); ?></div>
                                    <div style="font-size: 12px; color: #64748b;"><?php echo htmlspecialchars($leave['department'] ?: 'غير محدد'); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($leave['leave_type_name'] ?: 'غير محدد'); ?></td>
                                <td><?php echo formatArabicDate($leave['start_date']); ?></td>
                                <td><?php echo formatArabicDate($leave['end_date']); ?></td>
                                <td><?php echo $leave['days_count']; ?> يوم</td>
                                <td><?php echo formatArabicDate($leave['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <script>
        // تحديث الوقت الحالي
        function updateCurrentTime() {
            const now = new Date();
            const options = {
                timeZone: 'Asia/Riyadh',
                hour12: true,
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            };
            
            const timeString = now.toLocaleString('ar-SA', options);
            document.getElementById('currentTime').textContent = timeString;
        }
        
        // تحديث الوقت كل ثانية
        updateCurrentTime();
        setInterval(updateCurrentTime, 1000);
        
        // تحديث البيانات التلقائي
        function refreshDashboard() {
            const sections = document.querySelectorAll('.section');
            sections.forEach(section => section.classList.add('loading'));
            
            // محاكاة تحديث البيانات
            setTimeout(() => {
                sections.forEach(section => section.classList.remove('loading'));
                console.log('تم تحديث البيانات');
            }, 2000);
        }
        
        // تحديث كل 5 دقائق
        setInterval(refreshDashboard, 300000);
        
        // إدارة الإشعارات
        function toggleNotifications() {
            // يمكن إضافة modal للإشعارات هنا
            alert('سيتم إضافة نظام الإشعارات قريباً');
        }
        
        // تحسين الأداء - lazy loading للجداول
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '50px'
        };
        
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const section = entry.target;
                    section.style.opacity = '1';
                    section.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);
        
        document.querySelectorAll('.section').forEach(section => {
            section.style.opacity = '0';
            section.style.transform = 'translateY(20px)';
            section.style.transition = 'all 0.6s ease';
            observer.observe(section);
        });
        
        // تحسين تجربة المستخدم
        document.addEventListener('DOMContentLoaded', function() {
            // إضافة تأثيرات التحميل
            setTimeout(() => {
                document.body.classList.add('loaded');
            }, 500);
            
            // إدارة الأخطاء
            window.addEventListener('error', function(e) {
                console.error('خطأ في الصفحة:', e.error);
            });
            
            // فحص الاتصال بالإنترنت
            window.addEventListener('online', function() {
                console.log('تم استعادة الاتصال بالإنترنت');
            });
            
            window.addEventListener('offline', function() {
                console.log('انقطع الاتصال بالإنترنت');
            });
        });
    </script>
</body>
</html>