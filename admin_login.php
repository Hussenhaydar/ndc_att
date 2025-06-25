<?php
require_once 'config.php';

// إعادة توجيه إذا كان مسجل دخول - مع فحص إضافي لمنع التكرار
if (isset($_SESSION['admin_id']) && !isset($_GET['force_login'])) {
    // فحص إضافي للتأكد من صحة الجلسة
    if (!empty($_SESSION['admin_id']) && is_numeric($_SESSION['admin_id'])) {
        header("Location: admin_dashboard.php");
        exit(); // استخدام exit بدلاً من redirect لتجنب المشاكل
    }
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
                    
                    // إعادة تشغيل الجلسة لضمان النظافة
                    session_regenerate_id(true);
                    
                    // تسجيل دخول ناجح
                    $_SESSION['admin_id'] = $admin['id'];
                    $_SESSION['admin_username'] = $admin['username'];
                    $_SESSION['admin_name'] = $admin['full_name'];
                    $_SESSION['admin_email'] = $admin['email'];
                    $_SESSION['login_time'] = time();
                    $_SESSION['last_activity'] = time();
                    $_SESSION['user_ip'] = Security::getClientIP();
                    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
                    
                    // تحديث آخر تسجيل دخول
                    try {
                        $update_query = "UPDATE admins SET last_login = NOW() WHERE id = ?";
                        $update_stmt = $db->prepare($update_query);
                        $update_stmt->execute([$admin['id']]);
                    } catch (Exception $e) {
                        error_log("Failed to update last login: " . $e->getMessage());
                    }
                    
                    // حفظ الجلسة للتذكر
                    if ($remember_me) {
                        try {
                            $token = Security::generateToken(64);
                            $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
                            
                            // التحقق من وجود جدول user_sessions
                            $check_table = $db->query("SHOW TABLES LIKE 'user_sessions'");
                            if ($check_table->rowCount() > 0) {
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
                                
                                setcookie('remember_admin', $token, strtotime('+30 days'), '/', '', isset($_SERVER['HTTPS']), true);
                            }
                        } catch (Exception $e) {
                            error_log("Remember me functionality error: " . $e->getMessage());
                        }
                    }
                    
                    // تسجيل النشاط
                    logActivity('admin', $admin['id'], 'login', 'تسجيل دخول ناجح للوحة الإدارة');
                    
                    // إنشاء إشعار ترحيب
                    createNotification('admin', $admin['id'], 'مرحباً بك', 'تم تسجيل دخولك بنجاح', 'success');
                    
                    // التحويل المباشر بدون JavaScript
                    header("Location: admin_dashboard.php");
                    exit();
                    
                } else {
                    $error = 'كلمة المرور غير صحيحة';
                    Security::logFailedLogin($username);
                }
            } else {
                $error = 'اسم المستخدم غير موجود';
                Security::logFailedLogin($username);
            }
            
        } catch (PDOException $e) {
            error_log("Database error in admin login: " . $e->getMessage());
            $error = 'حدث خطأ في الاتصال بقاعدة البيانات. يرجى التأكد من الإعدادات.';
        } catch (Exception $e) {
            error_log("Admin login error: " . $e->getMessage());
            $error = 'حدث خطأ في النظام. يرجى المحاولة لاحقاً.';
        }
    }
}

// فحص cookie التذكر - مع فحص إضافي لمنع التكرار
if (!isset($_SESSION['admin_id']) && isset($_COOKIE['remember_admin']) && !isset($_GET['no_auto_login'])) {
    try {
        $token = $_COOKIE['remember_admin'];
        $db = Database::getInstance()->getConnection();
        
        $check_table = $db->query("SHOW TABLES LIKE 'user_sessions'");
        if ($check_table->rowCount() > 0) {
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
                    session_regenerate_id(true);
                    $_SESSION['admin_id'] = $session_data['id'];
                    $_SESSION['admin_username'] = $session_data['username'];
                    $_SESSION['admin_name'] = $session_data['full_name'];
                    $_SESSION['admin_email'] = $session_data['email'];
                    $_SESSION['login_time'] = time();
                    $_SESSION['last_activity'] = time();
                    $_SESSION['user_ip'] = Security::getClientIP();
                    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
                    
                    logActivity('admin', $session_data['id'], 'auto_login', 'تسجيل دخول تلقائي من cookie');
                    header("Location: admin_dashboard.php");
                    exit();
                }
            } else {
                // حذف cookie إذا كان غير صالح
                setcookie('remember_admin', '', time() - 3600, '/');
            }
        }
    } catch (Exception $e) {
        error_log("Remember me error: " . $e->getMessage());
        setcookie('remember_admin', '', time() - 3600, '/');
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
        
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 18px 20px;
            border: 2px solid #e1e5e9;
            border-radius: 15px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #f8f9fa;
            color: #2c3e50;
        }
        
        input:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 8px 25px rgba(102,126,234,0.15);
        }
        
        .remember-me {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            margin-bottom: 30px;
            gap: 10px;
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
        }
        
        .login-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 35px rgba(102,126,234,0.4);
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
        }
        
        /* إضافة زر التجربة */
        .test-credentials {
            background: #e3f2fd;
            border: 1px solid #2196f3;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .test-btn {
            background: #2196f3;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">🔒</div>
        <h1>لوحة الإدارة</h1>
        <p class="subtitle">نظام إدارة بصمة الوجه<br>تسجيل دخول آمن للإدارة</p>
        
        <!-- بيانات التجربة -->
        <div class="test-credentials">
            <strong>🔧 بيانات تجريبية:</strong><br>
            المستخدم: admin | كلمة المرور: admin123
            <button type="button" class="test-btn" onclick="fillTestData()">ملء تلقائي</button>
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
                <label for="username">اسم المستخدم</label>
                <input type="text" id="username" name="username" required 
                       autocomplete="username" spellcheck="false"
                       value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
            </div>
            
            <div class="form-group">
                <label for="password">كلمة المرور</label>
                <input type="password" id="password" name="password" required 
                       autocomplete="current-password">
            </div>
            
            <div class="remember-me">
                <input type="checkbox" id="remember_me" name="remember_me">
                <label for="remember_me">تذكرني لمدة 30 يوماً</label>
            </div>
            
            <button type="submit" class="login-btn" id="loginBtn">
                🔐 تسجيل الدخول
            </button>
        </form>
        
        <div class="back-link">
            <a href="index.php">← العودة إلى الصفحة الرئيسية</a>
        </div>
    </div>

    <script>
        function fillTestData() {
            document.getElementById('username').value = 'admin';
            document.getElementById('password').value = 'admin123';
        }
        
        // منع إرسال النموذج عدة مرات
        document.getElementById('loginForm').addEventListener('submit', function() {
            const btn = document.getElementById('loginBtn');
            btn.disabled = true;
            btn.textContent = 'جاري تسجيل الدخول...';
        });
    </script>
</body>
</html>