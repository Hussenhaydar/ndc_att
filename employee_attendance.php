<?php
require_once 'config.php';
checkEmployeeLogin();

$database = Database::getInstance();
$db = $database->getConnection();

$employee_id = $_SESSION['employee_id'];
$current_month = $_GET['month'] ?? date('Y-m');
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 31; // شهر كامل

// جلب بيانات الموظف
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$employee_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

// جلب سجلات الحضور للشهر المحدد
$stmt = $db->prepare("
    SELECT * FROM attendance 
    WHERE employee_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
    ORDER BY attendance_date DESC
");
$stmt->execute([$employee_id, $current_month]);
$attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// إحصائيات الشهر
$monthly_stats = [
    'total_days' => count($attendance_records),
    'present_days' => count(array_filter($attendance_records, fn($r) => $r['check_in_time'])),
    'late_days' => count(array_filter($attendance_records, fn($r) => $r['is_late'])),
    'absent_days' => count(array_filter($attendance_records, fn($r) => !$r['check_in_time'])),
    'total_work_hours' => array_sum(array_column($attendance_records, 'work_hours')),
    'avg_work_hours' => count($attendance_records) > 0 ? array_sum(array_column($attendance_records, 'work_hours')) / count($attendance_records) : 0
];

$app_name = getSetting('company_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>سجل الحضور - <?php echo htmlspecialchars($employee['full_name']); ?></title>
    
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
        
        .month-selector {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(20px);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .month-selector input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e1e5e9;
            border-radius: 10px;
            font-size: 16px;
            text-align: center;
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
        
        .attendance-list {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .list-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .attendance-item {
            padding: 15px;
            border-bottom: 1px solid #f1f3f4;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .attendance-item:last-child {
            border-bottom: none;
        }
        
        .attendance-date {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .attendance-times {
            font-size: 12px;
            color: #6c757d;
        }
        
        .attendance-status {
            text-align: right;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 5px;
            display: inline-block;
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
        
        .status-working {
            background: #cce5ff;
            color: #004085;
        }
        
        .work-hours {
            font-size: 12px;
            color: #28a745;
            font-weight: 600;
        }
        
        .late-info {
            font-size: 11px;
            color: #dc3545;
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
        
        .calendar-view {
            display: none;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
            margin-top: 15px;
        }
        
        .calendar-header {
            text-align: center;
            font-weight: 600;
            padding: 10px 5px;
            color: #667eea;
            font-size: 12px;
        }
        
        .calendar-day {
            aspect-ratio: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            position: relative;
        }
        
        .calendar-day.present {
            background: #d4edda;
            color: #155724;
        }
        
        .calendar-day.late {
            background: #fff3cd;
            color: #856404;
        }
        
        .calendar-day.absent {
            background: #f8d7da;
            color: #721c24;
        }
        
        .calendar-day.weekend {
            background: #f8f9fa;
            color: #6c757d;
        }
        
        .view-toggle {
            display: flex;
            background: #f8f9fa;
            border-radius: 10px;
            padding: 5px;
            margin-bottom: 20px;
        }
        
        .toggle-btn {
            flex: 1;
            padding: 10px;
            text-align: center;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .toggle-btn.active {
            background: #667eea;
            color: white;
        }
        
        @media (max-width: 480px) {
            .container {
                padding: 5px;
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
            <h1 class="title">سجل الحضور الشخصي</h1>
            <div class="employee-info">
                <?php echo htmlspecialchars($employee['full_name']); ?> - <?php echo htmlspecialchars($employee['employee_id']); ?>
            </div>
        </div>
        
        <!-- اختيار الشهر -->
        <div class="month-selector">
            <input type="month" value="<?php echo $current_month; ?>" onchange="changeMonth(this.value)">
        </div>
        
        <!-- إحصائيات الشهر -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $monthly_stats['present_days']; ?></div>
                <div class="stat-label">أيام الحضور</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $monthly_stats['late_days']; ?></div>
                <div class="stat-label">أيام التأخير</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $monthly_stats['absent_days']; ?></div>
                <div class="stat-label">أيام الغياب</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo round($monthly_stats['avg_work_hours'], 1); ?></div>
                <div class="stat-label">متوسط ساعات العمل</div>
            </div>
        </div>
        
        <!-- تبديل العرض -->
        <div class="view-toggle">
            <div class="toggle-btn active" onclick="switchView('list')">📋 قائمة</div>
            <div class="toggle-btn" onclick="switchView('calendar')">📅 تقويم</div>
        </div>
        
        <!-- عرض القائمة -->
        <div class="attendance-list" id="listView">
            <h3 class="list-title">سجل الحضور - <?php echo date('F Y', strtotime($current_month . '-01')); ?></h3>
            
            <?php if (empty($attendance_records)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📅</div>
                    <h3>لا توجد سجلات</h3>
                    <p>لا توجد سجلات حضور لهذا الشهر</p>
                </div>
            <?php else: ?>
                <?php foreach ($attendance_records as $record): ?>
                    <div class="attendance-item">
                        <div>
                            <div class="attendance-date">
                                <?php echo formatArabicDate($record['attendance_date']); ?>
                            </div>
                            <div class="attendance-times">
                                <?php if ($record['check_in_time']): ?>
                                    حضور: <?php echo formatArabicTime($record['check_in_time']); ?>
                                    <?php if ($record['check_out_time']): ?>
                                        | انصراف: <?php echo formatArabicTime($record['check_out_time']); ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    لم يتم تسجيل الحضور
                                <?php endif; ?>
                            </div>
                            <?php if ($record['is_late'] && $record['late_minutes'] > 0): ?>
                                <div class="late-info">
                                    متأخر <?php echo $record['late_minutes']; ?> دقيقة
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="attendance-status">
                            <?php
                            if (!$record['check_in_time']) {
                                echo '<span class="status-badge status-absent">غائب</span>';
                            } elseif (!$record['check_out_time']) {
                                echo '<span class="status-badge status-working">لم يسجل خروج</span>';
                            } elseif ($record['is_late']) {
                                echo '<span class="status-badge status-late">متأخر</span>';
                            } else {
                                echo '<span class="status-badge status-present">حاضر</span>';
                            }
                            ?>
                            
                            <?php if ($record['work_hours'] > 0): ?>
                                <div class="work-hours">
                                    <?php echo round($record['work_hours'], 1); ?> ساعة
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- عرض التقويم -->
        <div class="calendar-view" id="calendarView">
            <h3 class="list-title">تقويم الحضور - <?php echo date('F Y', strtotime($current_month . '-01')); ?></h3>
            
            <div class="calendar-grid">
                <!-- رؤوس الأيام -->
                <div class="calendar-header">أحد</div>
                <div class="calendar-header">اثنين</div>
                <div class="calendar-header">ثلاثاء</div>
                <div class="calendar-header">أربعاء</div>
                <div class="calendar-header">خميس</div>
                <div class="calendar-header">جمعة</div>
                <div class="calendar-header">سبت</div>
                
                <?php
                // إنشاء تقويم الشهر
                $year = date('Y', strtotime($current_month . '-01'));
                $month = date('n', strtotime($current_month . '-01'));
                $firstDay = mktime(0, 0, 0, $month, 1, $year);
                $lastDay = date('t', $firstDay);
                $startDayOfWeek = date('w', $firstDay);
                
                // إنشاء مصفوفة للسجلات
                $records_by_date = [];
                foreach ($attendance_records as $record) {
                    $date = date('j', strtotime($record['attendance_date']));
                    $records_by_date[$date] = $record;
                }
                
                // إضافة أيام فارغة قبل بداية الشهر
                for ($i = 0; $i < $startDayOfWeek; $i++) {
                    echo '<div class="calendar-day"></div>';
                }
                
                // إضافة أيام الشهر
                for ($day = 1; $day <= $lastDay; $day++) {
                    $dayOfWeek = date('w', mktime(0, 0, 0, $month, $day, $year));
                    $isWeekend = ($dayOfWeek == 5 || $dayOfWeek == 6); // جمعة وسبت
                    
                    $class = 'calendar-day';
                    
                    if (isset($records_by_date[$day])) {
                        $record = $records_by_date[$day];
                        if (!$record['check_in_time']) {
                            $class .= ' absent';
                        } elseif ($record['is_late']) {
                            $class .= ' late';
                        } else {
                            $class .= ' present';
                        }
                    } elseif ($isWeekend) {
                        $class .= ' weekend';
                    }
                    
                    echo '<div class="' . $class . '">' . $day . '</div>';
                }
                ?>
            </div>
            
            <!-- مفتاح الألوان -->
            <div style="display: flex; justify-content: space-around; margin-top: 15px; font-size: 12px;">
                <div><span class="status-badge status-present">حاضر</span></div>
                <div><span class="status-badge status-late">متأخر</span></div>
                <div><span class="status-badge status-absent">غائب</span></div>
                <div><span style="background: #f8f9fa; color: #6c757d; padding: 4px 8px; border-radius: 12px;">عطلة</span></div>
            </div>
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
            <a href="employee_attendance.php" class="nav-item active">
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
        function changeMonth(month) {
            window.location.href = `employee_attendance.php?month=${month}`;
        }
        
        function switchView(viewType) {
            const listView = document.getElementById('listView');
            const calendarView = document.getElementById('calendarView');
            const toggleBtns = document.querySelectorAll('.toggle-btn');
            
            // إزالة التفعيل من جميع الأزرار
            toggleBtns.forEach(btn => btn.classList.remove('active'));
            
            if (viewType === 'list') {
                listView.style.display = 'block';
                calendarView.style.display = 'none';
                toggleBtns[0].classList.add('active');
            } else {
                listView.style.display = 'none';
                calendarView.style.display = 'block';
                toggleBtns[1].classList.add('active');
            }
        }
        
        // حفظ تفضيل العرض
        function saveViewPreference(viewType) {
            localStorage.setItem('attendanceView', viewType);
        }
        
        // استعادة تفضيل العرض
        document.addEventListener('DOMContentLoaded', function() {
            const savedView = localStorage.getItem('attendanceView');
            if (savedView) {
                switchView(savedView);
            }
        });
        
        // إضافة مستمع للنقر على أزرار التبديل
        document.querySelectorAll('.toggle-btn').forEach((btn, index) => {
            btn.addEventListener('click', () => {
                const viewType = index === 0 ? 'list' : 'calendar';
                saveViewPreference(viewType);
            });
        });
    </script>
</body>
</html>