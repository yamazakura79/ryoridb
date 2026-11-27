<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$units = $pdo->query("SELECT unit_id, metric_code, us_code FROM units ORDER BY unit_id ASC")->fetchAll();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title        = trim($_POST['title'] ?? '');
    $baseServings = (float)($_POST['base_servings'] ?? 1);
    $prepMinutes  = $_POST['prep_minutes'] !== '' ? (int)$_POST['prep_minutes'] : null;
    $cookMinutes  = $_POST['cook_minutes'] !== '' ? (int)$_POST['cook_minutes'] : null;
    $notes        = trim($_POST['notes'] ?? '') ?: null;

    $ingNames    = $_POST['ing_name'] ?? [];
    $ingQtys     = $_POST['ing_qty'] ?? [];
    $ingDisplays = $_POST['ing_display'] ?? [];
    $ingUnits    = $_POST['ing_unit'] ?? [];
    $ingNotes    = $_POST['ing_note'] ?? [];
    $stepsRaw    = $_POST['steps'] ?? [];

    if ($title === '' || $baseServings <= 0) {
        $error = 'Recipe title and a positive base servings count are required.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmtRecipe = $pdo->prepare("
                INSERT INTO recipes (title, base_servings, prep_minutes, cook_minutes, notes)
                VALUES (:title, :base_servings, :prep_minutes, :cook_minutes, :notes)
            ");
            $stmtRecipe->execute([
                ':title'         => $title,
                ':base_servings' => $baseServings,
                ':prep_minutes'  => $prepMinutes,
                ':cook_minutes'  => $cookMinutes,
                ':notes'         => $notes,
            ]);
            $newRecipeId = (int)$pdo->lastInsertId();

            $stmtIng = $pdo->prepare("
                INSERT INTO recipe_ingredients
                    (recipe_id, sort_order, ingredient_name, metric_qty, metric_display, unit_id, prep_note)
                VALUES
                    (:recipe_id, :sort_order, :ingredient_name, :metric_qty, :metric_display, :unit_id, :prep_note)
            ");

            $sortOrder = 1;
            foreach ($ingNames as $i => $rawName) {
                $name = trim((string)$rawName);
                $qty  = (float)($ingQtys[$i] ?? 0);
                if ($name === '' || $qty <= 0) {
                    continue;
                }
                $display = trim((string)($ingDisplays[$i] ?? ''));
                $unitVal = ($ingUnits[$i] ?? '') !== '' ? (int)$ingUnits[$i] : null;
                $noteVal = trim((string)($ingNotes[$i] ?? '')) ?: null;

                $stmtIng->execute([
                    ':recipe_id'       => $newRecipeId,
                    ':sort_order'      => $sortOrder++,
                    ':ingredient_name' => $name,
                    ':metric_qty'      => $qty,
                    ':metric_display'  => $display,
                    ':unit_id'         => $unitVal,
                    ':prep_note'       => $noteVal,
                ]);
            }

            $stmtStep = $pdo->prepare("
                INSERT INTO recipe_steps (recipe_id, step_number, instruction)
                VALUES (:recipe_id, :step_number, :instruction)
            ");

            $stepNum = 1;
            foreach ($stepsRaw as $rawStep) {
                $text = trim((string)$rawStep);
                if ($text === '') {
                    continue;
                }
                $stmtStep->execute([
                    ':recipe_id'   => $newRecipeId,
                    ':step_number' => $stepNum++,
                    ':instruction' => $text,
                ]);
            }

            $pdo->commit();
            header("Location: recipe.php?id={$newRecipeId}");
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Failed to save recipe: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Add Recipe — ryoridb</title>
<style>
  :root {
    --bg: #121218;
    --card: #1c1b24;
    --text: #eceaf4;
    --muted: #a69ec4;
    --accent: #d982ff;
    --border: #2e2c3d;
    --danger: #ff6b8b;
  }
  body { background: var(--bg); color: var(--text); font-family: system-ui, sans-serif; padding: 1.5rem; max-width: 880px; margin: auto; }
  a { color: var(--accent); text-decoration: none; }
  .card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 1.25rem; margin-bottom: 1.25rem; }
  .grid-meta { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 0.75rem; margin-bottom: 0.75rem; }
  .ing-row { display: grid; grid-template-columns: 2fr 0.8fr 0.9fr 1.3fr 1.6fr auto; gap: 0.5rem; margin-bottom: 0.5rem; align-items: center; }
  .step-row { display: flex; gap: 0.5rem; margin-bottom: 0.5rem; align-items: flex-start; }
  label { display: block; font-size: 0.82rem; color: var(--muted); margin-bottom: 0.25rem; }
  input, select, textarea { width: 100%; box-sizing: border-box; background: var(--bg); color: var(--text); border: 1px solid var(--border); border-radius: 6px; padding: 0.45rem 0.6rem; font-size: 0.95rem; }
  textarea { resize: vertical; min-height: 2.6rem; }
  button { background: var(--accent); color: #121218; font-weight: 600; border: none; border-radius: 6px; padding: 0.5rem 0.9rem; cursor: pointer; }
  .btn-secondary { background: var(--bg); color: var(--accent); border: 1px solid var(--accent); }
  .btn-remove { background: transparent; color: var(--danger); border: 1px solid var(--border); padding: 0.4rem 0.65rem; }
  .error { background: #2d1822; border: 1px solid var(--danger); color: #ffb3c4; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; }
</style>
</head>
<body>
  <p><a href="recipes.php">← All Recipes</a></p>
  <h1>Add New Recipe</h1>

  <?php if ($error !== ''): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post">
    <div class="card">
      <h2>Details</h2>
      <div class="grid-meta">
        <div>
          <label>Title *</label>
          <input type="text" name="title" required placeholder="e.g., Chicken Tikka Masala">
        </div>
        <div>
          <label>Base Servings *</label>
          <input type="number" step="0.5" min="0.5" name="base_servings" value="4" required>
        </div>
        <div>
          <label>Prep (min)</label>
          <input type="number" min="0" name="prep_minutes" placeholder="20">
        </div>
        <div>
          <label>Cook (min)</label>
          <input type="number" min="0" name="cook_minutes" placeholder="30">
        </div>
      </div>
      <div>
        <label>Notes</label>
        <textarea name="notes" rows="2" placeholder="Yield notes, storage tips, or variations..."></textarea>
      </div>
    </div>

    <div class="card">
      <h2>Ingredients</h2>
      <div id="ingredients-list">
        <div class="ing-row">
          <input type="text" name="ing_name[]" placeholder="Ingredient (e.g., Chicken breast)">
          <input type="number" step="0.001" min="0" name="ing_qty[]" placeholder="Qty">
          <input type="text" name="ing_display[]" placeholder="Unit (g, mL)">
          <select name="ing_unit[]">
            <option value="">No US furigana</option>
            <?php foreach ($units as $u): ?>
              <option value="<?= (int)$u['unit_id'] ?>"><?= htmlspecialchars($u['metric_code']) ?> → <?= htmlspecialchars($u['us_code']) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="ing_note[]" placeholder="Prep note (diced)">
          <button type="button" class="btn-remove" onclick="this.parentElement.remove()">✕</button>
        </div>
      </div>
      <button type="button" class="btn-secondary" onclick="addIngredient()">+ Add Ingredient</button>
    </div>

    <div class="card">
      <h2>Steps</h2>
      <div id="steps-list">
        <div class="step-row">
          <textarea name="steps[]" rows="2" placeholder="Step instruction..."></textarea>
          <button type="button" class="btn-remove" onclick="this.parentElement.remove()">✕</button>
        </div>
      </div>
      <button type="button" class="btn-secondary" onclick="addStep()">+ Add Step</button>
    </div>

    <button type="submit">Save Recipe</button>
  </form>

<script>
const unitOptions = `
  <option value="">No US furigana</option>
  <?php foreach ($units as $u): ?>
    <option value="<?= (int)$u['unit_id'] ?>"><?= htmlspecialchars($u['metric_code']) ?> → <?= htmlspecialchars($u['us_code']) ?></option>
  <?php endforeach; ?>
`;

function addIngredient() {
  const row = document.createElement('div');
  row.className = 'ing-row';
  row.innerHTML = `
    <input type="text" name="ing_name[]" placeholder="Ingredient">
    <input type="number" step="0.001" min="0" name="ing_qty[]" placeholder="Qty">
    <input type="text" name="ing_display[]" placeholder="Unit (g, mL)">
    <select name="ing_unit[]">${unitOptions}</select>
    <input type="text" name="ing_note[]" placeholder="Prep note">
    <button type="button" class="btn-remove" onclick="this.parentElement.remove()">✕</button>
  `;
  document.getElementById('ingredients-list').appendChild(row);
}

function addStep() {
  const row = document.createElement('div');
  row.className = 'step-row';
  row.innerHTML = `
    <textarea name="steps[]" rows="2" placeholder="Step instruction..."></textarea>
    <button type="button" class="btn-remove" onclick="this.parentElement.remove()">✕</button>
  `;
  document.getElementById('steps-list').appendChild(row);
}
</script>
</body>
</html>
