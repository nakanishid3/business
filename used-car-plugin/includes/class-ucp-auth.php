<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UCP_Auth {

    const COOKIE_NAME      = 'ucp_seller_session';
    const SESSION_DURATION = 86400 * 7; // 7日間

    /**
     * ログイン処理
     * @return array|false  成功時: 販売者オブジェクト, 未承認時: ['status'=>'pending'/'rejected'], 失敗時: false
     */
    public static function login( $email, $password ) {
        global $wpdb;

        $seller = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ucp_sellers WHERE email = %s",
            sanitize_email( $email )
        ) );

        if ( ! $seller ) {
            return false;
        }

        if ( ! password_verify( $password, $seller->password ) ) {
            return false;
        }

        if ( $seller->status === 'pending' ) {
            return array( 'status' => 'pending' );
        }

        if ( $seller->status === 'rejected' ) {
            return array( 'status' => 'rejected', 'reason' => $seller->reject_reason );
        }

        // セッショントークンを生成して保存
        $token      = bin2hex( random_bytes( 32 ) );
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + self::SESSION_DURATION );

        $wpdb->insert(
            "{$wpdb->prefix}ucp_sessions",
            array(
                'seller_id'  => $seller->id,
                'token'      => $token,
                'expires_at' => $expires_at,
            ),
            array( '%d', '%s', '%s' )
        );

        // クッキーにセット（HttpOnly + SameSite=Strict）
        $cookie_options = array(
            'expires'  => time() + self::SESSION_DURATION,
            'path'     => COOKIEPATH,
            'domain'   => COOKIE_DOMAIN,
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Strict',
        );

        if ( PHP_VERSION_ID >= 70300 ) {
            setcookie( self::COOKIE_NAME, $token, $cookie_options );
        } else {
            setcookie(
                self::COOKIE_NAME,
                $token,
                $cookie_options['expires'],
                $cookie_options['path'] . '; samesite=Strict',
                $cookie_options['domain'],
                $cookie_options['secure'],
                $cookie_options['httponly']
            );
        }

        return $seller;
    }

    /**
     * 現在ログイン中の販売者を取得する
     * @return object|null
     */
    public static function get_current_seller() {
        if ( ! isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            return null;
        }

        global $wpdb;
        $token = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );

        if ( strlen( $token ) !== 64 || ! ctype_xdigit( $token ) ) {
            return null;
        }

        $seller = $wpdb->get_row( $wpdb->prepare(
            "SELECT sel.*
             FROM {$wpdb->prefix}ucp_sessions sess
             INNER JOIN {$wpdb->prefix}ucp_sellers sel ON sess.seller_id = sel.id
             WHERE sess.token = %s AND sess.expires_at > UTC_TIMESTAMP()",
            $token
        ) );

        return $seller;
    }

    /**
     * ログアウト処理
     */
    public static function logout() {
        if ( ! isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            return;
        }

        global $wpdb;
        $token = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );

        if ( strlen( $token ) === 64 && ctype_xdigit( $token ) ) {
            $wpdb->delete(
                "{$wpdb->prefix}ucp_sessions",
                array( 'token' => $token ),
                array( '%s' )
            );
        }

        // クッキーを削除
        setcookie( self::COOKIE_NAME, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
        unset( $_COOKIE[ self::COOKIE_NAME ] );
    }

    /**
     * ログイン状態確認
     */
    public static function is_logged_in() {
        return self::get_current_seller() !== null;
    }

    /**
     * 期限切れセッションを削除（定期実行）
     */
    public static function clean_sessions() {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}ucp_sessions WHERE expires_at < UTC_TIMESTAMP()" );
    }
}

// 週次でセッションクリーンアップ
add_action( 'ucp_weekly_cleanup', array( 'UCP_Auth', 'clean_sessions' ) );
if ( ! wp_next_scheduled( 'ucp_weekly_cleanup' ) ) {
    wp_schedule_event( time(), 'weekly', 'ucp_weekly_cleanup' );
}
