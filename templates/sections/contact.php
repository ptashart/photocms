<!--
PhotoCMS — photographer portfolio CMS.
Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
SPDX-License-Identifier: GPL-3.0-only
Full license: LICENSE · Additional attribution terms: NOTICE.md.
-->
<?php /** @var array $sec @var array $d @var array $page */
/* Форма связи (1.9.48): POST уходит в корневой /contact.php, обработка — app/contact.php.
   Токен привязан к адресу страницы и дате; back — куда вернуться после отправки (PRG). */
$back = page_url($page) . '#s' . (int)$sec['id'];
$btn = trim((string)$d['button']) !== '' ? trim((string)$d['button']) : 'Отправить';
?>
<section class="<?= sec_classes($sec, 'sec-contact') ?>" id="s<?= (int)$sec['id'] ?>">
  <?php if (trim((string)($d['kicker'] ?? '')) !== ''): ?><p class="sec-kicker"><?= e($d['kicker']) ?></p><?php endif; ?>
  <?php if (trim((string)$d['heading']) !== ''): ?><h2 class="cf-h"><?= e($d['heading']) ?></h2><?php endif; ?>
  <?php if (trim((string)$d['intro']) !== ''): ?><p class="cf-intro"><?= e($d['intro']) ?></p><?php endif; ?>
  <?php if (isset($_GET['sent'])): ?>
  <p class="cf-note ok" role="status">Сообщение отправлено — спасибо! Отвечу в ближайшее время.</p>
  <?php elseif (isset($_GET['err'])): ?>
  <p class="cf-note err" role="alert">Сообщение не отправлено: проверьте поля и попробуйте ещё раз. Если ошибка повторяется — напишите напрямую на почту или в мессенджер.</p>
  <?php endif; ?>
  <form class="cform" method="post" action="/contact.php">
    <input type="hidden" name="ct" value="<?= e(contact_token(page_url($page))) ?>">
    <input type="hidden" name="cp" value="<?= e(page_url($page)) ?>">
    <input type="hidden" name="back" value="<?= e($back) ?>">
    <?php /* «company» — ловушка для ботов: вынесено за экран стилем .cf-hp, люди его не заполняют */ ?>
    <div class="cf-hp" aria-hidden="true"><label>Компания<input type="text" name="company" tabindex="-1" autocomplete="off"></label></div>
    <label class="cf-f"><span>Ваше имя *</span><input type="text" name="name" maxlength="100" required autocomplete="name"></label>
    <div class="cf-row">
      <label class="cf-f"><span>Телефон</span><input type="tel" name="phone" maxlength="50" autocomplete="tel"></label>
      <label class="cf-f"><span>Email</span><input type="email" name="email" maxlength="200" autocomplete="email"></label>
    </div>
    <label class="cf-f"><span>Дата съёмки (если известна)</span><input type="text" name="date" maxlength="50"></label>
    <label class="cf-f"><span>Сообщение *</span><textarea name="message" rows="5" maxlength="5000" required></textarea></label>
    <p class="cf-hint">Укажите телефон или email — иначе я не смогу ответить.</p>
    <button class="btn btn-solid" type="submit"><?= e($btn) ?></button>
  </form>
</section>
