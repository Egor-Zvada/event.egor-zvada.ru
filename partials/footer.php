<footer class="site-footer">
  <a class="footer-brand" href="https://egor-zvada.ru" target="_blank" rel="noopener" aria-label="Сайт разработчика">
    <img src="/assets/svg/logo.svg" alt="" width="34" height="34">
    <span>egor_zvada</span>
  </a>
  <div class="footer-copy" aria-label="Информация о разработке">
    <span>Разработано в <?= date('Y') ?> году Егор Звада <sup>™</sup></span>
    <span>Все права защищены.</span>
    <button class="footer-version" type="button" data-admin-entry aria-label="Версия">&gt; build: <?= h(APP_VERSION) ?></button>
  </div>
  <button class="footer-top" type="button" data-scroll-top>Наверх ↑</button>
</footer>
<script src="/assets/js/app.js"></script>
</body>
</html>
