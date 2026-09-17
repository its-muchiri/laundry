<?php
/**
 * Global layout — header/nav/footer per planning/00-portfolio/design-system.md
 * §Global layout and artcollect-design-system.md §6. `$content` and
 * optional `$title` are provided by View::render().
 */
$pageTitle = isset($title) ? $title . ' — laundry.co.ke' : 'laundry.co.ke';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="/assets/css/main.css">
</head>
<body>
  <header class="site-header container">
    <a href="/" style="text-decoration:none;color:inherit;"><strong>laundry.co.ke</strong></a>
    <nav aria-label="Primary">
      <a href="/book" class="btn btn--secondary">Book a pickup</a>
    </nav>
  </header>

  <main class="container" style="padding-block: var(--ac-space-8);">
    <?= $content ?>
  </main>

  <footer class="site-footer container">
    <p class="card__meta">&copy; <?= date('Y') ?> laundry.co.ke — part of the artcollect.co.ke network. <a href="/showcase.html">Component showcase</a></p>
  </footer>

  <script type="module" src="/assets/js/main.js"></script>
</body>
</html>
