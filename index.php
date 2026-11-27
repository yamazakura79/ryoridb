<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/nav.php';

$hidden = ['index.php', 'db.php'];
$files = array_filter(glob('*.php') ?: [], function ($f) use ($hidden) {
    $base = basename($f);
    return !in_array($base, $hidden, true) && !str_starts_with($base, '_');
});
sort($files);

function get_meta(string $file): array {
    $head = implode('', array_slice(file($file) ?: [], 0, 12));
    preg_match('/\/\/\s*TITLE:\s*(.+)/i', $head, $t);
    preg_match('/\/\/\s*DESC:\s*(.+)/i', $head, $d);
    $fallback = ucwords(str_replace(['_', '-'], ' ', pathinfo($file, PATHINFO_FILENAME)));
    return [
        'title' => trim($t[1] ?? $fallback),
        'desc'  => trim($d[1] ?? 'Open ' . $file),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(basename(__DIR__)) ?></title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <main class="container">
        <h1><?= htmlspecialchars(basename(__DIR__)) ?></h1>
        <div class="card-grid">
            <?php foreach ($files as $file): $meta = get_meta($file); ?>
                <a class="card" href="<?= htmlspecialchars($file) ?>">
                    <div class="card-header">
                        <h2><?= htmlspecialchars($meta['title']) ?></h2>
                    </div>
                    <p><?= htmlspecialchars($meta['desc']) ?></p>
                    <span class="card-footer"><?= htmlspecialchars($file) ?> &rarr;</span>
                </a>
            <?php endforeach; ?>
        </div>
    </main>
</body>
</html>
