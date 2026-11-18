<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
/** @var array $sec @var array $d @var array $page */
$back = page_url($page) . '#s' . (int)$sec['id'];
$btn = trim((string)$d['button']) !== '' ? trim((string)$d['button']) : 'Отправить';
$old = contact_old_read();
$oldField = static fn(string $key): string => is_string($old[$key] ?? null) ? $old[$key] : '';
?>
<section class="<?= sec_classes($sec, 'sec-contact') ?>" id="s<?= (int)$sec['id'] ?>">
  <?php if (trim((string)($d['kicker'] ?? '')) !== ''): ?><p class="sec-kicker"><?= e($d['kicker']) ?></p><?php endif; ?>
  <?php if (trim((string)$d['heading']) !== ''): ?><h2 class="cf-h"><?= e($d['heading']) ?></h2><?php endif; ?>
  <?php if (trim((string)$d['intro']) !== ''): ?><p class="cf-intro"><?= e($d['intro']) ?></p><?php endif; ?>
  <?php if (isset($_GET['sent'])): ?>
  <p class="cf-note ok" role="status">Заявка получена — спасибо! Отвечу в ближайшее время.</p>
  <?php elseif (isset($_GET['err'])): ?>
  <p class="cf-note err" role="alert"><?= $_GET['err'] === 'limit' ? 'Слишком много отправок подряд. Ваш текст сохранён в форме — попробуйте через час или свяжитесь другим способом.' : 'Заявка не отправлена: проверьте поля и попробуйте ещё раз. Введённый текст сохранён в форме.' ?></p>
  <?php endif; ?>
  <form class="cform" method="post" action="/contact.php">
    <input type="hidden" name="ct" value="<?= e(contact_token(page_url($page))) ?>">
    <input type="hidden" name="cp" value="<?= e(page_url($page)) ?>">
    <input type="hidden" name="back" value="<?= e($back) ?>">
    <div hidden aria-hidden="true"><label>Не заполнять<input type="text" name="p48x2" tabindex="-1" autocomplete="new-password"></label></div>
    <label class="cf-f"><span>Ваше имя *</span><input type="text" name="name" maxlength="100" required autocomplete="name" value="<?= e($oldField('name')) ?>"></label>
    <div class="cf-row">
      <label class="cf-f"><span>Телефон</span><input type="tel" name="phone" maxlength="50" autocomplete="tel" value="<?= e($oldField('phone')) ?>"></label>
      <label class="cf-f"><span>Email</span><input type="email" name="email" maxlength="200" autocomplete="email" value="<?= e($oldField('email')) ?>"></label>
    </div>
    <label class="cf-f"><span>Дата съёмки (если известна)</span><input type="text" name="date" maxlength="50" value="<?= e($oldField('date')) ?>"></label>
    <label class="cf-f"><span>Сообщение *</span><textarea name="message" rows="5" maxlength="5000" required><?= e($oldField('message')) ?></textarea></label>
    <p class="cf-hint">Укажите телефон или email — иначе я не смогу ответить.</p>
    <button class="btn btn-solid" type="submit"><?= e($btn) ?></button>
  </form>
</section>
