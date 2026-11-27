<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$recipeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($recipeId <= 0) {
    header('Location: recipes.php');
    exit;
}

$stmtRecipe = $pdo->prepare("
    SELECT recipe_id, title, base_servings, prep_minutes, cook_minutes, notes
    FROM recipes
    WHERE recipe_id = :id
");
$stmtRecipe->execute([':id' => $recipeId]);
$recipe = $stmtRecipe->fetch();

if (!$recipe) {
    header('Location: recipes.php');
    exit;
}

$baseServings = (float)$recipe['base_servings'] > 0 ? (float)$recipe['base_servings'] : 1.0;
$servings = isset($_GET['servings']) && (float)$_GET['servings'] > 0
    ? (float)$_GET['servings']
    : $baseServings;

$stmtItems = $pdo->prepare("
    SELECT
        sort_order,
        ingredient_name,
        ROUND(metric_per_serving * :servings, 1) AS scaled_metric,
        metric_display,
        us_code,
        CASE
            WHEN to_us_factor IS NULL THEN NULL
            ELSE ROUND(
                ROUND(((metric_per_serving * :servings) * to_us_factor) / round_increment) * round_increment,
                2
            )
        END AS scaled_us,
        prep_note
    FROM v_recipe_ingredients_base
    WHERE recipe_id = :recipe_id
    ORDER BY sort_order ASC
");
$stmtItems->execute([
    ':servings'  => $servings,
    ':recipe_id' => $recipeId,
]);
$ingredients = $stmtItems->fetchAll();

$stmtSteps = $pdo->prepare("
    SELECT step_number, instruction
    FROM recipe_steps
    WHERE recipe_id = :recipe_id
    ORDER BY step_number ASC
");
$stmtSteps->execute([':recipe_id' => $recipeId]);
$steps = $stmtSteps->fetchAll();

function fmtNum(float $val, int $decimals = 1): string
{
    $formatted = number_format($val, $decimals, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars((string)$recipe['title']) ?> — ryoridb</title>
<style>
  :root {
    --bg: #121218;
    --card: #1c1b24;
    --text: #eceaf4;
    --muted: #a69ec4;
    --accent: #d982ff;
    --border: #2e2c3d;
  }
  body { background: var(--bg); color: var(--text); font-family: system-ui, sans-serif; padding: 1.5rem; max-width: 880px; margin: auto; line-height: 1.5; }
  a { color: var(--accent); text-decoration: none; }
  .top-nav { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
  h1 { margin: 0 0 0.35rem; font-size: 2rem; }
  .meta { color: var(--muted); font-size: 0.92rem; margin: 0 0 0.5rem; }
  .notes { color: var(--muted); font-style: italic; margin: 0 0 1.25rem; }
  .card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 1.25rem; margin-bottom: 1.25rem; }
  h2 { margin: 0 0 0.85rem; font-size: 1.25rem; }
  .scale-form { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; }
  .scale-form label { color: var(--muted); font-size: 0.95rem; }
  .scale-form input { width: 5.5rem; background: var(--bg); color: var(--text); border: 1px solid var(--border); border-radius: 6px; padding: 0.4rem 0.6rem; font-size: 0.95rem; }
  button { background: var(--accent); color: #121218; font-weight: 600; border: none; border-radius: 6px; padding: 0.45rem 0.9rem; cursor: pointer; }
  ul.ing-list { list-style: none; padding: 0; margin: 0; }
  ul.ing-list li { display: flex; align-items: baseline; gap: 0.5rem; margin-bottom: 0.85rem; }
  .bullet { color: var(--text); font-weight: bold; }
  .qty-stack { display: inline-flex; flex-direction: column; align-items: center; line-height: 1.15; }
  .furigana { font-size: 0.72rem; color: var(--accent); }
  .metric-main { font-weight: 700; color: var(--text); }
  .prep-note { color: var(--muted); font-style: italic; }
  ol.step-list { padding-left: 1.35rem; margin: 0; }
  ol.step-list li { margin-bottom: 0.7rem; }
</style>
</head>
<body>
  <div class="top-nav">
    <a href="recipes.php">← All Recipes</a>
    <a href="recipe_edit.php?id=<?= (int)$recipeId ?>">Edit Recipe</a>
  </div>

  <h1><?= htmlspecialchars((string)$recipe['title']) ?></h1>

  <?php if ($recipe['prep_minutes'] !== null || $recipe['cook_minutes'] !== null): ?>
    <p class="meta">
      <?php if ($recipe['prep_minutes'] !== null): ?>
        Prep: <?= (int)$recipe['prep_minutes'] ?> min
      <?php endif; ?>
      <?php if ($recipe['prep_minutes'] !== null && $recipe['cook_minutes'] !== null): ?>
        ·
      <?php endif; ?>
      <?php if ($recipe['cook_minutes'] !== null): ?>
        Cook: <?= (int)$recipe['cook_minutes'] ?> min
      <?php endif; ?>
    </p>
  <?php endif; ?>

  <?php if (!empty($recipe['notes'])): ?>
    <p class="notes"><?= htmlspecialchars((string)$recipe['notes']) ?></p>
  <?php endif; ?>

  <div class="card">
    <form method="get" class="scale-form">
      <input type="hidden" name="id" value="<?= (int)$recipeId ?>">
      <label for="servings">Servings (base <?= htmlspecialchars(fmtNum($baseServings, 2)) ?>):</label>
      <input id="servings" type="number" step="0.5" min="0.5" name="servings" value="<?= htmlspecialchars(fmtNum($servings, 2)) ?>">
      <button type="submit">Scale</button>
    </form>
  </div>

  <div class="card">
    <h2>Ingredients</h2>
    <ul class="ing-list">
      <?php foreach ($ingredients as $item): ?>
        <?php
          $metricNum = (float)$item['scaled_metric'];
          $metricDisp = trim((string)$item['metric_display']);
          $usNum = $item['scaled_us'] !== null ? (float)$item['scaled_us'] : null;
          $usCode = trim((string)($item['us_code'] ?? ''));
          $prepNote = trim((string)($item['prep_note'] ?? ''));
        ?>
        <li>
          <span class="bullet">•</span>
          <span class="qty-stack">
            <?php if ($usNum !== null && $usCode !== ''): ?>
              <span class="furigana"><?= htmlspecialchars(fmtNum($usNum, 2) . ' ' . $usCode) ?></span>
            <?php endif; ?>
            <span class="metric-main">
              <?= htmlspecialchars(fmtNum($metricNum, 1) . ($metricDisp !== '' ? ' ' . $metricDisp : '')) ?>
            </span>
          </span>
          <span>
            — <?= htmlspecialchars((string)$item['ingredient_name']) ?>
            <?php if ($prepNote !== ''): ?>
              <span class="prep-note">(<?= htmlspecialchars($prepNote) ?>)</span>
            <?php endif; ?>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="card">
    <h2>Steps</h2>
    <ol class="step-list">
      <?php foreach ($steps as $step): ?>
        <li><?= htmlspecialchars((string)$step['instruction']) ?></li>
      <?php endforeach; ?>
    </ol>
  </div>
</body>
</html>
