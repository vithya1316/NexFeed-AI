

import os


import numpy as np
import matplotlib.pyplot as plt
import keras
import tensorflow as tf
print(f"TensorFlow version: {tf.__version__}")
print(f"Keras version: {keras.__version__}")




# ── Config ────────────────────────────────────────────────────
DATASET_DIR   = os.path.join(os.path.dirname(__file__), "dataset")
OUTPUT_MODEL  = os.path.join(os.path.dirname(__file__), "food_model.keras")
IMAGE_SIZE    = (224, 224)    # MobileNetV2 input size
BATCH_SIZE    = 32
EPOCHS_FROZEN = 10            # Train only the new head first
EPOCHS_FINE   = 10            # Then fine-tune last layers of MobileNetV2
LEARNING_RATE = 1e-4

# ── Validate dataset ──────────────────────────────────────────
if not os.path.isdir(DATASET_DIR):
    print(f"\n❌ Dataset not found at: {DATASET_DIR}")
    print("   Create these folders and add images:")
    print(f"   {DATASET_DIR}/fresh/")
    print(f"   {DATASET_DIR}/rotten/")
    exit(1)

for cls in ['fresh', 'rotten']:
    cls_path = os.path.join(DATASET_DIR, cls)
    if not os.path.isdir(cls_path):
        print(f"❌ Missing folder: {cls_path}")
        exit(1)
    count = len([f for f in os.listdir(cls_path) if f.lower().endswith(('.jpg','.jpeg','.png','.webp'))])
    print(f"   Class '{cls}': {count} images")
    if count < 50:
        print(f"   ⚠️  Warning: Only {count} images for '{cls}'. Recommend 200+.")

# ── Data loading ──────────────────────────────────────────────
# Keras ImageDataGenerator handles augmentation + train/val split
datagen = keras.preprocessing.image.ImageDataGenerator(
    rescale=1.0 / 255.0,         # normalize to [0, 1]
    validation_split=0.2,        # 80% train, 20% validation
    rotation_range=20,           # augmentation
    width_shift_range=0.1,
    height_shift_range=0.1,
    horizontal_flip=True,
    zoom_range=0.1,
    brightness_range=[0.85, 1.15],
)

train_gen = datagen.flow_from_directory(
    DATASET_DIR,
    target_size=IMAGE_SIZE,
    batch_size=BATCH_SIZE,
    class_mode='binary',   # fresh=0, rotten=1 (or vice versa — see note below)
    subset='training',
    shuffle=True,
    seed=42,
)

val_gen = datagen.flow_from_directory(
    DATASET_DIR,
    target_size=IMAGE_SIZE,
    batch_size=BATCH_SIZE,
    class_mode='binary',
    subset='validation',
    shuffle=False,
    seed=42,
)

# Print class mapping — IMPORTANT for food_api.py logic
print(f"\n📊 Class indices: {train_gen.class_indices}")
# Typically: {'fresh': 0, 'rotten': 1}
# This means model output close to 0 = fresh, close to 1 = rotten
# food_api.py already handles this: confidence_raw > 0.5 → not_recommended

# ── Build model ───────────────────────────────────────────────
print("\n🔨 Building model (MobileNetV2 + custom head)...")

# Load MobileNetV2 backbone (pretrained on ImageNet, no top layers)
base_model = keras.applications.MobileNetV2(
    input_shape=(*IMAGE_SIZE, 3),
    include_top=False,     # remove ImageNet classifier
    weights='imagenet',    # use pretrained weights
)
base_model.trainable = False   # freeze backbone initially

# Add custom classification head
inputs = keras.Input(shape=(*IMAGE_SIZE, 3))
x = base_model(inputs, training=False)
x = keras.layers.GlobalAveragePooling2D()(x)
x = keras.layers.Dropout(0.3)(x)
x = keras.layers.Dense(128, activation='relu')(x)
x = keras.layers.Dropout(0.2)(x)
outputs = keras.layers.Dense(1, activation='sigmoid')(x)   # 0=fresh, 1=rotten

model = keras.Model(inputs, outputs)

model.compile(
    optimizer=keras.optimizers.Adam(LEARNING_RATE),
    loss='binary_crossentropy',
    metrics=['accuracy'],
)

print(f"\n📐 Model input shape: {model.input_shape}")
print(f"   Trainable params (head only): {sum(w.numpy().size for w in model.trainable_weights):,}")

# ── Phase 1: Train head only ──────────────────────────────────
print(f"\n🚀 Phase 1: Training head only ({EPOCHS_FROZEN} epochs)...")

callbacks = [
    keras.callbacks.EarlyStopping(patience=4, restore_best_weights=True, monitor='val_accuracy'),
    keras.callbacks.ReduceLROnPlateau(factor=0.5, patience=2, monitor='val_loss'),
]

history1 = model.fit(
    train_gen,
    validation_data=val_gen,
    epochs=EPOCHS_FROZEN,
    callbacks=callbacks,
    verbose=1,
)

# ── Phase 2: Fine-tune last 30 layers of backbone ────────────
print(f"\n🔧 Phase 2: Fine-tuning backbone (last 30 layers, {EPOCHS_FINE} epochs)...")

base_model.trainable = True
for layer in base_model.layers[:-30]:
    layer.trainable = False

model.compile(
    optimizer=keras.optimizers.Adam(LEARNING_RATE / 10),   # lower LR for fine-tuning
    loss='binary_crossentropy',
    metrics=['accuracy'],
)

history2 = model.fit(
    train_gen,
    validation_data=val_gen,
    epochs=EPOCHS_FINE,
    callbacks=callbacks,
    verbose=1,
)

# ── Evaluate ──────────────────────────────────────────────────
print("\n📊 Final evaluation on validation set:")
loss, acc = model.evaluate(val_gen, verbose=0)
print(f"   Accuracy: {acc * 100:.1f}%")
print(f"   Loss:     {loss:.4f}")

if acc < 0.75:
    print("   ⚠️  Accuracy below 75%. Add more images and retrain.")
elif acc < 0.85:
    print("   ✅ Acceptable. Consider adding more data.")
else:
    print("   🎉 Good accuracy!")

# ── Save model ────────────────────────────────────────────────
print(f"\n💾 Saving model to: {OUTPUT_MODEL}")
model.save(OUTPUT_MODEL)
print("✅ Model saved!")

# ── Plot training history ─────────────────────────────────────
all_acc  = history1.history['accuracy']  + history2.history['accuracy']
all_val  = history1.history['val_accuracy'] + history2.history['val_accuracy']
all_loss = history1.history['loss'] + history2.history['loss']
all_vloss= history1.history['val_loss'] + history2.history['val_loss']

fig, axes = plt.subplots(1, 2, figsize=(12, 4))
axes[0].plot(all_acc,  label='Train Accuracy')
axes[0].plot(all_val,  label='Val Accuracy')
axes[0].set_title('Accuracy')
axes[0].legend()
axes[0].axvline(x=EPOCHS_FROZEN, color='gray', linestyle='--', label='Fine-tune start')

axes[1].plot(all_loss,  label='Train Loss')
axes[1].plot(all_vloss, label='Val Loss')
axes[1].set_title('Loss')
axes[1].legend()
axes[1].axvline(x=EPOCHS_FROZEN, color='gray', linestyle='--')

plt.tight_layout()
plt.savefig('training_report.png', dpi=100)
print("📈 Training plot saved to training_report.png")


