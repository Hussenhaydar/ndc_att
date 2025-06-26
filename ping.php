<?php
header('Content-Type: application/json; charset=utf-8');

$check_type = $_GET['check'] ?? 'basic';

try {
    $response = ['success' => true, 'timestamp' => time()];
    
    switch ($check_type) {
        case 'network':
            // فحص الشبكة
            $client_ip = getClientIP();
            $response['ip'] = $client_ip;
            $response['network'] = 'connected';
            
            // فحص نطاق IP إذا كان محدداً
            $allowed_ranges = getSetting('allowed_ip_ranges', '');
            if (!empty($allowed_ranges)) {
                $ranges = explode(',', $allowed_ranges);
                $ip_allowed = false;
                
                foreach ($ranges as $range) {
                    $range = trim($range);
                    if (isIPInRange($client_ip, $range)) {
                        $ip_allowed = true;
                        break;
                    }
                }
                
                $response['ip_allowed'] = $ip_allowed;
                $response['required_ranges'] = $ranges;
            }
            break;
            
        case 'database':
            // فحص قاعدة البيانات
            require_once 'config.php';
            $db = Database::getInstance()->getConnection();
            $stmt = $db->query("SELECT 1");
            $response['database'] = 'connected';
            break;
            
        case 'system':
            // فحص النظام العام
            $response['php_version'] = PHP_VERSION;
            $response['memory_usage'] = memory_get_usage(true);
            $response['server_time'] = date('Y-m-d H:i:s');
            break;
            
        default:
            // فحص أساسي
            $response['status'] = 'ok';
            $response['server'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
            break;
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => time()
    ]);
}

function getClientIP() {
    $ip_keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    
    foreach ($ip_keys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                
                if (filter_var($ip, FILTER_VALIDATE_IP, 
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

function isIPInRange($ip, $range) {
    if (strpos($range, '/') !== false) {
        // CIDR notation
        list($subnet, $mask) = explode('/', $range);
        
        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);
        $mask_long = -1 << (32 - $mask);
        
        return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
    } else {
        // نطاق بسيط مثل 192.168.1.*
        $range = str_replace('*', '', $range);
        return strpos($ip, $range) === 0;
    }
}

function getSetting($key, $default = null) {
    // نسخة مبسطة من getSetting
    try {
        if (file_exists('config.php')) {
            require_once 'config.php';
            return getSetting($key, $default);
        }
    } catch (Exception $e) {
        // في حالة عدم توفر config.php
    }
    
    return $default;
}
?>