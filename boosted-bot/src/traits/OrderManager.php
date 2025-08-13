<?php

namespace App\Traits;

trait OrderManager {
    /**
     * Show pending orders to admin
     * 
     * @param string $chatId Admin's chat ID
     */
    private function showPendingOrders($chatId) {
        if (!$this->isAdmin($chatId)) {
            $this->sendTelegramMessage($chatId, "❌ You are not authorized to view pending orders.");
            return;
        }

        $message = "📝 *Pending Orders*\n\n";
        $pendingOrders = [];

        // Collect all pending orders from users
        foreach ($this->userData as $userId => $userData) {
            if (isset($userData['pending_order']) && 
                ($userData['pending_order']['status'] === 'pending' || 
                 $userData['pending_order']['status'] === 'pending_approval')) {
                $order = $userData['pending_order'];
                $plans = defined('PLANS') ? PLANS : [];
                $plan = $plans[$order['plan_id']] ?? ['name' => 'Unknown Plan'];
                
                $pendingOrders[] = [
                    'user_id' => $userId,
                    'order_id' => $order['order_id'],
                    'plan' => $plan['name'],
                    'status' => $order['status'],
                    'price' => $order['price'] ?? 0,
                    'timestamp' => $order['timestamp']
                ];
            }
        }

        if (empty($pendingOrders)) {
            $message .= "No pending orders at the moment.";
        } else {
            foreach ($pendingOrders as $order) {
                $message .= "*Order ID:* `{$order['order_id']}`\n";
                $message .= "• User: `{$order['user_id']}`\n";
                $message .= "• Plan: {$order['plan']}\n";
                $message .= "• Status: " . ($order['status'] === 'pending_approval' ? "✅ Payment Confirmed" : "⏳ Awaiting Payment") . "\n";
                $message .= "• Price: \${$order['price']}\n";
                $message .= "• Date: " . date('Y-m-d H:i:s', $order['timestamp']) . "\n";
                $message .= "------------------------\n";
            }
        }

        // Create keyboard with approve/reject buttons for each order
        $keyboard = [];
        foreach ($pendingOrders as $order) {
            if ($order['status'] === 'pending_approval') {
                $keyboard[] = [
                    ['text' => '✅ Approve #' . $order['order_id'], 'callback_data' => 'approve_order_' . $order['order_id']],
                    ['text' => '❌ Reject #' . $order['order_id'], 'callback_data' => 'reject_order_' . $order['order_id']]
                ];
            }
        }

        $keyboard[] = [
            ['text' => '🔄 Refresh Orders', 'callback_data' => 'admin_pending_orders'],
            ['text' => '🔙 Back to Admin', 'callback_data' => 'admin_panel']
        ];

        $this->sendTelegramMessage($chatId, $message, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);

        // Also notify about active orders count
        if (!empty($pendingOrders)) {
            $pendingApproval = count(array_filter($pendingOrders, function($o) { 
                return $o['status'] === 'pending_approval'; 
            }));
            if ($pendingApproval > 0) {
                $this->sendTelegramMessage($chatId, "⚠️ You have {$pendingApproval} order(s) waiting for approval!");
            }
        }
    }

    /**
     * Handle payment confirmation from user
     * 
     * @param string $chatId User's chat ID
     * @param string $orderId Order ID to confirm
     */
    private function handlePaymentConfirmation($chatId, $orderId) {
        // Get user data
        $userData = $this->getUserData($chatId);
        
        // Check if order exists and matches
        if (!isset($userData['pending_order']) || 
            $userData['pending_order']['order_id'] !== $orderId ||
            $userData['pending_order']['status'] !== 'pending') {
            $this->sendTelegramMessage($chatId, "❌ Invalid or expired order ID.");
            return;
        }

        // Update order status
        $userData['pending_order']['status'] = 'pending_approval';
        $userData['pending_order']['payment_confirmed_at'] = time();
        $this->userData[$chatId] = $userData;
        $this->saveUserData();

        // Notify user
        $message = "✅ *Payment Confirmation Received*\n\n";
        $message .= "Your payment confirmation has been submitted.\n";
        $message .= "Order ID: `{$orderId}`\n\n";
        $message .= "Please wait for admin approval. You will be notified once your plan is activated.";

        $this->sendTelegramMessage($chatId, $message, ['parse_mode' => 'Markdown']);

        // Notify admins about payment confirmation
        $plans = defined('PLANS') ? PLANS : [];
        $plan = $plans[$userData['pending_order']['plan_id']] ?? ['name' => 'Unknown Plan'];

        foreach ($this->adminIds as $adminId) {
            $adminMessage = "💳 *Payment Confirmation Received*\n\n";
            $adminMessage .= "Order ID: `{$orderId}`\n";
            $adminMessage .= "User: `{$chatId}`\n";
            $adminMessage .= "Plan: {$plan['name']}\n";
            $adminMessage .= "Price: \${$userData['pending_order']['price']}\n\n";
            $adminMessage .= "Ready for approval!";

            $keyboard = [
                [
                    ['text' => '✅ Approve Order', 'callback_data' => 'approve_order_' . $orderId],
                    ['text' => '❌ Reject Order', 'callback_data' => 'reject_order_' . $orderId]
                ],
                [
                    ['text' => '👀 View All Orders', 'callback_data' => 'admin_pending_orders']
                ]
            ];

            $this->sendTelegramMessage($adminId, $adminMessage, [
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
        }
    }

    /**
     * Handle order approval by admin
     * 
     * @param string $adminChatId Admin's chat ID
     * @param string $orderId Order ID to approve
     */
    private function handleOrderApproval($adminChatId, $orderId) {
        if (!$this->isAdmin($adminChatId)) {
            return;
        }

        // Find the order in user data
        $targetUserId = null;
        $userData = null;

        foreach ($this->userData as $userId => $data) {
            if (isset($data['pending_order']) && 
                $data['pending_order']['order_id'] === $orderId &&
                $data['pending_order']['status'] === 'pending_approval') {
                $targetUserId = $userId;
                $userData = $data;
                break;
            }
        }

        if (!$targetUserId) {
            $this->sendTelegramMessage($adminChatId, "❌ Order not found or not ready for approval: {$orderId}");
            return;
        }

        // Get plan details
        $plans = defined('PLANS') ? PLANS : [];
        $planId = $userData['pending_order']['plan_id'];
        $plan = $plans[$planId] ?? null;

        if (!$plan) {
            $this->sendTelegramMessage($adminChatId, "❌ Invalid plan ID in order: {$planId}");
            return;
        }

    // Update user's plan
    $this->userData[$targetUserId]['plan'] = $planId;
    $this->userData[$targetUserId]['plan_expires'] = time() + ($userData['pending_order']['duration'] * 86400);
    // Clear expiration flags and reset counters for new billing period
    unset($this->userData[$targetUserId]['is_expired']);
    unset($this->userData[$targetUserId]['expired_notified_at']);
    $this->userData[$targetUserId]['settings']['premium'] = true;
    $this->userData[$targetUserId]['emails_sent_hour'] = 0;
    $this->userData[$targetUserId]['emails_sent_today'] = 0;
    $this->userData[$targetUserId]['emails_sent_month'] = 0;
    $this->userData[$targetUserId]['last_hour_reset'] = strtotime(date('Y-m-d H:00:00'));
    $this->userData[$targetUserId]['last_day_reset'] = strtotime(date('Y-m-d'));
    $this->userData[$targetUserId]['last_month_reset'] = strtotime(gmdate('Y-m-01 00:00:00'));
        
        // Update order status
        $this->userData[$targetUserId]['pending_order']['status'] = 'approved';
        $this->userData[$targetUserId]['pending_order']['approved_at'] = time();
        $this->userData[$targetUserId]['pending_order']['approved_by'] = $adminChatId;
        
        // Save changes
        $this->saveUserData();

        // Notify admin
        $this->sendTelegramMessage($adminChatId, "✅ Order {$orderId} has been approved and plan activated.");

        // Notify user
        $userMessage = "🎉 *Your Plan Has Been Activated!*\n\n";
        $userMessage .= "Order ID: `{$orderId}`\n";
        $userMessage .= "Plan: *{$plan['name']}*\n";
        $userMessage .= "Duration: {$userData['pending_order']['duration']} days\n";
        $userMessage .= "Expires: " . date('Y-m-d H:i:s', $this->userData[$targetUserId]['plan_expires']) . "\n\n";
        $userMessage .= "✨ Enjoy your premium features!\n";
        $userMessage .= "Use /status to see your new limits.";

        $this->sendTelegramMessage($targetUserId, $userMessage, ['parse_mode' => 'Markdown']);
    }

    /**
     * Handle order rejection by admin
     * 
     * @param string $adminChatId Admin's chat ID
     * @param string $orderId Order ID to reject
     */
    private function handleOrderRejection($adminChatId, $orderId) {
        if (!$this->isAdmin($adminChatId)) {
            return;
        }

        // Find the order in user data
        $targetUserId = null;
        $userData = null;

        foreach ($this->userData as $userId => $data) {
            if (isset($data['pending_order']) && 
                $data['pending_order']['order_id'] === $orderId &&
                $data['pending_order']['status'] === 'pending_approval') {
                $targetUserId = $userId;
                $userData = $data;
                break;
            }
        }

        if (!$targetUserId) {
            $this->sendTelegramMessage($adminChatId, "❌ Order not found or not ready for rejection: {$orderId}");
            return;
        }

        // Update order status
        $this->userData[$targetUserId]['pending_order']['status'] = 'rejected';
        $this->userData[$targetUserId]['pending_order']['rejected_at'] = time();
        $this->userData[$targetUserId]['pending_order']['rejected_by'] = $adminChatId;
        
        // Save changes
        $this->saveUserData();

        // Notify admin
        $this->sendTelegramMessage($adminChatId, "❌ Order {$orderId} has been rejected.");

        // Notify user
        $userMessage = "❌ *Your Order Has Been Rejected*\n\n";
        $userMessage .= "Order ID: `{$orderId}`\n\n";
        $userMessage .= "Please contact support for more information or to make a new order.";

        $keyboard = [
            [
                ['text' => '💬 Contact Support', 'url' => 'https://t.me/Ninja111']
            ],
            [
                ['text' => '🔄 Try Again', 'callback_data' => 'plans']
            ]
        ];

        $this->sendTelegramMessage($targetUserId, $userMessage, [
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }
}
