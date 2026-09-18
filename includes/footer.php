    </main>
    <div class="foot"><span>© <?= date('Y') ?> <?= e(setting('store_name', APP_NAME)) ?></span><span>MyStore ERP</span></div>
  </div>
</div>
<nav class="bottomnav">
  <a href="index.php"><span class="bi">🏠</span><span>Home</span></a>
  <a href="sales.php"><span class="bi">🛒</span><span>Sales</span></a>
  <a href="couriers.php"><span class="bi">🚚</span><span>Courier</span></a>
  <a href="expenses.php"><span class="bi">💸</span><span>Expense</span></a>
  <a onclick="toggleSidebar()"><span class="bi">☰</span><span>More</span></a>
</nav>
<script src="assets/app.js?v=<?= @filemtime(__DIR__."/../assets/app.js") ?>"></script>
</body>
</html>
