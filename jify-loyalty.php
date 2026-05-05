<?php
/**
 * Plugin Name: Jify Loyalty (集點卡系統)
 * Description: 整合 WooCommerce 訂單自動集點與 LINE 帳號綁定系統 (真實串接版 + 規則引擎)。
 * Version: 0.5.1
 * Author: Jify Dev Team
 * Text Domain: jify-loyalty
 */

if (!defined('ABSPATH')) {
    exit;
}

// 預設匯率常數
define('JIFY_POINTS_CONVERSION_RATE_DEFAULT', 100); 

class Jify_Loyalty {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        register_activation_hook(__FILE__, array($this, 'install_db'));
        add_action('woocommerce_order_status_completed', array($this, 'award_points_on_order_complete'));
        add_action('init', array($this, 'add_my_account_endpoint'));
        add_filter('query_vars', array($this, 'add_query_vars'), 0);
        add_filter('woocommerce_account_menu_items', array($this, 'add_my_account_menu_item'));
        add_action('woocommerce_account_jify-points_endpoint', array($this, 'render_my_account_points_page'));
        
        add_action('woocommerce_thankyou', array($this, 'display_earned_points_on_thankyou'));
        add_action('template_redirect', array($this, 'handle_frontend_actions'));

        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Frontend Redemption UI
        add_action('woocommerce_review_order_before_payment', array($this, 'render_checkout_redemption_ui'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        
        // Backend Redemption Logic
        add_action('wp_ajax_jify_apply_points', array($this, 'handle_ajax_apply_points'));
        add_action('wp_ajax_nopriv_jify_apply_points', array($this, 'handle_ajax_apply_points'));
        add_action('woocommerce_cart_calculate_fees', array($this, 'calculate_redemption_fee'), 30); // Late priority to run after other discounts
        add_action('woocommerce_checkout_order_processed', array($this, 'reduce_points_on_checkout'));
        
        // Login Form Button - Priority 99 to push it to the bottom
        add_action('woocommerce_login_form_end', array($this, 'add_line_login_button'), 99);
        add_action('login_form', array($this, 'add_line_login_button'), 99);
    }

    public function handle_ajax_apply_points() {
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => '請先登入'));
        }
        $user_id = get_current_user_id();
        $balance = $this->get_user_points($user_id);
        
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
        
        if ($type === 'custom_cash') {
            $points = intval($_POST['points']);
            if ($points > $balance) {
                wp_send_json_error(array('message' => '點數不足'));
            }
            if ($points <= 0) {
                WC()->session->__unset('jify_redemption');
                wp_send_json_success(array('message' => '已取消'));
            }
            WC()->session->set('jify_redemption', array('type' => 'custom', 'points' => $points));
            wp_send_json_success();
            
        } elseif ($type === 'rule') {
            $index = intval($_POST['rule_index']);
            $rules = get_option('jify_loyalty_redemption_rules', array());
            if (!isset($rules[$index])) {
                wp_send_json_error(array('message' => '無效的規則'));
            }
            $rule = $rules[$index];
            if ($balance < $rule['points']) {
                wp_send_json_error(array('message' => '點數不足'));
            }
            
            // Handle Product Redemption Immediately (Add to Cart)
            if ($rule['type'] === 'product') {
                $product_id = intval($rule['value']);
                $found = false;
                foreach (WC()->cart->get_cart() as $cart_item) {
                    if ($cart_item['product_id'] == $product_id || $cart_item['variation_id'] == $product_id) {
                        $found = true; 
                        break;
                    }
                }
                if (!$found) {
                    WC()->cart->add_to_cart($product_id);
                }
            }
            
            WC()->session->set('jify_redemption', array('type' => 'rule', 'index' => $index, 'points_cost' => $rule['points']));
            wp_send_json_success();
        }
        
        wp_send_json_error(array('message' => '未知錯誤'));
    }

    public function calculate_redemption_fee($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        
        $session_data = WC()->session->get('jify_redemption');
        if (empty($session_data)) return;

        // Verify Balance Again (Security)
        $user_id = get_current_user_id();
        $balance = $this->get_user_points($user_id);
        
        // Calculate Redeemable Total (Scope Logic)
        $allowed_cats = get_option('jify_loyalty_redemption_allowed_categories', array());
        $allowed_tags = get_option('jify_loyalty_redemption_allowed_tags', array());
        $specific_ids_str = get_option('jify_loyalty_redemption_specific_product_ids', '');
        $specific_ids = !empty($specific_ids_str) ? array_map('intval', explode(',', $specific_ids_str)) : array();
        
        $has_restrictions = (!empty($allowed_cats) || !empty($allowed_tags) || !empty($specific_ids));
        
        $redeemable_total = 0;
        $product_discount_map = array(); // For handling product redemption specific pricing

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product_id = $cart_item['product_id'];
            $line_total = $cart_item['line_total']; // Tax excluded usually
            
            if (!$has_restrictions || in_array($product_id, $specific_ids) || has_term($allowed_cats, 'product_cat', $product_id) || has_term($allowed_tags, 'product_tag', $product_id)) {
                $redeemable_total += $line_total;
            }
        }
        
        $discount = 0;
        $points_used = 0;

        if ($session_data['type'] === 'custom') {
            $points_used = $session_data['points'];
            if ($points_used > $balance) {
                WC()->session->__unset('jify_redemption'); // Force clear if balance dropped
                return; 
            }
            $rate = floatval(get_option('jify_loyalty_redemption_rate', 0));
            $max_discount = $points_used * $rate;
            $discount = min($max_discount, $redeemable_total);
            
        } elseif ($session_data['type'] === 'rule') {
            $index = $session_data['index'];
            $rules = get_option('jify_loyalty_redemption_rules', array());
            if (isset($rules[$index])) {
                $rule = $rules[$index];
                $points_used = $rule['points'];
                if ($points_used > $balance) return;

                if ($rule['type'] === 'fixed') {
                    $discount = min(floatval($rule['value']), $redeemable_total);
                } elseif ($rule['type'] === 'percent') {
                    $percent = min(floatval($rule['value']), 100);
                    $discount = $redeemable_total * ($percent / 100);
                } elseif ($rule['type'] === 'product') {
                    // Product Redemption Logic: Find the product and discount its full price
                    $target_pid = intval($rule['value']);
                    foreach ($cart->get_cart() as $cart_item) {
                        if (($cart_item['product_id'] == $target_pid || $cart_item['variation_id'] == $target_pid) && $cart_item['quantity'] > 0) {
                            $unit_price = $cart_item['data']->get_price();
                            $discount += $unit_price; 
                            break; // Only discount the first matching item found
                        }
                    }
                }
            }
        }

        if ($discount > 0) {
            $cart->add_fee('點數折抵', -$discount);
            // Store actual used points for checkout processing
            WC()->session->set('jify_redemption_final_points', $points_used);
        }
    }

    public function reduce_points_on_checkout($order_id) {
        $points_used = WC()->session->get('jify_redemption_final_points');
        if ($points_used && $points_used > 0) {
            $order = wc_get_order($order_id);
            $user_id = $order->get_user_id();
            
            // Double check balance before final deduction
            if ($this->get_user_points($user_id) >= $points_used) {
                $this->adjust_points($user_id, -$points_used, "訂單折抵 (Order #$order_id)", 'redeem_' . $order_id);
                $order->add_order_note("Jify Loyalty: 使用了 $points_used 點進行折抵。");
            }
            
            WC()->session->__unset('jify_redemption');
            WC()->session->__unset('jify_redemption_final_points');
        }
    }

    public function enqueue_frontend_scripts() {
        if (is_checkout() || is_cart()) {
            wp_enqueue_script('jify-loyalty-checkout', '', array('jquery'), '1.0', true);
        }
    }

    public function render_checkout_redemption_ui() {
        if (!is_user_logged_in()) {
            echo '<div class="woocommerce-info">請 <a href="' . get_permalink(wc_get_page_id('myaccount')) . '">登入</a> 以使用會員點數折抵。</div>';
            return;
        }

        $user_id = get_current_user_id();
        $balance = $this->get_user_points($user_id);
        
        if ($balance <= 0) {
            return; 
        }

        $point_name = get_option('jify_loyalty_point_name', '點數');
        $rate = floatval(get_option('jify_loyalty_redemption_rate', 0));
        $rules = get_option('jify_loyalty_redemption_rules', array());

        // Basic output structure
        echo '<div id="jify-loyalty-redemption" style="background: #f9f9f9; padding: 15px; border: 1px solid #e5e5e5; border-radius: 4px; margin-bottom: 20px;">';
        echo '<h4 style="margin-top:0;">會員點數折抵 <small>(現有 ' . number_format($balance) . ' ' . esc_html($point_name) . ')</small></h4>';
        echo '<div id="jify-redemption-messages"></div>';
        
        // Mode 1: Cash Offset
        if ($rate > 0) {
            echo '<div class="jify-redemption-option" style="margin-bottom: 10px;">';
            echo '<label style="display:block; font-weight:bold; margin-bottom:5px;">直 接折抵現金 (匯率: 1點 = $' . $rate . ')</label>';
            echo '<div style="display:flex; gap:10px;">';
            echo '<input type="number" id="jify_redeem_points" class="input-text" placeholder="輸入使用點數" max="' . $balance . '" style="max-width: 150px;">';
            echo '<button type="button" class="button" id="jify_apply_points">折抵</button>';
            echo '</div>';
            echo '</div>';
        }

        // Mode 2: Rules
        if (!empty($rules) && is_array($rules)) {
            // Pre-calculate Best Value
            $best_rule_index = -1;
            $max_savings = 0;
            
            // Calculate redeemable total for percentage rules
            $allowed_cats = get_option('jify_loyalty_redemption_allowed_categories', array());
            $allowed_tags = get_option('jify_loyalty_redemption_allowed_tags', array());
            $specific_ids_str = get_option('jify_loyalty_redemption_specific_product_ids', '');
            $specific_ids = !empty($specific_ids_str) ? array_map('intval', explode(',', $specific_ids_str)) : array();
            $has_restrictions = (!empty($allowed_cats) || !empty($allowed_tags) || !empty($specific_ids));
            
            $redeemable_total = 0;
            if (WC()->cart) {
                foreach (WC()->cart->get_cart() as $cart_item) {
                    $pid = $cart_item['product_id'];
                    $lt = $cart_item['line_total'];
                    if (!$has_restrictions || in_array($pid, $specific_ids) || has_term($allowed_cats, 'product_cat', $pid) || has_term($allowed_tags, 'product_tag', $pid)) {
                        $redeemable_total += $lt;
                    }
                }
            }

            foreach ($rules as $idx => $r) {
                if ($balance < $r['points']) continue;
                
                $savings = 0;
                if ($r['type'] === 'fixed') {
                    $savings = min(floatval($r['value']), $redeemable_total);
                } elseif ($r['type'] === 'percent') {
                    $savings = $redeemable_total * (min(floatval($r['value']), 100) / 100);
                } elseif ($r['type'] === 'product') {
                    $target_pid = intval($r['value']);
                    $product = wc_get_product($target_pid);
                    if ($product) {
                        $savings = $product->get_price();
                    }
                }
                
                if ($savings > $max_savings) {
                    $max_savings = $savings;
                    $best_rule_index = $idx;
                }
            }

            echo '<div class="jify-redemption-rules" style="margin-top:15px; border-top:1px solid #eee; padding-top:10px;">';
            echo '<p style="font-weight:bold; margin-bottom:5px;">或選擇兌換優惠：</p>';
            foreach ($rules as $index => $rule) {
                if ($balance < $rule['points']) {
                    echo '<p style="color:#999; margin:0 0 5px 0;"><label><input type="radio" disabled> ' . esc_html($rule['desc']) . ' (點數不足)</label></p>';
                } else {
                    $is_best = ($index === $best_rule_index);
                    $checked = $is_best ? 'checked' : '';
                    $label_suffix = $is_best ? ' <span style="color:#e67e22; font-weight:bold; font-size:12px; background:#fff3cd; padding:2px 5px; border-radius:3px;">⭐ 最高回饋</span>' : '';
                    
                    echo '<p style="margin:0 0 5px 0;"><label><input type="radio" name="jify_redemption_rule" value="' . $index . '" ' . $checked . '> ' . esc_html($rule['desc']) . $label_suffix . '</label></p>';
                }
            }
            $btn_style = ($best_rule_index !== -1) ? 'margin-top:5px;' : 'margin-top:5px; display:none;';
            echo '<button type="button" class="button alt" id="jify_apply_rule" style="' . $btn_style . '">確認兌換</button>';
            echo '</div>';
        }
        
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('input[name="jify_redemption_rule"]').change(function() {
                $('#jify_apply_rule').show().prop('disabled', false).text('確認兌換').removeClass('applied');
            });
            $('#jify_redeem_points').on('input', function() {
                $('#jify_apply_points').prop('disabled', false).text('折抵').removeClass('applied');
            });
            function showMessage(msg, type) {
                var color = (type === 'success') ? '#0f834d' : '#e2401c';
                $('#jify-redemption-messages').html('<p style="color:'+color+';">' + msg + '</p>');
            }
            $('#jify_apply_points').click(function() {
                var btn = $(this);
                if (btn.hasClass('applied')) return; 
                var points = $('#jify_redeem_points').val();
                if (points <= 0) return;
                btn.addClass('loading');
                $.ajax({
                    type: 'POST',
                    url: wc_checkout_params.ajax_url,
                    data: { action: 'jify_apply_points', points: points, type: 'custom_cash' },
                    success: function(response) {
                        btn.removeClass('loading');
                        if (response.success) {
                            showMessage('折抵成功！', 'success');
                            btn.prop('disabled', true).text('已套用').addClass('applied');
                            $('body').trigger('update_checkout');
                        } else {
                            showMessage(response.data.message, 'error');
                        }
                    }
                });
            });
            $('#jify_apply_rule').click(function() {
                var btn = $(this);
                if (btn.hasClass('applied')) return;
                var ruleIndex = $('input[name="jify_redemption_rule"]:checked').val();
                if (ruleIndex === undefined) return;
                btn.addClass('loading');
                $.ajax({
                    type: 'POST',
                    url: wc_checkout_params.ajax_url,
                    data: { action: 'jify_apply_points', rule_index: ruleIndex, type: 'rule' },
                    success: function(response) {
                        btn.removeClass('loading');
                        if (response.success) {
                            showMessage('兌換成功！', 'success');
                            btn.prop('disabled', true).text('已套用').addClass('applied');
                            $('body').trigger('update_checkout');
                        } else {
                            showMessage(response.data.message, 'error');
                        }
                    }
                });
            });
        });
        </script>
        <?php
        echo '</div>';
    }

    public function add_admin_menu() {
        global $admin_page_hooks;
        if (isset($admin_page_hooks['jify-setting'])) {
            add_submenu_page('jify-setting', 'Jify Loyalty 設定', 'Jify Loyalty', 'manage_options', 'jify-loyalty', array($this, 'render_admin_page'));
        } else {
            add_menu_page('Jify Loyalty 設定', 'Jify Loyalty', 'manage_options', 'jify-loyalty', array($this, 'render_admin_page'), 'dashicons-awards', 56);
        }
    }

    public function register_settings() {
        register_setting('jify_loyalty_options', 'jify_loyalty_conversion_rate');
        register_setting('jify_loyalty_options', 'jify_loyalty_point_name');
        register_setting('jify_loyalty_options', 'jify_loyalty_line_channel_id');
        register_setting('jify_loyalty_options', 'jify_loyalty_line_channel_secret');
        register_setting('jify_loyalty_options', 'jify_loyalty_allowed_categories');
        register_setting('jify_loyalty_options', 'jify_loyalty_allowed_tags');
        register_setting('jify_loyalty_options', 'jify_loyalty_specific_product_ids');
        
        // Earning Strategy Settings
        register_setting('jify_loyalty_options', 'jify_loyalty_earning_strategy'); // 'rate' or 'fixed'
        register_setting('jify_loyalty_options', 'jify_loyalty_earning_cap'); // Max points per order
        register_setting('jify_loyalty_options', 'jify_loyalty_earning_fixed_threshold'); // Spend X
        register_setting('jify_loyalty_options', 'jify_loyalty_earning_fixed_amount'); // Get Y

        // Redemption Settings
        register_setting('jify_loyalty_options', 'jify_loyalty_redemption_rate'); 
        register_setting('jify_loyalty_options', 'jify_loyalty_redemption_rules');
        register_setting('jify_loyalty_options', 'jify_loyalty_redemption_allowed_categories');
        register_setting('jify_loyalty_options', 'jify_loyalty_redemption_allowed_tags');
        register_setting('jify_loyalty_options', 'jify_loyalty_redemption_specific_product_ids');
        
        // Message Settings
        register_setting('jify_loyalty_options', 'jify_loyalty_msg_thankyou_title');
        register_setting('jify_loyalty_options', 'jify_loyalty_msg_thankyou_content');
        register_setting('jify_loyalty_options', 'jify_loyalty_msg_thankyou_note');
        register_setting('jify_loyalty_options', 'jify_loyalty_msg_bind_cta');
        register_setting('jify_loyalty_options', 'jify_loyalty_line_access_token');
        register_setting('jify_loyalty_options', 'jify_loyalty_msg_line_notify');
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) return;
        
        if (isset($_POST['jify_manual_adjust']) && check_admin_referer('jify_manual_adjust_action')) {
            $email = sanitize_email($_POST['user_email']);
            $points = intval($_POST['points']);
            $desc = sanitize_text_field($_POST['description']);
            $user = get_user_by('email', $email);
            if ($user) {
                $this->adjust_points($user->ID, $points, $desc . ' (管理員手動調整)');
                echo '<div class="notice notice-success is-dismissible"><p>已成功為 ' . esc_html($user->display_name) . ' 調整 ' . $points . ' 點。</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>找不到該 Email 的使用者。</p></div>';
            }
        }
        
        $earning_strategy = get_option('jify_loyalty_earning_strategy', 'rate');
        ?>
        <div class="wrap">
            <h1>Jify Loyalty 點數銀行設定</h1>
            <h2 class="nav-tab-wrapper">
                <a href="#settings" class="nav-tab nav-tab-active" onclick="showTab(event, 'settings')">一般設定 (LINE)</a>
                <a href="#display" class="nav-tab" onclick="showTab(event, 'display')">訊息設定</a>
                <a href="#rules" class="nav-tab" onclick="showTab(event, 'rules')">集點規則</a>
                <a href="#redemption" class="nav-tab" onclick="showTab(event, 'redemption')">兌換/折抵設定</a>
                <a href="#members" class="nav-tab" onclick="showTab(event, 'members')">會員列表</a>
                <a href="#manual" class="nav-tab" onclick="showTab(event, 'manual')">手動操作</a>
            </h2>

            <form method="post" action="options.php">
                <?php settings_fields('jify_loyalty_options'); ?>
                <?php do_settings_sections('jify_loyalty_options'); ?>

                <div id="settings" class="tab-content">
                    <table class="form-table">
                        <tr><th colspan="2"><h3>LINE Login 設定</h3></th></tr>
                        <tr valign="top"><th scope="row">Channel ID</th><td><input type="text" name="jify_loyalty_line_channel_id" value="<?php echo esc_attr(get_option('jify_loyalty_line_channel_id')); ?>" style="width: 300px;" /></td></tr>
                        <tr valign="top"><th scope="row">Channel Secret</th><td><input type="password" name="jify_loyalty_line_channel_secret" value="<?php echo esc_attr(get_option('jify_loyalty_line_channel_secret')); ?>" style="width: 300px;" /></td></tr>
                        <tr valign="top"><th scope="row">Callback URL</th><td><code><?php echo esc_url(wc_get_account_endpoint_url('jify-points')); ?></code></td></tr>
                        <tr><th colspan="2"><h3>LINE Messaging API 設定</h3></th></tr>
                        <tr valign="top"><th scope="row">Channel Access Token</th><td><input type="password" name="jify_loyalty_line_access_token" value="<?php echo esc_attr(get_option('jify_loyalty_line_access_token')); ?>" style="width: 400px;" /></td></tr>
                    </table>
                    <?php submit_button(); ?>
                </div>

                <div id="display" class="tab-content" style="display:none;">
                    <h3>感謝頁面 (Thank You Page) 訊息</h3>
                    <table class="form-table">
                        <tr valign="top"><th scope="row">恭喜標題</th><td><input type="text" name="jify_loyalty_msg_thankyou_title" value="<?php echo esc_attr(get_option('jify_loyalty_msg_thankyou_title', '恭喜您！')); ?>" class="regular-text" /></td></tr>
                        <tr valign="top"><th scope="row">獲點內容</th><td><textarea name="jify_loyalty_msg_thankyou_content" rows="3" class="large-text"><?php echo esc_textarea(get_option('jify_loyalty_msg_thankyou_content', '本筆訂單預計為您賺得 {points} {point_name}！')); ?></textarea></td></tr>
                        <tr valign="top"><th scope="row">底部備註</th><td><input type="text" name="jify_loyalty_msg_thankyou_note" value="<?php echo esc_attr(get_option('jify_loyalty_msg_thankyou_note', '(點數將於訂單狀態變更為「已完成」後自動入帳)')); ?>" class="large-text" /></td></tr>
                        <tr valign="top"><th scope="row">LINE 綁定引導語</th><td><input type="text" name="jify_loyalty_msg_bind_cta" value="<?php echo esc_attr(get_option('jify_loyalty_msg_bind_cta', '想要即時收到點數入帳通知嗎？')); ?>" class="large-text" /></td></tr>
                        <tr><th colspan="2"><h3>LINE 通知訊息範本</h3></th></tr>
                        <tr valign="top"><th scope="row">點數變動通知</th><td><textarea name="jify_loyalty_msg_line_notify" rows="6" class="large-text"><?php echo esc_textarea(get_option('jify_loyalty_msg_line_notify', "親愛的會員您好，\n您的帳戶點數已有變動：\n\n變動：{change}\n原因：{reason}\n目前餘額：{balance} {point_name}\n\n感謝您的支持！")); ?></textarea></td></tr>
                    </table>
                    <?php submit_button(); ?>
                </div>

                <div id="rules" class="tab-content" style="display:none;">
                    <h3>基本設定</h3>
                    <table class="form-table">
                        <tr valign="top"><th scope="row">點數名稱</th><td><input type="text" name="jify_loyalty_point_name" value="<?php echo esc_attr(get_option('jify_loyalty_point_name', '點數')); ?>" /></td></tr>
                    </table>
                    
                    <h3>集點計算策略</h3>
                    <p>請選擇一種點數計算方式：</p>
                    
                    <fieldset style="background: #fff; border: 1px solid #ddd; padding: 20px; border-radius: 4px;">
                        <label style="display: block; margin-bottom: 15px; font-weight: bold; font-size: 14px;">
                            <input type="radio" name="jify_loyalty_earning_strategy" value="rate" <?php checked($earning_strategy, 'rate'); ?> onclick="toggleStrategy('rate')"> 
                            模式 A：依匯率累計 (Rate Based)
                        </label>
                        <div id="strategy-rate-settings" style="padding-left: 25px; margin-bottom: 20px; <?php echo $earning_strategy === 'rate' ? '' : 'display:none;'; ?>">
                            <p>
                                每消費 <input type="number" step="0.01" name="jify_loyalty_conversion_rate" value="<?php echo esc_attr(get_option('jify_loyalty_conversion_rate', 100)); ?>" class="small-text"> 元 = 1 點
                            </p>
                            <p>
                                <label for="jify_loyalty_earning_cap">單筆訂單上限 (Cap)：</label>
                                <input type="number" name="jify_loyalty_earning_cap" id="jify_loyalty_earning_cap" value="<?php echo esc_attr(get_option('jify_loyalty_earning_cap', 0)); ?>" class="small-text"> 點
                                <br><span class="description">設定為 0 代表無上限。若設定為 500，則無論消費金額多高，單筆最多只送 500 點。</span>
                            </p>
                        </div>

                        <hr style="border-top: 1px dashed #ddd; margin: 15px 0;">

                        <label style="display: block; margin-bottom: 15px; font-weight: bold; font-size: 14px;">
                            <input type="radio" name="jify_loyalty_earning_strategy" value="fixed" <?php checked($earning_strategy, 'fixed'); ?> onclick="toggleStrategy('fixed')"> 
                            模式 B：滿額定額贈送 (Fixed Threshold)
                        </label>
                        <div id="strategy-fixed-settings" style="padding-left: 25px; margin-bottom: 10px; <?php echo $earning_strategy === 'fixed' ? '' : 'display:none;'; ?>">
                            <p>
                                單筆訂單滿 <input type="number" name="jify_loyalty_earning_fixed_threshold" value="<?php echo esc_attr(get_option('jify_loyalty_earning_fixed_threshold', 1000)); ?>" class="small-text"> 元，
                                即贈送 <input type="number" name="jify_loyalty_earning_fixed_amount" value="<?php echo esc_attr(get_option('jify_loyalty_earning_fixed_amount', 100)); ?>" class="small-text"> 點。
                            </p>
                            <span class="description">此模式下，點數為固定值，<b>不隨消費金額增加而累加</b>。例如：設定滿 200 元送 1 點，則消費 200 元或 2000 元皆只會獲得 1 點。未達門檻則不贈送。</span>
                        </div>
                    </fieldset>

                    <script>
                    function toggleStrategy(val) {
                        if(val === 'rate') {
                            document.getElementById('strategy-rate-settings').style.display = 'block';
                            document.getElementById('strategy-fixed-settings').style.display = 'none';
                        } else {
                            document.getElementById('strategy-rate-settings').style.display = 'none';
                            document.getElementById('strategy-fixed-settings').style.display = 'block';
                        }
                    }
                    </script>

                    <hr>
                    <h3>適用商品範圍</h3>
                    <p class="description">若未勾選任何選項，則預設為<b>「全館商品」</b>皆可集點。</p>
                    <table class="form-table">
                        <tr valign="top"><th scope="row">指定分類</th>
                            <td>
                                <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">
                                    <?php
                                    $selected_cats = get_option('jify_loyalty_allowed_categories', array());
                                    if (!is_array($selected_cats)) $selected_cats = array();
                                    $cats = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
                                    if (!empty($cats) && !is_wp_error($cats)) {
                                        foreach ($cats as $cat) {
                                            $checked = in_array($cat->term_id, $selected_cats) ? 'checked' : '';
                                            echo '<label style="display:block;"><input type="checkbox" name="jify_loyalty_allowed_categories[]" value="' . esc_attr($cat->term_id) . '" ' . $checked . '> ' . esc_html($cat->name) . '</label>';
                                        }
                                    }
                                    ?>
                                </div>
                            </td>
                        </tr>
                        <tr valign="top"><th scope="row">指定標籤</th>
                            <td>
                                <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">
                                    <?php
                                    $selected_tags = get_option('jify_loyalty_allowed_tags', array());
                                    if (!is_array($selected_tags)) $selected_tags = array();
                                    $tags = get_terms(array('taxonomy' => 'product_tag', 'hide_empty' => false));
                                    if (!empty($tags) && !is_wp_error($tags)) {
                                        foreach ($tags as $tag) {
                                            $checked = in_array($tag->term_id, $selected_tags) ? 'checked' : '';
                                            echo '<label style="display:block;"><input type="checkbox" name="jify_loyalty_allowed_tags[]" value="' . esc_attr($tag->term_id) . '" ' . $checked . '> ' . esc_html($tag->name) . '</label>';
                                        }
                                    }
                                    ?>
                                </div>
                            </td>
                        </tr>
                        <tr valign="top"><th scope="row">指定商品 ID</th><td><input type="text" name="jify_loyalty_specific_product_ids" class="regular-text" value="<?php echo esc_attr(get_option('jify_loyalty_specific_product_ids')); ?>"><p class="description">多個 ID 請用逗號分隔。</p></td></tr>
                    </table>
                    <?php submit_button(); ?>
                </div>

                <div id="redemption" class="tab-content" style="display:none;">
                    <h3>折抵適用範圍</h3>
                    <p class="description">設定點數可以用來折抵哪些商品的金額。若未勾選 任何選項，則預設為<b>「全館商品」</b>皆可折抵。</p>
                    <table class="form-table">
                        <tr valign="top"><th scope="row">指定分類</th>
                            <td>
                                <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">
                                    <?php
                                    $redemption_cats = get_option('jify_loyalty_redemption_allowed_categories', array());
                                    if (!is_array($redemption_cats)) $redemption_cats = array();
                                    $cats = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
                                    if (!empty($cats) && !is_wp_error($cats)) {
                                        foreach ($cats as $cat) {
                                            $checked = in_array($cat->term_id, $redemption_cats) ? 'checked' : '';
                                            echo '<label style="display:block;"><input type="checkbox" name="jify_loyalty_redemption_allowed_categories[]" value="' . esc_attr($cat->term_id) . '" ' . $checked . '> ' . esc_html($cat->name) . '</label>';
                                        }
                                    }
                                    ?>
                                </div>
                            </td>
                        </tr>
                        <tr valign="top"><th scope="row">指定標籤</th>
                            <td>
                                <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">
                                    <?php
                                    $redemption_tags = get_option('jify_loyalty_redemption_allowed_tags', array());
                                    if (!is_array($redemption_tags)) $redemption_tags = array();
                                    $tags = get_terms(array('taxonomy' => 'product_tag', 'hide_empty' => false));
                                    if (!empty($tags) && !is_wp_error($tags)) {
                                        foreach ($tags as $tag) {
                                            $checked = in_array($tag->term_id, $redemption_tags) ? 'checked' : '';
                                            echo '<label style="display:block;"><input type="checkbox" name="jify_loyalty_redemption_allowed_tags[]" value="' . esc_attr($tag->term_id) . '" ' . $checked . '> ' . esc_html($tag->name) . '</label>';
                                        }
                                    }
                                    ?>
                                </div>
                            </td>
                        </tr>
                        <tr valign="top"><th scope="row">指定商品 ID</th><td><input type="text" name="jify_loyalty_redemption_specific_product_ids" class="regular-text" value="<?php echo esc_attr(get_option('jify_loyalty_redemption_specific_product_ids')); ?>"><p class="description">多個 ID 請用逗號分隔。</p></td></tr>
                    </table>
                    <hr>
                    <h3>模式一：直接現金折抵</h3>
                    <table class="form-table"><tr valign="top"><th scope="row">折抵匯率</th><td><input type="number" step="0.01" name="jify_loyalty_redemption_rate" value="<?php echo esc_attr(get_option('jify_loyalty_redemption_rate', '0')); ?>" /></td></tr></table>
                    <hr>
                    <h3>模式二：固定兌換規則</h3>
                    <table class="widefat" style="margin-top: 15px;">
                        <thead><tr><th>所需點數</th><th>兌換類型</th><th>回饋數值</th><th>前台描述</th><th>操作</th></tr></thead>
                        <tbody id="redemption-rules-list">
                            <?php 
                            $rules = get_option('jify_loyalty_redemption_rules', array());
                            if (!is_array($rules)) $rules = array();
                            foreach ($rules as $index => $rule) : ?>
                            <tr class="rule-row">
                                <td><input type="number" name="jify_loyalty_redemption_rules[<?php echo $index; ?>][points]" value="<?php echo esc_attr($rule['points']); ?>" class="small-text" required></td>
                                <td><select name="jify_loyalty_redemption_rules[<?php echo $index; ?>][type]" class="rule-type-select"><option value="fixed" <?php selected($rule['type'], 'fixed'); ?>>折抵現金</option><option value="percent" <?php selected($rule['type'], 'percent'); ?>>百分比折扣</option><option value="product" <?php selected($rule['type'], 'product'); ?>>兌換指定商品</option></select></td>
                                <td><input type="number" step="0.01" name="jify_loyalty_redemption_rules[<?php echo $index; ?>][value]" value="<?php echo esc_attr($rule['value']); ?>" class="small-text rule-value-input" required></td>
                                <td><input type="text" name="jify_loyalty_redemption_rules[<?php echo $index; ?>][desc]" value="<?php echo esc_attr($rule['desc']); ?>" class="regular-text" required></td>
                                <td><button type="button" class="button remove-rule">移除</button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p><button type="button" class="button" id="add-redemption-rule">+ 新增規則</button></p>
                    <?php submit_button(); ?>
                </div>
                
            </form>

            <div id="members" class="tab-content" style="display:none;">
                <form method="post" style="margin-bottom: 20px;">
                    <input type="text" name="member_search" placeholder="搜尋姓名或 Email..." value="<?php echo isset($_POST['member_search']) ? esc_attr($_POST['member_search']) : ''; ?>" />
                    <input type="submit" class="button" value="搜尋" />
                    <?php if (isset($_POST['member_search'])) : ?><a href="?page=jify-loyalty" class="button">清除</a><?php endif; ?>
                </form>
                <table class="widefat striped">
                    <thead><tr><th>ID</th><th>姓名</th><th>Email</th><th>LINE 狀態</th><th>目前點數</th><th>操作</th></tr></thead>
                    <tbody>
                        <?php
                        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
                        $args = array('number' => 20, 'paged' => $paged);
                        if (isset($_POST['member_search']) && !empty($_POST['member_search'])) {
                            $term = sanitize_text_field($_POST['member_search']);
                            $args['search'] = '*' . $term . '*';
                            $args['search_columns'] = array('user_login', 'user_email', 'display_name');
                        }
                        $user_query = new WP_User_Query($args);
                        $users = $user_query->get_results();
                        if (!empty($users)) {
                            foreach ($users as $user) {
                                $line_id = get_user_meta($user->ID, 'jify_line_user_id', true);
                                $balance = $this->get_user_points($user->ID);
                                ?>
                                <tr>
                                    <td><?php echo $user->ID; ?></td>
                                    <td><?php echo esc_html($user->display_name); ?></td>
                                    <td><?php echo esc_html($user->user_email); ?></td>
                                    <td><?php echo $line_id ? '<span style="color:green;">✅ 已綁定</span>' : '<span style="color:#999;">❌ 未綁定</span>'; ?></td>
                                    <td><strong><?php echo number_format($balance); ?></strong></td>
                                    <td><button type="button" class="button button-small" onclick="fillManualAdjust('<?php echo esc_js($user->user_email); ?>')">調整點數</button></td>
                                </tr>
                                <?php
                            }
                        } else { echo '<tr><td colspan="6">找不到會員資料。</td></tr>'; }
                        ?>
                    </tbody>
                </table>
            </div>

            <div id="manual" class="tab-content" style="display:none;">
                <h3>手動調整會員點數</h3>
                <form method="post" action=""><?php wp_nonce_field('jify_manual_adjust_action'); ?>
                    <table class="form-table">
                        <tr><th>會員 Email</th><td><input type="email" name="user_email" required class="regular-text"></td></tr>
                        <tr><th>調整點數</th><td><input type="number" name="points" required class="small-text"></td></tr>
                        <tr><th>備註原因</th><td><input type="text" name="description" required class="regular-text" placeholder="原因備註"></td></tr>
                    </table>
                    <input type="submit" name="jify_manual_adjust" class="button button-primary" value="確認調整">
                </form>
            </div>
        </div>
        <script>
        function showTab(evt, tabName) {
            var i, tabcontent, navtab;
            tabcontent = document.getElementsByClassName("tab-content");
            for (i = 0; i < tabcontent.length; i++) { tabcontent[i].style.display = "none"; }
            navtab = document.getElementsByClassName("nav-tab");
            for (i = 0; i < navtab.length; i++) { navtab[i].className = navtab[i].className.replace(" nav-tab-active", ""); }
            document.getElementById(tabName).style.display = "block";
            if(evt) evt.currentTarget.className += " nav-tab-active";
        }
        function fillManualAdjust(email) {
            var manualTabLink = document.querySelector('a[href="#manual"]');
            if(manualTabLink) { manualTabLink.click(); }
            var emailInput = document.querySelector('input[name="user_email"]');
            if(emailInput) { emailInput.value = email; document.querySelector('input[name="points"]').focus(); }
        }
        document.addEventListener('DOMContentLoaded', function() {
            var list = document.getElementById('redemption-rules-list');
            var addBtn = document.getElementById('add-redemption-rule');
            if (addBtn && list) {
                addBtn.addEventListener('click', function() {
                    var count = list.querySelectorAll('tr').length;
                    var row = document.createElement('tr');
                    row.className = 'rule-row';
                    row.innerHTML = `<td><input type="number" name="jify_loyalty_redemption_rules[${count}][points]" class="small-text" required></td><td><select name="jify_loyalty_redemption_rules[${count}][type]" class="rule-type-select"><option value="fixed">折抵現金</option><option value="percent">百分比折扣</option><option value="product">兌換指定商品</option></select></td><td><input type="number" step="0.01" name="jify_loyalty_redemption_rules[${count}][value]" class="small-text rule-value-input" required></td><td><input type="text" name="jify_loyalty_redemption_rules[${count}][desc]" class="regular-text" required></td><td><button type="button" class="button remove-rule">移除</button></td>`;
                    list.appendChild(row);
                });
                list.addEventListener('click', function(e) { if (e.target && e.target.classList.contains('remove-rule')) { e.target.closest('tr').remove(); } });
            }
        });
        </script>
        <?php
    }

    public function install_db() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'jify_loyalty_logs';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table_name ( id mediumint(9) NOT NULL AUTO_INCREMENT, user_id bigint(20) NOT NULL, points int(11) NOT NULL, description varchar(255) NOT NULL, reference_id varchar(50) DEFAULT '', created_at datetime DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id) ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        $this->add_my_account_endpoint();
        flush_rewrite_rules();
    }

    public function get_user_points($user_id) {
        $points = get_user_meta($user_id, 'jify_point_balance', true);
        return $points ? intval($points) : 0;
    }

    public function adjust_points($user_id, $points, $description, $reference_id = '') {
        $current_points = $this->get_user_points($user_id);
        $new_balance = $current_points + $points;
        update_user_meta($user_id, 'jify_point_balance', $new_balance);
        global $wpdb;
        $table_name = $wpdb->prefix . 'jify_loyalty_logs';
        $wpdb->insert($table_name, array('user_id' => $user_id, 'points' => $points, 'description' => $description, 'reference_id' => $reference_id, 'created_at' => current_time('mysql')));
        $this->send_line_push_message($user_id, $points, $description, $new_balance);
    }

    private function send_line_push_message($user_id, $points, $reason, $balance) {
        $line_uid = get_user_meta($user_id, 'jify_line_user_id', true);
        $token = get_option('jify_loyalty_line_access_token');
        if (empty($line_uid) || empty($token)) return;
        $point_name = get_option('jify_loyalty_point_name', '點數');
        $change_str = ($points > 0 ? '+' : '') . number_format($points);
        $template = get_option('jify_loyalty_msg_line_notify', "親愛的會員您好，\n您的帳戶點數已有變動：\n\n變動：{change}\n原因：{reason}\n目前餘額：{balance} {point_name}\n\n感謝您的支持！");
        $msg = str_replace(array('{change}', '{reason}', '{balance}', '{point_name}'), array($change_str, $reason, number_format($balance), $point_name), $template);
        wp_remote_post('https://api.line.me/v2/bot/message/push', array('headers' => array('Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $token), 'body' => json_encode(array('to' => $line_uid, 'messages' => array(array('type' => 'text', 'text' => $msg))))));
    }

    public function award_points_on_order_complete($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $user_id = $order->get_user_id();
        if (!$user_id) {
            $email = $order->get_billing_email();
            if ($email && is_email($email)) {
                $user = get_user_by('email', $email);
                if ($user) $user_id = $user->ID;
            }
        }
        if (!$user_id) return;

        global $wpdb;
        $table_name = $wpdb->prefix . 'jify_loyalty_logs';
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE user_id = %d AND reference_id = %s", $user_id, 'order_' . $order_id));
        if ($existing) return;

        $points_to_award = $this->calculate_order_points($order);

        if ($points_to_award > 0) {
            $this->adjust_points($user_id, $points_to_award, "訂單完成回饋 (Order #$order_id)", 'order_' . $order_id);
            $order->add_order_note("Jify Loyalty: 已發放 $points_to_award 點。");
        }
    }

    private function calculate_order_points($order) {
        $allowed_cats = get_option('jify_loyalty_allowed_categories', array());
        $allowed_tags = get_option('jify_loyalty_allowed_tags', array());
        $specific_ids_str = get_option('jify_loyalty_specific_product_ids', '');
        $specific_ids = !empty($specific_ids_str) ? array_map('intval', explode(',', $specific_ids_str)) : array();
        $has_restrictions = (!empty($allowed_cats) || !empty($allowed_tags) || !empty($specific_ids));

        $eligible_total = 0;
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $line_total = floatval($item->get_total());
            if (!$has_restrictions || in_array($product_id, $specific_ids) || has_term($allowed_cats, 'product_cat', $product_id) || has_term($allowed_tags, 'product_tag', $product_id)) {
                $eligible_total += $line_total;
            }
        }

        $strategy = get_option('jify_loyalty_earning_strategy', 'rate');

        if ($strategy === 'fixed') {
            // Mode B: Fixed Threshold
            $threshold = intval(get_option('jify_loyalty_earning_fixed_threshold', 1000));
            $fixed_points = intval(get_option('jify_loyalty_earning_fixed_amount', 100));
            
            if ($eligible_total >= $threshold) {
                return $fixed_points;
            } else {
                return 0;
            }
        } else {
            // Mode A: Rate Based (Default)
            $rate = get_option('jify_loyalty_conversion_rate', JIFY_POINTS_CONVERSION_RATE_DEFAULT);
            if ($rate <= 0) $rate = 100;
            
            $points = floor($eligible_total / $rate);
            
            // Apply Cap if set
            $cap = intval(get_option('jify_loyalty_earning_cap', 0));
            if ($cap > 0 && $points > $cap) {
                $points = $cap;
            }
            
            return $points;
        }
    }

    public function display_earned_points_on_thankyou($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $points = $this->calculate_order_points($order);
        if ($points <= 0) return;

        $point_name = get_option('jify_loyalty_point_name', '點數');
        $user_id = $order->get_user_id();
        $is_bound = $user_id ? get_user_meta($user_id, 'jify_line_user_id', true) : false;

        $title = get_option('jify_loyalty_msg_thankyou_title', '恭喜您！');
        $raw_content = get_option('jify_loyalty_msg_thankyou_content', '本筆訂單預計為您賺得 {points} {point_name}！');
        $note = get_option('jify_loyalty_msg_thankyou_note', '(點數將於訂單狀態變更為「已完成」後自動入帳)');
        $cta_text = get_option('jify_loyalty_msg_bind_cta', '想要即時收到點數入帳通知嗎？');

        $dashboard_url = wc_get_account_endpoint_url('jify-points');
        $points_html = '<a href="' . esc_url($dashboard_url) . '" style="color: #e67e22; font-size: 24px; font-weight: 800; text-decoration: underline;">' . number_format($points) . '</a>';
        
        $content = str_replace('{points}', $points_html, esc_html($raw_content)); 
        $content = str_replace('{points}', $points_html, $content); 
        $content = str_replace('{point_name}', esc_html($point_name), $content);

        ?>
        <div class="jify-loyalty-thankyou" style="background: linear-gradient(135deg, #fff9e6 0%, #fffbf0 100%); border: 2px dashed #f1c40f; padding: 20px; border-radius: 10px; margin: 20px 0; text-align: center;">
            <div style="font-size: 40px; margin-bottom: 10px;">🎉</div>
            <h3 style="margin: 0 0 10px 0; color: #d35400;"><?php echo esc_html($title); ?></h3>
            <p style="font-size: 18px; margin: 0;"><?php echo $content; ?></p>
            <p style="font-size: 14px; color: #7f8c8d; margin-top: 5px;"><?php echo esc_html($note); ?></p>
            <?php if (!$is_bound) : ?>
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #f9e79f;">
                    <p style="font-size: 15px; margin-bottom: 10px;"><?php echo esc_html($cta_text); ?></p>
                    <a href="<?php echo esc_url(wc_get_account_endpoint_url('jify-points')); ?>" class="button" style="background-color: #06C755; color: white; border: none;">綁定 LINE 帳號</a>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function add_my_account_endpoint() { add_rewrite_endpoint('jify-points', EP_ROOT | EP_PAGES); }
    public function add_query_vars($vars) { $vars[] = 'jify-points'; $vars[] = 'code'; $vars[] = 'state'; return $vars; }
    public function add_my_account_menu_item($items) {
        $logout = $items['customer-logout']; unset($items['customer-logout']);
        $name = get_option('jify_loyalty_point_name', '我的集點卡');
        $items['jify-points'] = $name;
        $items['customer-logout'] = $logout;
        return $items;
    }

    private function get_line_login_url() {
        $callback_url = wc_get_account_endpoint_url('jify-points');
        $channel_id = get_option('jify_loyalty_line_channel_id');
        if (empty($channel_id)) return '#';
        $query = array('response_type' => 'code', 'client_id' => $channel_id, 'redirect_uri' => $callback_url, 'state' => wp_create_nonce('jify_line_login'), 'scope' => 'profile openid');
        return 'https://access.line.me/oauth2/v2.1/authorize?' . http_build_query($query);
    }

    public function render_my_account_points_page() {
        $user_id = get_current_user_id();
        $balance = $this->get_user_points($user_id);
        $line_uid = get_user_meta($user_id, 'jify_line_user_id', true);
        $point_name = get_option('jify_loyalty_point_name', '點數');

        echo '<div class="jify-loyalty-dashboard" style="background:#fff; padding:30px; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,0.05);">';
        echo '<h3 style="margin-top:0; margin-bottom:20px; font-weight:700; color:#333;">Jify 會員中心</h3>';
        echo '<div style="background: linear-gradient(135deg, #0F2027 0%, #203A43 50%, #2C5364 100%); color: white; padding: 30px; border-radius: 16px; margin-bottom: 30px; position: relative; overflow: hidden; box-shadow: 0 10px 20px rgba(15, 32, 39, 0.4); border: 1px solid rgba(255,255,255,0.1);">';
        echo '<div style="position:absolute; top:-50px; right:-50px; width:150px; height:150px; background:rgba(255,255,255,0.05); border-radius:50%;"></div>';
        echo '<p style="margin:0; opacity:0.8; font-size:14px; text-transform:uppercase; letter-spacing:1px;">Current Balance</p>';
        echo '<div style="display:flex; align-items:baseline; margin-top:10px;">';
        echo '<h2 style="margin:0; font-size:48px; font-weight:800; color:#00FFA3; text-shadow: 0 0 20px rgba(0,255,163,0.4);">' . number_format($balance) . '</h2>';
        echo '<span style="font-size:18px; margin-left:10px; font-weight:500;">' . esc_html($point_name) . '</span>';
        echo '</div>';
        echo '</div>';

        echo '<div style="margin-bottom:30px; border:1px solid #eee; padding:20px; border-radius:12px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:15px;">';
        echo '<div><h4 style="margin:0 0 5px 0;">LINE 帳號綁定</h4><p style="margin:0; color:#666; font-size:14px;">綁定後可即時接收點數通知。</p></div>';
        if ($line_uid) { echo '<div style="text-align:right;"><span style="color:#06C755; font-weight:bold; margin-right:15px;">✅ 已連結 LINE</span><a href="?jify_action=unbind_line" class="button" style="border-radius:20px;">解除</a></div>'; } else { echo '<a href="' . esc_url($this->get_line_login_url()) . '" class="button" style="background:#06C755; color:white; border-radius:20px; padding:10px 25px; height:auto; line-height:1.5; border:none; box-shadow:0 4px 10px rgba(6,199,85,0.3);">連結 LINE 帳號</a>'; }
        echo '</div>';

        echo '<h4 style="margin-bottom:15px; font-weight:600;">點數紀錄</h4>';
        global $wpdb; 
        $table_name = $wpdb->prefix . 'jify_loyalty_logs';
        $logs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE user_id = %d ORDER BY created_at DESC LIMIT 10", $user_id));
        if ($logs) { 
            echo '<div style="overflow-x:auto;"><table class="shop_table" style="width:100%; border-collapse:collapse; border-radius:8px; overflow:hidden;"><thead><tr style="background:#f5f5f5; text-align:left;"><th style="padding:15px;">日期</th><th>描述</th><th style="text-align:right; padding:15px;">變動</th></tr></thead><tbody>';
            foreach ($logs as $log) { 
                $color = $log->points > 0 ? '#00b894' : '#ff7675';
                $sign = $log->points > 0 ? '+' : '';
                echo '<tr style="border-bottom:1px solid #eee;"><td style="padding:15px; color:#888; font-size:13px;">' . date('Y-m-d', strtotime($log->created_at)) . '</td><td style="padding:15px;">' . esc_html($log->description) . '</td><td style="text-align:right; padding:15px; font-weight:bold; color:' . $color . ';">' . $sign . number_format($log->points) . '</td></tr>'; 
            }
            echo '</tbody></table></div>'; 
        } else { echo '<p style="color:#999; text-align:center; padding:30px; background:#f9f9f9; border-radius:8px;">尚無紀錄。</p>'; }
        echo '</div>';
    }

    public function add_line_login_button() {
        $url = $this->get_line_login_url();
        if ($url !== '#') {
            ?>
            <style>
            .jify-line-login-wrapper { margin-top: 15px; margin-bottom: 15px; width: 100%; text-align: center; }
            .jify-line-login-btn { display: flex; align-items: center; justify-content: center; background-color: #06C755; color: #fff !important; text-decoration: none !important; font-weight: bold; font-size: 14px; border-radius: 4px; padding: 10px 15px; border: none; transition: background-color 0.3s; width: 100%; box-sizing: border-box; line-height: 1.2; cursor: pointer; }
            .jify-line-login-btn:hover { background-color: #05b34c; color: #fff !important; }
            .jify-line-login-icon { width: 20px; height: 20px; margin-right: 10px; fill: #fff; }
            </style>
            <div class="jify-line-login-wrapper"><a href="<?php echo esc_url($url); ?>" class="jify-line-login-btn"><svg class="jify-line-login-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M20.3 10.6c0-4.6-4.5-8.3-10-8.3-5.5 0-10 3.8-10 8.3 0 4.1 3.6 7.5 8.4 8.2.3.1.8.2.9.6l.2 1.3c.1.5-.3 2 .1 2.1.5.1 2.5-1.5 3.5-2.6 1.7-1.7 6.9-3.9 6.9-9.6zM9.4 12.8h-1c-.2 0-.4-.2-.4-.4V9.6h-.6v2.8c0 .2-.2.4-.4.4h-1c-.2 0-.4-.2-.4-.4V8.4c0-.2.2-.4.4-.4h2.9c.2 0 .4.2.4.4v.6c0 .2-.2.4-.4.4h-1.5v.9h1.5c.2 0 .4.2.4.4v.6c0 .2-.2.4-.4.4zm2.7-3.8V12.4c0 .2-.2.4-.4.4h-1c-.2 0-.4-.2-.4-.4V8.4c0-.2.2-.4.4-.4h1c.2 0 .4.2.4.4zm3.8 3.8h-2.3c-.2 0-.4-.2-.4-.4V9c-.1.1-.2.1-.2.2v3.1c0 .2-.2.4-.4.4h-1c-.2 0-.4-.2-.4-.4V8.4c0-.2.2-.4.4-.4h1c.1 0 .3.1.4.2l1.7 2.4c.1-.1.2-.1.2-.2V8.4c0-.2.2-.4.4-.4h1c.2 0 .4.2.4.4v4c0 .2-.2.4-.4.4z"/></svg>使用 LINE 帳號登入</a></div>
            <?php
        }
    }

    public function handle_frontend_actions() {
        global $wp;
        if (isset($_GET['code']) && isset($_GET['state']) && array_key_exists('jify-points', $wp->query_vars)) {
            if (!wp_verify_nonce($_GET['state'], 'jify_line_login')) { wc_add_notice('驗證失敗 (Nonce Error)', 'error'); return; }
            $response = wp_remote_post('https://api.line.me/oauth2/v2.1/token', array('body' => array('grant_type' => 'authorization_code', 'code' => $_GET['code'], 'redirect_uri' => wc_get_account_endpoint_url('jify-points'), 'client_id' => get_option('jify_loyalty_line_channel_id'), 'client_secret' => get_option('jify_loyalty_line_channel_secret'))));
            if (is_wp_error($response)) { wc_add_notice('連線失敗 (Connection Error)', 'error'); return; }
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['id_token'])) {
                $payload = json_decode(base64_decode(explode('.', $body['id_token'])[1]), true);
                if (isset($payload['sub'])) { 
                    $line_uid = $payload['sub'];
                    if (is_user_logged_in()) {
                        $user_id = get_current_user_id();
                        update_user_meta($user_id, 'jify_line_user_id', $line_uid); 
                        wc_add_notice('LINE 帳號綁定成功！', 'success'); 
                        wp_safe_redirect(wc_get_account_endpoint_url('jify-points')); 
                        exit;
                    } else {
                        $users = get_users(array('meta_key' => 'jify_line_user_id', 'meta_value' => $line_uid, 'number' => 1));
                        if (!empty($users)) {
                            $user = $users[0];
                            wp_set_current_user($user->ID, $user->user_login);
                            wp_set_auth_cookie($user->ID);
                            do_action('wp_login', $user->user_login, $user);
                            wc_add_notice('登入成功！歡迎回來，' . $user->display_name, 'success');
                            wp_safe_redirect(wc_get_account_endpoint_url('jify-points'));
                            exit;
                        } else { wc_add_notice('此 LINE 帳號尚未綁定任何會員，請先使用 Email 登入後至會員中心進行綁定。', 'error'); }
                    }
                }
            }
        }
        if (isset($_GET['jify_action']) && $_GET['jify_action'] === 'unbind_line') { 
            if (!is_user_logged_in()) return;
            $user_id = get_current_user_id();
            delete_user_meta($user_id, 'jify_line_user_id'); 
            wc_add_notice('已解除 LINE 綁定', 'success'); 
            wp_safe_redirect(wc_get_account_endpoint_url('jify-points')); 
            exit; 
        }
    }
}
Jify_Loyalty::get_instance();
