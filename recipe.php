<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
);

$recipeId = (int)($_GET['id'] ?? 1);

$stmtRecipe = $pdo->prepare("SELECT * FROM recipes WHERE recipe_id = ?");
$stmtRecipe->execute([$recipeId]);
$recipe = $stmtRecipe->fetch(PDO::FETCH_ASSOC);

if (!$recipe) {
    die("Recipe not found. Make sure seed_gyoza.sql has been imported into ryoridb.");
}

$targetServings = isset($_GET['servings']) && (float)$_GET['servings'] > 0
    ? (float)$_GET['servings']
    : (float)$recipe['base_servings'];

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
                GREATEST(
                    round_increment,
                    ROUND(((metric_per_serving * :servings) * to_us_factor) / round_increment) * round_increment
                ),
                2
            )
        END AS scaled_us,
        prep_note
    FROM v_recipe_ingredients_base
    WHERE recipe_id = :recipe_id
    ORDER BY sort_order ASC
");
$stmtItems->execute([
    ':servings'  => $targetServings,
    ':recipe_id' => $recipeId
]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

$stmtSteps = $pdo->prepare("
    SELECT step_number, instruction
    FROM recipe_steps
    WHERE recipe_id = ?
    ORDER BY step_number ASC
");
$stmtSteps->execute([$recipeId]);
$steps = $stmtSteps->fetchAll(PDO::FETCH_ASSOC);

function format_decimal(string|float $val): string {
    return rtrim(rtrim(number_format((float)$val, 2, '.', ''), '0'), '.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($recipe['title']) ?> — ryoridb</title>
<style>
  :root {
    --bg: #121218;
    --card: #1c1b24;
    --text: #eceaf4;
    --muted: #a69ec4;
    --accent: #d982ff;
    --border: #2e2c3d;
  }
  body { background: var(--bg); color: var(--text); font-family: system-ui, sans-serif; padding: 1.5rem; max-width: 760px; margin: auto; }
  .card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 1.25rem; margin-bottom: 1.25rem; }
  ruby { ruby-align: center; font-weight: 600; font-size: 1.15rem; }
  rt { color: var(--accent); font-size: 0.72rem; font-weight: 500; letter-spacing: 0.02em; }
  li { margin-bottom: 0.65rem; line-height: 1.5; }
  .prep { color: var(--muted); font-style: italic; }
  input[type="number"] { background: var(--bg); color: var(--text); border: 1px solid var(--border); border-radius: 6px; padding: 0.35rem 0.5rem; width: 5rem; }
  button { background: var(--accent); color: #121218; font-weight: 600; border: none; border-radius: 6px; padding: 0.4rem 0.85rem; cursor: pointer; }
</style>
</head>
<body>
  <a href="recipes.php" style="color: var(--accent); text-decoration: none;">← All Recipes</a>
  <h1><?= htmlspecialchars($recipe['title']) ?></h1>
  <?php if (!empty($recipe['notes'])): ?>
    <p class="prep"><?= htmlspecialchars($recipe['notes']) ?></p>
  <?php endif; ?>

  <div class="card">
    <form method="get">
      <input type="hidden" name="id" value="<?= $recipeId ?>">
      <label>
        Servings (base <?= format_decimal($recipe['base_servings']) ?>):
        <input type="number" step="0.5" min="0.5" name="servings" value="<?= format_decimal($targetServings) ?>">
      </label>
      <button type="submit">Scale</button>
    </form>
  </div>

  <div class="card">
    <h2>Ingredients</h2>
    <ul>
      <?php foreach ($items as $row): ?>
        <li>
          <ruby>
            <?= format_decimal($row['scaled_metric']) ?> <?= htmlspecialchars($row['metric_display']) ?>
            <?php if (!empty($row['us_code']) && $row['scaled_us'] !== null): ?>
              <rt><?= format_decimal($row['scaled_us']) ?> <?= htmlspecialchars($row['us_code']) ?></rt>
            <?php endif; ?>
          </ruby>
          — <?= htmlspecialchars($row['ingredient_name']) ?>
          <?php if (!empty($row['prep_note'])): ?>
            <span class="prep">(<?= htmlspecialchars($row['prep_note']) ?>)</span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="card">
    <h2>Steps</h2>
    <ol>
      <?php foreach ($steps as $step): ?>
        <li><?= htmlspecialchars($step['instruction']) ?></li>
      <?php endforeach; ?>
    </ol>
  </div>
</body>
</html>
