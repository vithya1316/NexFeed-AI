"""
food_api.py — NEXFEEDAI Food Freshness AI
"""

import sys
print("RUNNING PYTHON:", sys.executable)

import os
# ✅ REQUIRED for TF 2.15 — must be set BEFORE importing tensorflow
os.environ["TF_USE_LEGACY_KERAS"] = "1"

import io
import numpy as np
from flask import Flask, request, jsonify
import tensorflow as tf
from PIL import Image

app = Flask(__name__)

# ── Load model ────────────────────────────────
MODEL_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "food_model.keras")

print("Loading food freshness model...")
model = None
try:
    model = tf.keras.models.load_model(MODEL_PATH, compile=False)
    print("✅ Model loaded successfully.")
    print("   Input shape:", model.input_shape)
except Exception as e:
    print(f"❌ Failed to load model: {e}")
    print(f"   Make sure food_model.keras is in: {os.path.dirname(MODEL_PATH)}")


@app.route('/', methods=['GET'])
def home():
    return jsonify({
        'status': 'running',
        'model_loaded': model is not None,
        'tensorflow': tf.__version__
    })


@app.route('/predict', methods=['POST'])
def predict():
    if model is None:
        return jsonify({'error': 'Model not loaded. Run train_model.py first.'}), 500

    if 'file' not in request.files:
        return jsonify({'error': 'No file field in request'}), 400

    file = request.files['file']
    if file.filename == '':
        return jsonify({'error': 'Empty filename'}), 400

    allowed = {'jpg', 'jpeg', 'png', 'webp', 'gif'}
    ext = file.filename.rsplit('.', 1)[-1].lower() if '.' in file.filename else ''
    if ext not in allowed:
        return jsonify({'error': f'File type .{ext} not supported'}), 400

    try:
        img_bytes = file.read()
        img = Image.open(io.BytesIO(img_bytes)).convert('RGB')
        img = img.resize((224, 224))
        img_array = np.array(img, dtype=np.float32) / 255.0
        img_array = np.expand_dims(img_array, axis=0)
    except Exception as e:
        return jsonify({'error': f'Image processing failed: {str(e)}'}), 400

    try:
        raw_prediction = model.predict(img_array, verbose=0)
        confidence_raw = float(raw_prediction[0][0])

        is_unlikely_safe = confidence_raw > 0.5
        prediction = "not_recommended" if is_unlikely_safe else "likely_safe"
        confidence = confidence_raw if is_unlikely_safe else (1.0 - confidence_raw)
        confidence_pct = round(confidence * 100, 1)

        if prediction == "likely_safe":
            label   = "✅ Likely Safe"
            message = f"This food appears suitable for donation ({confidence_pct}% confidence)."
        else:
            label   = "⚠️ Not Recommended"
            message = f"This food may not be suitable for donation ({confidence_pct}% confidence). Please verify manually."

        return jsonify({
            'prediction':     prediction,
            'label':          label,
            'confidence':     confidence_pct,
            'confidence_raw': confidence_raw,
            'message':        message,
            'safe':           prediction == "likely_safe"
        })

    except Exception as e:
        return jsonify({'error': f'Prediction failed: {str(e)}'}), 500


@app.route('/status', methods=['GET'])
def status():
    return jsonify({'alive': True, 'model_loaded': model is not None})


if __name__ == '__main__':
    print("\n" + "="*50)
    print("  NEXFEEDAI Food Freshness API")
    print("  Running at: http://localhost:5000")
    print("  Press Ctrl+C to stop")
    print("="*50 + "\n")
    app.run(host='0.0.0.0', port=5000, debug=False)
