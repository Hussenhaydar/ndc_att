<?php
require_once 'config.php';
checkEmployeeLogin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'طريقة طلب غير صحيحة', null, 405);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['image'])) {
    jsonResponse(false, 'بيانات غير مكتملة');
}

$employee_id = $_SESSION['employee_id'];
$image_data = $input['image'];

try {
    $db = Database::getInstance()->getConnection();
    
    // جلب بيانات الموظف الحالية
    $stmt = $db->prepare("SELECT face_encodings, face_images FROM employees WHERE id = ?");
    $stmt->execute([$employee_id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$employee) {
        jsonResponse(false, 'موظف غير موجود');
    }
    
    // فحص الصور الحالية
    $face_images = $employee['face_images'] ? json_decode($employee['face_images'], true) : [];
    $face_encodings = $employee['face_encodings'] ? json_decode($employee['face_encodings'], true) : [];
    
    if (count($face_images) >= MAX_FACE_IMAGES_PER_USER) {
        jsonResponse(false, 'تم الوصول للحد الأقصى من صور الوجه (' . MAX_FACE_IMAGES_PER_USER . ' صور)');
    }
    
    // تحويل الصورة من base64
    $image_data = str_replace('data:image/jpeg;base64,', '', $image_data);
    $image_binary = base64_decode($image_data);
    
    if (!$image_binary) {
        jsonResponse(false, 'صورة غير صحيحة');
    }
    
    // التحقق من حجم الصورة
    if (strlen($image_binary) > MAX_FILE_SIZE) {
        jsonResponse(false, 'حجم الصورة كبير جداً');
    }
    
    // حفظ الصورة مؤقتاً لمعالجتها
    $temp_image_path = sys_get_temp_dir() . '/face_setup_' . uniqid() . '.jpg';
    file_put_contents($temp_image_path, $image_binary);
    
    // كشف الوجه واستخراج encoding
    $face_encoding = extractFaceEncoding($temp_image_path);
    
    if (!$face_encoding) {
        unlink($temp_image_path);
        jsonResponse(false, 'لم يتم العثور على وجه واضح في الصورة. يرجى المحاولة مرة أخرى بإضاءة أفضل');
    }
    
    // فحص التشابه مع الصور الموجودة (لتجنب التكرار)
    if (!empty($face_encodings)) {
        foreach ($face_encodings as $existing_encoding) {
            $similarity = compareFaceEncodings($face_encoding, $existing_encoding);
            if ($similarity > 0.95) { // تشابه عالي جداً
                unlink($temp_image_path);
                jsonResponse(false, 'هذه الصورة مشابهة جداً لصورة موجودة. يرجى التقاط صورة من زاوية مختلفة');
            }
        }
    }
    
    // إنشاء مجلد الصور إذا لم يكن موجوداً
    if (!is_dir(FACES_PATH)) {
        mkdir(FACES_PATH, 0755, true);
    }
    
    // إنشاء اسم الملف
    $filename = 'emp_' . $employee_id . '_face_' . (count($face_images) + 1) . '_' . uniqid() . '.jpg';
    $file_path = FACES_PATH . $filename;
    
    // نسخ الصورة إلى المجلد النهائي
    if (!copy($temp_image_path, $file_path)) {
        unlink($temp_image_path);
        jsonResponse(false, 'فشل في حفظ الصورة');
    }
    
    // حذف الملف المؤقت
    unlink($temp_image_path);
    
    // إضافة الصورة والencoding للمصفوفات
    $face_images[] = $filename;
    $face_encodings[] = $face_encoding;
    
    // تحديث قاعدة البيانات
    $stmt = $db->prepare("UPDATE employees SET face_images = ?, face_encodings = ?, updated_at = NOW() WHERE id = ?");
    $success = $stmt->execute([
        json_encode($face_images),
        json_encode($face_encodings),
        $employee_id
    ]);
    
    if (!$success) {
        // حذف الصورة إذا فشل التحديث
        unlink($file_path);
        jsonResponse(false, 'فشل في حفظ بيانات الصورة');
    }
    
    // تسجيل النشاط
    logActivity('employee', $employee_id, 'add_face_image', 
               'إضافة صورة وجه جديدة - العدد الكلي: ' . count($face_images));
    
    // إنشاء إشعار
    createNotification('employee', $employee_id, 'إضافة صورة وجه', 
                      'تم إضافة صورة وجه جديدة بنجاح', 'success');
    
    jsonResponse(true, 'تم حفظ صورة الوجه بنجاح', [
        'total_images' => count($face_images),
        'max_images' => MAX_FACE_IMAGES_PER_USER
    ]);
    
} catch (Exception $e) {
    error_log("Face image save error: " . $e->getMessage());
    jsonResponse(false, 'حدث خطأ في حفظ الصورة');
}

/**
 * استخراج face encoding من صورة (نفس الدالة من process_face.php)
 */
function extractFaceEncoding($image_path) {
    // استخدام Python script إذا كان متاحاً
    $python_script = __DIR__ . '/python/extract_face.py';
    
    if (file_exists($python_script)) {
        $command = "python3 " . escapeshellarg($python_script) . " " . escapeshellarg($image_path);
        $output = shell_exec($command);
        
        if ($output) {
            $result = json_decode(trim($output), true);
            if ($result && isset($result['encoding']) && $result['face_detected']) {
                return $result['encoding'];
            }
        }
    }
    
    // Fallback: كشف الوجه البسيط
    return detectFaceSimple($image_path);
}

/**
 * كشف الوجه البسيط (نفس الدالة من process_face.php)
 */
function detectFaceSimple($image_path) {
    $image = imagecreatefromjpeg($image_path);
    if (!$image) return false;
    
    $width = imagesx($image);
    $height = imagesy($image);
    
    // فحص أساسي للتأكد من وجود محتوى في الصورة
    if ($width < 100 || $height < 100) {
        imagedestroy($image);
        return false;
    }
    
    $encoding = [];
    
    // تقسيم الصورة إلى شبكة وحساب متوسط اللون لكل منطقة
    $grid_size = 8;
    $cell_width = $width / $grid_size;
    $cell_height = $height / $grid_size;
    
    for ($x = 0; $x < $grid_size; $x++) {
        for ($y = 0; $y < $grid_size; $y++) {
            $start_x = (int)($x * $cell_width);
            $start_y = (int)($y * $cell_height);
            $end_x = (int)(($x + 1) * $cell_width);
            $end_y = (int)(($y + 1) * $cell_height);
            
            $total_r = $total_g = $total_b = 0;
            $pixel_count = 0;
            
            for ($px = $start_x; $px < $end_x; $px++) {
                for ($py = $start_y; $py < $end_y; $py++) {
                    if ($px < $width && $py < $height) {
                        $color = imagecolorat($image, $px, $py);
                        $total_r += ($color >> 16) & 0xFF;
                        $total_g += ($color >> 8) & 0xFF;
                        $total_b += $color & 0xFF;
                        $pixel_count++;
                    }
                }
            }
            
            if ($pixel_count > 0) {
                $encoding[] = $total_r / $pixel_count;
                $encoding[] = $total_g / $pixel_count;
                $encoding[] = $total_b / $pixel_count;
            }
        }
    }
    
    imagedestroy($image);
    
    // التحقق من وجود تباين كافي في الصورة (ليس مجرد لون واحد)
    $variance = 0;
    $mean = array_sum($encoding) / count($encoding);
    
    foreach ($encoding as $value) {
        $variance += pow($value - $mean, 2);
    }
    $variance = $variance / count($encoding);
    
    // إذا كان التباين قليل جداً، فالصورة قد تكون فارغة أو غير واضحة
    if ($variance < 100) {
        return false;
    }
    
    return $encoding;
}

/**
 * مقارنة face encodings (نفس الدالة من process_face.php)
 */
function compareFaceEncodings($encoding1, $encoding2) {
    if (!is_array($encoding1) || !is_array($encoding2)) {
        return 0;
    }
    
    if (count($encoding1) !== count($encoding2)) {
        return 0;
    }
    
    // حساب المسافة الإقليدية
    $distance = 0;
    for ($i = 0; $i < count($encoding1); $i++) {
        $diff = $encoding1[$i] - $encoding2[$i];
        $distance += $diff * $diff;
    }
    
    $distance = sqrt($distance);
    
    // تحويل المسافة إلى درجة تشابه
    $max_distance = 255 * sqrt(count($encoding1));
    $similarity = 1 - ($distance / $max_distance);
    
    return max(0, min(1, $similarity));
}
?>