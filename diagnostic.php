<?php
// ملف تشخيص مشاكل قاعدة البيانات
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>🔍 تشخيص مشاكل قاعدة البيانات</h2>";

// فحص ملف config.php
echo "<h3>1. فحص ملف config.php</h3>";
if (file_exists('config.php')) {
    echo "✅ ملف config.php موجود<br>";
    
    // تضمين الملف
    try {
        require_once 'config.php';
        echo "✅ تم تحميل config.php بنجاح<br>";
        
        // عرض إعدادات قاعدة البيانات
        echo "<strong>إعدادات قاعدة البيانات:</strong><br>";
        echo "🖥️ الخادم: " . (defined('DB_HOST') ? DB_HOST : 'غير معرف') . "<br>";
        echo "🗄️ قاعدة البيانات: " . (defined('DB_NAME') ? DB_NAME : 'غير معرف') . "<br>";
        echo "👤 المستخدم: " . (defined('DB_USER') ? DB_USER : 'غير معرف') . "<br>";
        echo "🔤 الترميز: " . (defined('DB_CHARSET') ? DB_CHARSET : 'غير معرف') . "<br>";
        
    } catch (Exception $e) {
        echo "❌ خطأ في تحميل config.php: " . $e->getMessage() . "<br>";
        die();
    }
} else {
    echo "❌ ملف config.php غير موجود<br>";
    die();
}

echo "<hr>";

// فحص امتداد PDO
echo "<h3>2. فحص امتداد PDO</h3>";
if (extension_loaded('pdo')) {
    echo "✅ امتداد PDO متاح<br>";
    
    if (extension_loaded('pdo_mysql')) {
        echo "✅ امتداد PDO MySQL متاح<br>";
    } else {
        echo "❌ امتداد PDO MySQL غير متاح<br>";
    }
} else {
    echo "❌ امتداد PDO غير متاح<br>";
}

echo "<hr>";

// اختبار الاتصال المباشر
echo "<h3>3. اختبار الاتصال المباشر</h3>";
try {
    $dsn = "mysql:host=" . DB_HOST . ";charset=" . DB_CHARSET;
    $pdo_test = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5
    ]);
    echo "✅ الاتصال بخادم MySQL نجح<br>";
    
    // فحص وجود قاعدة البيانات
    $databases = $pdo_test->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array(DB_NAME, $databases)) {
        echo "✅ قاعدة البيانات '" . DB_NAME . "' موجودة<br>";
        
        // الاتصال بقاعدة البيانات المحددة
        $dsn_with_db = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo_db = new PDO($dsn_with_db, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        echo "✅ الاتصال بقاعدة البيانات نجح<br>";
        
        // فحص الجداول
        $tables = $pdo_db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        echo "📋 الجداول الموجودة (" . count($tables) . "): " . implode(', ', $tables) . "<br>";
        
    } else {
        echo "❌ قاعدة البيانات '" . DB_NAME . "' غير موجودة<br>";
        echo "📋 قواعد البيانات المتاحة: " . implode(', ', $databases) . "<br>";
    }
    
} catch (PDOException $e) {
    echo "❌ خطأ في الاتصال: " . $e->getMessage() . "<br>";
    echo "🔧 كود الخطأ: " . $e->getCode() . "<br>";
}

echo "<hr>";

// اختبار فئة Database
echo "<h3>4. اختبار فئة Database</h3>";
try {
    if (class_exists('Database')) {
        echo "✅ فئة Database موجودة<br>";
        
        $db_instance = Database::getInstance();
        echo "✅ تم إنشاء مثيل من Database<br>";
        
        $connection = $db_instance->getConnection();
        if ($connection) {
            echo "✅ تم الحصول على الاتصال<br>";
            
            // اختبار استعلام بسيط
            $stmt = $connection->query("SELECT 1 as test");
            $result = $stmt->fetch();
            if ($result['test'] == 1) {
                echo "✅ اختبار الاستعلام نجح<br>";
            }
        }
        
    } else {
        echo "❌ فئة Database غير موجودة<br>";
    }
    
} catch (Exception $e) {
    echo "❌ خطأ في فئة Database: " . $e->getMessage() . "<br>";
    echo "📍 الملف: " . $e->getFile() . " السطر: " . $e->getLine() . "<br>";
}

echo "<hr>";

// فحص الملفات المطلوبة
echo "<h3>5. فحص الملفات المطلوبة</h3>";
$required_files = [
    'config.php',
    'admin_login.php', 
    'admin_dashboard.php',
    'employee_login.php',
    'index.php'
];

foreach ($required_files as $file) {
    if (file_exists($file)) {
        echo "✅ $file موجود<br>";
    } else {
        echo "❌ $file مفقود<br>";
    }
}

echo "<hr>";

// فحص المجلدات
echo "<h3>6. فحص المجلدات المطلوبة</h3>";
$required_dirs = [
    'uploads',
    'uploads/faces',
    'uploads/attendance', 
    'logs',
    'cache'
];

foreach ($required_dirs as $dir) {
    if (is_dir($dir)) {
        echo "✅ مجلد $dir موجود<br>";
        if (is_writable($dir)) {
            echo "✅ مجلد $dir قابل للكتابة<br>";
        } else {
            echo "⚠️ مجلد $dir غير قابل للكتابة<br>";
        }
    } else {
        echo "❌ مجلد $dir مفقود<br>";
        // محاولة إنشاؤه
        if (mkdir($dir, 0755, true)) {
            echo "✅ تم إنشاء مجلد $dir<br>";
        } else {
            echo "❌ فشل في إنشاء مجلد $dir<br>";
        }
    }
}

echo "<hr>";

// معلومات PHP
echo "<h3>7. معلومات PHP</h3>";
echo "🐘 إصدار PHP: " . PHP_VERSION . "<br>";
echo "💾 حد الذاكرة: " . ini_get('memory_limit') . "<br>";
echo "⏱️ أقصى وقت تنفيذ: " . ini_get('max_execution_time') . " ثانية<br>";
echo "📁 مجلد التحميل المؤقت: " . sys_get_temp_dir() . "<br>";

echo "<hr>";

// خلاصة النتائج
echo "<h3>🎯 خلاصة التشخيص</h3>";
echo "<div style='background: #e8f5e9; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
echo "<strong>إذا كانت جميع الاختبارات ناجحة:</strong><br>";
echo "- يجب أن يعمل النظام بشكل طبيعي<br>";
echo "- تحقق من وجود أخطاء أخرى في سجلات PHP<br>";
echo "</div>";

echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
echo "<strong>إذا كان هناك أخطاء:</strong><br>";
echo "- أصلح الأخطاء المذكورة أعلاه<br>";
echo "- تأكد من تشغيل خادم MySQL<br>";
echo "- تحقق من إعدادات config.php<br>";
echo "</div>";

echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
echo "<strong>🔧 خطوات الإصلاح السريع:</strong><br>";
echo "1. تشغيل create_database.php مرة أخرى<br>";
echo "2. التأكد من إعدادات config.php<br>";
echo "3. فحص سجلات أخطاء PHP<br>";
echo "4. إعادة تشغيل خادم الويب<br>";
echo "</div>";
?>