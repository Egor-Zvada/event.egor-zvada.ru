<footer class="site-footer">
  <a class="footer-brand" href="https://egor-zvada.ru" target="_blank" rel="noopener" aria-label="Сайт разработчика">
    <img src="/assets/svg/logo.svg" alt="" width="34" height="34">
    <span>Егор Звада</span>
  </a>
  <div class="footer-copy" aria-label="Информация о разработке">
    <span>Разработано в <?= date('Y') ?> году</span>
    <span>Егор Звада <sup>™</sup></span>
    <span>Все права защищены</span>
  </div>
  <button class="footer-version" type="button" data-admin-entry aria-label="Версия"><?= h(APP_VERSION) ?></button>
</footer>
<script src="/assets/js/app.js"></script>
</body>
</html>
