<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SUAG_Core {
    private static $instance = null;
    private static $sending_guard_email = false;

    public static function instance() {
        if ( null === self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }

    public static function defaults() {
        return array(
            'enabled'                    => 1,
            'site_name'                  => get_bloginfo( 'name' ),
            'verify_page_id'             => 0,
            'otp_length'                 => 6,
            'expiration_minutes'         => 15,
            'max_attempts'               => 5,
            'resend_cooldown'            => 90,
            'max_resends_hour'           => 5,
            'cleanup_days'               => 14,
            'protect_wp_registration'     => 1,
            'protect_woocommerce'         => 1,
            'protect_admin_created'       => 1,
            'require_verification_login' => 1,
            'suppress_native_user_email' => 1,
            'force_password_setup'        => 1,
            'exempt_roles'               => array( 'administrator' ),
            'from_name'                  => get_bloginfo( 'name' ),
            'from_email'                 => get_option( 'admin_email' ),
            'reply_to'                   => get_option( 'admin_email' ),
            'email_subject'              => 'Verify your {site_name} account',
            'email_body'                 => "Hello {username},\n\nYour verification code is:\n\n{otp}\n\nThis code expires in {expiration_minutes} minutes.\n\nEnter the code here:\n{verify_url}\n\nAfter verification, you will be sent to create your password.\n\nIf you did not request this account, you can ignore this email.\n\n{site_name}",
            'admin_failure_email'         => get_option( 'admin_email' ),
            'failure_alerts'              => 1,
            'role_redirects'              => array(),
            'last_test_mail'              => array(),
        );
    }

    public static function settings() {
        $saved = get_option( 'suag_settings', array() );
        return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
    }

    public static function activate() {
        $settings = self::settings();
        $page_id = absint( $settings['verify_page_id'] );
        if ( ! $page_id || 'trash' === get_post_status( $page_id ) || ! get_post( $page_id ) ) {
            $existing = get_page_by_path( 'verify-account' );
            if ( $existing ) {
                $page_id = $existing->ID;

                // Migrate the legacy Level6 verification shortcode when present.
                $content = (string) $existing->post_content;
                if ( has_shortcode( $content, 'l6_otp_verify' ) && ! has_shortcode( $content, 'suag_verify' ) ) {
                    wp_update_post( array(
                        'ID'           => $page_id,
                        'post_content' => str_replace( '[l6_otp_verify]', '[suag_verify]', $content ),
                    ) );
                }
            } else {
                $page_id = wp_insert_post( array(
                    'post_title' => 'Verify Account', 'post_name' => 'verify-account',
                    'post_content' => '[suag_verify]', 'post_status' => 'publish', 'post_type' => 'page',
                ) );
            }
            if ( ! is_wp_error( $page_id ) && $page_id ) { $settings['verify_page_id'] = absint( $page_id ); }
        }
        update_option( 'suag_settings', $settings );
        update_option( 'suag_version', SUAG_VERSION );
        if ( ! wp_next_scheduled( 'suag_cleanup_unverified_users' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'suag_cleanup_unverified_users' );
        }
        self::log_event( 'info', 'Soldier-up Account Guard activated.' );
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'suag_cleanup_unverified_users' );
    }

    private function __construct() {
        add_action( 'user_register', array( $this, 'on_user_register' ), 20, 2 );
        add_action( 'edit_user_created_user', array( $this, 'on_admin_user_created' ), 20, 2 );
        add_filter( 'wp_send_new_user_notification_to_user', array( $this, 'suppress_native_new_user_email' ), 20, 2 );
        add_filter( 'authenticate', array( $this, 'block_unverified_login' ), 30, 3 );
        add_filter( 'registration_redirect', array( $this, 'registration_redirect' ) );
        add_filter( 'woocommerce_registration_redirect', array( $this, 'woocommerce_registration_redirect' ) );
        add_filter( 'login_redirect', array( $this, 'login_redirect' ), 20, 3 );
        add_filter( 'woocommerce_login_redirect', array( $this, 'woocommerce_login_redirect' ), 20, 2 );
        add_action( 'wp_mail_failed', array( $this, 'mail_failed' ) );
        add_action( 'suag_cleanup_unverified_users', array( $this, 'cleanup_unverified_users' ) );
        add_shortcode( 'suag_verify', array( $this, 'verification_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_assets' ) );
    }

    public function enqueue_public_assets() {
        $s = self::settings();
        if ( ! empty( $s['verify_page_id'] ) && is_page( absint( $s['verify_page_id'] ) ) ) {
            wp_enqueue_style( 'suag-public', SUAG_URL . 'assets/public.css', array(), SUAG_VERSION );
        }
    }

    public static function get_verify_url( $user_id ) {
        $s = self::settings();
        $url = ! empty( $s['verify_page_id'] ) ? get_permalink( absint( $s['verify_page_id'] ) ) : home_url( '/verify-account/' );
        return add_query_arg( 'user_id', absint( $user_id ), $url );
    }

    public static function is_verified( $user_id ) {
        return '1' === get_user_meta( $user_id, '_suag_email_verified', true );
    }

    public static function mark_unverified( $user_id ) {
        update_user_meta( $user_id, '_suag_email_verified', '0' );
        if ( ! get_user_meta( $user_id, '_suag_created', true ) ) { update_user_meta( $user_id, '_suag_created', time() ); }
    }

    public static function mark_verified( $user_id ) {
        update_user_meta( $user_id, '_suag_email_verified', '1' );
        foreach ( array( '_suag_otp_hash','_suag_otp_expires','_suag_otp_attempts','_suag_otp_last_sent','_suag_otp_resends','_suag_mail_failed' ) as $key ) {
            delete_user_meta( $user_id, $key );
        }
        self::log_event( 'info', 'User ID ' . absint( $user_id ) . ' verified.' );
    }

    public static function user_is_exempt( $user ) {
        if ( ! $user instanceof WP_User ) { return true; }
        $s = self::settings();
        $exempt = array_map( 'sanitize_key', (array) $s['exempt_roles'] );
        foreach ( (array) $user->roles as $role ) {
            if ( in_array( $role, $exempt, true ) ) { return true; }
        }
        return false;
    }

    private static function registration_source() {
        if ( is_admin() && ! wp_doing_ajax() ) { return 'admin'; }
        if ( isset( $_POST['woocommerce-register-nonce'] ) || isset( $_POST['woocommerce_register'] ) ) { return 'woocommerce'; }
        return 'wordpress';
    }

    private static function source_enabled( $source ) {
        $s = self::settings();
        if ( 'admin' === $source ) { return ! empty( $s['protect_admin_created'] ); }
        if ( 'woocommerce' === $source ) { return ! empty( $s['protect_woocommerce'] ); }
        return ! empty( $s['protect_wp_registration'] );
    }

    public function on_user_register( $user_id, $userdata = array() ) {
        $s = self::settings();
        if ( empty( $s['enabled'] ) ) { return; }
        $user = get_userdata( $user_id );
        if ( ! $user || self::user_is_exempt( $user ) ) { update_user_meta( $user_id, '_suag_email_verified', '1' ); return; }
        $source = self::registration_source();
        if ( ! self::source_enabled( $source ) ) { update_user_meta( $user_id, '_suag_email_verified', '1' ); return; }
        self::mark_unverified( $user_id );
        $this->send_otp( $user_id, false );
    }

    public function on_admin_user_created( $user_id, $notify = '' ) {
        $s = self::settings();
        if ( empty( $s['enabled'] ) || empty( $s['protect_admin_created'] ) ) { return; }
        $user = get_userdata( $user_id );
        if ( ! $user || self::user_is_exempt( $user ) ) { return; }
        $hash = get_user_meta( $user_id, '_suag_otp_hash', true );
        $expires = (int) get_user_meta( $user_id, '_suag_otp_expires', true );
        if ( $hash && $expires > time() ) { return; }
        self::mark_unverified( $user_id );
        add_action( 'shutdown', function() use ( $user_id ) { SUAG_Core::instance()->send_otp( $user_id, false ); }, 100 );
    }

    public function suppress_native_new_user_email( $send, $user ) {
        $s = self::settings();
        if ( empty( $s['enabled'] ) || empty( $s['suppress_native_user_email'] ) ) { return $send; }
        if ( ! $user instanceof WP_User || self::user_is_exempt( $user ) ) { return $send; }
        return '0' === get_user_meta( $user->ID, '_suag_email_verified', true ) ? false : $send;
    }

    public function send_otp( $user_id, $is_resend = false ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) { return false; }
        $s = self::settings();
        $length = max( 4, min( 8, absint( $s['otp_length'] ) ) );
        $otp = (string) random_int( (int) pow( 10, $length - 1 ), (int) pow( 10, $length ) - 1 );
        self::mark_unverified( $user_id );
        update_user_meta( $user_id, '_suag_otp_hash', wp_hash_password( $otp ) );
        update_user_meta( $user_id, '_suag_otp_expires', time() + ( absint( $s['expiration_minutes'] ) * MINUTE_IN_SECONDS ) );
        update_user_meta( $user_id, '_suag_otp_attempts', 0 );
        update_user_meta( $user_id, '_suag_otp_last_sent', time() );

        $vars = array(
            '{site_name}' => $s['site_name'], '{otp}' => $otp, '{verify_url}' => self::get_verify_url( $user_id ),
            '{expiration_minutes}' => absint( $s['expiration_minutes'] ), '{username}' => $user->user_login, '{email}' => $user->user_email,
        );
        $subject = strtr( $s['email_subject'], $vars );
        $message = strtr( $s['email_body'], $vars );
        $headers = array( 'Content-Type: text/plain; charset=UTF-8' );
        if ( ! empty( $s['reply_to'] ) && is_email( $s['reply_to'] ) ) { $headers[] = 'Reply-To: ' . sanitize_email( $s['reply_to'] ); }

        self::$sending_guard_email = true;
        add_filter( 'wp_mail_from_name', array( $this, 'filter_from_name' ) );
        add_filter( 'wp_mail_from', array( $this, 'filter_from_email' ) );
        $sent = wp_mail( $user->user_email, $subject, $message, $headers );
        remove_filter( 'wp_mail_from_name', array( $this, 'filter_from_name' ) );
        remove_filter( 'wp_mail_from', array( $this, 'filter_from_email' ) );
        self::$sending_guard_email = false;

        if ( $sent ) {
            delete_user_meta( $user_id, '_suag_mail_failed' );
            update_user_meta( $user_id, '_suag_last_sent', time() );
            self::log_event( 'info', ( $is_resend ? 'Verification resent' : 'Verification sent' ) . ' to user ID ' . absint( $user_id ) . '.' );
        } else {
            update_user_meta( $user_id, '_suag_mail_failed', time() );
            self::log_event( 'error', 'Verification submission failed for user ID ' . absint( $user_id ) . '.' );
        }
        return $sent;
    }

    public function filter_from_name( $name ) {
        if ( ! self::$sending_guard_email ) { return $name; }
        $s = self::settings(); return sanitize_text_field( $s['from_name'] );
    }
    public function filter_from_email( $email ) {
        if ( ! self::$sending_guard_email ) { return $email; }
        $s = self::settings(); return is_email( $s['from_email'] ) ? sanitize_email( $s['from_email'] ) : $email;
    }

    public function mail_failed( $error ) {
        if ( ! is_wp_error( $error ) ) { return; }
        if ( ! self::$sending_guard_email ) { return; }
        $data = $error->get_error_data();
        $subject = is_array( $data ) && ! empty( $data['subject'] ) ? (string) $data['subject'] : '';
        $to = is_array( $data ) && ! empty( $data['to'] ) ? $data['to'] : '';
        $to = is_array( $to ) ? implode( ', ', $to ) : $to;
        $s = self::settings();
        $message = $error->get_error_message();
        error_log( '[Soldier-up Account Guard MAIL FAILURE] Recipient: ' . $to . ' | Error: ' . $message );
        self::log_event( 'error', 'Mail failure for ' . sanitize_text_field( $to ) . ': ' . sanitize_text_field( $message ) );
        foreach ( array_filter( array_map( 'trim', explode( ',', (string) $to ) ) ) as $email ) {
            $u = get_user_by( 'email', sanitize_email( $email ) ); if ( $u ) { update_user_meta( $u->ID, '_suag_mail_failed', time() ); }
        }
        if ( ! empty( $s['failure_alerts'] ) && is_email( $s['admin_failure_email'] ) ) {
            self::$sending_guard_email = false;
            wp_mail( sanitize_email( $s['admin_failure_email'] ), '[Account Guard] Verification Email Failure',
                "A Soldier-up Account Guard verification email failed.\n\nRecipient: {$to}\nError: {$message}\nTime: " . current_time( 'mysql' ) . "\nSite: " . home_url( '/' ) );
        }
    }

    public function block_unverified_login( $user, $username, $password ) {
        $s = self::settings();
        if ( empty( $s['enabled'] ) || empty( $s['require_verification_login'] ) ) { return $user; }
        if ( $user instanceof WP_User && ! self::user_is_exempt( $user ) && '0' === get_user_meta( $user->ID, '_suag_email_verified', true ) ) {
            return new WP_Error( 'suag_email_not_verified', 'Your account email has not been verified yet. Please check your email for the verification code.' );
        }
        return $user;
    }

    public function registration_redirect( $redirect_to ) {
        $email = isset( $_POST['user_email'] ) ? sanitize_email( wp_unslash( $_POST['user_email'] ) ) : '';
        if ( $email ) { $u = get_user_by( 'email', $email ); if ( $u && ! self::is_verified( $u->ID ) ) { return self::get_verify_url( $u->ID ); } }
        return $redirect_to;
    }
    public function woocommerce_registration_redirect( $redirect ) {
        $uid = get_current_user_id(); if ( $uid && ! self::is_verified( $uid ) ) { $url = self::get_verify_url( $uid ); wp_logout(); return $url; }
        return $redirect;
    }

    public static function resolve_role_redirect( $user, $default ) {
        if ( ! $user instanceof WP_User ) { return $default; }
        $s = self::settings(); $rules = is_array( $s['role_redirects'] ) ? $s['role_redirects'] : array();
        foreach ( (array) $user->roles as $role ) {
            if ( empty( $rules[ $role ] ) || ! is_array( $rules[ $role ] ) ) { continue; }
            $r = $rules[ $role ]; $type = isset( $r['type'] ) ? sanitize_key( $r['type'] ) : 'default';
            if ( 'home' === $type ) { return home_url( '/' ); }
            if ( 'page' === $type && ! empty( $r['page_id'] ) ) { $url = get_permalink( absint( $r['page_id'] ) ); if ( $url ) { return $url; } }
            if ( 'custom' === $type && ! empty( $r['url'] ) ) { $url = esc_url_raw( $r['url'] ); if ( $url ) { return $url; } }
        }
        return $default;
    }
    public function login_redirect( $redirect_to, $requested_redirect_to, $user ) {
        return ( $user instanceof WP_User && ! self::user_is_exempt( $user ) ) ? self::resolve_role_redirect( $user, $redirect_to ) : $redirect_to;
    }
    public function woocommerce_login_redirect( $redirect, $user ) {
        return ( $user instanceof WP_User && ! self::user_is_exempt( $user ) ) ? self::resolve_role_redirect( $user, $redirect ) : $redirect;
    }

    public static function password_setup_url( $user ) {
        if ( ! $user instanceof WP_User ) { return false; }
        $key = get_password_reset_key( $user );
        if ( is_wp_error( $key ) ) { self::log_event( 'error', 'Password setup key failed for user ID ' . $user->ID . ': ' . $key->get_error_message() ); return false; }
        return network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ), 'login' );
    }

    public function verification_shortcode() {
        $user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
        if ( ! $user_id ) { return $this->verification_message( 'Invalid Verification Request', 'This verification link is missing required information.' ); }
        $user = get_userdata( $user_id );
        if ( ! $user ) { return $this->verification_message( 'Invalid Verification Request', 'This account could not be found.' ); }
        $s = self::settings(); $notice = ''; $error = ''; $redirect_script = '';

        if ( self::is_verified( $user_id ) && ! empty( $s['force_password_setup'] ) ) {
            $setup = self::password_setup_url( $user );
            if ( $setup ) { $notice = 'Your account is already verified. Sending you to create your password...'; $redirect_script = '<script>setTimeout(function(){window.location.href=' . wp_json_encode( $setup ) . ';},1200);</script>'; }
        }

        if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
            $nonce = isset( $_POST['suag_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['suag_nonce'] ) ) : '';
            if ( ! wp_verify_nonce( $nonce, 'suag_verify_' . $user_id ) ) { $error = 'Security check failed. Please refresh the page and try again.'; }
            else {
                $action = isset( $_POST['suag_action'] ) ? sanitize_key( wp_unslash( $_POST['suag_action'] ) ) : '';
                if ( 'verify' === $action ) {
                    $otp = isset( $_POST['suag_code'] ) ? preg_replace( '/\D/', '', wp_unslash( $_POST['suag_code'] ) ) : '';
                    $hash = get_user_meta( $user_id, '_suag_otp_hash', true ); $expires = (int) get_user_meta( $user_id, '_suag_otp_expires', true ); $attempts = (int) get_user_meta( $user_id, '_suag_otp_attempts', true );
                    if ( $attempts >= absint( $s['max_attempts'] ) ) { $error = 'Too many incorrect attempts. Please request a new code.'; }
                    elseif ( ! $hash || ! $expires ) { $error = 'No active verification code was found. Please request a new code.'; }
                    elseif ( time() > $expires ) { $error = 'That code has expired. Please request a new code.'; }
                    elseif ( strlen( $otp ) !== absint( $s['otp_length'] ) || ! wp_check_password( $otp, $hash ) ) { update_user_meta( $user_id, '_suag_otp_attempts', $attempts + 1 ); $error = 'Invalid code. Please try again.'; }
                    else {
                        self::mark_verified( $user_id );
                        if ( ! empty( $s['force_password_setup'] ) ) { $setup = self::password_setup_url( $user ); if ( $setup ) { $notice = 'Your account has been verified. Sending you to create your password...'; $redirect_script = '<script>setTimeout(function(){window.location.href=' . wp_json_encode( $setup ) . ';},1200);</script>'; } }
                        else { $notice = 'Your account has been verified. You may now log in.'; }
                    }
                }
                if ( 'resend' === $action ) {
                    $last = (int) get_user_meta( $user_id, '_suag_otp_last_sent', true );
                    if ( $last && ( time() - $last ) < absint( $s['resend_cooldown'] ) ) { $error = 'Please wait before requesting another code.'; }
                    else {
                        $resends = get_user_meta( $user_id, '_suag_otp_resends', true ); $resends = is_array( $resends ) ? $resends : array(); $cutoff = time() - HOUR_IN_SECONDS;
                        $resends = array_values( array_filter( $resends, function( $ts ) use ( $cutoff ) { return (int) $ts >= $cutoff; } ) );
                        if ( count( $resends ) >= absint( $s['max_resends_hour'] ) ) { $error = 'Too many resend requests. Please try again later.'; }
                        else { $resends[] = time(); update_user_meta( $user_id, '_suag_otp_resends', $resends ); $notice = $this->send_otp( $user_id, true ) ? 'A new verification code has been sent.' : ''; if ( ! $notice ) { $error = 'The verification email could not be sent. Please contact the site administrator.'; } }
                    }
                }
                if ( 'change_email' === $action ) {
                    $new = isset( $_POST['suag_new_email'] ) ? sanitize_email( wp_unslash( $_POST['suag_new_email'] ) ) : '';
                    if ( self::is_verified( $user_id ) ) { $error = 'This account is already verified. Email changes must be made from your profile.'; }
                    elseif ( ! is_email( $new ) ) { $error = 'Please enter a valid email address.'; }
                    elseif ( email_exists( $new ) ) { $error = 'That email address is already in use.'; }
                    else { $result = wp_update_user( array( 'ID' => $user_id, 'user_email' => $new ) ); if ( is_wp_error( $result ) ) { $error = 'Email update failed. Please try again.'; } else { $user = get_userdata( $user_id ); $notice = $this->send_otp( $user_id, true ) ? 'Your email address was updated and a new verification code has been sent.' : ''; if ( ! $notice ) { $error = 'Your email was updated, but the verification email could not be sent.'; } } }
                }
            }
        }

        ob_start(); ?>
        <div class="suag-shell"><div class="suag-card">
            <div class="suag-brand-row"><img src="<?php echo esc_url( SUAG_URL . 'assets/soldier-up-designs-logo.png' ); ?>" alt="Soldier-up Designs" class="suag-logo"><div><div class="suag-kicker">ACCOUNT GUARD</div><h2>Verify Your Account</h2></div></div>
            <?php if ( $notice ) : ?><div class="suag-notice"><?php echo esc_html( $notice ); ?></div><?php endif; ?>
            <?php if ( $error ) : ?><div class="suag-error"><?php echo esc_html( $error ); ?></div><?php endif; ?>
            <p class="suag-muted">A verification code was sent to:</p><div class="suag-email"><strong><?php echo esc_html( $user->user_email ); ?></strong></div>
            <?php if ( ! self::is_verified( $user_id ) ) : ?>
            <form method="post" autocomplete="off"><?php wp_nonce_field( 'suag_verify_' . $user_id, 'suag_nonce' ); ?><input type="hidden" name="suag_action" value="verify"><label for="suag_code"><strong>Verification Code</strong></label><input class="suag-code" type="text" id="suag_code" name="suag_code" maxlength="<?php echo esc_attr( absint( $s['otp_length'] ) ); ?>" inputmode="numeric" pattern="[0-9]*" required><button class="suag-button" type="submit">Verify Account</button></form>
            <div class="suag-divider"></div>
            <form method="post"><?php wp_nonce_field( 'suag_verify_' . $user_id, 'suag_nonce' ); ?><input type="hidden" name="suag_action" value="resend"><button class="suag-button suag-button-secondary" type="submit">Resend Code</button></form>
            <div class="suag-divider"></div>
            <form method="post"><?php wp_nonce_field( 'suag_verify_' . $user_id, 'suag_nonce' ); ?><input type="hidden" name="suag_action" value="change_email"><label for="suag_new_email"><strong>Need to correct your email?</strong></label><input class="suag-email-input" type="email" id="suag_new_email" name="suag_new_email" placeholder="new-email@example.com" required><button class="suag-button suag-button-secondary" type="submit">Update Email &amp; Send New Code</button></form>
            <?php else : ?><p class="suag-muted">Your account is verified.</p><?php endif; ?>
        </div></div><?php echo $redirect_script; return ob_get_clean();
    }

    private function verification_message( $title, $message ) {
        return '<div class="suag-shell"><div class="suag-card"><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $message ) . '</p></div></div>';
    }

    public function cleanup_unverified_users() {
        $s = self::settings(); $cutoff = time() - ( max( 1, absint( $s['cleanup_days'] ) ) * DAY_IN_SECONDS );
        $users = get_users( array( 'meta_query' => array( array( 'key'=>'_suag_email_verified','value'=>'0' ), array( 'key'=>'_suag_created','value'=>$cutoff,'compare'=>'<','type'=>'NUMERIC' ) ), 'fields'=>'ID', 'number'=>100 ) );
        if ( ! $users ) { return; }
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ( $users as $uid ) { $u = get_userdata( $uid ); if ( $u && ! self::user_is_exempt( $u ) ) { wp_delete_user( $uid ); self::log_event( 'info', 'Deleted stale unverified user ID ' . $uid . '.' ); } }
    }

    public static function log_event( $level, $message ) {
        $logs = get_option( 'suag_logs', array() ); $logs = is_array( $logs ) ? $logs : array();
        array_unshift( $logs, array( 'time'=>current_time( 'mysql' ), 'level'=>sanitize_key( $level ), 'message'=>sanitize_text_field( $message ) ) );
        update_option( 'suag_logs', array_slice( $logs, 0, 100 ), false );
    }
}
