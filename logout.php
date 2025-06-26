<?php
require_once 'config.php';

$user_type = '';
$user_id = 0;
$user_name = '';

// تحديد نوع المستخدم
if (isset($_SESSION['admin_id'])) {
    $user_type = 'admin';
    $user_id = $_SESSION['admin_id'];
    $user_name = $_SESSION['admin_name'] ?? 'مدير';
} elseif (isset($_SESSION['employee_id'])) {
    $user_type = 'employee';
    $user_id = $_SESSION['employee_id'];
    $user_name = $_SESSION['employee_name'] ?? 'موظف';
}

// تسجيل النشاط قبل إتلاف الجلسة
if ($user_type && $user_id) {
    try {
        logActivity($user_type, $user_id, 'logout', 'تسجيل خروج من النظام');
        
        // حذف remember me cookies إذا كانت موجودة
        if (isset($_COOKIE['remember_admin'])) {
            setcookie('remember_admin', '', time() - 3600, '/');
            
            // إلغاء تفعيل الجلسة في قاعدة البيانات
            try {
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare("UPDATE user_sessions SET is_active = 0 WHERE session_token = ? AND user_type = 'admin'");
                $stmt->execute([$_COOKIE['remember_admin']]);
            } catch (Exception $e) {
                error_log("Error deactivating admin session: " . $e->getMessage());
            }
        }
        
        if (isset($_COOKIE['remember_employee'])) {
            setcookie('remember_employee', '', time() - 3600, '/');
            
            // إلغاء تفعيل الجلسة في قاعدة البيانات
            try {
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare("UPDATE user_sessions SET is_active = 0 WHERE session_token = ? AND user_type = 'employee'");
                $stmt->execute([$_COOKIE['remember_employee']]);
            } catch (Exception $e) {
                error_log("Error deactivating employee session: " . $e->getMessage());
            }
        }
        
    } catch (Exception $e) {
        error_log("Logout activity logging error: " . $e->getMessage());
    }
}

// إتلاف الجلسة
SessionManager::destroy();

// تحديد صفحة إعادة التوجيه
$redirect_page = 'index.php';
if ($user_type === 'admin') {
    $redirect_page = 'admin_login.php';
} elseif ($user_type === 'employee') {
    $redirect_page = 'employee_login.php';
}

$app_name = getSetting('company_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الخروج - <?php echo htmlspecialchars($app_name); ?></title>
    <meta name="robots" content="noindex, nofollow">
    
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
        }
        
        .logout-container {
            background: rgba(255