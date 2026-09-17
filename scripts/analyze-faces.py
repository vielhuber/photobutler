import base64
import hashlib
import json
import os
from pathlib import Path
import sys

os.environ['OPENCV_IO_MAX_IMAGE_PIXELS'] = '60000000'
import cv2
import numpy as np

cv2.setNumThreads(2)
cv2.ocl.setUseOpenCL(False)
manifest = json.loads((Path(__file__).parent / 'face-models.json').read_text())
models = Path(sys.argv[2])
try:
    if cv2.__version__ != '4.13.0':
        raise RuntimeError('Unexpected OpenCV version')
    for model in manifest['models']:
        if hashlib.sha256((models / model['file']).read_bytes()).hexdigest() != model['sha256']:
            raise RuntimeError('Model checksum mismatch')
    try:
        image = cv2.imread(sys.argv[1], cv2.IMREAD_COLOR)
    except cv2.error:
        image = None
    if image is None:
        print(json.dumps({'status': 'unsupported', 'faces': []}))
        sys.exit(0)
    height, width = image.shape[:2]
    scale = min(1.0, 1600 / max(height, width))
    working = cv2.resize(image, (round(width * scale), round(height * scale))) if scale < 1 else image
    detector = cv2.FaceDetectorYN.create(str(models / manifest['models'][0]['file']), '', (working.shape[1], working.shape[0]), 0.9, 0.3, 5000)
    recognizer = cv2.FaceRecognizerSF.create(str(models / manifest['models'][1]['file']), '')
    detected = detector.detect(working)[1]
    faces = []
    for face in ([] if detected is None else detected):
        face[:14] /= scale
        aligned = recognizer.alignCrop(image, face)
        embedding = recognizer.feature(aligned).flatten()
        embedding /= np.linalg.norm(embedding)
        if not np.isfinite(embedding).all():
            raise RuntimeError('Invalid embedding')
        crop = cv2.imencode('.jpg', aligned, [cv2.IMWRITE_JPEG_QUALITY, 82])[1]
        faces.append({'box': [float(face[0] / width), float(face[1] / height), float(face[2] / width), float(face[3] / height)], 'embedding': embedding.tolist(), 'crop': base64.b64encode(crop).decode('ascii')})
    print(json.dumps({'status': 'done', 'faces': faces}, allow_nan=False))
except (cv2.error, OSError, RuntimeError, ValueError):
    print('Local face analysis failed; verify runtime, models and image.', file=sys.stderr)
    sys.exit(1)
