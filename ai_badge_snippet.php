<?php
/*
 * AI BADGE SNIPPET — donor_dashboard.php
 * ========================================
 * In your donor_dashboard.php, inside the donation card loop,
 * add this RIGHT AFTER the card-chips div to show the AI result.
 *
 * Find this line in donor_dashboard.php:
 *   </div>  ← closing div of card-chips
 *
 * Add immediately after it:
 */
?>

<!-- AI FRESHNESS BADGE — add this after card-chips in donor_dashboard.php -->
<?php if (!empty($d['ai_checked']) && $d['ai_checked'] == 1): ?>
  <?php if ($d['ai_prediction'] === 'fresh'): ?>
  <div style="display:inline-flex;align-items:center;gap:5px;font-size:.73rem;font-weight:600;color:#1B5E20;background:#E8F5E9;padding:3px 10px;border-radius:99px;border:1px solid #C8E6C9;margin-bottom:7px;">
    ✅ AI: Fresh Food · <?= round((float)$d['ai_confidence'], 1) ?>% confidence
  </div>
  <?php elseif ($d['ai_prediction'] === 'rotten'): ?>
  <div style="display:inline-flex;align-items:center;gap:5px;font-size:.73rem;font-weight:600;color:#B71C1C;background:#FFEBEE;padding:3px 10px;border-radius:99px;border:1px solid #FFCDD2;margin-bottom:7px;">
    ❌ AI: Rotten · <?= round((float)$d['ai_confidence'], 1) ?>% confidence
  </div>
  <?php endif; ?>
<?php elseif (!empty($d['image_path'])): ?>
  <!-- Image uploaded but AI not checked (API was down) -->
  <div style="display:inline-flex;align-items:center;gap:5px;font-size:.71rem;color:#8896AB;background:#F5F5F5;padding:3px 10px;border-radius:99px;border:1px solid #E0E0E0;margin-bottom:7px;">
    ❓ AI check pending
  </div>
<?php endif; ?>

<?php
/*
 * Also update your SELECT query in donor_dashboard.php from:
 *   $result = $conn->query("SELECT * FROM donations WHERE donor_id=$uid ORDER BY id DESC");
 * 
 * To (same query works if you ran add_ai_prediction.sql — SELECT * includes new columns):
 *   $result = $conn->query("SELECT * FROM donations WHERE donor_id=$uid ORDER BY id DESC");
 * 
 * No change needed in the query — SELECT * already picks up the new columns.
 *
 *
 * ── HOW TO ADD THIS TO ngo_dashboard.php TOO ──────────────────
 * NGOs can see whether food is AI-verified before accepting.
 * Add the same badge snippet in ngo_dashboard.php inside the
 * available donations card loop, after the card-chips div.
 * Change $d['ai_prediction'] etc — same variable names since
 * the query already selects all columns from donations table.
 */
?>
