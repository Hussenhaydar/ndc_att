<?php
require_once 'config.php';

// إعادة توجيه إذا كان مسجل دخول
if (isset($_SESSION['admin_id'])) {
    redirect('admin_dashboard.php');
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
    elseif (!Security::rateLimit('admin_login', 5, 60)) {
        $error = 'الكثير من المحاولات. يرجى الانتظار قليلاً قبل المحاولة مرة أخرى.';
    }
    else {
        try {
            $db = Database::getInstance()->getConnection();
            
            // البحث عن المدير
            $query = "SELECT id, username, password, full_name, email, is_active, last_login 
                      FROM admins WHERE username = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$username]);
            
            if ($stmt->rowCount() > 0) {
                $admin = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // فحص حالة الحساب
                if (!$admin['is_active']) {
                    $error = 'حسابك غير مفعل. يرجى مراجعة مدير النظام.';
                    Security::logFailedLogin($username);
                }
                // فحص كلمة المرور
                elseif (Security::verifyPassword($password, $admin['password'])) {
                    // تسجيل دخول ناجح
                    $_SESSION['admin_id'] = $admin['id'];
                    $_SESSION['admin_username'] = $admin['username'];
                    $_SESSION['admin_name'] = $admin['full_name'];
                    $_SESSION['admin_email'] = $admin['email'];
                    $_SESSION['login_time'] = time();
                    
                    // تحديث آخر تسجيل دخول
                    $update_query = "UPDATE admins SET last_login = NOW() WHERE id = ?";
                    $update_stmt = $db->prepare($update_query);
                    $update_stmt->execute([$admin['id']]);
                    
                    // حفظ الجلسة للتذكر
                    if ($remember_me) {
                        $token = Security::generateToken(64);
                        $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
                        
                        // حفظ token في قاعدة البيانات
                        $session_query = "INSERT INTO user_sessions (user_type, user_id, session_token, ip_address, user_agent, expires_at) 
                                         VALUES ('admin', ?, ?, ?, ?, ?)";
                        $session_stmt = $db->prepare($session_query);
                        $session_stmt->execute([
                            $admin['id'], 
                            $token, 
                            Security::getClientIP(), 
                            $_SERVER['HTTP_USER_AGENT'] ?? '', 
                            $expires
                        ]);
                        
                        // تعيين cookie
                        setcookie('remember_admin', $token, strtotime('+30 days'), '/', '', true, true);
                    }
                    
                    // تسجيل النشاط
                    logActivity('admin', $admin['id'], 'login', 'تسجيل دخول ناجح للوحة الإدارة');
                    
                    // إنشاء إشعار ترحيب
                    createNotification('admin', $admin['id'], 'مرحباً بك', 'تم تسجيل دخولك بنجاح', 'success');
                    
                    $success = 'تم تسجيل الدخول بنجاح. جاري التحويل...';
                    
                    // التحويل بعد ثانيتين
                    echo "<script>
                        setTimeout(function() {
                            window.location.href = 'admin_dashboard.php';
                        }, 2000);
                    </script>";
                    
                } else {
                    $error = 'كلمة المرور غير صحيحة';
                    Security::logFailedLogin($username);
                }
            } else {
                $error = 'اسم المستخدم غير موجود';
                Security::logFailedLogin($username);
            }
            
        } catch (Exception $e) {
            error_log("Admin login error: " . $e->getMessage());
            $error = 'حدث خطأ في النظام. يرجى المحاولة لاحقاً.';
        }
    }
}

// فحص cookie التذكر
if (!isset($_SESSION['admin_id']) && isset($_COOKIE['remember_admin'])) {
    try {
        $token = $_COOKIE['remember_admin'];
        $db = Database::getInstance()->getConnection();
        
        $query = "SELECT s.*, a.id, a.username, a.full_name, a.email, a.is_active 
                  FROM user_sessions s 
                  JOIN admins a ON s.user_id = a.id 
                  WHERE s.session_token = ? AND s.user_type = 'admin' 
                  AND s.is_active = 1 AND s.expires_at > NOW()";
        $stmt = $db->prepare($query);
        $stmt->execute([$token]);
        
        if ($stmt->rowCount() > 0) {
            $session_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($session_data['is_active']) {
                // استعادة الجلسة
                $_SESSION['admin_id'] = $session_data['id'];
                $_SESSION['admin_username'] = $session_data['username'];
                $_SESSION['admin_name'] = $session_data['full_name'];
                $_SESSION['admin_email'] = $session_data['email'];
                $_SESSION['login_time'] = time();
                
                logActivity('admin', $session_data['id'], 'auto_login', 'تسجيل دخول تلقائي من cookie');
                redirect('admin_dashboard.php');
            }
        } else {
            // حذف cookie إذا كان غير صالح
            setcookie('remember_admin', '', time() - 3600, '/');
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل دخول الإدارة - <?php echo htmlspecialchars($app_name); ?></title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="لوحة تحكم الإدارة لنظام بصمة الوجه">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
        }
        
        /* خلفية متحركة */
        .animated-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            background: linear-gradient(45deg, transparent 30%, rgba(255,255,255,0.1) 50%, transparent 70%);
            background-size: 200% 200%;
            animation: shimmer 4s ease-in-out infinite;
        }
        
        @keyframes shimmer {
            0%, 100% { background-position: 0% 0%; }
            50% { background-position: 100% 100%; }
        }
        
        .login-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 25px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.2);
            padding: 50px 40px;
            width: 100%;
            max-width: 480px;
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
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 25px 25px 0 0;
        }
        
        .logo {
            width: 90px;
            height: 90px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 22px;
            margin: 0 auto 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 36px;
            box-shadow: 0 15px 35px rgba(102,126,234,0.3);
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        h1 {
            color: #2c3e50;
            margin-bottom: 10px;
            font-size: 32px;
            font-weight: 700;
        }
        
        .subtitle {
            color: #7f8c8d;
            margin-bottom: 40px;
            font-size: 16px;
            line-height: 1.5;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-weight: 500;
            animation: slideIn 0.5s ease;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
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
            margin-bottom: 8px;
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
            padding: 18px 50px 18px 20px;
            border: 2px solid #e1e5e9;
            border-radius: 15px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #f8f9fa;
            color: #2c3e50;
        }
        
        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102,126,234,0.15);
        }
        
        .input-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #7f8c8d;
            font-size: 18px;
            transition: color 0.3s ease;
        }
        
        input:focus + .input-icon {
            color: #667eea;
        }
        
        .password-toggle {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #7f8c8d;
            cursor: pointer;
            font-size: 18px;
            transition: color 0.3s ease;
        }
        
        .password-toggle:hover {
            color: #667eea;
        }
        
        .remember-me {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            margin-bottom: 30px;
            gap: 10px;
        }
        
        .remember-me input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #667eea;
        }
        
        .remember-me label {
            margin: 0;
            font-size: 14px;
            color: #6c757d;
            cursor: pointer;
        }
        
        .login-btn {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 15px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .login-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s ease;
        }
        
        .login-btn:hover::before {
            left: 100%;
        }
        
        .login-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 35px rgba(102,126,234,0.4);
        }
        
        .login-btn:active {
            transform: translateY(-1px);
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
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: translateY(-50%) rotate(360deg); }
        }
        
        .back-link {
            margin-top: 30px;
            padding-top: 25px;
            border-top: 1px solid #e1e5e9;
        }
        
        .back-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .back-link a:hover {
            color: #764ba2;
        }
        
        .security-info {
            background: linear-gradient(135deg, #e8f4fd, #d1ecf1);
            border: 1px solid #bee5eb;
            border-radius: 12px;
            padding: 20px;
            margin-top: 30px;
            text-align: right;
        }
        
        .security-info h4 {
            color: #0c5460;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .security-info p {
            color: #055160;
            font-size: 14px;
            line-height: 1.5;
            margin: 0;
        }
        
        /* تحسينات للهواتف */
        @media (max-width: 768px) {
            body {
                padding: 15px;
            }
            
            .login-container {
                padding: 40px 30px;
                border-radius: 20px;
            }
            
            .logo {
                width: 70px;
                height: 70px;
                font-size: 28px;
                border-radius: 18px;
            }
            
            h1 {
                font-size: 26px;
            }
            
            input[type="text"],
            input[type="password"] {
                padding: 16px 45px 16px 18px;
                font-size: 16px; /* منع التكبير في iOS */
            }
            
            .login-btn {
                padding: 16px;
                font-size: 17px;
            }
        }
        
        @media (max-width: 480px) {
            .login-container {
                padding: 30px 25px;
            }
            
            .logo {
                width: 60px;
                height: 60px;
                font-size: 24px;
            }
            
            h1 {
                font-size: 22px;
            }
        }
        
        /* منع التكبير في iOS */
        input[type="text"],
        input[type="password"] {
            -webkit-appearance: none;
            appearance: none;
        }
        
        /* تحسين إمكانية الوصول */
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
    </style>
</head>
<body>
    <div class="animated-bg"></div>
    
    <div class="login-container">
        <div class="logo">🔒</div>
        <h1>لوحة الإدارة</h1>
        <p class="subtitle">نظام إدارة بصمة الوجه<br>تسجيل دخول آمن للإدارة</p>
        
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
                <label for="username">اسم المستخدم</label>
                <div class="input-wrapper">
                    <input type="text" id="username" name="username" required 
                           autocomplete="username" spellcheck="false"
                           value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                    <span class="input-icon">👤</span>
                </div>
            </div>
            
            <div class="form-group">
                <label for="password">كلمة المرور</label>
                <div class="input-wrapper">
                    <input type="password" id="password" name="password" required 
                           autocomplete="current-password">
                    <span class="input-icon">🔐</span>
                    <button type="button" class="password-toggle" id="togglePassword" aria-label="إظهار/إخفاء كلمة المرور">
                        👁️
                    </button>
                </div>
            </div>
            
            <div class="remember-me">
                <input type="checkbox" id="remember_me" name="remember_me">
                <label for="remember_me">تذكرني لمدة 30 يوماً</label>
            </div>
            
            <button type="submit" class="login-btn" id="loginBtn">
                🔐 تسجيل الدخول
            </button>
        </form>
        
        <div class="security-info">
            <h4>🔒 معلومات الأمان</h4>
            <p>
                يتم تسجيل جميع محاولات تسجيل الدخول. استخدم بيانات اعتماد صحيحة فقط.
                في حالة نسيان كلمة المرور، يرجى مراجعة مدير النظام.
            </p>
        </div>
        
        <div class="back-link">
            <a href="index.php">
                ← العودة إلى الصفحة الرئيسية
            </a>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
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
            form.addEventListener('submit', function(e) {
                const username = usernameInput.value.trim();
                const password = passwordInput.value;
                
                if (!username || !password) {
                    e.preventDefault();
                    showAlert('يرجى إدخال جميع البيانات المطلوبة', 'error');
                    return;
                }
                
                if (username.length < 3) {
                    e.preventDefault();
                    showAlert('اسم المستخدم قصير جداً', 'error');
                    usernameInput.focus();
                    return;
                }
                
                if (password.length < 4) {
                    e.preventDefault();
                    showAlert('كلمة المرور قصيرة جداً', 'error');
                    passwordInput.focus();
                    return;
                }
                
                // إظهار حالة التحميل
                loginBtn.classList.add('loading');
                loginBtn.disabled = true;
                loginBtn.innerHTML = 'جاري التحقق... <span class="sr-only">يرجى الانتظار</span>';
            });
            
            // التركيز التلقائي
            if (usernameInput.value) {
                passwordInput.focus();
            } else {
                usernameInput.focus();
            }
            
            // منع إرسال النموذج عدة مرات
            let isSubmitting = false;
            form.addEventListener('submit', function(e) {
                if (isSubmitting) {
                    e.preventDefault();
                    return;
                }
                isSubmitting = true;
            });
            
            // تنظيف حالة التحميل عند حدوث خطأ
            window.addEventListener('pageshow', function() {
                loginBtn.classList.remove('loading');
                loginBtn.disabled = false;
                loginBtn.innerHTML = '🔐 تسجيل الدخول';
                isSubmitting = false;
            });
            
            // منع التكبير في iOS
            if (/iPad|iPhone|iPod/.test(navigator.userAgent)) {
                const viewport = document.querySelector('meta[name=viewport]');
                viewport.setAttribute('content', 
                    'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no');
            }
            
            // تحسين تجربة المستخدم - تذكير بـ Caps Lock
            passwordInput.addEventListener('keydown', function(e) {
                if (e.getModifierState && e.getModifierState('CapsLock')) {
                    showAlert('تحذير: Caps Lock مفعل', 'warning', 3000);
                }
            });
            
            // إضافة تأثيرات بصرية للتفاعل
            const inputs = document.querySelectorAll('input[type="text"], input[type="password"]');
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.style.transform = 'scale(1.02)';
                });
                
                input.addEventListener('blur', function() {
                    this.parentElement.style.transform = 'scale(1)';
                });
            });
        });
        
        // وظيفة إظهار التنبيهات
        function showAlert(message, type = 'info', duration = 5000) {
            // إزالة التنبيهات السابقة
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
        
        // معالجة الأخطاء العامة
        window.addEventListener('error', function(e) {
            console.error('خطأ في الصفحة:', e.error);
        });
        
        // تتبع محاولات تسجيل الدخول للإحصائيات
        if (typeof gtag !== 'undefined') {
            gtag('event', 'admin_login_page_view', {
                'event_category': 'Authentication',
                'event_label': 'Admin Login Page'
            });
        }
    </script>
</body>
</html>