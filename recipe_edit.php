<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$units = $pdo->query("SELECT unit_id, metric_code, us_code FROM units ORDER BY unit_id ASC")->fetchAll();
$recipeId = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['recipe_id'] ?? 0);

if ($recipeId <= 0) {
    header('Location: recipes.php');
    exit;
}

$error = '';

$usToMetric = [
    'lb'          => ['factor' => 453.59237, 'display' => 'g',  'unit_id' => 1],
    'lbs'         => ['factor' => 453.59237, 'display' => 'g',  'unit_id' => 1],
    'pound'       => ['factor' => 453.59237, 'display' => 'g',  'unit_id' => 1],
    'pounds'      => ['factor' => 453.59237, 'display' => 'g',  'unit_id' => 1],
    'oz'          => ['factor' => 28.34952,  'display' => 'g',  'unit_id' => 1],
    'ounce'       => ['factor' => 28.34952,  'display' => 'g',  'unit_id' => 1],
    'ounces'      => ['factor' => 28.34952,  'display' => 'g',  'unit_id' => 1],
    'cup'         => ['factor' => 236.58824, 'display' => 'mL', 'unit_id' => 2],
    'cups'        => ['factor' => 236.58824, 'display' => 'mL', 'unit_id' => 2],
    'c'           => ['factor' => 236.58824, 'display' => 'mL', 'unit_id' => 2],
    'floz'        => ['factor' => 29.57353,  'display' => 'mL', 'unit_id' => 2],
    'fluidounce'  => ['factor' => 29.57353,  'display' => 'mL', 'unit_id' => 2],
    'fluidounces' => ['factor' => 29.57353,  'display' => 'mL', 'unit_id' => 2],
    'pt'          => ['factor' => 473.17647, 'display' => 'mL', 'unit_id' => 2],
    'pint'        => ['factor' => 473.17647, 'display' => 'mL', 'unit_id' => 2],
    'pints'       => ['factor' => 473.17647, 'display' => 'mL', 'unit_id' => 2],
    'qt'          => ['factor' => 946.35295, 'display' => 'mL', 'unit_id' => 2],
    'quart'       => ['factor' => 946.35295, 'display' => 'mL', 'unit_id' => 2],
    'quarts'      => ['factor' => 946.35295, 'display' => 'mL', 'unit_id' => 2],
    'gal'         => ['factor' => 3785.41178,'display' => 'mL', 'unit_id' => 2],
    'gallon'      => ['factor' => 3785.41178,'display' => 'mL', 'unit_id' => 2],
    'gallons'     => ['factor' => 3785.41178,'display' => 'mL', 'unit_id' => 2],
    'tsp'         => ['factor' => 4.92892,   'display' => 'mL', 'unit_id' => 3],
    'teaspoon'    => ['factor' => 4.92892,   'display' => 'mL', 'unit_id' => 3],
    'teaspoons'   => ['factor' => 4.92892,   'display' => 'mL', 'unit_id' => 3],
    'tbsp'        => ['factor' => 14.78676,  'display' => 'mL', 'unit_id' => 4],
    'tablespoon'  => ['factor' => 14.78676,  'display' => 'mL', 'unit_id' => 4],
    'tablespoons' => ['factor' => 14.78676,  'display' => 'mL', 'unit_id' => 4],
    'in'          => ['factor' => 2.54,      'display' => 'cm', 'unit_id' => 5],
    'inch'        => ['factor' => 2.54,      'display' => 'cm', 'unit_id' => 5],
    'inches'      => ['factor' => 2.54,      'display' => 'cm', 'unit_id' => 5],
];

$metricScale = [
    'kg' => ['factor' => 1000.0, 'display' => 'g',  'default_unit_id' => 1],
    'l'  => ['factor' => 1000.0, 'display' => 'mL', 'default_unit_id' => 2],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete') {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM recipe_ingredients WHERE recipe_id = :id")->execute([':id' => $recipeId]);
            $pdo->prepare("DELETE FROM recipe_steps WHERE recipe_id = :id")->execute([':id' => $recipeId]);
            $pdo->prepare("DELETE FROM recipes WHERE recipe_id = :id")->execute([':id' => $recipeId]);
            $pdo->commit();
            header('Location: recipes.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Failed to delete recipe: ' . $e->getMessage();
        }
    } else {
        $title        = trim($_POST['title'] ?? '');
        $baseServings = (float)($_POST['base_servings'] ?? 1);
        $prepMinutes  = ($_POST['prep_minutes'] ?? '') !== '' ? (int)$_POST['prep_minutes'] : null;
        $cookMinutes  = ($_POST['cook_minutes'] ?? '') !== '' ? (int)$_POST['cook_minutes'] : null;
        $notes        = trim($_POST['notes'] ?? '') ?: null;

        $ingNames    = $_POST['ing_name'] ?? [];
        $ingQtys     = $_POST['ing_qty'] ?? [];
        $ingDisplays = $_POST['ing_display'] ?? [];
        $ingUnits    = $_POST['ing_unit'] ?? [];
        $ingNotes    = $_POST['ing_note'] ?? [];
        $stepsRaw    = $_POST['steps'] ?? [];

        if ($title === '' || $baseServings <= 0) {
            $error = 'Recipe title and a positive base servings count are required.';
        } elseif (($prepMinutes !== null && ($prepMinutes < 0 || $prepMinutes > 65535)) ||
                  ($cookMinutes !== null && ($cookMinutes < 0 || $cookMinutes > 65535))) {
            $error = 'Prep and cook times must be between 0 and 65,535 minutes.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmtUpdate = $pdo->prepare("
                    UPDATE recipes
                    SET title = :title,
                        base_servings = :base_servings,
                        prep_minutes = :prep_minutes,
                        cook_minutes = :cook_minutes,
                        notes = :notes
                    WHERE recipe_id = :recipe_id
                ");
                $stmtUpdate->execute([
                    ':title'         => $title,
                    ':base_servings' => $baseServings,
                    ':prep_minutes'  => $prepMinutes,
                    ':cook_minutes'  => $cookMinutes,
                    ':notes'         => $notes,
                    ':recipe_id'     => $recipeId,
                ]);

                $pdo->prepare("DELETE FROM recipe_ingredients WHERE recipe_id = :id")->execute([':id' => $recipeId]);
                $pdo->prepare("DELETE FROM recipe_steps WHERE recipe_id = :id")->execute([':id' => $recipeId]);

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

                    $key = strtolower(rtrim(str_replace(' ', '', $display), '.'));
                    if (isset($usToMetric[$key])) {
                        $qty     = round($qty * $usToMetric[$key]['factor'], 3);
                        $display = $usToMetric[$key]['display'];
                        if ($unitVal === null) {
                            $unitVal = $usToMetric[$key]['unit_id'];
                        }
                    } elseif (isset($metricScale[$key])) {
                        $qty     = round($qty * $metricScale[$key]['factor'], 3);
                        $display = $metricScale[$key]['display'];
                        if ($unitVal === null) {
                            $unitVal = $metricScale[$key]['default_unit_id'];
                        }
                    }

                    $stmtIng->execute([
                        ':recipe_id'       => $recipeId,
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
                        ':recipe_id'   => $recipeId,
                        ':step_number' => $stepNum++,
                        ':instruction' => $text,
                    ]);
                }

                $pdo->commit();
                header("Location: recipe.php?id={$recipeId}");
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Failed to update recipe: ' . $e->getMessage();
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== '') {
    $recipe = [
        'title'         => $_POST['title'] ?? '',
        'base_servings' => $_POST['base_servings'] ?? '4',
        'prep_minutes'  => $_POST['prep_minutes'] ?? '',
        'cook_minutes'  => $_POST['cook_minutes'] ?? '',
        'notes'         => $_POST['notes'] ?? '',
    ];
    $ingredients = [];
    foreach (($_POST['ing_name'] ?? []) as $i => $name) {
        $ingredients[] = [
            'ingredient_name' => $name,
            'metric_qty'      => $_POST['ing_qty'][$i] ?? '',
            'metric_display'  => $_POST['ing_display'][$i] ?? '',
            'unit_id'         => $_POST['ing_unit'][$i] ?? '',
            'prep_note'       => $_POST['ing_note'][$i] ?? '',
        ];
    }
    $steps = [];
    foreach (($_POST['steps'] ?? []) as $text) {
        $steps[] = ['instruction' => $text];
    }
} else {
    $stmtR = $pdo->prepare("SELECT * FROM recipes WHERE recipe_id = :id");
    $stmtR->execute([':id' => $recipeId]);
    $recipe = $stmtR->fetch();
    if (!$recipe) {
        header('Location: recipes.php');
        exit;
    }

    $stmtI = $pdo->prepare("SELECT * FROM recipe_ingredients WHERE recipe_id = :id ORDER BY sort_order ASC");
    $stmtI->execute([':id' => $recipeId]);
    $ingredients = $stmtI->fetchAll();

    $stmtS = $pdo->prepare("SELECT * FROM recipe_steps WHERE recipe_id = :id ORDER BY step_number ASC");
    $stmtS->execute([':id' => $recipeId]);
    $steps = $stmtS->fetchAll();
}

if (empty($ingredients)) {
    $ingredients = [['ingredient_name' => '', 'metric_qty' => '', 'metric_display' => '', 'unit_id' => '', 'prep_note' => '']];
}
if (empty($steps)) {
    $steps = [['instruction' => '']];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Edit <?= htmlspecialchars((string)$recipe['title']) ?> — ryoridb</title>
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
  .top-nav { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
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
  .btn-delete { background: transparent; color: var(--danger); border: 1px solid var(--danger); }
  .actions { display: flex; justify-content: space-between; align-items: center; }
  .error { background: #2d1822; border: 1px solid var(--danger); color: #ffb3c4; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; }
</style>
</head>
<body>
  <div class="top-nav">
    <a href="recipe.php?id=<?= $recipeId ?>">← Back to Recipe</a>
    <a href="recipes.php">All Recipes</a>
  </div>
  <h1>Edit Recipe</h1>

  <?php if ($error !== ''): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="recipe_id" value="<?= $recipeId ?>">
    <div class="card">
      <h2>Details</h2>
      <div class="grid-meta">
        <div>
          <label>Title *</label>
          <input type="text" name="title" required value="<?= htmlspecialchars((string)$recipe['title']) ?>">
        </div>
        <div>
          <label>Base Servings *</label>
          <input type="number" step="0.5" min="0.5" name="base_servings" required value="<?= htmlspecialchars((string)(float)$recipe['base_servings']) ?>">
        </div>
        <div>
          <label>Prep (min)</label>
          <input type="number" min="0" max="65535" name="prep_minutes" value="<?= htmlspecialchars((string)($recipe['prep_minutes'] ?? '')) ?>">
        </div>
        <div>
          <label>Cook (min)</label>
          <input type="number" min="0" max="65535" name="cook_minutes" value="<?= htmlspecialchars((string)($recipe['cook_minutes'] ?? '')) ?>">
        </div>
      </div>
      <div>
        <label>Notes</label>
        <textarea name="notes" rows="2"><?= htmlspecialchars((string)($recipe['notes'] ?? '')) ?></textarea>
      </div>
    </div>

    <div class="card">
      <h2>Ingredients</h2>
      <div id="ingredients-list">
        <?php foreach ($ingredients as $ing): ?>
          <?php $qtyDisplay = ($ing['metric_qty'] !== '' && $ing['metric_qty'] !== null) ? (string)(float)$ing['metric_qty'] : ''; ?>
          <div class="ing-row">
            <input type="text" name="ing_name[]" placeholder="Ingredient" value="<?= htmlspecialchars((string)$ing['ingredient_name']) ?>">
            <input type="number" step="0.001" min="0" name="ing_qty[]" placeholder="Qty" value="<?= htmlspecialchars($qtyDisplay) ?>">
            <input type="text" name="ing_display[]" placeholder="Unit (g, mL)" value="<?= htmlspecialchars((string)$ing['metric_display']) ?>">
            <select name="ing_unit[]">
              <option value="">No US furigana</option>
              <?php foreach ($units as $u): ?>
                <option value="<?= (int)$u['unit_id'] ?>" <?= ((string)$ing['unit_id'] === (string)$u['unit_id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($u['metric_code']) ?> → <?= htmlspecialchars($u['us_code']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="ing_note[]" placeholder="Prep note" value="<?= htmlspecialchars((string)($ing['prep_note'] ?? '')) ?>">
            <button type="button" class="btn-remove" onclick="this.parentElement.remove()">✕</button>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn-secondary" onclick="addIngredient()">+ Add Ingredient</button>
    </div>

    <div class="card">
      <h2>Steps</h2>
      <div id="steps-list">
        <?php foreach ($steps as $st): ?>
          <div class="step-row">
            <textarea name="steps[]" rows="2" placeholder="Step instruction..."><?= htmlspecialchars((string)$st['instruction']) ?></textarea>
            <button type="button" class="btn-remove" onclick="this.parentElement.remove()">✕</button>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn-secondary" onclick="addStep()">+ Add Step</button>
    </div>

    <div class="actions">
      <button type="submit" name="action" value="save">Save Changes</button>
      <button type="submit" name="action" value="delete" class="btn-delete" onclick="return confirm('Delete this recipe permanently?');">Delete Recipe</button>
    </div>
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

let isDirty = false;
document.querySelector('form').addEventListener('input', () => { isDirty = true; });
document.querySelector('form').addEventListener('click', (e) => {
  if (e.target.tagName === 'BUTTON' && e.target.type !== 'submit') {
    isDirty = true;
  }
});
document.querySelector('form').addEventListener('submit', () => { isDirty = false; });
window.addEventListener('beforeunload', (e) => {
  if (isDirty) {
    e.preventDefault();
    e.returnValue = '';
  }
});
</script>
</body>
</html>
