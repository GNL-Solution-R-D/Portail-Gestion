<?php
/**
 * include/status_page.php
 *
 * Page d'état autonome (sans menu ni session) au design GNL Solution :
 * utilisée par pages/404.php et pages/maintenance.php.
 *
 * gnl_status_page(int $code, string $kicker, string $title, string $text, array $actions)
 *   $actions : liste de [libellé, href, primaire(bool)]
 */

if (!function_exists('t')) {
    $langInc = __DIR__ . '/lang.php';
    if (is_file($langInc)) { require_once $langInc; }
}
if (!function_exists('t')) { function t($k, $r = []) { return $k; } }

function gnl_status_page(int $code, string $kicker, string $title, string $text, array $actions = []): void
{
    $e = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    if ($code > 0 && !headers_sent()) { http_response_code($code); }
    $lang = is_string($GLOBALS['lang'] ?? null) ? $GLOBALS['lang'] : 'fr';
    ?><!DOCTYPE html>
<html lang="<?= $e($lang) ?>">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <meta name="robots" content="noindex"/>
  <meta name="theme-color" content="#141a33"/>
  <title><?= $e($title) ?> | GNL Solution</title>
  <link rel="preload" href="/assets/gnl/fonts/Manrope-latin.woff2" as="font" type="font/woff2" crossorigin/>
  <link rel="icon" href="/assets/gnl/img/favicon-32.png" sizes="32x32"/>
  <style>
    @font-face{font-family:Manrope;font-style:normal;font-weight:200 800;font-display:swap;src:url(/assets/gnl/fonts/Manrope-latin.woff2) format("woff2")}
    *{box-sizing:border-box}
    html,body{margin:0;min-height:100%}
    body{min-height:100vh;display:flex;flex-direction:column;background:#f4f6fa;color:#141a33;font:15px/24px Manrope,system-ui,sans-serif}
    header{background:#141a33;padding:20px 24px}
    header img{display:block;width:170px;height:auto}
    main{flex:1;display:flex;align-items:center;justify-content:center;padding:48px 16px}
    .box{width:100%;max-width:620px;background:#fff;border:1px solid #e3e6ee;border-radius:3px;padding:40px 44px}
    .k{margin:0 0 10px;display:flex;align-items:center;gap:10px;font-size:12px;font-weight:700;letter-spacing:.2em;color:#1f7a4a;text-transform:uppercase}
    h1{margin:0 0 12px;font-size:34px;line-height:42px;font-weight:800}
    p.t{margin:0 0 28px;color:#5e6684}
    .a{display:flex;flex-wrap:wrap;gap:10px}
    .a a{display:inline-flex;align-items:center;height:42px;padding:0 18px;border-radius:3px;font-weight:700;font-size:14px;text-decoration:none;border:1px solid #c5cbd8;color:#141a33;background:#fff}
    .a a.p{background:#141a33;border-color:#141a33;color:#fff}
    .a a:hover{border-color:#141a33}
    .a a.p:hover{background:#232b52}
    footer{padding:20px 24px;text-align:center;font-size:13px;color:#5e6684}
    footer a{color:#5e6684}
    @media (max-width:520px){.box{padding:28px 22px}h1{font-size:28px;line-height:36px}}
  </style>
</head>
<body>
  <header><a href="/dashboard"><img src="/assets/gnl/img/logo-gnl-marine.svg" alt="GNL Solution" width="170"/></a></header>
  <main>
    <div class="box">
      <p class="k"><svg aria-hidden="true" width="8" height="14" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#5cd98b"/></svg><?= $e($kicker) ?></p>
      <h1><?= $e($title) ?></h1>
      <p class="t"><?= $e($text) ?></p>
      <?php if ($actions): ?><div class="a">
        <?php foreach ($actions as $act): ?><a href="<?= $e($act[1]) ?>"<?= !empty($act[2]) ? ' class="p"' : '' ?>><?= $e($act[0]) ?></a>
        <?php endforeach; ?></div><?php endif; ?>
    </div>
  </main>
  <footer><?= $e(t('Une question ?')) ?> <a href="tel:+33365670169">03 65 67 01 69</a> · <a href="https://gnl-solution.com/stats"><?= $e(t('État des services')) ?></a></footer>
</body>
</html>
<?php
}
