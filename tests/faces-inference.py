import hashlib
import json
import os
from pathlib import Path
import struct
import subprocess
import sys
import tempfile
import unittest

import cv2
import numpy as np


class FaceInferenceTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.project = Path(__file__).resolve().parent.parent
        cls.fixtures = Path(os.environ['PHOTOBUTLER_FACE_FIXTURES'])
        cls.runtime = Path(os.environ.get('PHOTOBUTLER_TEST_FACE_RUNTIME', cls.project / '.data/face-runtime'))
        cls.temporary = tempfile.TemporaryDirectory(prefix='photobutler-inference-')
        cls.workspace = Path(cls.temporary.name)

    @classmethod
    def tearDownClass(cls):
        cls.temporary.cleanup()

    def analyze(self, path):
        before = hashlib.sha256(path.read_bytes()).hexdigest()
        completed = subprocess.run([str(self.runtime / 'bin/python'), str(self.project / 'scripts/analyze-faces.py'), str(path), str(self.runtime / 'models')], capture_output=True, check=True, timeout=60)
        self.assertEqual(before, hashlib.sha256(path.read_bytes()).hexdigest())
        return json.loads(completed.stdout)

    def test_detection_normalized_embeddings_and_conservative_similarity(self):
        first = self.analyze(self.fixtures / 'sample-a.jpg')['faces']
        other = self.analyze(self.fixtures / 'sample-b.jpg')['faces']
        self.assertEqual(1, len(first))
        self.assertEqual(1, len(other))
        original = cv2.imread(str(self.fixtures / 'sample-a.jpg'))
        altered = self.workspace / 'brightness.jpg'
        cv2.imwrite(str(altered), cv2.convertScaleAbs(original, alpha=0.9, beta=12))
        variant = self.analyze(altered)['faces'][0]
        same_score = float(np.dot(first[0]['embedding'], variant['embedding']))
        other_score = float(np.dot(first[0]['embedding'], other[0]['embedding']))
        self.assertGreater(same_score, 0.55)
        self.assertLess(other_score, 0.55)
        self.assertGreater(same_score - other_score, 0.08)
        self.assertAlmostEqual(1.0, np.linalg.norm(first[0]['embedding']), places=5)
        print(f'Limited sample scores: same={same_score:.4f}, different={other_score:.4f}')

    def test_exif_orientation_is_applied_before_landmarks(self):
        original = cv2.imread(str(self.fixtures / 'sample-a.jpg'))
        rotated = cv2.rotate(original, cv2.ROTATE_90_COUNTERCLOCKWISE)
        encoded = cv2.imencode('.jpg', rotated)[1].tobytes()
        exif = b'Exif\x00\x00II' + struct.pack('<HIH', 42, 8, 1) + struct.pack('<HHIHHI', 0x112, 3, 1, 6, 0, 0)
        path = self.workspace / 'oriented.jpg'
        path.write_bytes(encoded[:2] + b'\xff\xe1' + struct.pack('>H', len(exif) + 2) + exif + encoded[2:])
        actual = self.analyze(path)['faces']
        expected = self.analyze(self.fixtures / 'sample-a.jpg')['faces']
        self.assertEqual(1, len(actual))
        self.assertGreater(np.dot(actual[0]['embedding'], expected[0]['embedding']), 0.9)
        np.testing.assert_allclose(actual[0]['box'], expected[0]['box'], atol=0.015)

    def test_blank_and_unsupported_inputs_finish_without_retries(self):
        path = self.workspace / 'blank.png'
        cv2.imwrite(str(path), np.zeros((100, 200, 3), dtype=np.uint8))
        self.assertEqual({'status': 'done', 'faces': []}, self.analyze(path))
        path = self.workspace / 'unsupported.jpg'
        path.write_bytes(b'not an image')
        self.assertEqual({'status': 'unsupported', 'faces': []}, self.analyze(path))

    def test_multiple_people_are_detected(self):
        first = cv2.imread(str(self.fixtures / 'sample-a.jpg'))
        other = cv2.imread(str(self.fixtures / 'sample-b.jpg'))
        other = cv2.resize(other, (round(other.shape[1] * first.shape[0] / other.shape[0]), first.shape[0]))
        path = self.workspace / 'two-people.jpg'
        cv2.imwrite(str(path), np.concatenate([first, other], axis=1))
        self.assertEqual(2, len(self.analyze(path)['faces']))


if __name__ == '__main__':
    unittest.main()
