<?php
/**
 * Soldier-up Account Guard administration.
 * Copyright © 2026 Jonathan R. Adcox
 * Original author: Jonathan R. Adcox (Blayderunner123)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See LICENSE.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SUAG_Admin {
    private static $instance = null;
    public static function instance() { if ( null === self::$instance ) { self::$instance = new self(); } return self::$instance; }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
        add_action( 'admin_post_suag_save_settings', array( $this, 'save_settings' ) );
        add_action( 'admin_post_suag_send_test_email', array( $this, 'send_test_email' ) );
        add_action( 'admin_post_suag_repair_verify_page', array( $this, 'repair_verify_page' ) );
        add_action( 'admin_post_suag_resend_user', array( $this, 'resend_user' ) );
        add_action( 'admin_post_suag_mark_verified', array( $this, 'mark_verified' ) );
        add_action( 'admin_post_suag_clear_logs', array( $this, 'clear_logs' ) );
        add_filter( 'manage_users_columns', array( $this, 'user_columns' ) );
        add_filter( 'manage_users_custom_column', array( $this, 'user_column_content' ), 10, 3 );
        add_filter( 'user_row_actions', array( $this, 'user_row_actions' ), 10, 2 );
        add_action( 'show_user_profile', array( $this, 'profile_panel' ) );
        add_action( 'edit_user_profile', array( $this, 'profile_panel' ) );
    }

    public function admin_menu() {
        add_menu_page( 'Account Guard', 'Account Guard', 'manage_options', 'suag-account-guard', array( $this, 'render_page' ), 'dashicons-shield-alt', 58 );
    }

    public function admin_assets( $hook ) {
        if ( false === strpos( $hook, 'suag-account-guard' ) && ! in_array( $hook, array( 'users.php', 'user-edit.php', 'profile.php' ), true ) ) { return; }
        wp_enqueue_style( 'suag-admin', SUAG_URL . 'assets/admin.css', array(), SUAG_VERSION );
    }

    private function tabs() {
        return array( 'general'=>'General','verification'=>'Verification','email'=>'Email','users'=>'User Creation','redirects'=>'Redirects','roles'=>'Roles & Access','diagnostics'=>'Diagnostics','logs'=>'Logs' );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $tabs = $this->tabs();
        $current = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
        if ( ! isset( $tabs[ $current ] ) ) { $current = 'general'; }
        $s = SUAG_Core::settings(); ?>
        <div class="wrap suag-admin">
            <div class="suag-admin-hero">
                <div class="suag-admin-brand"><img src="<?php echo esc_url( SUAG_URL . 'assets/soldier-up-designs-logo.png' ); ?>" alt="Soldier-up Designs"><div><h1>Soldier-up Account Guard</h1><p>Account verification, onboarding, login protection, and role-aware redirects for WordPress and WooCommerce.</p></div></div>
                <div class="suag-version-card"><span class="dashicons dashicons-shield-alt"></span><strong>Account Guard</strong><small>Version <?php echo esc_html( SUAG_VERSION ); ?></small><em class="<?php echo ! empty( $s['enabled'] ) ? 'is-good' : 'is-warn'; ?>"><?php echo ! empty( $s['enabled'] ) ? 'Protection Active' : 'Protection Disabled'; ?></em></div>
            </div>
            <?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Account Guard settings saved.</p></div><?php endif; ?>
            <?php if ( isset( $_GET['suag_message'] ) ) : ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['suag_message'] ) ) ); ?></p></div><?php endif; ?>
            <nav class="suag-tabs"><?php foreach ( $tabs as $slug => $label ) : ?><a class="<?php echo $current === $slug ? 'active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=suag-account-guard&tab=' . $slug ) ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?></nav>
            <div class="suag-layout"><main class="suag-main"><?php $this->render_tab( $current, $s ); ?></main><aside class="suag-sidebar"><?php $this->status_card( $s ); ?></aside></div>
        </div><?php
    }

    private function form_start( $tab ) { ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="suag_save_settings"><input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>"><?php wp_nonce_field( 'suag_save_settings', 'suag_nonce' ); ?><?php }
    private function form_end() { submit_button( 'Save Settings', 'primary suag-primary' ); echo '</form>'; }

    private function render_tab( $tab, $s ) {
        switch ( $tab ) {
            case 'verification':
                $this->form_start( $tab ); $this->card_open( 'Verification Rules', 'Control how OTP verification behaves.' );
                $this->number( 'OTP length','otp_length',$s['otp_length'],4,8,'digits','Number of numeric digits in each verification code. Six digits is recommended for most sites.' );
                $this->number( 'Code expiration','expiration_minutes',$s['expiration_minutes'],1,120,'minutes','How long a verification code remains valid after it is sent.' );
                $this->number( 'Maximum attempts','max_attempts',$s['max_attempts'],1,20,'attempts','Incorrect entries allowed before the user must request a new code.' );
                $this->number( 'Resend cooldown','resend_cooldown',$s['resend_cooldown'],10,3600,'seconds','Minimum wait between resend requests. Helps prevent abuse and accidental mail flooding.' );
                $this->number( 'Maximum resends','max_resends_hour',$s['max_resends_hour'],1,50,'per hour','Maximum verification emails one user may request during a rolling hour.' );
                $this->number( 'Stale account cleanup','cleanup_days',$s['cleanup_days'],1,365,'days','Unverified managed accounts older than this are automatically removed. Exempt roles are never deleted.' );
                $this->toggle( 'Require verification before login','require_verification_login',$s['require_verification_login'],'Blocks protected users from authenticating until their email verification is complete.' );
                $this->toggle( 'Send verified users to create their password','force_password_setup',$s['force_password_setup'],'Recommended for new accounts. After OTP verification, Account Guard sends the user into WordPress password setup.' );
                $this->card_close(); $this->form_end(); break;
            case 'email':
                $this->form_start( $tab ); $this->card_open( 'Email Branding', 'Configure the identity and content used for verification messages.' );
                $this->text( 'From name','from_name',$s['from_name'],'Display name recipients should see on Account Guard verification messages.' );
                $this->email( 'From email','from_email',$s['from_email'],'Sender address requested for Account Guard email. Your host or mail provider must permit this address/domain.' );
                $this->email( 'Reply-to email','reply_to',$s['reply_to'],'Replies to verification messages are directed here.' );
                $this->text( 'Verification subject','email_subject',$s['email_subject'],'You may use {site_name} in the subject.' );
                $this->textarea( 'Verification message','email_body',$s['email_body'],'{site_name}, {username}, {email}, {otp}, {verify_url}, {expiration_minutes}' );
                $this->email( 'Failure alert recipient','admin_failure_email',$s['admin_failure_email'],'Account Guard sends application-level verification mail failure alerts to this address.' );
                $this->toggle( 'Send administrator alerts for verification mail failures','failure_alerts',$s['failure_alerts'],'Alerts are generated when WordPress/PHPMailer reports a verification email submission failure.' );
                $this->card_close(); $this->form_end();
                $this->card_open( 'Send Test Email', 'Confirm that WordPress can submit a branded Account Guard message.' ); ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="suag-inline-form"><input type="hidden" name="action" value="suag_send_test_email"><?php wp_nonce_field( 'suag_send_test_email', 'suag_nonce' ); ?><input type="email" name="test_email" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" required><button class="button button-secondary">Send Test Email</button></form><?php $this->card_close(); break;
            case 'users':
                $this->form_start( $tab ); $this->card_open( 'Registration Sources', 'Choose which account-creation paths Account Guard should protect.' );
                $this->toggle( 'Protect native WordPress/front-end registrations','protect_wp_registration',$s['protect_wp_registration'],'Applies OTP verification to users created through standard WordPress registration paths.' );
                $this->toggle( 'Protect WooCommerce registrations when WooCommerce is installed','protect_woocommerce',$s['protect_woocommerce'],'Applies Account Guard to WooCommerce My Account registration. This setting is harmless when WooCommerce is not installed.' );
                $this->toggle( 'Protect users created by administrators in WP-Admin','protect_admin_created',$s['protect_admin_created'],'Ensures manually provisioned users receive the same verification and password-creation workflow as front-end users.' );
                $this->toggle( 'Suppress WordPress native new-user password email for protected users','suppress_native_user_email',$s['suppress_native_user_email'],'Prevents WordPress from sending a competing password email before Account Guard verification is complete.' );
                $this->card_close(); $this->form_end(); break;
            case 'redirects':
                $this->form_start( $tab ); $this->card_open( 'Role Login Redirects', 'Account Guard discovers the roles and published pages already on this site. Leave a role on WordPress Default unless you want to override it.' ); $this->redirect_table( $s ); $this->card_close(); $this->form_end(); break;
            case 'roles':
                $this->form_start( $tab ); $this->card_open( 'Role Exemptions', 'Exempt roles bypass verification and Account Guard login redirect rules.' ); $this->role_exemptions( $s ); $this->card_close(); $this->form_end(); break;
            case 'diagnostics': $this->diagnostics( $s ); break;
            case 'logs': $this->logs(); break;
            default:
                $this->quick_start_card( $s );
                $this->form_start( 'general' );
                $this->card_open( 'General Settings', 'These are the only site-level settings required before Account Guard can begin protecting registrations.' );
                $this->toggle( 'Enable Soldier-up Account Guard','enabled',$s['enabled'], 'Master switch. Disable this to stop Account Guard enforcement without removing your saved configuration.' );
                $this->text( 'Site / organization name','site_name',$s['site_name'], 'Used in verification emails and Account Guard messaging. This does not change the WordPress Site Title.' );
                $this->page_select( 'Verification page','verify_page_id',$s['verify_page_id'] );
                $this->card_close();
                $this->form_end();
        }
    }

    private function quick_start_card( $s ) {
        $page_id = absint( $s['verify_page_id'] );
        $page = $page_id ? get_post( $page_id ) : null;
        $page_ok = $page && 'publish' === $page->post_status;
        $shortcode_ok = $page_ok && has_shortcode( $page->post_content, 'suag_verify' );
        $legacy_shortcode = $page_ok && has_shortcode( $page->post_content, 'l6_otp_verify' );
        ?>
        <section class="suag-card-admin suag-quick-start">
            <div class="suag-card-heading">
                <h2>Quick Start</h2>
                <p>Account Guard is designed to be configured without editing PHP. Complete these steps once on each site.</p>
            </div>
            <div class="suag-card-body">
                <ol class="suag-setup-steps">
                    <li><strong>Choose the verification page.</strong><span>Account Guard uses this page to display the OTP form. The page must contain <code>[suag_verify]</code>.</span></li>
                    <li><strong>Configure verification rules.</strong><span>Use the Verification tab to control code length, expiration, attempts, resend limits, and stale-account cleanup.</span></li>
                    <li><strong>Configure email identity and test delivery.</strong><span>Use the Email tab to set the From name/address and send a test message before creating production users.</span></li>
                    <li><strong>Choose registration sources.</strong><span>Use User Creation to protect WordPress, WooCommerce, and/or users created manually in WP-Admin.</span></li>
                    <li><strong>Review roles and redirects.</strong><span>Keep privileged roles exempt as needed, then optionally send each site role to an existing page or custom URL after login.</span></li>
                    <li><strong>Run Diagnostics.</strong><span>Do not begin production testing until the verification page and shortcode checks are green.</span></li>
                </ol>

                <?php if ( ! $shortcode_ok ) : ?>
                    <div class="suag-setup-alert">
                        <div>
                            <strong><?php echo $page_ok ? 'Verification page needs setup.' : 'Verification page is not configured.'; ?></strong>
                            <p><?php echo $legacy_shortcode ? 'Account Guard found the legacy Level6 shortcode. Use the button to replace it with [suag_verify].' : ( $page_ok ? 'The selected page does not contain [suag_verify]. Account Guard can add it without deleting the rest of the page content.' : 'Account Guard can create a new published Verify Account page and add [suag_verify] automatically.' ); ?></p>
                        </div>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="suag_repair_verify_page">
                            <?php wp_nonce_field( 'suag_repair_verify_page', 'suag_nonce' ); ?>
                            <button class="button button-primary suag-primary" type="submit"><?php echo $page_ok ? 'Install / Repair Shortcode' : 'Create Verification Page'; ?></button>
                        </form>
                    </div>
                <?php else : ?>
                    <div class="suag-setup-ready"><strong>Verification page ready.</strong> <code>[suag_verify]</code> is installed on the selected page.</div>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    public function repair_verify_page() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized.' ); }
        check_admin_referer( 'suag_repair_verify_page', 'suag_nonce' );

        $s = SUAG_Core::settings();
        $page_id = absint( $s['verify_page_id'] );
        $page = $page_id ? get_post( $page_id ) : null;
        $message = '';

        if ( ! $page || 'trash' === $page->post_status ) {
            $existing = get_page_by_path( 'verify-account' );
            if ( $existing && 'trash' !== $existing->post_status ) {
                $page = $existing;
                $page_id = $existing->ID;
            } else {
                $page_id = wp_insert_post( array(
                    'post_title'   => 'Verify Account',
                    'post_name'    => 'verify-account',
                    'post_content' => '[suag_verify]',
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                ) );
                $page = ! is_wp_error( $page_id ) ? get_post( $page_id ) : null;
                $message = 'Verification page created with [suag_verify].';
            }
        }

        if ( $page && ! is_wp_error( $page_id ) ) {
            $content = (string) $page->post_content;
            if ( has_shortcode( $content, 'l6_otp_verify' ) && ! has_shortcode( $content, 'suag_verify' ) ) {
                $content = str_replace( '[l6_otp_verify]', '[suag_verify]', $content );
                $message = 'Legacy verification shortcode replaced with [suag_verify].';
            } elseif ( ! has_shortcode( $content, 'suag_verify' ) ) {
                $content = rtrim( $content ) . "\n\n[suag_verify]";
                $message = 'Account Guard shortcode added to the selected verification page.';
            } elseif ( ! $message ) {
                $message = 'Verification page is already configured correctly.';
            }

            wp_update_post( array( 'ID' => absint( $page_id ), 'post_content' => $content, 'post_status' => 'publish' ) );
            $s['verify_page_id'] = absint( $page_id );
            update_option( 'suag_settings', $s );
            SUAG_Core::log_event( 'info', $message );
        } else {
            $message = 'Account Guard could not create or update the verification page.';
            SUAG_Core::log_event( 'error', $message );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=suag-account-guard&tab=general&suag_message=' . rawurlencode( $message ) ) );
        exit;
    }

    private function status_card( $s ) {
        $page_ok = ! empty( $s['verify_page_id'] ) && 'publish' === get_post_status( absint( $s['verify_page_id'] ) );
        $shortcode_ok = false;
        if ( $page_ok ) { $verify_post = get_post( absint( $s['verify_page_id'] ) ); $shortcode_ok = $verify_post && has_shortcode( $verify_post->post_content, 'suag_verify' ); }
        $verify_ready = $page_ok && $shortcode_ok;
        $cron_ok = (bool) wp_next_scheduled( 'suag_cleanup_unverified_users' ); $woo = class_exists( 'WooCommerce' ); ?>
        <section class="suag-status-card"><h3>System Status</h3><?php $this->status_item( 'Account Guard',! empty( $s['enabled'] ),! empty( $s['enabled'] )?'Active':'Disabled' ); $this->status_item( 'Verification page',$verify_ready,$verify_ready?'Ready':($page_ok?'Shortcode missing':'Needs attention') ); $this->status_item( 'Cleanup schedule',$cron_ok,$cron_ok?'Scheduled':'Not scheduled' ); $this->status_item( 'WooCommerce',true,$woo?'Detected':'Not installed' ); $this->status_item( 'Mail failure monitoring',true,'Active' ); ?><div class="suag-status-meta"><strong>Environment</strong><span>WordPress <?php echo esc_html( get_bloginfo( 'version' ) ); ?></span><span>PHP <?php echo esc_html( PHP_VERSION ); ?></span></div></section><?php
    }
    private function status_item( $label,$good,$value ) { ?><div class="suag-status-item"><span class="suag-dot <?php echo $good?'good':'warn'; ?>"></span><div><strong><?php echo esc_html( $label ); ?></strong><small><?php echo esc_html( $value ); ?></small></div></div><?php }

    private function diagnostics( $s ) {
        $page_ok = ! empty( $s['verify_page_id'] ) && 'publish' === get_post_status( absint( $s['verify_page_id'] ) ); $shortcode_ok = false;
        if ( $page_ok ) { $p = get_post( absint( $s['verify_page_id'] ) ); $shortcode_ok = $p && has_shortcode( $p->post_content,'suag_verify' ); }
        $checks = array( array('Plugin enabled',!empty($s['enabled']),'Account Guard protection is enabled.'), array('Verification page published',$page_ok,$page_ok?'Verification page is published.':'Choose or create a published verification page.'), array('Verification shortcode present',$shortcode_ok,$shortcode_ok?'[suag_verify] detected.':'The selected page should contain [suag_verify].'), array('Cleanup job scheduled',(bool)wp_next_scheduled('suag_cleanup_unverified_users'),'Daily cleanup should be scheduled while the plugin is active.'), array('WooCommerce integration',true,class_exists('WooCommerce')?'WooCommerce detected.':'WooCommerce is not installed; core WordPress protection still works.'), array('Failure monitoring',true,'wp_mail_failed monitoring is registered.') );
        $this->card_open( 'System Check','A quick operational health view for Account Guard.' ); echo '<div class="suag-check-grid">'; foreach ( $checks as $c ) { echo '<div class="suag-check '.($c[1]?'pass':'fail').'"><span>'.($c[1]?'✓':'!').'</span><div><strong>'.esc_html($c[0]).'</strong><small>'.esc_html($c[2]).'</small></div></div>'; } echo '</div>'; $this->card_close();
    }

    private function logs() {
        $logs = get_option( 'suag_logs', array() ); $this->card_open( 'Account Guard Activity','The most recent 100 Account Guard events.' );
        if ( empty( $logs ) ) { echo '<p>No Account Guard events have been recorded yet.</p>'; } else { echo '<div class="suag-log-table">'; foreach ( $logs as $log ) { $level = isset($log['level'])?sanitize_key($log['level']):'info'; echo '<div class="suag-log-row"><span class="suag-log-level '.esc_attr($level).'">'.esc_html(strtoupper($level)).'</span><time>'.esc_html($log['time']).'</time><div>'.esc_html($log['message']).'</div></div>'; } echo '</div>'; }
        ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="suag_clear_logs"><?php wp_nonce_field( 'suag_clear_logs','suag_nonce' ); submit_button( 'Clear Logs','secondary' ); ?></form><?php $this->card_close();
    }

    private function redirect_table( $s ) {
        $roles = wp_roles()->roles; $pages = get_pages( array('post_status'=>'publish','sort_column'=>'post_title') ); $rules = is_array($s['role_redirects'])?$s['role_redirects']:array(); ?>
        <div class="suag-redirect-table"><div class="suag-redirect-head"><span>Role</span><span>Destination</span><span>Page</span><span>Custom URL</span></div><?php foreach ( $roles as $slug=>$role ) : $r = isset($rules[$slug])&&is_array($rules[$slug])?$rules[$slug]:array(); $type=$r['type']??'default'; $pid=isset($r['page_id'])?absint($r['page_id']):0; $url=$r['url']??''; ?><div class="suag-redirect-row"><strong><?php echo esc_html( translate_user_role($role['name']) ); ?><small><?php echo esc_html($slug); ?></small></strong><select name="role_redirects[<?php echo esc_attr($slug); ?>][type]"><option value="default" <?php selected($type,'default'); ?>>WordPress Default</option><option value="home" <?php selected($type,'home'); ?>>Site Home</option><option value="page" <?php selected($type,'page'); ?>>Existing Page</option><option value="custom" <?php selected($type,'custom'); ?>>Custom URL</option></select><select name="role_redirects[<?php echo esc_attr($slug); ?>][page_id]"><option value="0">Select a page…</option><?php foreach($pages as $p): ?><option value="<?php echo esc_attr($p->ID); ?>" <?php selected($pid,$p->ID); ?>><?php echo esc_html($p->post_title); ?></option><?php endforeach; ?></select><input type="url" name="role_redirects[<?php echo esc_attr($slug); ?>][url]" value="<?php echo esc_attr($url); ?>" placeholder="https://example.com/portal/"></div><?php endforeach; ?></div><p class="description">Existing Page stores the WordPress page ID, so redirects continue working if the page slug changes later.</p><?php
    }

    private function role_exemptions( $s ) { $roles=wp_roles()->roles; $exempt=array_map('sanitize_key',(array)$s['exempt_roles']); echo '<div class="suag-role-grid">'; foreach($roles as $slug=>$role){ echo '<label class="suag-role-option"><input type="checkbox" name="exempt_roles[]" value="'.esc_attr($slug).'" '.checked(in_array($slug,$exempt,true),true,false).'><span><strong>'.esc_html(translate_user_role($role['name'])).'</strong><small>'.esc_html($slug).'</small></span></label>'; } echo '</div><p class="description">Administrators should normally remain exempt as a break-glass safeguard.</p>'; }

    public function save_settings() {
        if ( ! current_user_can('manage_options') ) { wp_die('Unauthorized.'); } check_admin_referer('suag_save_settings','suag_nonce'); $tab=isset($_POST['tab'])?sanitize_key($_POST['tab']):'general'; $s=SUAG_Core::settings();
        if('general'===$tab){$s['enabled']=isset($_POST['enabled'])?1:0;$s['site_name']=isset($_POST['site_name'])?sanitize_text_field(wp_unslash($_POST['site_name'])):$s['site_name'];$s['verify_page_id']=isset($_POST['verify_page_id'])?absint($_POST['verify_page_id']):0;}
        elseif('verification'===$tab){$s['otp_length']=$this->bounded_int('otp_length',6,4,8);$s['expiration_minutes']=$this->bounded_int('expiration_minutes',15,1,120);$s['max_attempts']=$this->bounded_int('max_attempts',5,1,20);$s['resend_cooldown']=$this->bounded_int('resend_cooldown',90,10,3600);$s['max_resends_hour']=$this->bounded_int('max_resends_hour',5,1,50);$s['cleanup_days']=$this->bounded_int('cleanup_days',14,1,365);$s['require_verification_login']=isset($_POST['require_verification_login'])?1:0;$s['force_password_setup']=isset($_POST['force_password_setup'])?1:0;}
        elseif('email'===$tab){$s['from_name']=isset($_POST['from_name'])?sanitize_text_field(wp_unslash($_POST['from_name'])):$s['from_name'];$s['from_email']=isset($_POST['from_email'])?sanitize_email(wp_unslash($_POST['from_email'])):$s['from_email'];$s['reply_to']=isset($_POST['reply_to'])?sanitize_email(wp_unslash($_POST['reply_to'])):$s['reply_to'];$s['email_subject']=isset($_POST['email_subject'])?sanitize_text_field(wp_unslash($_POST['email_subject'])):$s['email_subject'];$s['email_body']=isset($_POST['email_body'])?sanitize_textarea_field(wp_unslash($_POST['email_body'])):$s['email_body'];$s['admin_failure_email']=isset($_POST['admin_failure_email'])?sanitize_email(wp_unslash($_POST['admin_failure_email'])):$s['admin_failure_email'];$s['failure_alerts']=isset($_POST['failure_alerts'])?1:0;}
        elseif('users'===$tab){$s['protect_wp_registration']=isset($_POST['protect_wp_registration'])?1:0;$s['protect_woocommerce']=isset($_POST['protect_woocommerce'])?1:0;$s['protect_admin_created']=isset($_POST['protect_admin_created'])?1:0;$s['suppress_native_user_email']=isset($_POST['suppress_native_user_email'])?1:0;}
        elseif('redirects'===$tab){$rules=isset($_POST['role_redirects'])&&is_array($_POST['role_redirects'])?wp_unslash($_POST['role_redirects']):array();$clean=array();foreach(wp_roles()->roles as $slug=>$role){$raw=isset($rules[$slug])&&is_array($rules[$slug])?$rules[$slug]:array();$type=isset($raw['type'])&&in_array($raw['type'],array('default','home','page','custom'),true)?$raw['type']:'default';$clean[$slug]=array('type'=>$type,'page_id'=>isset($raw['page_id'])?absint($raw['page_id']):0,'url'=>isset($raw['url'])?esc_url_raw($raw['url']):'');}$s['role_redirects']=$clean;}
        elseif('roles'===$tab){$roles=isset($_POST['exempt_roles'])&&is_array($_POST['exempt_roles'])?array_map('sanitize_key',wp_unslash($_POST['exempt_roles'])):array();$s['exempt_roles']=array_values(array_intersect($roles,array_keys(wp_roles()->roles)));}
        update_option('suag_settings',$s); SUAG_Core::log_event('info','Settings updated: '.$tab.'.'); wp_safe_redirect(admin_url('admin.php?page=suag-account-guard&tab='.$tab.'&updated=1')); exit;
    }
    private function bounded_int($name,$default,$min,$max){$v=isset($_POST[$name])?absint($_POST[$name]):$default;return max($min,min($max,$v));}

    public function send_test_email() {
        if(!current_user_can('manage_options')){wp_die('Unauthorized.');} check_admin_referer('suag_send_test_email','suag_nonce'); $email=isset($_POST['test_email'])?sanitize_email(wp_unslash($_POST['test_email'])):''; $s=SUAG_Core::settings();
        if(!is_email($email)){$message='Please enter a valid test email address.';} else {$headers=array('Content-Type: text/plain; charset=UTF-8');if(is_email($s['reply_to'])){$headers[]='Reply-To: '.sanitize_email($s['reply_to']);}if(is_email($s['from_email'])){$headers[]='From: '.sanitize_text_field($s['from_name']).' <'.sanitize_email($s['from_email']).'>';}$sent=wp_mail($email,'[Account Guard Test] '.$s['site_name'],"This is a Soldier-up Account Guard test email.\n\nSite: ".home_url('/')."\nTime: ".current_time('mysql'),$headers);$s['last_test_mail']=array('time'=>current_time('mysql'),'recipient'=>$email,'result'=>$sent?'success':'failed');update_option('suag_settings',$s);SUAG_Core::log_event($sent?'info':'error','Test email to '.$email.' result='.($sent?'success':'failed').'.');$message=$sent?'Test email submitted successfully.':'WordPress reported that the test email could not be submitted.';} wp_safe_redirect(admin_url('admin.php?page=suag-account-guard&tab=email&suag_message='.rawurlencode($message))); exit;
    }

    public function user_columns($columns){$columns['suag_verification']='Verification';return $columns;}
    public function user_column_content($value,$column_name,$user_id){if('suag_verification'!==$column_name){return $value;}$u=get_userdata($user_id);if(!$u){return '—';}if(SUAG_Core::user_is_exempt($u)){return '<span class="suag-user-status exempt">Exempt</span>';}if(SUAG_Core::is_verified($user_id)){return '<span class="suag-user-status verified">✓ Verified</span>';}if(get_user_meta($user_id,'_suag_mail_failed',true)){return '<span class="suag-user-status failed">! Send Failed</span>';}if('0'===get_user_meta($user_id,'_suag_email_verified',true)){return '<span class="suag-user-status pending">○ Pending</span>';}return '<span class="suag-user-status neutral">Not managed</span>';}
    public function user_row_actions($actions,$user){if(!current_user_can('edit_users')||SUAG_Core::user_is_exempt($user)){return $actions;}if(!SUAG_Core::is_verified($user->ID)){$r=wp_nonce_url(admin_url('admin-post.php?action=suag_resend_user&user_id='.$user->ID),'suag_resend_user_'.$user->ID);$v=wp_nonce_url(admin_url('admin-post.php?action=suag_mark_verified&user_id='.$user->ID),'suag_mark_verified_'.$user->ID);$actions['suag_resend']='<a href="'.esc_url($r).'">Resend verification</a>';$actions['suag_verify']='<a href="'.esc_url($v).'">Mark verified</a>';}return $actions;}
    public function resend_user(){if(!current_user_can('edit_users')){wp_die('Unauthorized.');}$uid=isset($_GET['user_id'])?absint($_GET['user_id']):0;check_admin_referer('suag_resend_user_'.$uid);$u=get_userdata($uid);if($u&&!SUAG_Core::user_is_exempt($u)){SUAG_Core::instance()->send_otp($uid,true);}wp_safe_redirect(admin_url('users.php'));exit;}
    public function mark_verified(){if(!current_user_can('edit_users')){wp_die('Unauthorized.');}$uid=isset($_GET['user_id'])?absint($_GET['user_id']):0;check_admin_referer('suag_mark_verified_'.$uid);SUAG_Core::mark_verified($uid);wp_safe_redirect(admin_url('users.php'));exit;}
    public function clear_logs(){if(!current_user_can('manage_options')){wp_die('Unauthorized.');}check_admin_referer('suag_clear_logs','suag_nonce');delete_option('suag_logs');wp_safe_redirect(admin_url('admin.php?page=suag-account-guard&tab=logs'));exit;}

    public function profile_panel($user){if(!current_user_can('edit_users')){return;}$status=SUAG_Core::user_is_exempt($user)?'Exempt':(SUAG_Core::is_verified($user->ID)?'Verified':'Pending');$last=(int)get_user_meta($user->ID,'_suag_last_sent',true);$expires=(int)get_user_meta($user->ID,'_suag_otp_expires',true);?><h2>Soldier-up Account Guard</h2><table class="form-table" role="presentation"><tr><th>Verification status</th><td><strong><?php echo esc_html($status); ?></strong></td></tr><tr><th>Last verification sent</th><td><?php echo $last?esc_html(wp_date('Y-m-d H:i:s',$last)):'—'; ?></td></tr><tr><th>Current code expires</th><td><?php echo $expires?esc_html(wp_date('Y-m-d H:i:s',$expires)):'—'; ?></td></tr></table><?php }

    private function card_open($title,$description=''){echo '<section class="suag-card-admin"><div class="suag-card-heading"><h2>'.esc_html($title).'</h2>';if($description){echo '<p>'.esc_html($description).'</p>';}echo '</div><div class="suag-card-body">';}
    private function card_close(){echo '</div></section>';}
    private function toggle($label,$name,$value,$description=''){echo '<label class="suag-setting suag-toggle-row"><span><strong>'.esc_html($label).'</strong>';if($description){echo '<small>'.esc_html($description).'</small>';}echo '</span><span class="suag-switch"><input type="checkbox" name="'.esc_attr($name).'" value="1" '.checked(!empty($value),true,false).'><i></i></span></label>';}
    private function text($label,$name,$value,$description=''){echo '<label class="suag-setting"><span><strong>'.esc_html($label).'</strong>';if($description){echo '<small>'.esc_html($description).'</small>';}echo '</span><input type="text" name="'.esc_attr($name).'" value="'.esc_attr($value).'"></label>';}
    private function email($label,$name,$value,$description=''){echo '<label class="suag-setting"><span><strong>'.esc_html($label).'</strong>';if($description){echo '<small>'.esc_html($description).'</small>';}echo '</span><input type="email" name="'.esc_attr($name).'" value="'.esc_attr($value).'"></label>';}
    private function textarea($label,$name,$value,$hint=''){echo '<label class="suag-setting"><span><strong>'.esc_html($label).'</strong>';if($hint){echo '<small>Available placeholders: '.esc_html($hint).'</small>';}echo '</span><textarea name="'.esc_attr($name).'" rows="12">'.esc_textarea($value).'</textarea></label>';}
    private function number($label,$name,$value,$min,$max,$suffix='',$description=''){echo '<label class="suag-setting"><span><strong>'.esc_html($label).'</strong>';if($description){echo '<small>'.esc_html($description).'</small>';}echo '</span><div class="suag-number"><input type="number" name="'.esc_attr($name).'" value="'.esc_attr($value).'" min="'.esc_attr($min).'" max="'.esc_attr($max).'"><span>'.esc_html($suffix).'</span></div></label>';}
    private function page_select($label,$name,$value){
        $pages=get_pages(array('post_status'=>'publish','sort_column'=>'post_title'));
        echo '<label class="suag-setting"><span><strong>'.esc_html($label).'</strong><small>This is the page users visit to enter their verification code. It must contain the <code>[suag_verify]</code> shortcode. Account Guard can create or repair this page for you using the Quick Start panel above.</small></span><select name="'.esc_attr($name).'"><option value="0">Select a page…</option>';
        foreach($pages as $p){echo '<option value="'.esc_attr($p->ID).'" '.selected(absint($value),$p->ID,false).'>'.esc_html($p->post_title).'</option>';}
        echo '</select></label>';
        if(absint($value)){
            $edit=get_edit_post_link(absint($value));$view=get_permalink(absint($value));
            echo '<div class="suag-page-links">';
            if($edit){echo '<a class="button button-secondary" href="'.esc_url($edit).'">Edit Verification Page</a> ';}
            if($view){echo '<a class="button button-secondary" href="'.esc_url($view).'" target="_blank">View Page</a>';}
            echo '</div>';
        }
    }
}
