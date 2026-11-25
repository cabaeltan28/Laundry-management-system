<?php
/**
 * Handles every order-changing button (POST only) for employees and owner.
 */
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role(['employee', 'owner']);

if (!is_post()) {
    redirect('staff/orders.php');
}
verify_csrf();

$orderId = (int)post('order_id');
$action = post('action');
$return = post('return');
if (!preg_match('#^(staff|owner)/[a-z_]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$#', $return)) {
    $return = 'staff/order.php?id=' . $orderId;
}
$actor = (int)$u['id'];

try {
    switch ($action) {
        case 'advance':
            $o = get_order($orderId);
            if (!$o) throw new RuntimeException('Order not found.');
            $to = next_status($o['status']);
            if (!$to || $to !== post('to')) throw new RuntimeException(t('The order status changed in the meantime. Please check and try again.'));
            change_status($orderId, $to, $actor, post('note'));
            flash('success', '✅ ' . $o['order_no'] . ' → ' . status_label($to));
            break;

        case 'revert':
            revert_status($orderId, post('reason'), $actor);
            flash('success', t('Order moved back one step.'));
            break;

        case 'update':
            $changed = update_order_details($orderId, [
                'service_id' => (int)post('service_id'),
                'weight'     => (float)post('weight'),
                'bag_count'  => (int)post('bag_count'),
                'is_rush'    => post('is_rush') === '1',
                'notes'      => post('notes'),
            ], post('reason'), $actor);
            flash($changed ? 'success' : 'info', $changed ? t('Order updated. The change was recorded.') . ' (' . implode(', ', $changed) . ')' : t('Nothing changed.'));
            break;

        case 'pay':
            $amount = (float)str_replace(',', '', post('amount'));
            record_payment($orderId, $amount, post('method'), post('reference'), $actor);
            flash('success', '💵 ' . t('Payment recorded') . ': ' . money($amount));
            break;

        case 'void':
            $pid = (int)post('payment_id');
            $p = q_one('SELECT order_id FROM payments WHERE id = ?', [$pid]);
            if (!$p || (int)$p['order_id'] !== $orderId) throw new RuntimeException('Payment not found.');
            void_payment($pid, post('reason'), $actor);
            flash('success', t('Payment voided. The owner was notified.'));
            break;

        case 'claim':
            claim_order($orderId, post('pickup_code'), $actor, post('release_unpaid') === '1', post('reason'));
            flash('success', '✅ ' . t('Released! Laundry claimed.'));
            break;

        case 'pay_claim':
            $paid = pay_balance_and_claim($orderId, post('pickup_code'), post('method', 'cash'), post('reference'), $actor);
            flash('success', '✅ ' . t('Released! Laundry claimed.') . ($paid > 0 ? ' ' . t('Payment recorded') . ': ' . money($paid) : ''));
            break;

        case 'cancel':
            cancel_order($orderId, post('reason'), $actor);
            flash('success', t('Order cancelled.'));
            break;

        case 'resend':
            $o = get_order($orderId);
            if (!$o || in_array($o['status'], ['claimed', 'cancelled'], true)) throw new RuntimeException('Updates can only be sent for open orders.');
            $msg = $o['order_no'] . ' status: ' . status_label($o['status']) . '. ' .
                ($o['status'] === 'ready' ? 'Pickup code: ' . $o['pickup_code'] . '.' : 'Estimated ready: ' . fmt_when($o['estimated_ready_at']) . '.');
            notify_customer($o, 'status', 'Order update', $msg, true);
            audit('customer_notified', 'order', $orderId, $orderId, null, null, 'Update sent to ' . mask_phone($o['customer_phone']), null, $actor);
            flash('success', t('Update sent to the customer.'));
            break;

        default:
            throw new RuntimeException('Unknown action.');
    }
} catch (RuntimeException $ex) {
    flash('error', $ex->getMessage());
}
redirect($return);
