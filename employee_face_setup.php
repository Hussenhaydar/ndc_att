<?php
require_once 'config.php';
checkEmployeeLogin();

$employee_id = $_SESSION['employee_id'];

// جلب بيانات الموظف
$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$employee_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

// فحص البصمات المحفوظة
$face_encodings = $employee['face_encodings'] ? json_decode($employee['face_encodings'], true) : [];
$face_images = $employee['face_images'] ? json_decode($employee['face_images'], true) : [];

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete_face' && isset($_POST['image_index'])) {
        // حذف صورة وجه
        $index = (int)$_POST['image_index'];
        
        if (isset($face_images[$index])) {
            // حذف الملف
            $image_path = FACES_PATH . $face_images[$index];
            if (file_exists($image_path)) {
                unlink($image_path);
            }
            
            // إزالة من المصفوفات
            unset($face_images[$index]);
            unset($face_encodings[$index]);
            
            // إعادة ترتيب المصفوفات
            $face_images = array_values($face_images);
            $face_encodings = array_values($face_encodings);
            
            // تحديث قاعدة البيانات
            $stmt = $db->prepare("UPDATE employees SET face_images = ?, face_encodings = ? WHERE id = ?");
            $stmt->execute([
                json_encode($face_images),
                json_encode($face_encodings),
                $employee_id
            ]);
            
            $message = 'تم حذف صورة الوجه بنجاح';
            logActivity('employee', $employee_id, 'delete_face_image', 'حذف صورة وجه');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>إعداد بصمة الوجه</title>
    
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
            padding: 10px;
            color: #2c3e50;
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
        
        .title {
            font-size: 24px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .subtitle {
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
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .faces-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .face-item {
            position: relative;
            aspect-ratio: 1;
            border-radius: 15px;
            overflow: hidden;
            border: 3px solid #667eea;
        }
        
        .face-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .face-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: all 0.3s ease;
        }
        
        .face-item:hover .face-overlay {
            opacity: 1;
        }
        
        .delete-btn {
            background: #e74c3c;
            color: white;
            border: none;
            padding: 8px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 16px;
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .add-face-btn {
            aspect-ratio: 1;
            border: 3px dashed #667eea;
            border-radius: 15px;
            background: rgba(102,126,234,0.1);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: #667eea;
        }
        
        .add-face-btn:hover {
            background: rgba(102,126,234,0.2);
            transform: scale(1.05);
        }
        
        .add-icon {
            font-size: 32px;
            margin-bottom: 5px;
        }
        
        .add-text {
            font-size: 12px;
            font-weight: 600;
        }
        
        .instructions {
            background: #e3f2fd;
            border: 1px solid #2196f3;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .instructions h4 {
            color: #1976d2;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .instructions ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .instructions li {
            margin-bottom: 8px;
            color: #424242;
            padding-right: 20px;
            position: relative;
            font-size: 14px;
        }
        
        .instructions li::before {
            content: '•';
            color: #2196f3;
            position: absolute;
            right: 0;
            font-weight: bold;
        }
        
        .camera-container {
            position: relative;
            width: 250px;
            height: 250px;
            margin: 20px auto;
            border-radius: 50%;
            overflow: hidden;
            border: 5px solid #667eea;
            background: #f8f9fa;
            display: none;
        }
        
        #video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transform: scaleX(-1);
        }
        
        .camera-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border: 3px solid transparent;
            border-radius: 50%;
            transition: all 0.3s ease;
        }
        
        .camera-overlay.scanning {
            border-color: #f39c12;
            animation: pulse 1.5s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { 
                border-color: #f39c12; 
                transform: scale(1);
            }
            50% { 
                border-color: #e67e22; 
                transform: scale(1.05);
            }
        }
        
        .controls {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .btn {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #56ab2f, #a8e6cf);
            color: white;
        }
        
        .btn-secondary {
            background: #f8f9fa;
            color: #6c757d;
            border: 2px solid #dee2e6;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .btn:disabled {
            background: #bdc3c7;
            cursor: not-allowed;
            transform: none;
        }
        
        .status {
            margin: 15px 0;
            padding: 12px;
            border-radius: 10px;
            font-weight: 500;
            text-align: center;
        }
        
        .status.success {
            background: #d4edda;
            color: #155724;
        }
        
        .status.error {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status.info {
            background: #d1ecf1;
            color: #0c5460;
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
        
        .face-count {
            background: #667eea;
            color: white;
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        @media (max-width: 480px) {
            .container {
                padding: 5px;
            }
            
            .header {
                padding: 20px;
            }
            
            .card {
                padding: 20px;
            }
            
            .camera-container {
                width: 200px;
                height: 200px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 class="title">إعداد بصمة الوجه</h1>
            <p class="subtitle">أضف صور وجهك لتسجيل الحضور بأمان</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-title">
                👤 صور الوجه المحفوظة 
                <span class="face-count"><?php echo count($face_images); ?>/<?php echo MAX_FACE_IMAGES_PER_USER; ?></span>
            </div>
            
            <div class="faces-grid">
                <?php if (count($face_images) < MAX_FACE_IMAGES_PER_USER): ?>
                    <div class="add-face-btn" onclick="startFaceCapture()">
                        <div class="add-icon">📷</div>
                        <div class="add-text">إضافة صورة</div>
                    </div>
                <?php endif; ?>
                
                <?php foreach ($face_images as $index => $image): ?>
                    <div class="face-item">
                        <img src="<?php echo UPLOAD_PATH . 'faces/' . $image; ?>" 
                             alt="صورة الوجه <?php echo $index + 1; ?>" 
                             class="face-image">
                        <div class="face-overlay">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="delete_face">
                                <input type="hidden" name="image_index" value="<?php echo $index; ?>">
                                <button type="submit" class="delete-btn" 
                                        onclick="return confirm('هل أنت متأكد من حذف هذه الصورة؟')">
                                    🗑️
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="instructions">
            <h4>📋 نصائح لأفضل النتائج:</h4>
            <ul>
                <li>التقط صور من زوايا مختلفة قليلاً</li>
                <li>تأكد من وجود إضاءة طبيعية جيدة</li>
                <li>انظر مباشرة إلى الكاميرا</li>
                <li>تجنب النظارات الشمسية أو القبعات</li>
                <li>أبق تعبير وجهك طبيعياً</li>
                <li>يُنصح بإضافة 2-3 صور لدقة أفضل</li>
            </ul>
        </div>

        <!-- كاميرا التقاط الوجه -->
        <div class="card" id="captureCard" style="display: none;">
            <div class="card-title">📷 التقاط صورة جديدة</div>
            
            <div class="camera-container">
                <video id="video" autoplay muted playsinline></video>
                <div class="camera-overlay" id="cameraOverlay"></div>
                <canvas id="canvas" style="display: none;"></canvas>
            </div>
            
            <div class="status info" id="captureStatus">
                📷 اضغط "التقاط صورة" عند الاستعداد
            </div>
            
            <div class="controls">
                <button class="btn btn-primary" id="captureBtn" onclick="captureImage()">
                    📷 التقاط صورة
                </button>
                <button class="btn btn-secondary" onclick="cancelCapture()">
                    ❌ إلغاء
                </button>
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
            <a href="employee_face_setup.php" class="nav-item active">
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
            <a href="employee_profile.php" class="nav-item">
                <div class="nav-icon">⚙️</div>
                <div>الملف الشخصي</div>
            </a>
        </div>
    </div>

    <script>
        let video = document.getElementById('video');
        let canvas = document.getElementById('canvas');
        let captureCard = document.getElementById('captureCard');
        let captureStatus = document.getElementById('captureStatus');
        let cameraOverlay = document.getElementById('cameraOverlay');
        let captureBtn = document.getElementById('captureBtn');
        
        let stream = null;
        let captureMode = false;
        
        async function startFaceCapture() {
            try {
                captureCard.style.display = 'block';
                captureCard.scrollIntoView({ behavior: 'smooth' });
                
                stream = await navigator.mediaDevices.getUserMedia({ 
                    video: { 
                        facingMode: 'user',
                        width: { ideal: 640 },
                        height: { ideal: 640 }
                    } 
                });
                
                video.srcObject = stream;
                captureMode = true;
                
                updateCaptureStatus('info', '📷 اضغط "التقاط صورة" عند الاستعداد');
                
            } catch (error) {
                console.error('Camera error:', error);
                updateCaptureStatus('error', '❌ لا يمكن الوصول للكاميرا');
            }
        }
        
        async function captureImage() {
            if (!captureMode) return;
            
            try {
                // التقاط الصورة
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                
                const ctx = canvas.getContext('2d');
                ctx.scale(-1, 1); // قلب الصورة أفقياً
                ctx.drawImage(video, -canvas.width, 0);
                
                const imageData = canvas.toDataURL('image/jpeg', 0.8);
                
                updateCaptureStatus('info', '🔍 جاري معالجة الصورة...');
                captureBtn.disabled = true;
                cameraOverlay.className = 'camera-overlay scanning';
                
                // إرسال الصورة للمعالجة
                const response = await fetch('save_face_image.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        image: imageData
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    updateCaptureStatus('success', '✅ تم حفظ صورة الوجه بنجاح!');
                    
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    updateCaptureStatus('error', '❌ ' + result.message);
                    captureBtn.disabled = false;
                    cameraOverlay.className = 'camera-overlay';
                }
                
            } catch (error) {
                console.error('Capture error:', error);
                updateCaptureStatus('error', '❌ حدث خطأ في التقاط الصورة');
                captureBtn.disabled = false;
                cameraOverlay.className = 'camera-overlay';
            }
        }
        
        function cancelCapture() {
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                stream = null;
            }
            
            captureMode = false;
            captureCard.style.display = 'none';
            captureBtn.disabled = false;
            cameraOverlay.className = 'camera-overlay';
        }
        
        function updateCaptureStatus(type, message) {
            captureStatus.className = `status ${type}`;
            captureStatus.textContent = message;
        }
        
        // تنظيف الموارد عند إغلاق الصفحة
        window.addEventListener('beforeunload', () => {
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
            }
        });
    </script>
</body>
</html>