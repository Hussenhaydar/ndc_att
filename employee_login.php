<?php
require_once 'config.php';

// إعادة توجيه إذا كان مسجل دخول
if (isset($_SESSION['employee_id'])) {
    redirect('employee_dashboard.php');
}

$error = '';
$success = '';
$lockout_message = '';

// معالجة تسجيل الدخول
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = Security::sanitizeInput($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';
    $remember_me = isset($_POST['remember_me']);
    
    // التحقق من CSRF
    if (!Security::validateCSRFToken($csrf_token)) {
        $error = 'رمز الأمان غير صحيح. يرجى المحاولة مرة أخرى.';
    }
    // فحص البيانات الأساسية
    elseif (empty($username) || empty($password)) {
        $error = 'يرجى إدخال اسم المستخدم وكلمة المرور';
    }
    // فحص محاولات تسجيل الدخول
    elseif (!Security::checkLoginAttempts($username)) {
        $lockout_message = 'تم تجاوز عدد محاولات تسجيل الدخول المسموحة. يرجى المحاولة بعد ' . LOGIN_LOCKOUT_TIME . ' دقيقة.';
    }
    // فحص معدل الطلبات
    elseif (!Security::rateLimit('employee_login', 5, 60)) {
        $error = 'الكثير من المحاولات. يرجى الانتظار قليلاً قبل المحاولة مرة أخرى.';
    }
    else {
        try {
            $db = Database::getInstance()->getConnection();
            
            // البحث عن الموظف
            $query = "SELECT id, employee_id, username, password, full_name, email, department, position, is_active, last_login 
                      FROM employees WHERE username = ? OR employee_id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$username, $username]);
            
            if ($stmt->rowCount() > 0) {
                $employee = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // فحص حالة الحساب
                if (!$employee['is_active']) {
                    $error = 'حسابك غير مفعل. يرجى مراجعة الإدارة.';
                    Security::logFailedLogin($username);
                }
                // فحص كلمة المرور
                elseif (Security::verifyPassword($password, $employee['password'])) {
                    // تسجيل دخول ناجح
                    $_SESSION['employee_id'] = $employee['id'];
                    $_SESSION['employee_username'] = $employee['username'];
                    $_SESSION['employee_name'] = $employee['full_name'];
                    $_SESSION['employee_number'] = $employee['employee_id'];
                    $_SESSION['employee_email'] = $employee['email'];
                    $_SESSION['employee_department'] = $employee['department'];
                    $_SESSION['employee_position'] = $employee['position'];
                    $_SESSION['login_time'] = time();
                    
                    // تحديث آخر تسجيل دخول
                    $update_query = "UPDATE employees SET last_login = NOW() WHERE id = ?";
                    $update_stmt = $db->prepare($update_query);
                    $update_stmt->execute([$employee['id']]);
                    
                    // حفظ الجلسة للتذكر
                    if ($remember_me) {
                        $token = Security::generateToken(64);
                        $expires = date('Y-m-d H:i:s', strtotime('+7 days')); // أسبوع للموظفين
                        
                        // حفظ token في قاعدة البيانات
                        $session_query = "INSERT INTO user_sessions (user_type, user_id, session_token, ip_address, user_agent, expires_at) 
                                         VALUES ('employee', ?, ?, ?, ?, ?)";
                        $session_stmt = $db->prepare($session_query);
                        $session_stmt->execute([
                            $employee['id'], 
                            $token, 
                            Security::getClientIP(), 
                            $_SERVER['HTTP_USER_AGENT'] ?? '', 
                            $expires
                        ]);
                        
                        // تعيين cookie
                        setcookie('remember_employee', $token, strtotime('+7 days'), '/', '', true, true);
                    }
                    
                    // تسجيل النشاط
                    logActivity('employee', $employee['id'], 'login', 'تسجيل دخول ناجح للتطبيق');
                    
                    // إنشاء إشعار ترحيب
                    createNotification('employee', $employee['id'], 'مرحباً بك', 'تم تسجيل دخولك بنجاح', 'success');
                    
                    $success = 'تم تسجيل الدخول بنجاح. جاري التحويل...';
                    
                    // التحويل بعد ثانيتين
                    echo "<script>
                        setTimeout(function() {
                            window.location.href = 'employee_dashboard.php';
                        }, 2000);
                    </script>";
                    
                } else {
                    $error = 'كلمة المرور غير صحيحة';
                    Security::logFailedLogin($username);
                }
            } else {
                $error = 'اسم المستخدم أو رقم الموظف غير موجود';
                Security::logFailedLogin($username);
            }
            
        } catch (Exception $e) {
            error_log("Employee login error: " . $e->getMessage());
            $error = 'حدث خطأ في النظام. يرجى المحاولة لاحقاً.';
        }
    }
}

// فحص cookie التذكر
if (!isset($_SESSION['employee_id']) && isset($_COOKIE['remember_employee'])) {
    try {
        $token = $_COOKIE['remember_employee'];
        $db = Database::getInstance()->getConnection();
        
        $query = "SELECT s.*, e.id, e.username, e.full_name, e.email, e.employee_id, e.department, e.position, e.is_active 
                  FROM user_sessions s 
                  JOIN employees e ON s.user_id = e.id 
                  WHERE s.session_token = ? AND s.user_type = 'employee' 
                  AND s.is_active = 1 AND s.expires_at > NOW()";
        $stmt = $db->prepare($query);
        $stmt->execute([$token]);
        
        if ($stmt->rowCount() > 0) {
            $session_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($session_data['is_active']) {
                // استعادة الجلسة
                $_SESSION['employee_id'] = $session_data['id'];
                $_SESSION['employee_username'] = $session_data['username'];
                $_SESSION['employee_name'] = $session_data['full_name'];
                $_SESSION['employee_number'] = $session_data['employee_id'];
                $_SESSION['employee_email'] = $session_data['email'];
                $_SESSION['employee_department'] = $session_data['department'];
                $_SESSION['employee_position'] = $session_data['position'];
                $_SESSION['login_time'] = time();
                
                logActivity('employee', $session_data['id'], 'auto_login', 'تسجيل دخول تلقائي من cookie');
                redirect('employee_dashboard.php');
            }
        } else {
            // حذف cookie إذا كان غير صالح
            setcookie('remember_employee', '', time() - 3600, '/');
        }
    } catch (Exception $e) {
        error_log("Remember me error: " . $e->getMessage());
    }
}

$app_name = getSetting('company_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>تسجيل دخول الموظف - <?php echo htmlspecialchars($app_name); ?></title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="تسجيل دخول الموظفين لنظام بصمة الوجه">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#667eea">
    
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
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow-x: hidden;
        }
        
        /* تأثيرات الخلفية المتحركة */
        .floating-shapes {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: -1;
        }
        
        .shape {
            position: absolute;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            animation: float 15s infinite ease-in-out;
        }
        
        .shape:nth-child(1) {
            width: 80px;
            height: 80px;
            top: 20%;
            left: 10%;
            animation-delay: 0s;
        }
        
        .shape:nth-child(2) {
            width: 120px;
            height: 120px;
            top: 60%;
            right: 10%;
            animation-delay: 2s;
        }
        
        .shape:nth-child(3) {
            width: 60px;
            height: 60px;
            bottom: 20%;
            left: 20%;
            animation-delay: 4s;
        }
        
        @keyframes float {
            0%, 100% {
                transform: translateY(0px) rotate(0deg);
                opacity: 0.1;
            }
            50% {
                transform: translateY(-20px) rotate(180deg);
                opacity: 0.3;
            }
        }
        
        .login-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 30px;
            box-shadow: 0 30px 60px rgba(0,0,0,0.2);
            padding: 50px 30px;
            width: 100%;
            max-width: 420px;
            text-align: center;
            position: relative;
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #667eea, #764ba2, #56ab2f, #a8e6cf);
            background-size: 300% 100%;
            animation: gradient-shift 3s ease infinite;
            border-radius: 30px 30px 0 0;
        }
        
        @keyframes gradient-shift {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        
        .logo {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 30px;
            margin: 0 auto 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 48px;
            box-shadow: 0 20px 40px rgba(102,126,234,0.3);
            position: relative;
            animation: logo-pulse 3s infinite;
        }
        
        @keyframes logo-pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .logo::after {
            content: '';
            position: absolute;
            inset: -3px;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.3), transparent);
            border-radius: 33px;
            animation: rotate-border 4s linear infinite;
            z-index: -1;
        }
        
        @keyframes rotate-border {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        h1 {
            font-size: 32px;
            color: #2c3e50;
            margin-bottom: 12px;
            font-weight: 800;
            background: linear-gradient(135deg, #2c3e50, #3498db);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .subtitle {
            color: #7f8c8d;
            margin-bottom: 40px;
            font-size: 18px;
            line-height: 1.6;
            font-weight: 500;
        }
        
        .alert {
            padding: 16px 20px;
            border-radius: 15px;
            margin-bottom: 25px;
            font-weight: 500;
            animation: alert-slide 0.5s ease;
            position: relative;
            overflow: hidden;
        }
        
        @keyframes alert-slide {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .alert::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            animation: alert-shimmer 2s infinite;
        }
        
        @keyframes alert-shimmer {
            0% { left: -100%; }
            100% { left: 100%; }
        }
        
        .alert.success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert.error {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert.warning {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        
        .form-group {
            margin-bottom: 25px;
            text-align: right;
        }
        
        label {
            display: block;
            margin-bottom: 10px;
            color: #2c3e50;
            font-weight: 600;
            font-size: 16px;
        }
        
        .input-wrapper {
            position: relative;
        }
        
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 20px 55px 20px 20px;
            border: 2px solid #e1e5e9;
            border-radius: 18px;
            font-size: 16px;
            transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            background: #f8f9fa;
            color: #2c3e50;
            -webkit-appearance: none;
            appearance: none;
        }
        
        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(102,126,234,0.2);
        }
        
        .input-icon {
            position: absolute;
            right: 18px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 20px;
            transition: all 0.3s ease;
        }
        
        input:focus + .input-icon {
            color: #667eea;
            transform: translateY(-50%) scale(1.1);
        }
        
        .password-toggle {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #7f8c8d;
            cursor: pointer;
            font-size: 20px;
            transition: all 0.3s ease;
            padding: 5px;
            border-radius: 50%;
        }
        
        .password-toggle:hover {
            color: #667eea;
            background: rgba(102,126,234,0.1);
        }
        
        .remember-me {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            margin-bottom: 30px;
            gap: 12px;
            padding: 10px 0;
        }
        
        .remember-me input[type="checkbox"] {
            width: 20px;
            height: 20px;
            accent-color: #667eea;
            cursor: pointer;
        }
        
        .remember-me label {
            margin: 0;
            font-size: 15px;
            color: #6c757d;
            cursor: pointer;
            font-weight: 500;
        }
        
        .login-btn {
            width: 100%;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 18px;
            font-size: 18px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .login-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.6s ease;
        }
        
        .login-btn:hover::before {
            left: 100%;
        }
        
        .login-btn:hover {
            transform: translateY(-4px) scale(1.02);
            box-shadow: 0 20px 40px rgba(102,126,234,0.4);
        }
        
        .login-btn:active {
            transform: translateY(-2px) scale(0.98);
        }
        
        .login-btn:disabled {
            background: #bdc3c7;
            cursor: not-allowed;
            transform: none;
        }
        
        .login-btn.loading {
            pointer-events: none;
        }
        
        .login-btn.loading::after {
            content: '';
            position: absolute;
            right: 25px;
            top: 50%;
            transform: translateY(-50%);
            width: 22px;
            height: 22px;
            border: 3px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: btn-spin 1s linear infinite;
        }
        
        @keyframes btn-spin {
            to { transform: translateY(-50%) rotate(360deg); }
        }
        
        .quick-access {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .quick-btn {
            padding: 15px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border: 1px solid #dee2e6;
            border-radius: 15px;
            text-decoration: none;
            color: #495057;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .quick-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
            background: linear-gradient(135deg, #e9ecef, #dee2e6);
        }
        
        .network-status {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            border: 1px solid #ffd32a;
            border-radius: 15px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 14px;
            color: #856404;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .network-status.connected {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            border-color: #28a745;
            color: #155724;
        }
        
        .back-link {
            margin-top: 30px;
            padding-top: 25px;
            border-top: 1px solid rgba(0,0,0,0.1);
        }
        
        .back-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 15px;
            border-radius: 10px;
        }
        
        .back-link a:hover {
            color: #764ba2;
            background: rgba(102,126,234,0.1);
        }
        
        .features-preview {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            border: 1px solid #2196f3;
            border-radius: 15px;
            padding: 20px;
            margin-top: 25px;
            text-align: right;
        }
        
        .features-preview h4 {
            color: #1976d2;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .features-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        
        .features-list li {
            margin-bottom: 8px;
            padding: 5px 0;
            color: #424242;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }
        
        .features-list li::before {
            content: '✓';
            color: #4caf50;
            font-weight: bold;
            font-size: 16px;
        }
        
        /* تحسينات للهواتف الذكية */
        @media (max-width: 768px) {
            body {
                padding: 15px;
            }
            
            .login-container {
                padding: 40px 25px;
                border-radius: 25px;
                max-width: 100%;
            }
            
            .logo {
                width: 100px;
                height: 100px;
                font-size: 40px;
                border-radius: 25px;
            }
            
            h1 {
                font-size: 28px;
            }
            
            .subtitle {
                font-size: 16px;
            }
            
            input[type="text"],
            input[type="password"] {
                padding: 18px 50px 18px 18px;
                border-radius: 15px;
            }
            
            .login-btn {
                padding: 18px;
                border-radius: 15px;
            }
            
            .quick-access {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 480px) {
            .login-container {
                padding: 30px 20px;
                border-radius: 20px;
            }
            
            .logo {
                width: 80px;
                height: 80px;
                font-size: 32px;
                border-radius: 20px;
            }
            
            h1 {
                font-size: 24px;
            }
            
            input[type="text"],
            input[type="password"] {
                padding: 16px 45px 16px 16px;
            }
        }
        
        /* تحسينات لأجهزة iPhone */
        @supports (-webkit-appearance: none) {
            input[type="text"],
            input[type="password"] {
                -webkit-appearance: none;
                -webkit-border-radius: 18px;
            }
        }
        
        /* منع التكبير في iOS */
        @media screen and (-webkit-min-device-pixel-ratio: 0) {
            select,
            textarea,
            input[type="text"],
            input[type="password"],
            input[type="datetime"],
            input[type="datetime-local"],
            input[type="date"],
            input[type="month"],
            input[type="time"],
            input[type="week"],
            input[type="number"],
            input[type="email"],
            input[type="url"],
            input[type="search"],
            input[type="tel"],
            input[type="color"] {
                font-size: 16px;
            }
        }
    </style>
</head>
<body>
    <div class="floating-shapes">
        <div class="shape"></div>
        <div class="shape"></div>
        <div class="shape"></div>
    </div>
    
    <div class="login-container">
        <div class="logo">📱</div>
        <h1>مرحباً بك</h1>
        <p class="subtitle">
            سجل دخولك لتسجيل الحضور والانصراف<br>
            عبر بصمة الوجه الذكية
        </p>
        
        <!-- حالة الشبكة -->
        <div class="network-status" id="networkStatus">
            <span>📶</span>
            <span id="networkText">جاري فحص الاتصال بالشبكة...</span>
        </div>
        
        <?php if ($success): ?>
            <div class="alert success">
                <strong>✅ نجح!</strong> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert error">
                <strong>❌ خطأ!</strong> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($lockout_message): ?>
            <div class="alert warning">
                <strong>⚠️ تحذير!</strong> <?php echo htmlspecialchars($lockout_message); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" id="loginForm" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            
            <div class="form-group">
                <label for="username">اسم المستخدم أو رقم الموظف</label>
                <div class="input-wrapper">
                    <input type="text" id="username" name="username" required 
                           autocomplete="username" spellcheck="false"
                           placeholder="أدخل اسم المستخدم أو رقم الموظف"
                           value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                    <span class="input-icon">👤</span>
                </div>
            </div>
            
            <div class="form-group">
                <label for="password">كلمة المرور</label>
                <div class="input-wrapper">
                    <input type="password" id="password" name="password" required 
                           autocomplete="current-password" placeholder="أدخل كلمة المرور">
                    <span class="input-icon">🔐</span>
                    <button type="button" class="password-toggle" id="togglePassword" 
                            aria-label="إظهار/إخفاء كلمة المرور">
                        👁️
                    </button>
                </div>
            </div>
            
            <div class="remember-me">
                <input type="checkbox" id="remember_me" name="remember_me">
                <label for="remember_me">تذكرني لمدة أسبوع</label>
            </div>
            
            <button type="submit" class="login-btn" id="loginBtn">
                🔐 تسجيل الدخول
            </button>
        </form>
        
        <div class="quick-access">
            <a href="#" class="quick-btn" onclick="showDemo()">
                🎥 مشاهدة الشرح
            </a>
            <a href="#" class="quick-btn" onclick="contactSupport()">
                📞 الدعم الفني
            </a>
        </div>
        
        <div class="features-preview">
            <h4>📱 مميزات التطبيق للموظفين</h4>
            <ul class="features-list">
                <li>تسجيل حضور سريع وآمن بالوجه</li>
                <li>عرض سجل الحضور الشخصي</li>
                <li>تتبع ساعات العمل اليومية</li>
                <li>إشعارات فورية للعمليات</li>
                <li>واجهة سهلة ومتجاوبة</li>
            </ul>
        </div>
        
        <div class="back-link">
            <a href="index.php">
                ← العودة إلى الصفحة الرئيسية
            </a>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            initializeForm();
            checkNetworkStatus();
            checkCameraPermissions();
        });
        
        function initializeForm() {
            const form = document.getElementById('loginForm');
            const loginBtn = document.getElementById('loginBtn');
            const togglePassword = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('password');
            const usernameInput = document.getElementById('username');
            
            // إظهار/إخفاء كلمة المرور
            togglePassword.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                this.textContent = type === 'password' ? '👁️' : '🙈';
            });
            
            // معالجة إرسال النموذج
            let isSubmitting = false;
            form.addEventListener('submit', function(e) {
                if (isSubmitting) {
                    e.preventDefault();
                    return;
                }
                
                const username = usernameInput.value.trim();
                const password = passwordInput.value;
                
                if (!username || !password) {
                    e.preventDefault();
                    showAlert('يرجى إدخال جميع البيانات المطلوبة', 'error');
                    return;
                }
                
                if (username.length < 2) {
                    e.preventDefault();
                    showAlert('اسم المستخدم قصير جداً', 'error');
                    usernameInput.focus();
                    return;
                }
                
                if (password.length < 3) {
                    e.preventDefault();
                    showAlert('كلمة المرور قصيرة جداً', 'error');
                    passwordInput.focus();
                    return;
                }
                
                // إظهار حالة التحميل
                isSubmitting = true;
                loginBtn.classList.add('loading');
                loginBtn.disabled = true;
                loginBtn.innerHTML = 'جاري التحقق...';
            });
            
            // التركيز التلقائي
            if (usernameInput.value) {
                passwordInput.focus();
            } else {
                usernameInput.focus();
            }
            
            // تحسين تجربة المستخدم - auto-complete للهاتف
            usernameInput.addEventListener('input', function() {
                // إزالة المسافات من بداية ونهاية النص
                this.value = this.value.trim();
            });
            
            // منع التكبير في iOS
            if (/iPad|iPhone|iPod/.test(navigator.userAgent)) {
                const viewport = document.querySelector('meta[name=viewport]');
                viewport.setAttribute('content', 
                    'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no');
            }
            
            // إضافة تأثيرات بصرية
            const inputs = document.querySelectorAll('input[type="text"], input[type="password"]');
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.style.transform = 'scale(1.02)';
                });
                
                input.addEventListener('blur', function() {
                    this.parentElement.style.transform = 'scale(1)';
                });
            });
        }
        
        // فحص حالة الشبكة
        async function checkNetworkStatus() {
            const networkStatus = document.getElementById('networkStatus');
            const networkText = document.getElementById('networkText');
            
            try {
                const response = await fetch('ping.php?check=network', { 
                    method: 'GET',
                    cache: 'no-cache'
                });
                
                if (response.ok) {
                    const data = await response.json();
                    networkStatus.className = 'network-status connected';
                    networkText.textContent = 'متصل بشبكة الشركة ✓';
                } else {
                    throw new Error('Network error');
                }
            } catch (error) {
                networkStatus.className = 'network-status';
                networkText.textContent = 'تحقق من الاتصال بشبكة الشركة';
            }
            
            // إعادة فحص كل 30 ثانية
            setTimeout(checkNetworkStatus, 30000);
        }
        
        // فحص صلاحيات الكاميرا
        async function checkCameraPermissions() {
            try {
                if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                    const permissions = await navigator.permissions.query({name: 'camera'});
                    
                    if (permissions.state === 'denied') {
                        showAlert('صلاحية الكاميرا مرفوضة. ستحتاج لتفعيلها لتسجيل الحضور.', 'warning', 8000);
                    }
                }
            } catch (error) {
                console.log('Camera permission check failed:', error);
            }
        }
        
        // وظائف مساعدة
        function showDemo() {
            showAlert('سيتم إضافة فيديو تعليمي قريباً', 'info');
        }
        
        function contactSupport() {
            const phone = '<?php echo getSetting("support_phone", "+966501234567"); ?>';
            const message = 'مرحبا، أحتاج مساعدة في تسجيل الدخول لنظام بصمة الوجه';
            const whatsappUrl = `https://wa.me/${phone.replace(/[^0-9]/g, '')}?text=${encodeURIComponent(message)}`;
            
            if (confirm('هل تريد التواصل مع الدعم الفني عبر الواتساب؟')) {
                window.open(whatsappUrl, '_blank');
            }
        }
        
        // وظيفة إظهار التنبيهات
        function showAlert(message, type = 'info', duration = 5000) {
            const existingAlerts = document.querySelectorAll('.alert.dynamic');
            existingAlerts.forEach(alert => alert.remove());
            
            const alert = document.createElement('div');
            alert.className = `alert ${type} dynamic`;
            alert.innerHTML = `<strong>${getAlertIcon(type)}</strong> ${message}`;
            
            const form = document.getElementById('loginForm');
            form.parentNode.insertBefore(alert, form);
            
            if (duration > 0) {
                setTimeout(() => {
                    alert.remove();
                }, duration);
            }
        }
        
        function getAlertIcon(type) {
            const icons = {
                'success': '✅ نجح!',
                'error': '❌ خطأ!',
                'warning': '⚠️ تحذير!',
                'info': 'ℹ️ معلومة!'
            };
            return icons[type] || icons.info;
        }
        
        // إدارة التحديث التلقائي للصفحة
        let pageVisibilityTimer;
        
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                // الصفحة مخفية - توقف عن التحديثات
                clearTimeout(pageVisibilityTimer);
            } else {
                // الصفحة ظاهرة - استمر في التحديثات
                pageVisibilityTimer = setTimeout(checkNetworkStatus, 1000);
            }
        });
        
        // تحسين الأداء - منع إعادة التحميل غير الضروري
        window.addEventListener('beforeunload', function() {
            // حفظ حالة النموذج في localStorage
            const formData = {
                username: document.getElementById('username').value,
                remember_me: document.getElementById('remember_me').checked
            };
            localStorage.setItem('loginFormData', JSON.stringify(formData));
        });
        
        // استعادة بيانات النموذج
        window.addEventListener('load', function() {
            try {
                const savedData = localStorage.getItem('loginFormData');
                if (savedData) {
                    const data = JSON.parse(savedData);
                    if (data.username) {
                        document.getElementById('username').value = data.username;
                    }
                    if (data.remember_me) {
                        document.getElementById('remember_me').checked = data.remember_me;
                    }
                    localStorage.removeItem('loginFormData');
                }
            } catch (error) {
                console.log('Could not restore form data:', error);
            }
        });
        
        // معالجة الأخطاء العامة
        window.addEventListener('error', function(e) {
            console.error('خطأ في الصفحة:', e.error);
        });
        
        // منع زوم الصفحة بالقرص
        document.addEventListener('touchmove', function(e) {
            if (e.scale !== 1) {
                e.preventDefault();
            }
        }, { passive: false });
    </script>
</body>
</html>