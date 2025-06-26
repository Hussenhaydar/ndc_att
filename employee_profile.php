<?php
require_once 'config.php';
checkEmployeeLogin();

$database = Database::getInstance();
$db = $database->getConnection();

$employee_id = $_SESSION['employee_id'];
$message = '';
$error = '';

// معالجة تحديث الملف الشخصي
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'update_profile') {
            $email = Security::sanitizeInput($_POST['email']);
            $phone = Security::sanitizeInput($_POST['phone']);
            
            // التحقق من البريد الإلكتروني
            if (!empty($email) && !Security::validateEmail($email)) {
                throw new Exception('البريد الإلكتروني غير صحيح');
            }
            
            $stmt = $db->prepare("UPDATE employees SET email = ?, phone = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$email, $phone, $employee_id]);
            
            // تحديث الجلسة
            $_SESSION['employee_email'] = $email;
            
            logActivity('employee', $employee_id, 'update_profile', 'تحديث الملف الشخصي');
            $message = 'تم تحديث الملف الشخصي بنجاح';
            
        } elseif ($action === 'change_password') {
            $current_password = $_POST['current_password'];
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];
            
            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                throw new Exception('يرجى ملء جميع حقول كلمة المرور');
            }
            
            if ($new_password !== $confirm_password) {
                throw new Exception('كلمة المرور الجديدة وتأكيدها غير متطابقتين');
            }
            
            if (!Security::validatePassword($new_password)) {
                throw new Exception('كلمة المرور يجب أن تكون 6 أحرف على الأقل وتحتوي على أرقام وحروف');
            }
            
            // التحقق من كلمة المرور الحالية
            $stmt = $db->prepare("SELECT password FROM employees WHERE id = ?");
            $stmt->execute([$employee_id]);
            $current_hash = $stmt->fetchColumn();
            
            if (!Security::verifyPassword($current_password, $current_hash)) {
                throw new Exception('كلمة المرور الحالية غير صحيحة');
            }
            
            // تحديث كلمة المرور
            $new_hash = Security::hashPassword($new_password);
            $stmt = $db->prepare("UPDATE employees SET password = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_hash, $employee_id]);
            
            logActivity('employee', $employee_id, 'change_password', 'تغيير كلمة المرور');
            $message = 'تم تغيير كلمة المرور بنجاح';
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// جلب بيانات الموظف
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$employee_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

// جلب إحصائيات سريعة
$current_month = date('Y-m');
$stats_stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_days,
        SUM(CASE WHEN check_in_time IS NOT NULL THEN 1 ELSE 0 END) as present_days,
        SUM(CASE WHEN is_late = 1 THEN 1 ELSE 0 END) as late_days,
        AVG(work_hours) as avg_work_hours
    FROM attendance 
    WHERE employee_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
");
$stats_stmt->execute([$employee_id, $current_month]);
$monthly_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$app_name = getSetting('company_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>الملف الشخصي - <?php echo htmlspecialchars($employee['full_name']); ?></title>
    
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
        
        .card {
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
        
        .info-grid {
            display: grid;
            gap: 15px;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        
        .info-label {
            font-weight: 600;
            color: #6c757d;
        }
        
        .info-value {
            color: #2c3e50;
            font-weight: 500;
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
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e1e5e9;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
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
        
        .btn-warning {
            background: linear-gradient(135deg, #f39c12, #e67e22);
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.2);
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
        
        .section-toggle {
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
        
        .section {
            display: none;
        }
        
        .section.active {
            display: block;
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
            <div class="profile-pic">
                <?php echo strtoupper(substr($employee['full_name'], 0, 1)); ?>
            </div>
            <h1 class="title">الملف الشخصي</h1>
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
                <div class="stat-number"><?php echo count(json_decode($employee['face_images'] ?? '[]', true)); ?></div>
                <div class="stat-label">صور بصمة الوجه</div>
            </div>
        </div>
        
        <!-- تبديل الأقسام -->
        <div class="section-toggle">
            <div class="toggle-btn active" onclick="showSection('info')">المعلومات الشخصية</div>
            <div class="toggle-btn" onclick="showSection('password')">كلمة المرور</div>
        </div>
        
        <!-- المعلومات الشخصية -->
        <div id="info" class="section active">
            <div class="card">
                <h2 class="card-title">👤 المعلومات الأساسية</h2>
                
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">الاسم الكامل</span>
                        <span class="info-value"><?php echo htmlspecialchars($employee['full_name']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">رقم الموظف</span>
                        <span class="info-value"><?php echo htmlspecialchars($employee['employee_id']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">اسم المستخدم</span>
                        <span class="info-value"><?php echo htmlspecialchars($employee['username']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">القسم</span>
                        <span class="info-value"><?php echo htmlspecialchars($employee['department'] ?: 'غير محدد'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">المنصب</span>
                        <span class="info-value"><?php echo htmlspecialchars($employee['position'] ?: 'غير محدد'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">تاريخ التوظيف</span>
                        <span class="info-value"><?php echo $employee['hire_date'] ? formatArabicDate($employee['hire_date']) : 'غير محدد'; ?></span>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <h2 class="card-title">📞 معلومات الاتصال</h2>
                
                <form method="POST">
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div class="form-group">
                        <label for="email">البريد الإلكتروني</label>
                        <input type="email" id="email" name="email" 
                               value="<?php echo htmlspecialchars($employee['email'] ?? ''); ?>"
                               placeholder="your.email@example.com">
                    </div>
                    
                    <div class="form-group">
                        <label for="phone">رقم الهاتف</label>
                        <input type="text" id="phone" name="phone" 
                               value="<?php echo htmlspecialchars($employee['phone'] ?? ''); ?>"
                               placeholder="+966501234567">
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        💾 حفظ التغييرات
                    </button>
                </form>
            </div>
        </div>
        
        <!-- تغيير كلمة المرور -->
        <div id="password" class="section">
            <div class="card">
                <h2 class="card-title">🔒 تغيير كلمة المرور</h2>
                
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="form-group">
                        <label for="current_password">كلمة المرور الحالية</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="new_password">كلمة المرور الجديدة</label>
                        <input type="password" id="new_password" name="new_password" required minlength="6">
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">تأكيد كلمة المرور الجديدة</label>
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="6">
                    </div>
                    
                    <button type="submit" class="btn btn-warning">
                        🔑 تغيير كلمة المرور
                    </button>
                </form>
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
            <a href="employee_attendance.php" class="nav-item">
                <div class="nav-icon">📊</div>
                <div>سجل الحضور</div>
            </a>
            <a href="employee_leaves.php" class="nav-item">
                <div class="nav-icon">🏖️</div>
                <div>الإجازات</div>
            </a>
            <a href="employee_profile.php" class="nav-item active">
                <div class="nav-icon">⚙️</div>
                <div>الملف الشخصي</div>
            </a>
        </div>
    </div>

    <script>
        function showSection(sectionId) {
            // إخفاء جميع الأقسام
            document.querySelectorAll('.section').forEach(section => {
                section.classList.remove('active');
            });
            
            // إظهار القسم المحدد
            document.getElementById(sectionId).classList.add('active');
            
            // تحديث أزرار التبديل
            document.querySelectorAll('.toggle-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            event.target.classList.add('active');
        }
        
        // التحقق من تطابق كلمات المرور
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (newPassword !== confirmPassword) {
                this.setCustomValidity('كلمات المرور غير متطابقة');
            } else {
                this.setCustomValidity('');
            }
        });
        
        // فحص قوة كلمة المرور
        document.getElementById('new_password').addEventListener('input', function() {
            const password = this.value;
            let strength = 0;
            
            if (password.length >= 6) strength++;
            if (/[a-z]/.test(password)) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            // يمكن إضافة مؤشر قوة كلمة المرور هنا
        });
    </script>
</body>
</html>