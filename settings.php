<?php
require_once 'config.php';
checkAdminLogin();

$message = '';
$error = '';

// معالجة حفظ الإعدادات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = Database::getInstance()->getConnection();
        $db->beginTransaction();
        
        // إعدادات الشركة
        if (isset($_POST['company_settings'])) {
            updateSetting('company_name', $_POST['company_name'] ?? '');
            updateSetting('company_address', $_POST['company_address'] ?? '');
            updateSetting('company_phone', $_POST['company_phone'] ?? '');
            updateSetting('company_email', $_POST['company_email'] ?? '');
        }
        
        // إعدادات أوقات العمل
        if (isset($_POST['work_time_settings'])) {
            updateSetting('work_start_time', $_POST['work_start_time'] ?? '08:00');
            updateSetting('work_end_time', $_POST['work_end_time'] ?? '17:00');
            updateSetting('attendance_start_time', $_POST['attendance_start_time'] ?? '07:30');
            updateSetting('attendance_end_time', $_POST['attendance_end_time'] ?? '09:00');
            updateSetting('checkout_start_time', $_POST['checkout_start_time'] ?? '16:30');
            updateSetting('checkout_end_time', $_POST['checkout_end_time'] ?? '18:00');
            updateSetting('late_tolerance_minutes', $_POST['late_tolerance_minutes'] ?? '15');
        }
        
        // إعدادات الشبكة والأمان
        if (isset($_POST['network_settings'])) {
            updateSetting('required_wifi_network', $_POST['required_wifi_network'] ?? '');
            updateSetting('allowed_ip_ranges', $_POST['allowed_ip_ranges'] ?? '');
            updateSetting('face_match_threshold', $_POST['face_match_threshold'] ?? '0.75');
        }
        
        // إعدادات الإشعارات
        if (isset($_POST['notification_settings'])) {
            updateSetting('enable_notifications', isset($_POST['enable_notifications']) ? '1' : '0');
            updateSetting('notification_email', $_POST['notification_email'] ?? '');
            updateSetting('late_notification_threshold', $_POST['late_notification_threshold'] ?? '30');
        }
        
        $db->commit();
        logActivity('admin', $_SESSION['admin_id'], 'update_settings', 'تحديث إعدادات النظام');
        $message = 'تم حفظ الإعدادات بنجاح';
        
    } catch (Exception $e) {
        $db->rollback();
        error_log("Settings update error: " . $e->getMessage());
        $error = 'حدث خطأ في حفظ الإعدادات';
    }
}

// جلب الإعدادات الحالية
$settings = getSystemSettings();
$app_name = getSetting('company_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إعدادات النظام - <?php echo htmlspecialchars($app_name); ?></title>
    
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
            max-width: 1200px;
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
        
        .settings-grid {
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 30px;
        }
        
        .settings-sidebar {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            height: fit-content;
        }
        
        .sidebar-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #2c3e50;
        }
        
        .sidebar-nav {
            list-style: none;
        }
        
        .sidebar-nav li {
            margin-bottom: 10px;
        }
        
        .sidebar-nav a {
            display: block;
            padding: 12px 15px;
            color: #7f8c8d;
            text-decoration: none;
            border-radius: 10px;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        
        .sidebar-nav a:hover,
        .sidebar-nav a.active {
            background: #667eea;
            color: white;
        }
        
        .settings-content {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        
        .settings-section {
            display: none;
            padding: 30px;
        }
        
        .settings-section.active {
            display: block;
        }
        
        .section-title {
            font-size: 24px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-description {
            color: #7f8c8d;
            margin-bottom: 30px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        input[type="text"],
        input[type="email"],
        input[type="time"],
        input[type="number"],
        textarea,
        select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e1e5e9;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }
        
        input:focus,
        textarea:focus,
        select:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }
        
        textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: auto;
            accent-color: #667eea;
        }
        
        .save-btn {
            background: linear-gradient(135deg, #56ab2f, #a8e6cf);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s ease;
            margin-top: 30px;
        }
        
        .save-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(86,171,47,0.3);
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
        
        .help-text {
            font-size: 14px;
            color: #6c757d;
            margin-top: 5px;
        }
        
        .divider {
            height: 1px;
            background: #dee2e6;
            margin: 30px 0;
        }
        
        @media (max-width: 1024px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 0 15px;
            }
            
            .settings-content {
                margin: 0;
            }
            
            .settings-section {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo-section">
                <div class="logo">⚙️</div>
                <div>
                    <div class="header-title">إعدادات النظام</div>
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
                <li class="nav-item"><a href="reports.php">التقارير</a></li>
                <li class="nav-item"><a href="settings.php" class="active">الإعدادات</a></li>
            </ul>
        </div>
    </nav>
    
    <main class="main-content">
        <?php if ($message): ?>
            <div class="notification success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        
        <div class="page-header">
            <h1 class="page-title">إعدادات النظام</h1>
            <p class="page-description">تخصيص وإدارة جميع إعدادات نظام بصمة الوجه</p>
        </div>
        
        <div class="settings-grid">
            <div class="settings-sidebar">
                <h3 class="sidebar-title">أقسام الإعدادات</h3>
                <ul class="sidebar-nav">
                    <li><a href="#company" class="nav-link active" onclick="showSection('company')">🏢 معلومات الشركة</a></li>
                    <li><a href="#work-time" class="nav-link" onclick="showSection('work-time')">⏰ أوقات العمل</a></li>
                    <li><a href="#network" class="nav-link" onclick="showSection('network')">🌐 الشبكة والأمان</a></li>
                    <li><a href="#notifications" class="nav-link" onclick="showSection('notifications')">🔔 الإشعارات</a></li>
                    <li><a href="#holidays" class="nav-link" onclick="showSection('holidays')">🏖️ العطل والإجازات</a></li>
                </ul>
            </div>
            
            <div class="settings-content">
                <!-- إعدادات الشركة -->
                <div id="company" class="settings-section active">
                    <h2 class="section-title">🏢 معلومات الشركة</h2>
                    <p class="section-description">تحديث البيانات الأساسية للشركة والمؤسسة</p>
                    
                    <form method="POST">
                        <input type="hidden" name="company_settings" value="1">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="company_name">اسم الشركة *</label>
                                <input type="text" id="company_name" name="company_name" 
                                       value="<?php echo htmlspecialchars($settings['company_name'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="company_email">البريد الإلكتروني</label>
                                <input type="email" id="company_email" name="company_email" 
                                       value="<?php echo htmlspecialchars($settings['company_email'] ?? ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="company_phone">رقم الهاتف</label>
                                <input type="text" id="company_phone" name="company_phone" 
                                       value="<?php echo htmlspecialchars($settings['company_phone'] ?? ''); ?>">
                            </div>
                            
                            <div class="form-group full-width">
                                <label for="company_address">عنوان الشركة</label>
                                <textarea id="company_address" name="company_address" 
                                          placeholder="أدخل العنوان الكامل للشركة"><?php echo htmlspecialchars($settings['company_address'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        
                        <button type="submit" class="save-btn">💾 حفظ معلومات الشركة</button>
                    </form>
                </div>
                
                <!-- إعدادات أوقات العمل -->
                <div id="work-time" class="settings-section">
                    <h2 class="section-title">⏰ أوقات العمل والحضور</h2>
                    <p class="section-description">تحديد مواعيد العمل الرسمية وأوقات تسجيل الحضور والانصراف</p>
                    
                    <form method="POST">
                        <input type="hidden" name="work_time_settings" value="1">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="work_start_time">بداية الدوام الرسمي</label>
                                <input type="time" id="work_start_time" name="work_start_time" 
                                       value="<?php echo $settings['work_start_time'] ?? '08:00'; ?>">
                                <div class="help-text">الوقت الرسمي لبداية العمل</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="work_end_time">نهاية الدوام الرسمي</label>
                                <input type="time" id="work_end_time" name="work_end_time" 
                                       value="<?php echo $settings['work_end_time'] ?? '17:00'; ?>">
                                <div class="help-text">الوقت الرسمي لنهاية العمل</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="attendance_start_time">بداية فترة تسجيل الحضور</label>
                                <input type="time" id="attendance_start_time" name="attendance_start_time" 
                                       value="<?php echo $settings['attendance_start_time'] ?? '07:30'; ?>">
                                <div class="help-text">أقرب وقت يمكن تسجيل الحضور فيه</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="attendance_end_time">نهاية فترة تسجيل الحضور</label>
                                <input type="time" id="attendance_end_time" name="attendance_end_time" 
                                       value="<?php echo $settings['attendance_end_time'] ?? '09:00'; ?>">
                                <div class="help-text">آخر وقت يمكن تسجيل الحضور فيه</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="checkout_start_time">بداية فترة تسجيل الانصراف</label>
                                <input type="time" id="checkout_start_time" name="checkout_start_time" 
                                       value="<?php echo $settings['checkout_start_time'] ?? '16:30'; ?>">
                                <div class="help-text">أقرب وقت يمكن تسجيل الانصراف فيه</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="checkout_end_time">نهاية فترة تسجيل الانصراف</label>
                                <input type="time" id="checkout_end_time" name="checkout_end_time" 
                                       value="<?php echo $settings['checkout_end_time'] ?? '18:00'; ?>">
                                <div class="help-text">آخر وقت يمكن تسجيل الانصراف فيه</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="late_tolerance_minutes">فترة السماح للتأخير (بالدقائق)</label>
                                <input type="number" id="late_tolerance_minutes" name="late_tolerance_minutes" 
                                       value="<?php echo $settings['late_tolerance_minutes'] ?? '15'; ?>" min="0" max="60">
                                <div class="help-text">عدد الدقائق المسموحة بعد موعد الحضور قبل اعتبار الموظف متأخراً</div>
                            </div>
                        </div>
                        
                        <button type="submit" class="save-btn">💾 حفظ إعدادات أوقات العمل</button>
                    </form>
                </div>
                
                <!-- إعدادات الشبكة والأمان -->
                <div id="network" class="settings-section">
                    <h2 class="section-title">🌐 الشبكة والأمان</h2>
                    <p class="section-description">إعدادات الأمان وقيود الشبكة لضمان تسجيل الحضور من مكان العمل</p>
                    
                    <form method="POST">
                        <input type="hidden" name="network_settings" value="1">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="required_wifi_network">اسم شبكة WiFi المطلوبة</label>
                                <input type="text" id="required_wifi_network" name="required_wifi_network" 
                                       value="<?php echo htmlspecialchars($settings['required_wifi_network'] ?? ''); ?>"
                                       placeholder="اسم شبكة الشركة">
                                <div class="help-text">الموظفون يجب أن يكونوا متصلين بهذه الشبكة لتسجيل الحضور</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="face_match_threshold">درجة دقة التطابق للوجه</label>
                                <select id="face_match_threshold" name="face_match_threshold">
                                    <option value="0.6" <?php echo ($settings['face_match_threshold'] ?? '0.75') == '0.6' ? 'selected' : ''; ?>>منخفضة (60%)</option>
                                    <option value="0.7" <?php echo ($settings['face_match_threshold'] ?? '0.75') == '0.7' ? 'selected' : ''; ?>>متوسطة (70%)</option>
                                    <option value="0.75" <?php echo ($settings['face_match_threshold'] ?? '0.75') == '0.75' ? 'selected' : ''; ?>>عالية (75%) - مُوصى</option>
                                    <option value="0.8" <?php echo ($settings['face_match_threshold'] ?? '0.75') == '0.8' ? 'selected' : ''; ?>>عالية جداً (80%)</option>
                                    <option value="0.9" <?php echo ($settings['face_match_threshold'] ?? '0.75') == '0.9' ? 'selected' : ''; ?>>قصوى (90%)</option>
                                </select>
                                <div class="help-text">كلما زادت الدرجة، زادت الدقة وقلت فرص القبول الخاطئ</div>
                            </div>
                            
                            <div class="form-group full-width">
                                <label for="allowed_ip_ranges">نطاقات IP المسموحة</label>
                                <textarea id="allowed_ip_ranges" name="allowed_ip_ranges" 
                                          placeholder="192.168.1.*, 10.0.0.0/24, 172.16.0.0/16"><?php echo htmlspecialchars($settings['allowed_ip_ranges'] ?? ''); ?></textarea>
                                <div class="help-text">نطاقات IP للشبكات المسموحة، مفصولة بفواصل. مثال: 192.168.1.*, 10.0.0.0/24</div>
                            </div>
                        </div>
                        
                        <button type="submit" class="save-btn">💾 حفظ إعدادات الشبكة والأمان</button>
                    </form>
                </div>
                
                <!-- إعدادات الإشعارات -->
                <div id="notifications" class="settings-section">
                    <h2 class="section-title">🔔 إعدادات الإشعارات</h2>
                    <p class="section-description">تخصيص الإشعارات والتنبيهات التي يتم إرسالها للإدارة</p>
                    
                    <form method="POST">
                        <input type="hidden" name="notification_settings" value="1">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <div class="checkbox-group">
                                    <input type="checkbox" id="enable_notifications" name="enable_notifications" 
                                           <?php echo ($settings['enable_notifications'] ?? '1') == '1' ? 'checked' : ''; ?>>
                                    <label for="enable_notifications">تفعيل الإشعارات</label>
                                </div>
                                <div class="help-text">تفعيل أو إيقاف جميع الإشعارات في النظام</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="notification_email">البريد الإلكتروني للإشعارات</label>
                                <input type="email" id="notification_email" name="notification_email" 
                                       value="<?php echo htmlspecialchars($settings['notification_email'] ?? ''); ?>"
                                       placeholder="admin@company.com">
                                <div class="help-text">البريد الذي ستصله الإشعارات المهمة</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="late_notification_threshold">عتبة التنبيه للتأخير (دقائق)</label>
                                <input type="number" id="late_notification_threshold" name="late_notification_threshold" 
                                       value="<?php echo $settings['late_notification_threshold'] ?? '30'; ?>" min="5" max="120">
                                <div class="help-text">إرسال تنبيه عند تأخر الموظف أكثر من هذا العدد من الدقائق</div>
                            </div>
                        </div>
                        
                        <button type="submit" class="save-btn">💾 حفظ إعدادات الإشعارات</button>
                    </form>
                </div>
                
                <!-- إدارة العطل -->
                <div id="holidays" class="settings-section">
                    <h2 class="section-title">🏖️ إدارة العطل والإجازات</h2>
                    <p class="section-description">إضافة وإدارة أيام العطل الرسمية وأنواع الإجازات</p>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <h3>العطل الرسمية القادمة</h3>
                            <div id="holidaysList">
                                <!-- قائمة العطل ستُملأ بـ JavaScript -->
                            </div>
                            <button type="button" class="save-btn" onclick="addHoliday()">➕ إضافة عطلة جديدة</button>
                        </div>
                        
                        <div class="form-group">
                            <h3>أنواع الإجازات</h3>
                            <div id="leaveTypesList">
                                <!-- قائمة أنواع الإجازات -->
                            </div>
                            <button type="button" class="save-btn" onclick="addLeaveType()">➕ إضافة نوع إجازة</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        function showSection(sectionId) {
            // إخفاء جميع الأقسام
            document.querySelectorAll('.settings-section').forEach(section => {
                section.classList.remove('active');
            });
            
            // إظهار القسم المحدد
            document.getElementById(sectionId).classList.add('active');
            
            // تحديث التنقل الجانبي
            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active');
            });
            
            event.target.classList.add('active');
        }
        
        function addHoliday() {
            const name = prompt('اسم العطلة:');
            const date = prompt('تاريخ العطلة (YYYY-MM-DD):');
            
            if (name && date) {
                fetch('manage_holiday.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'add',
                        name: name,
                        date: date
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('تم إضافة العطلة بنجاح');
                        loadHolidays();
                    } else {
                        alert('حدث خطأ: ' + data.message);
                    }
                });
            }
        }
        
        function addLeaveType() {
            const nameAr = prompt('اسم نوع الإجازة (بالعربية):');
            const nameEn = prompt('اسم نوع الإجازة (بالإنجليزية):');
            const maxDays = prompt('الحد الأقصى للأيام سنوياً (0 = بلا حدود):');
            
            if (nameAr && nameEn) {
                fetch('manage_leave_type.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'add',
                        name_ar: nameAr,
                        name_en: nameEn,
                        max_days: parseInt(maxDays) || 0
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('تم إضافة نوع الإجازة بنجاح');
                        loadLeaveTypes();
                    } else {
                        alert('حدث خطأ: ' + data.message);
                    }
                });
            }
        }
        
        function loadHolidays() {
            // تحميل قائمة العطل
            fetch('get_holidays.php')
                .then(response => response.json())
                .then(data => {
                    const container = document.getElementById('holidaysList');
                    container.innerHTML = '';
                    
                    if (data.success && data.holidays.length > 0) {
                        data.holidays.forEach(holiday => {
                            const item = document.createElement('div');
                            item.style.cssText = 'padding: 10px; border: 1px solid #dee2e6; border-radius: 8px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center;';
                            item.innerHTML = `
                                <div>
                                    <strong>${holiday.holiday_name}</strong><br>
                                    <small>${holiday.holiday_date}</small>
                                </div>
                                <button onclick="deleteHoliday(${holiday.id})" style="background: #e74c3c; color: white; border: none; padding: 5px 10px; border-radius: 5px; cursor: pointer;">حذف</button>
                            `;
                            container.appendChild(item);
                        });
                    } else {
                        container.innerHTML = '<p style="color: #6c757d;">لا توجد عطل مُضافة</p>';
                    }
                });
        }
        
        function loadLeaveTypes() {
            // تحميل أنواع الإجازات
            fetch('get_leave_types.php')
                .then(response => response.json())
                .then(data => {
                    const container = document.getElementById('leaveTypesList');
                    container.innerHTML = '';
                    
                    if (data.success && data.types.length > 0) {
                        data.types.forEach(type => {
                            const item = document.createElement('div');
                            item.style.cssText = 'padding: 10px; border: 1px solid #dee2e6; border-radius: 8px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center;';
                            item.innerHTML = `
                                <div>
                                    <strong>${type.type_name_ar}</strong> (${type.type_name})<br>
                                    <small>الحد الأقصى: ${type.max_days_per_year || 'بلا حدود'} يوم</small>
                                </div>
                                <button onclick="deleteLeaveType(${type.id})" style="background: #e74c3c; color: white; border: none; padding: 5px 10px; border-radius: 5px; cursor: pointer;">حذف</button>
                            `;
                            container.appendChild(item);
                        });
                    } else {
                        container.innerHTML = '<p style="color: #6c757d;">لا توجد أنواع إجازات مُضافة</p>';
                    }
                });
        }
        
        function deleteHoliday(id) {
            if (confirm('هل أنت متأكد من حذف هذه العطلة؟')) {
                fetch('manage_holiday.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'delete',
                        id: id
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        loadHolidays();
                    } else {
                        alert('حدث خطأ في الحذف');
                    }
                });
            }
        }
        
        function deleteLeaveType(id) {
            if (confirm('هل أنت متأكد من حذف نوع الإجازة هذا؟')) {
                fetch('manage_leave_type.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'delete',
                        id: id
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        loadLeaveTypes();
                    } else {
                        alert('حدث خطأ في الحذف');
                    }
                });
            }
        }
        
        // تحميل البيانات عند بدء الصفحة
        document.addEventListener('DOMContentLoaded', function() {
            loadHolidays();
            loadLeaveTypes();
        });
    </script>
</body>
</html>