<footer class="site-footer">
  <div class="footer-identity">
    <a class="footer-brand" href="https://egor-zvada.ru" target="_blank" rel="noopener" aria-label="Сайт разработчика">
      <img src="/assets/svg/logo.svg" alt="" width="34" height="34">
      <span>egor_zvada</span>
    </a>
    <button class="footer-version" type="button" data-admin-entry aria-label="Версия">&gt; build: <?= h(APP_VERSION) ?></button>
  </div>
  <div class="footer-copy" aria-label="Информация о разработке">
    <span>Разработано в <?= date('Y') ?> году Егор Звада <sup>™</sup></span>
    <span>Все права защищены.</span>
  </div>
  <button class="footer-top" type="button" data-scroll-top>Наверх ↑</button>
</footer>
<div class="privacy-consent" data-privacy-consent hidden>
  <div class="privacy-consent__panel" role="dialog" aria-modal="true" aria-label="Согласие на обработку персональных данных">
    <p>Продолжая пользоваться сайтом, вы подтверждаете согласие на обработку персональных данных и принимаете <a href="/assets/docs/privacy-policy.pdf" target="_blank" rel="noopener">Политику персональных данных</a>.</p>
    <button class="button" type="button" data-privacy-accept>Хорошо</button>
  </div>
</div>
<script src="/assets/js/app.js"></script>
</body>
</html>
