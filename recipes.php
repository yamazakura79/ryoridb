<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
);

$q = trim($_GET['q'] ?? '');

$sql = "
    SELECT
        r.recipe_id,
        r.title,
        r.base_servings,
        r.prep_minutes,
        r.cook_minutes,
        r.notes,
        COUNT(DISTINCT ri.item_id) AS ingredient_count,
        COUNT(DISTINCT rs.step_id) AS step_count
    FROM recipes AS r
    LEFT JOIN recipe_ingredients AS ri
        ON ri.recipe_id = r.recipe_id
    LEFT JOIN recipe_steps AS rs
        ON rs.recipe_id = r.recipe_id
";

$params = [];
if ($q !== '') {
    $sql .= "
        WHERE r.title LIKE :q
           OR r.notes LIKE :q
           OR ri.ingredient_name LIKE :q
    ";
    $params[':q'] = '%' . $q . '%';
}

$sql .= "
    GROUP BY r.recipe_id
    ORDER BY r.title ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$recipes = $stmt->fetchAll(PDO::FETCH_ASSOC);

function format_decimal(string|float $val): string {
    return rtrim(rtrim(number_format((float)$val, 2, '.', ''), '0'), '.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Recipes — ryoridb</title>
<style>
  :root {
    --bg: #121218;
    --card: #1c1b24;
    --card-hover: #242230;
    --text: #eceaf4;
    --muted: #a69ec4;
    --accent: #d982ff;
    --border: #2e2c3d;
  }
  body { background: var(--bg); color: var(--text); font-family: system-ui, sans-serif; padding: 1.5rem; max-width: 820px; margin: auto; }
  header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 1.25rem; }
  h1 { margin: 0; font-size: 1.75rem; }
  .subtitle { color: var(--muted); font-size: 0.9rem; }
  .search-bar { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; }
  input[type="search"] { flex: 1; background: var(--card); color: var(--text); border: 1px solid var(--border); border-radius: 8px; padding: 0.55rem 0.85rem; font-size: 1rem; }
  button, .btn-clear { background: var(--accent); color: #121218; font-weight: 600; border: none; border-radius: 8px; padding: 0.55rem 1rem; cursor: pointer; text-decoration: none; font-size: 0.95rem; }
  .btn-clear { background: var(--card); color: var(--muted); border: 1px solid var(--border); }
  .recipe-card { display: block; background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 1.15rem 1.25rem; margin-bottom: 1rem; text-decoration: none; color: inherit; transition: background 0.15s, border-color 0.15s; }
  .recipe-card:hover { background: var(--card-hover); border-color: var(--accent); }
  .recipe-title { font-size: 1.2rem; font-weight: 600; color: var(--accent); margin: 0 0 0.4rem 0; }
  .meta { display: flex; flex-wrap: wrap; gap: 0.85rem; font-size: 0.85rem; color: var(--muted); margin-bottom: 0.45rem; }
  .notes { margin: 0; font-size: 0.92rem; color: var(--text); opacity: 0.88; }
</style>
</head>
<body>
  <header>
    <h1>ryoridb</h1>
    <span class="subtitle"><?= count($recipes) ?> recipe<?= count($recipes) === 1 ? '' : 's' ?></span>
  </header>

  <form class="search-bar" method="get">
    <input type="search" name="q" placeholder="Search recipes or ingredients (e.g., gyoza, pork, ginger)..." value="<?= htmlspecialchars($q) ?>">
    <button type="submit">Search</button>
    <?php if ($q !== ''): ?>
      <a class="btn-clear" href="recipes.php">Clear</a>
    <?php endif; ?>
  </form>

  <?php if (empty($recipes)): ?>
    <p class="subtitle">No matching recipes found.</p>
  <?php else: ?>
    <?php foreach ($recipes as $r): ?>
      <?php
        $totalMin = (int)($r['prep_minutes'] ?? 0) + (int)($r['cook_minutes'] ?? 0);
      ?>
      <a class="recipe-card" href="recipe.php?id=<?= (int)$r['recipe_id'] ?>">
        <h2 class="recipe-title"><?= htmlspecialchars($r['title']) ?></h2>
        <div class="meta">
          <span>Base: <?= format_decimal($r['base_servings']) ?> servings</span>
          <?php if ($totalMin > 0): ?>
            <span>Total: <?= $totalMin ?> min (<?= (int)$r['prep_minutes'] ?> prep / <?= (int)$r['cook_minutes'] ?> cook)</span>
          <?php endif; ?>
          <span><?= (int)$r['ingredient_count'] ?> ingredients · <?= (int)$r['step_count'] ?> steps</span>
        </div>
        <?php if (!empty($r['notes'])): ?>
          <p class="notes"><?= htmlspecialchars($r['notes']) ?></p>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>
</body>
</html>
