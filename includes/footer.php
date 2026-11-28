    </main>
    <div class="foot"><span>© <?= date('Y') ?> <?= e(setting('store_name', APP_NAME)) ?></span><span>Luprah ERP</span></div>
  </div>
</div>
<?php $bnCur = basename($_SERVER['SCRIPT_NAME'] ?? '');
  $bnIco = fn($d) => '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'.$d.'</svg>';
  $bnTab = fn($href, $label, $d) => '<a href="'.$href.'"'.($bnCur === $href ? ' class="on" aria-current="page"' : '').'><span class="bi">'.$bnIco($d).'</span><span>'.$label.'</span></a>'; ?>
<nav class="bottomnav">
  <?= $bnTab('index.php', 'Home', '<path d="M3 10.5L12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1v-9.5z"/>') ?>
  <?= $bnTab('sales.php', 'Sales', '<path d="M8 6h12M8 12h12M8 18h12"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>') ?>
  <a href="sales.php?new=1" class="bn-plus" aria-label="New order"><span class="bi"><?= $bnIco('<path d="M12 5v14M5 12h14"/>') ?></span><span>New</span></a>
  <?= $bnTab('daraz_sales.php', 'Daraz', '<path d="M6 7h12l-1 12H7L6 7z"/><path d="M9 7a3 3 0 0 1 6 0"/>') ?>
  <a onclick="toggleSidebar()" role="button"><span class="bi"><?= $bnIco('<path d="M4 7h16M4 12h16M4 17h16"/>') ?></span><span>More</span></a>
</nav>
<script src="assets/app.js?v=<?= @filemtime(__DIR__."/../assets/app.js") ?>"></script>
</body>
</html>
