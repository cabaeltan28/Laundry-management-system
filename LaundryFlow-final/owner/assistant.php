<?php
/**
 * AI BUSINESS ASSISTANT for the owner. Ask in English or Tagalog:
 * "Magkano kita ngayong linggo?", "Who has the most discrepancies?"...
 * Works offline too (built-in answers) when AI is not configured.
 */
require __DIR__ . '/../includes/bootstrap.php';
require_role('owner');

$history = $_SESSION['assistant'] ?? [];
if (is_post()) {
    verify_csrf();
    if (post('action') === 'clear') {
        $history = [];
    } elseif (post('action') === 'refresh_tips') {
        daily_insights(true);
        redirect('owner/assistant.php');
    } else {
        $q = mb_substr(post('q'), 0, 800);
        if ($q !== '') {
            $ans = ai_business_answer($q, $history);
            $history[] = ['q' => $q, 'a' => $ans['text'], 'src' => $ans['source']];
            $history = array_slice($history, -12);
        }
    }
    $_SESSION['assistant'] = $history;
    redirect('owner/assistant.php#end');
}

$tips = daily_insights();
$suggest = ['How much did we earn this week?', 'What is our profit this month?', 'Which service sells the most?', 'When are we busiest?',
            'Who has unpaid balances?', 'Any suspicious changes or cash shortages?', 'How is each employee doing?', 'Magkano kita natin ngayon?'];

layout_start(t('AI assistant'), 'assistant', [
    'subtitle' => ai_enabled() ? t('Powered by Claude AI · answers from your shop data') : t('Offline mode · add an AI key in config.php for open questions'),
]);
if (ai_last_error()) {
    echo '<div class="alert alert-warn">AI: ' . e(ai_last_error()) . ' — ' . e(t('showing offline answer.')) . '</div>';
}
?>
<div class="grid g-main">
  <div class="card">
    <div class="card-head"><div><h3>💬 <?= e(t('Ask about your business')) ?></h3><p class="muted"><?= e(t('English or Tagalog. Answers use only your shop data.')) ?></p></div>
      <?php if ($history): ?><form method="post"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" name="action" value="clear"><?= e(t('Clear')) ?></button></form><?php endif; ?></div>
    <div class="chat">
      <?php if (!$history): ?>
        <div class="bubble a">👋 <?= e(t('Hi! I can tell you about sales, profit, best services, busy hours, unpaid balances, unclaimed laundry, staff and discrepancies. Ask me anything.')) ?></div>
      <?php endif; ?>
      <?php foreach ($history as $h): ?>
        <div class="bubble q"><?= e($h['q']) ?></div>
        <div class="bubble a"><?= e($h['a']) ?><br><span class="ai-tag <?= $h['src'] === 'ai' ? '' : 'off' ?>" style="margin-top:6px"><?= $h['src'] === 'ai' ? 'AI' : e(t('Offline')) ?></span></div>
      <?php endforeach; ?>
      <span id="end"></span>
    </div>
    <form method="post" class="mt">
      <?= csrf_field() ?>
      <div class="search-bar"><input type="text" name="q" placeholder="<?= e(t('Type your question…')) ?>" autofocus required><button class="btn btn-big"><?= e(t('Ask')) ?></button></div>
    </form>
    <form method="post" class="suggest mt">
      <?= csrf_field() ?>
      <?php foreach ($suggest as $s): ?><button name="q" value="<?= e($s) ?>"><?= e($s) ?></button><?php endforeach; ?>
    </form>
  </div>
  <div class="stack">
    <div class="card">
      <div class="card-head"><h3>✨ <?= e(t("Today's insights")) ?></h3><span class="ai-tag <?= $tips['source'] === 'ai' ? '' : 'off' ?>"><?= $tips['source'] === 'ai' ? 'AI' : e(t('Offline')) ?></span></div>
      <ul class="insights"><?php foreach ($tips['items'] as $tip): ?><li><?= e($tip) ?></li><?php endforeach; ?></ul>
      <?php if (ai_enabled()): ?><form method="post"><?= csrf_field() ?><button class="btn btn-outline btn-sm" name="action" value="refresh_tips"><?= e(t('Refresh insights')) ?></button></form><?php endif; ?>
    </div>
    <div class="card">
      <h3>🔐 <?= e(t('Privacy')) ?></h3>
      <p class="small muted"><?= e(t('Only summary numbers (totals, counts, staff names) are sent to the AI. Customer phone numbers are never sent from this page.')) ?></p>
    </div>
  </div>
</div>
<?php layout_end();
