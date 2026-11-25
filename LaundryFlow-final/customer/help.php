<?php
/**
 * Customer "Ask us" — answers questions about THEIR orders, prices and hours.
 * Uses AI when configured, otherwise built-in answers.
 */
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('customer');

$history = $_SESSION['cust_help'] ?? [];
if (is_post()) {
    verify_csrf();
    $q = mb_substr(post('q'), 0, 500);
    // Simple limit: 20 questions per hour per customer
    $_SESSION['cust_help_times'] = array_values(array_filter($_SESSION['cust_help_times'] ?? [], fn($t) => $t > time() - 3600));
    if ($q !== '' && count($_SESSION['cust_help_times']) >= 20) {
        flash('error', t('Too many questions. Please try again later or call the shop.'));
    } elseif ($q !== '') {
        $_SESSION['cust_help_times'][] = time();
        $ans = ai_customer_answer($u, $q);
        $history[] = ['q' => $q, 'a' => $ans['text']];
        $_SESSION['cust_help'] = array_slice($history, -10);
    }
    redirect('customer/help.php#end');
}

$suggest = ['Kailan ready ang laundry ko?', 'How much is wash dry fold?', 'What is my pickup code?', 'Anong oras kayo bukas?', 'Can I pay with GCash?'];
layout_start(t('Ask us'), 'help');
?>
<h1>💬 <?= e(t('Ask us')) ?></h1>
<p class="muted mb"><?= e(t('Ask about your laundry, prices or our hours — in English or Tagalog.')) ?></p>
<div class="card">
  <div class="chat">
    <div class="bubble a">👋 <?= e(t('Hi! How can we help you today?')) ?></div>
    <?php foreach ($history as $h): ?>
      <div class="bubble q"><?= e($h['q']) ?></div>
      <div class="bubble a"><?= e($h['a']) ?></div>
    <?php endforeach; ?>
    <span id="end"></span>
  </div>
  <form method="post" class="mt">
    <?= csrf_field() ?>
    <div class="search-bar"><input type="text" name="q" placeholder="<?= e(t('Type your question…')) ?>" required autofocus><button class="btn"><?= e(t('Send')) ?></button></div>
  </form>
  <form method="post" class="suggest mt"><?= csrf_field() ?>
    <?php foreach ($suggest as $s): ?><button name="q" value="<?= e($s) ?>"><?= e($s) ?></button><?php endforeach; ?>
  </form>
</div>
<p class="small muted mt"><?= e(t('For urgent concerns call')) ?> <?= e(setting('shop_phone', '')) ?>.</p>
<?php layout_end();
