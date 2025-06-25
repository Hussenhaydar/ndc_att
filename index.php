<?php
require_once 'config.php';

// إعادة توجيه المستخدمين المسجلين دخولهم
if (isset($_SESSION['admin_id'])) {
    redirect('admin_dashboard.php');
} elseif (isset($_SESSION['employee_id'])) {
    redirect('employee_dashboard.php');
}

$app_name = getSetting('company_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($app_name); ?> - نظام بصمة الوجه</title>
    <meta name="description" content="نظام متطور لإدارة الحضور والانصراف باستخدام تقنية التعرف على الوجه">
    <meta name="keywords" content="بصمة الوجه, حضور, انصراف, إدارة الموظفين">
    <meta name="author" content="<?php echo htmlspecialchars($app_name); ?>">
    
    <!-- منع التخزين المؤقت للأمان -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    
    <!-- أيقونات الموقع -->
    <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
    <link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }
        
        body {
            font-family: 'Segoe UI', 'Tahoma', 'Geneva', 'Verdana', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            overflow-x: hidden;
        }
        
        .animated-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            opacity: 0.1;
        }
        
        .animated-bg::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 200%;
            height: 200%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="2" fill="white" opacity="0.5"><animate attributeName="r" values="2;10;2" dur="3s" repeatCount="indefinite"/></circle><circle cx="20" cy="30" r="1" fill="white" opacity="0.3"><animate attributeName="r" values="1;5;1" dur="4s" repeatCount="indefinite"/></circle><circle cx="80" cy="70" r="1.5" fill="white" opacity="0.4"><animate attributeName="r" values="1.5;7;1.5" dur="5s" repeatCount="indefinite"/></circle></svg>') repeat;
            animation: float 20s infinite linear;
        }
        
        @keyframes float {
            0% { transform: translateY(0px) translateX(0px); }
            50% { transform: translateY(-20px) translateX(-10px); }
            100% { transform: translateY(0px) translateX(0px); }
        }
        
        .container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 30px;
            box-shadow: 0 30px 60px rgba(0,0,0,0.2);
            padding: 60px 40px;
            text-align: center;
            max-width: 600px;
            width: 100%;
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2, #667eea);
            background-size: 200% 100%;
            animation: shimmer 3s infinite;
        }
        
        @keyframes shimmer {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        
        .logo {
            width: 140px;
            height: 140px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 35px;
            margin: 0 auto 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 56px;
            box-shadow: 0 20px 40px rgba(102,126,234,0.4);
            position: relative;
            animation: pulse 3s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .logo::after {
            content: '';
            position: absolute;
            inset: -5px;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.3), transparent);
            border-radius: 40px;
            animation: rotate 4s linear infinite;
            z-index: -1;
        }
        
        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        h1 {
            font-size: 36px;
            color: #2c3e50;
            margin-bottom: 15px;
            font-weight: 800;
            background: linear-gradient(135deg, #2c3e50, #3498db);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .subtitle {
            color: #7f8c8d;
            font-size: 20px;
            margin-bottom: 50px;
            line-height: 1.6;
            font-weight: 500;
        }
        
        .login-options {
            display: flex;
            flex-direction: column;
            gap: 25px;
            margin-bottom: 40px;
        }
        
        .login-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            padding: 22px 40px;
            border-radius: 20px;
            font-size: 20px;
            font-weight: 700;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            position: relative;
            overflow: hidden;
            border: none;
            cursor: pointer;
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
        
        .login-btn.employee {
            background: linear-gradient(135deg, #56ab2f 0%, #a8e6cf 100%);
        }
        
        .login-btn:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 20px 40px rgba(102,126,234,0.4);
        }
        
        .login-btn.employee:hover {
            box-shadow: 0 20px 40px rgba(86,171,47,0.4);
        }
        
        .login-btn:active {
            transform: translateY(-2px) scale(0.98);
        }
        
        .btn-icon {
            font-size: 24px;
            transition: transform 0.3s ease;
        }
        
        .login-btn:hover .btn-icon {
            transform: scale(1.2) rotate(5deg);
        }
        
        .features {
            margin-top: 40px;
            padding-top: 30px;
            border-top: 2px solid #ecf0f1;
        }
        
        .features-title {
            font-size: 22px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 25px;
        }
        
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .feature-item {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 15px;
            padding: 20px;
            transition: all 0.3s ease;
            border: 1px solid #dee2e6;
        }
        
        .feature-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .feature-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 15px;
            margin: 0 auto 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }
        
        .feature-title {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 8px;
            font-size: 16px;
        }
        
        .feature-desc {
            color: #6c757d;
            font-size: 14px;
            line-height: 1.5;
        }
        
        .stats {
            display: flex;
            justify-content: space-around;
            margin-top: 30px;
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 20px;
            border: 1px solid #dee2e6;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: 800;
            color: #667eea;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 12px;
            color: #6c757d;
            font-weight: 500;
        }
        
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
            color: #6c757d;
            font-size: 14px;
        }
        
        .system-info {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border: 1px solid #2196f3;
            border-radius: 15px;
            padding: 20px;
            margin-top: 30px;
            text-align: right;
        }
        
        .system-info h3 {
            color: #1976d2;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .system-info ul {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        
        .system-info li {
            margin-bottom: 8px;
            padding: 5px 0;
            color: #424242;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .system-info li::before {
            content: '✓';
            color: #4caf50;
            font-weight: bold;
        }
        
        /* تحسينات للهواتف الذكية */
        @media (max-width: 768px) {
            body {
                padding: 15px;
            }
            
            .container {
                padding: 40px 25px;
                border-radius: 20px;
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
            
            .login-btn {
                padding: 18px 30px;
                font-size: 18px;
            }
            
            .features-grid {
                grid-template-columns: 1fr;
            }
            
            .stats {
                flex-direction: column;
                gap: 15px;
            }
        }
        
        /* تحسينات للشاشات الصغيرة جداً */
        @media (max-width: 480px) {
            .container {
                padding: 30px 20px;
            }
            
            .logo {
                width: 80px;
                height: 80px;
                font-size: 32px;
            }
            
            h1 {
                font-size: 24px;
            }
            
            .login-btn {
                padding: 16px 25px;
                font-size: 16px;
            }
        }
        
        /* تأثيرات تفاعلية إضافية */
        .ripple {
            position: relative;
            overflow: hidden;
        }
        
        .ripple::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255,255,255,0.3);
            transform: translate(-50%, -50%);
            transition: width 0.3s, height 0.3s;
        }
        
        .ripple:active::before {
            width: 300px;
            height: 300px;
        }
        
        /* تحسينات إضافية للأداء */
        .login-btn,
        .feature-item,
        .logo {
            will-change: transform;
        }
        
        /* طباعة */
        @media print {
            body {
                background: white;
            }
            
            .container {
                box-shadow: none;
                background: white;
            }
            
            .login-options,
            .features {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="animated-bg"></div>
    
    <div class="container">
        <div class="logo">
            🏢
        </div>
        
        <h1><?php echo htmlspecialchars($app_name); ?></h1>
        <p class="subtitle">
            نظام متطور لإدارة الحضور والانصراف<br>
            باستخدام تقنية التعرف على الوجه الذكية
        </p>
        
        <div class="login-options">
            <a href="employee_login.php" class="login-btn employee ripple">
                <span class="btn-icon">📱</span>
                <span>تسجيل دخول الموظف</span>
            </a>
            
            <a href="admin_login.php" class="login-btn ripple">
                <span class="btn-icon">🔒</span>
                <span>تسجيل دخول الإدارة</span>
            </a>
        </div>
        
        <div class="stats">
            <div class="stat-item">
                <div class="stat-number" id="employees-count">0</div>
                <div class="stat-label">موظف مسجل</div>
            </div>
            <div class="stat-item">
                <div class="stat-number" id="attendance-count">0</div>
                <div class="stat-label">سجل حضور</div>
            </div>
            <div class="stat-item">
                <div class="stat-number" id="accuracy-rate">99.8</div>
                <div class="stat-label">% دقة النظام</div>
            </div>
        </div>
        
        <div class="features">
            <h3 class="features-title">🌟 مميزات النظام</h3>
            
            <div class="features-grid">
                <div class="feature-item">
                    <div class="feature-icon">👤</div>
                    <div class="feature-title">تسجيل آمن بالوجه</div>
                    <div class="feature-desc">تقنية متطورة للتعرف على الوجه بدقة عالية وأمان تام</div>
                </div>
                
                <div class="feature-item">
                    <div class="feature-icon">📍</div>
                    <div class="feature-title">تحديد الموقع</div>
                    <div class="feature-desc">ضمان التسجيل من مكان العمل فقط للحفاظ على الدقة</div>
                </div>
                
                <div class="feature-item">
                    <div class="feature-icon">📊</div>
                    <div class="feature-title">تقارير مفصلة</div>
                    <div class="feature-desc">إحصائيات شاملة وتقارير دقيقة لجميع بيانات الحضور</div>
                </div>
                
                <div class="feature-item">
                    <div class="feature-icon">⚡</div>
                    <div class="feature-title">سرعة فائقة</div>
                    <div class="feature-desc">واجهة سريعة ومتجاوبة تعمل على جميع الأجهزة</div>
                </div>
            </div>
        </div>
        
        <div class="system-info">
            <h3>🔒 أمان وموثوقية النظام</h3>
            <ul>
                <li>تشفير عالي الجودة لحماية البيانات</li>
                <li>نسخ احتياطية تلقائية</li>
                <li>مراقبة النشاط والتحديثات الأمنية</li>
                <li>دعم فني متخصص على مدار الساعة</li>
                <li>توافق مع معايير الأمان العالمية</li>
            </ul>
        </div>
        
        <div class="footer">
            <p>
                <strong><?php echo htmlspecialchars($app_name); ?></strong> - الإصدار <?php echo APP_VERSION; ?><br>
                جميع الحقوق محفوظة © <?php echo date('Y'); ?>
            </p>
        </div>
    </div>

    <script>
        // تأثيرات التحميل والتفاعل
        document.addEventListener('DOMContentLoaded', function() {
            // تحريك الأرقام في الإحصائيات
            animateCounters();
            
            // إضافة تأثيرات التحميل
            addLoadingEffects();
            
            // تفعيل تأثيرات التمرير
            addScrollEffects();
            
            // فحص دعم الكاميرا
            checkCameraSupport();
        });
        
        function animateCounters() {
            const counters = [
                { element: 'employees-count', target: 250, duration: 2000 },
                { element: 'attendance-count', target: 15420, duration: 2500 },
                { element: 'accuracy-rate', target: 99.8, duration: 2000, decimal: true }
            ];
            
            counters.forEach(counter => {
                animateCounter(counter.element, counter.target, counter.duration, counter.decimal);
            });
        }
        
        function animateCounter(elementId, target, duration, isDecimal = false) {
            const element = document.getElementById(elementId);
            if (!element) return;
            
            const start = 0;
            const increment = target / (duration / 16);
            let current = start;
            
            const timer = setInterval(() => {
                current += increment;
                if (current >= target) {
                    current = target;
                    clearInterval(timer);
                }
                
                if (isDecimal) {
                    element.textContent = current.toFixed(1);
                } else {
                    element.textContent = Math.floor(current).toLocaleString();
                }
            }, 16);
        }
        
        function addLoadingEffects() {
            const elements = document.querySelectorAll('.feature-item, .login-btn');
            
            elements.forEach((element, index) => {
                element.style.opacity = '0';
                element.style.transform = 'translateY(30px)';
                element.style.transition = 'all 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275)';
                
                setTimeout(() => {
                    element.style.opacity = '1';
                    element.style.transform = 'translateY(0)';
                }, 200 * (index + 1));
            });
        }
        
        function addScrollEffects() {
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, observerOptions);
            
            document.querySelectorAll('.feature-item, .system-info').forEach(el => {
                observer.observe(el);
            });
        }
        
        function checkCameraSupport() {
            if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                console.log('✅ الكاميرا مدعومة في هذا المتصفح');
            } else {
                console.warn('⚠️ الكاميرا غير مدعومة في هذا المتصفح');
                
                // إظهار تحذير للمستخدم
                const warning = document.createElement('div');
                warning.style.cssText = `
                    position: fixed;
                    top: 20px;
                    left: 50%;
                    transform: translateX(-50%);
                    background: #fff3cd;
                    color: #856404;
                    padding: 10px 20px;
                    border-radius: 8px;
                    border: 1px solid #ffeaa7;
                    z-index: 1000;
                    font-size: 14px;
                `;
                warning.textContent = 'تحذير: متصفحك قد لا يدعم الكاميرا. يُنصح بتحديث المتصفح.';
                document.body.appendChild(warning);
                
                setTimeout(() => {
                    warning.remove();
                }, 5000);
            }
        }
        
        // تحسين الأداء - lazy loading للصور
        document.addEventListener('DOMContentLoaded', function() {
            if ('IntersectionObserver' in window) {
                const imageObserver = new IntersectionObserver((entries, observer) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const img = entry.target;
                            img.src = img.dataset.src;
                            img.classList.remove('lazy');
                            observer.unobserve(img);
                        }
                    });
                });
                
                document.querySelectorAll('img[data-src]').forEach(img => {
                    imageObserver.observe(img);
                });
            }
        });
        
        // تحسين تجربة المستخدم - منع التمرير المطاطي في iOS
        document.addEventListener('touchmove', function(e) {
            if (e.scale !== 1) {
                e.preventDefault();
            }
        }, { passive: false });
        
        // تحسين الأداء - إزالة المؤشرات غير المستخدمة
        document.addEventListener('DOMContentLoaded', function() {
            // إزالة مؤشرات التحميل بعد انتهاء التحميل
            setTimeout(() => {
                document.body.classList.add('loaded');
            }, 1000);
        });
        
        // معالجة الأخطاء العامة
        window.addEventListener('error', function(e) {
            console.error('خطأ في الصفحة:', e.error);
        });
        
        // معالجة الوعود المرفوضة
        window.addEventListener('unhandledrejection', function(e) {
            console.error('وعد مرفوض:', e.reason);
        });
    </script>
</body>
</html>