<?php
require_once 'config.php';
checkEmployeeLogin();

$database = Database::getInstance();
$db = $database->getConnection();

$employee_id = $_SESSION['employee_id'];
$today = date('Y-m-d');
$current_month = date('Y-m');

// جلب بيانات الموظف
$employee_query = "SELECT * FROM employees WHERE id = ?";
$stmt = $db->prepare($employee_query);
$stmt->execute([$employee_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

// جلب حضور اليوم
$attendance_query = "SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ?";
$stmt = $db->prepare($attendance_query);
$stmt->execute([$employee_id, $today]);
$today_attendance = $stmt->fetch(PDO::FETCH_ASSOC);

// جلب إحصائيات الشهر الحالي
$monthly_stats_query = "SELECT 
    COUNT(*) as total_days,
    SUM(CASE WHEN check_in_time IS NOT NULL THEN 1 ELSE 0 END) as present_days,
    SUM(CASE WHEN is_late = 1 THEN 1 ELSE 0 END) as late_days,
    AVG(work_hours) as avg_work_hours,
    SUM(work_hours) as total_work_hours
    FROM attendance 
    WHERE employee_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?";
$stmt = $db->prepare($monthly_stats_query);
$stmt->execute([$employee_id, $current_month]);
$monthly_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// جلب آخر 5 سجلات حضور
$recent_attendance_query = "SELECT * FROM attendance 
    WHERE employee_id = ? 
    ORDER BY attendance_date DESC 
    LIMIT 5";
$stmt = $db->prepare($recent_attendance_query);
$stmt->execute([$employee_id]);
$recent_attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

// جلب الإجازات الحالية والقادمة
$leaves_query = "SELECT l.*, lt.type_name_ar 
    FROM leaves l 
    LEFT JOIN leave_types lt ON l.leave_type_id = lt.id
    WHERE l.employee_id = ? AND (l.status = 'pending' OR l.end_date >= CURDATE())
    ORDER BY l.start_date DESC";
$stmt = $db->prepare($leaves_query);
$stmt->execute([$employee_id]);
$current_leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

// فحص هل الموظف في إجازة اليوم
$on_leave_today = isOnLeave($employee_id, $today);

$app_name = getSetting('company_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>لوحة التحكم - <?php echo htmlspecialchars($employee['full_name']); ?></title>
    
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
        
        .header {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .profile-pic {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            color: white;
            font-size: 32px;
        }
        
        .welcome-text {
            font-size: 24px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .employee-info {
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .current-time {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 10px;
            margin-top: 15px;
            font-weight: 600;
            color: #495057;
        }
        
        .attendance-card {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .card-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .attendance-status {
            text-align: center;
            margin-bottom: 25px;
        }
        
        .status-indicator {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 48px;
            color: white;
            position: relative;
        }
        
        .status-checked-in {
            background: linear-gradient(135deg, #56ab2f, #a8e6cf);
        }
        
        .status-checked-out {
            background: linear-gradient(135deg, #667eea, #764ba2);
        }
        
        .status-not-checked {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
        }
        
        .status-on-leave {
            background: linear-gradient(135deg, #f39c12, #e67e22);
        }
        
        .status-text {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .status-time {
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }
        
        .btn {
            flex: 1;
            padding: 15px;
            border: none;
            border-radius: 15px;
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
        
        .btn-secondary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.2);
        }
        
        .btn:disabled {
            background: #bdc3c7;
            cursor: not-allowed;
            transform: none;
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
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .stat-number {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
            color: #667eea;
        }
        
        .stat-label {
            font-size: 12px;
            color: #7f8c8d;
        }
        
        .recent-activity {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .activity-item {
            display: flex;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #ecf0f1;
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
            margin-left: 15px;
            color: white;
            font-size: 16px;
        }
        
        .activity-icon.present {
            background: #27ae60;
        }
        
        .activity-icon.late {
            background: #f39c12;
        }
        
        .activity-icon.absent {
            background: #e74c3c;
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-title {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .activity-details {
            font-size: 14px;
            color: #7f8c8d;
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
        
        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .content-wrapper {
            padding-bottom: 100px;
        }
        
        @media (max-width: 480px) {
            body {
                padding: 5px;
            }
            
            .header {
                padding: 15px;
            }
            
            .attendance-card {
                padding: 20px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="content-wrapper">
        <!-- Header -->
        <div class="header">
            <div class="profile-pic">
                <?php echo strtoupper(substr($employee['full_name'], 0, 1)); ?>
            </div>
            <div class="welcome-text">مرحباً، <?php echo htmlspecialchars($employee['full_name']); ?></div>
            <div class="employee-info">
                <?php echo htmlspecialchars($employee['employee_id']); ?> | <?php echo htmlspecialchars($employee['department'] ?: 'غير محدد'); ?>
            </div>
            <div class="current-time" id="currentTime"></div>
        </div>

        <!-- تنبيه الإجازة -->
        <?php if ($on_leave_today): ?>
            <div class="alert alert-warning">
                🏖️ أنت في إجازة اليوم. لا يمكنك تسجيل الحضور.
            </div>
        <?php endif; ?>

        <!-- بطاقة الحضور -->
        <div class="attendance-card">
            <h2 class="card-title">📊 حضور اليوم</h2>
            
            <div class="attendance-status">
                <?php if ($on_leave_today): ?>
                    <div class="status-indicator status-on-leave">🏖️</div>
                    <div class="status-text">في إجازة</div>
                    <div class="status-time">استمتع بإجازتك</div>
                <?php elseif (!$today_attendance): ?>
                    <div class="status-indicator status-not-checked">❌</div>
                    <div class="status-text">لم تسجل حضورك بعد</div>
                    <div class="status-time">ابدأ يومك بتسجيل الحضور</div>
                <?php elseif ($today_attendance && !$today_attendance['check_out_time']): ?>
                    <div class="status-indicator status-checked-in">✅</div>
                    <div class="status-text">متواجد في العمل</div>
                    <div class="status-time">
                        وقت الحضور: <?php echo formatArabicTime($today_attendance['check_in_time']); ?>
                        <?php if ($today_attendance['is_late']): ?>
                            <br><span style="color: #e74c3c;">متأخر <?php echo $today_attendance['late_minutes']; ?> دقيقة</span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="status-indicator status-checked-out">🚪</div>
                    <div class="status-text">انتهى يوم العمل</div>
                    <div class="status-time">
                        الحضور: <?php echo formatArabicTime($today_attendance['check_in_time']); ?><br>
                        الانصراف: <?php echo formatArabicTime($today_attendance['check_out_time']); ?><br>
                        ساعات العمل: <?php echo round($today_attendance['work_hours'], 1); ?> ساعة
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!$on_leave_today): ?>
                <div class="action-buttons">
                    <?php if (!$today_attendance): ?>
                        <button class="btn btn-primary" onclick="openFaceAttendance('checkin')">
                            📷 تسجيل حضور
                        </button>
                    <?php elseif (!$today_attendance['check_out_time']): ?>
                        <button class="btn btn-secondary" onclick="openFaceAttendance('checkout')">
                            🚪 تسجيل انصراف
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- إحصائيات الشهر -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $monthly_stats['present_days'] ?? 0; ?></div>
                <div class="stat-label">أيام الحضور</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $monthly_stats['late_days'] ?? 0; ?></div>
                <div class="stat-label">أيام التأخير</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo round($monthly_stats['avg_work_hours'] ?? 0, 1); ?></div>
                <div class="stat-label">متوسط ساعات العمل</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo round($monthly_stats['total_work_hours'] ?? 0); ?></div>
                <div class="stat-label">إجمالي الساعات</div>
            </div>
        </div>

        <!-- النشاط الأخير -->
        <div class="recent-activity">
            <h3 class="card-title">📅 سجل الحضور الأخير</h3>
            <?php if (empty($recent_attendance)): ?>
                <div style="text-align: center; color: #7f8c8d; padding: 20px;">
                    لا يوجد سجل حضور حتى الآن
                </div>
            <?php else: ?>
                <?php foreach ($recent_attendance as $record): ?>
                    <div class="activity-item">
                        <div class="activity-icon <?php echo $record['status']; ?>">
                            <?php
                            if ($record['status'] == 'late') echo '⏰';
                            elseif ($record['status'] == 'absent') echo '❌';
                            else echo '✅';
                            ?>
                        </div>
                        <div class="activity-content">
                            <div class="activity-title"><?php echo formatArabicDate($record['attendance_date']); ?></div>
                            <div class="activity-details">
                                <?php if ($record['check_in_time']): ?>
                                    حضور: <?php echo formatArabicTime($record['check_in_time']); ?>
                                    <?php if ($record['check_out_time']): ?>
                                        | انصراف: <?php echo formatArabicTime($record['check_out_time']); ?>
                                    <?php endif; ?>
                                    <?php if ($record['work_hours']): ?>
                                        | ساعات: <?php echo round($record['work_hours'], 1); ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    غياب
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- شريط التنقل السفلي -->
    <div class="navbar">
        <div class="nav-items">
            <a href="employee_dashboard.php" class="nav-item active">
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
            <a href="employee_leaves.php" class="nav-item">
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
        
        updateCurrentTime();
        setInterval(updateCurrentTime, 1000);

        function openFaceAttendance(type) {
            window.location.href = `face_attendance.php?type=${type}`;
        }
    </script>
</body>
</html>