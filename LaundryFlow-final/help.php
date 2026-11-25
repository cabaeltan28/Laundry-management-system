<?php
/**
 * Help & shortcut keys — simple step-by-step guide for staff and owner.
 */
require __DIR__ . '/includes/bootstrap.php';
$u = require_role(['employee', 'owner']);

$steps = [
    ['➕', 'Receive laundry', [
        'Press NEW ORDER (or Alt+N).',
        'Type the mobile number. Old customers are found automatically.',
        'Tap the service, type the kilo and number of bags.',
        'Choose how they paid (or Not paid yet), then press SAVE ORDER.',
        'Print the claim stub (Ctrl+P). Tell the customer the PICKUP CODE.',
    ]],
    ['🧺', 'Update the laundry', [
        'Go to COUNTER (Alt+H).',
        'In the Work queue, press the pink button when a step is done (Washing → Drying → Folding → Ready).',
        'When you press Ready, the customer gets a message automatically.',
    ]],
    ['✅', 'Release laundry', [
        'Ask the customer for the 4-digit PICKUP CODE.',
        'On the Counter, type the code in the big box (Alt+Q) and press FIND.',
        'Check the name and amount, choose how they paid, press RELEASE.',
    ]],
    ['⚖️', 'Weight is wrong?', [
        'Open the order, press "Change weight, service or details".',
        'Type the correct weight and the REASON. The owner will review it.',
    ]],
    ['💵', 'End of shift', [
        'Go to CASH CLOSING (Alt+K).',
        'Count the cash in the drawer and type the amount.',
        'If it is not exact, write the reason.',
    ]],
];
$keys = [
    ['Alt + H', 'Counter / Dashboard'], ['Alt + N', 'New order'], ['Alt + Z', 'Save order (on the New order page)'],
    ['Alt + Q', 'Type pickup code (Counter)'], ['Alt + S', 'Search'], ['Alt + P', 'Ready for pickup'], ['Alt + O', 'Orders'],
    ['Alt + C', 'Customers'], ['Alt + K', 'Cash closing'], ['Alt + W', 'Switch user'], ['Alt + I', 'Help'], ['Ctrl + P', 'Print'],
    ['F5', 'Refresh the page'],
];
if ($u['role'] === 'owner') {
    $keys[] = ['Alt + A', 'AI assistant'];
    $keys[] = ['Alt + R', 'Reports'];
    $keys[] = ['Alt + X', 'Discrepancies'];
    $keys[] = ['Alt + Y', 'Expenses'];
}
layout_start(t('Help & shortcuts'), 'help', ['subtitle' => t('How to use the system in simple steps')]);
?>
<div class="grid g-main">
  <div class="stack">
    <?php foreach ($steps as [$ico, $title, $list]): ?>
      <div class="card">
        <h3><?= $ico ?> <?= e(t($title)) ?></h3>
        <ol style="margin:6px 0 0;padding-left:22px;line-height:1.9;font-size:15.5px">
          <?php foreach ($list as $li): ?><li><?= e(t($li)) ?></li><?php endforeach; ?>
        </ol>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="stack">
    <div class="card">
      <h3>⌨️ <?= e(t('Shortcut keys')) ?></h3>
      <p class="small muted"><?= e(t('Chrome / Edge: hold Alt and press the letter. Firefox: Alt + Shift + letter.')) ?></p>
      <?php foreach ($keys as [$k, $what]): ?>
        <div class="list-item"><span><?= e(t($what)) ?></span><kbd style="font-size:12px"><?= e($k) ?></kbd></div>
      <?php endforeach; ?>
    </div>
    <div class="card">
      <h3>🌐 <?= e(t('Language')) ?></h3>
      <p class="small muted"><?= e(t('Choose Tagalog or English at the bottom of the menu.')) ?></p>
    </div>
  </div>
</div>
<?php layout_end();
