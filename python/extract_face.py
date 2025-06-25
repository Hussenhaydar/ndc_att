#!/usr/bin/env python3
"""
Face encoding extraction using face_recognition library
Install: pip install face_recognition opencv-python pillow
"""

import sys
import json
import face_recognition
import cv2
import numpy as np
from PIL import Image

def extract_face_encoding(image_path):
    """
    استخراج face encoding من صورة
    """
    try:
        # قراءة الصورة
        image = face_recognition.load_image_file(image_path)
        
        # البحث عن الوجوه في الصورة
        face_locations = face_recognition.face_locations(image)
        
        if not face_locations:
            return {
                'success': False,
                'face_detected': False,
                'message': 'لم يتم العثور على وجه في الصورة'
            }
        
        if len(face_locations) > 1:
            return {
                'success': False,
                'face_detected': True,
                'message': 'تم العثور على أكثر من وجه. يرجى التأكد من وجود وجه واحد فقط'
            }
        
        # استخراج face encodings
        face_encodings = face_recognition.face_encodings(image, face_locations)
        
        if not face_encodings:
            return {
                'success': False,
                'face_detected': True,
                'message': 'لم يتم التمكن من استخراج معالم الوجه'
            }
        
        # تحويل encoding إلى قائمة للتسلسل في JSON
        encoding = face_encodings[0].tolist()
        
        # فحص جودة الصورة
        quality_score = assess_image_quality(image, face_locations[0])
        
        if quality_score < 0.5:
            return {
                'success': False,
                'face_detected': True,
                'message': 'جودة الصورة منخفضة. يرجى التقاط صورة بإضاءة أفضل'
            }
        
        return {
            'success': True,
            'face_detected': True,
            'encoding': encoding,
            'quality_score': quality_score,
            'face_location': face_locations[0]
        }
        
    except Exception as e:
        return {
            'success': False,
            'face_detected': False,
            'message': f'خطأ في معالجة الصورة: {str(e)}'
        }

def assess_image_quality(image, face_location):
    """
    تقييم جودة الصورة والوجه
    """
    try:
        top, right, bottom, left = face_location
        
        # قص منطقة الوجه
        face_image = image[top:bottom, left:right]
        
        # تحويل إلى رمادي للتحليل
        gray_face = cv2.cvtColor(face_image, cv2.COLOR_RGB2GRAY)
        
        # فحص الوضوح (sharpness) باستخدام Laplacian variance
        laplacian_var = cv2.Laplacian(gray_face, cv2.CV_64F).var()
        sharpness_score = min(laplacian_var / 500.0, 1.0)  # تطبيع النتيجة
        
        # فحص الإضاءة
        mean_brightness = np.mean(gray_face)
        brightness_score = 1.0 - abs(mean_brightness - 128) / 128.0
        
        # فحص التباين
        contrast = np.std(gray_face)
        contrast_score = min(contrast / 50.0, 1.0)
        
        # فحص حجم الوجه
        face_area = (right - left) * (bottom - top)
        min_face_size = 10000  # الحد الأدنى لحجم الوجه
        size_score = min(face_area / min_face_size, 1.0)
        
        # النتيجة الإجمالية
        overall_score = (sharpness_score * 0.4 + 
                        brightness_score * 0.3 + 
                        contrast_score * 0.2 + 
                        size_score * 0.1)
        
        return overall_score
        
    except Exception:
        return 0.5  # نتيجة افتراضية متوسطة

def main():
    if len(sys.argv) != 2:
        print(json.dumps({
            'success': False,
            'message': 'Usage: python extract_face.py <image_path>'
        }))
        return
    
    image_path = sys.argv[1]
    result = extract_face_encoding(image_path)
    print(json.dumps(result))

if __name__ == "__main__":
    main()