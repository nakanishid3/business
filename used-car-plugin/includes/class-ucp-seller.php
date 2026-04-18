<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UCP_Seller {

    /**
     * 販売者の新規登録
     * @param  array $data フォームデータ
     * @return int|WP_Error  挿入ID or エラー
     */
    public static function register( $data ) {
        global $wpdb;

        $email = sanitize_email( $data['email'] ?? '' );

        if ( ! is_email( $email ) ) {
            return new WP_Error( 'invalid_email', 'メールアドレスの形式が正しくありません。' );
        }

        // 重複チェック
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ucp_sellers WHERE email = %s",
            $email
        ) );
        if ( $exists ) {
            return new WP_Error( 'duplicate_email', 'このメールアドレスはすでに登録されています。' );
        }

        $password = $data['password'] ?? '';
        if ( strlen( $password ) < 8 ) {
            return new WP_Error( 'weak_password', 'パスワードは8文字以上で設定してください。' );
        }

        $insert = $wpdb->insert(
            "{$wpdb->prefix}ucp_sellers",
            array(
                'company_name' => sanitize_text_field( $data['company_name'] ?? '' ),
                'contact_name' => sanitize_text_field( $data['contact_name'] ?? '' ),
                'email'        => $email,
                'password'     => password_hash( $password, PASSWORD_BCRYPT ),
                'phone'        => sanitize_text_field( $data['phone'] ?? '' ),
                'postal_code'  => sanitize_text_field( $data['postal_code'] ?? '' ),
                'prefecture'   => sanitize_text_field( $data['prefecture'] ?? '' ),
                'address'      => sanitize_text_field( $data['address'] ?? '' ),
                'website'      => esc_url_raw( $data['website'] ?? '' ),
                'description'  => sanitize_textarea_field( $data['description'] ?? '' ),
                'status'       => 'pending',
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( ! $insert ) {
            return new WP_Error( 'db_error', '登録中にエラーが発生しました。しばらくしてから再度お試しください。' );
        }

        $seller_id = $wpdb->insert_id;

        // 管理者へメール通知
        self::notify_admin_new_seller( $seller_id );

        return $seller_id;
    }

    /**
     * 販売者プロフィールの更新
     * @param  int   $seller_id
     * @param  array $data
     * @return bool|WP_Error
     */
    public static function update( $seller_id, $data ) {
        global $wpdb;

        $seller_id = intval( $seller_id );
        if ( ! $seller_id ) {
            return new WP_Error( 'invalid_id', '無効な販売者IDです。' );
        }

        $update_data = array(
            'company_name' => sanitize_text_field( $data['company_name'] ?? '' ),
            'contact_name' => sanitize_text_field( $data['contact_name'] ?? '' ),
            'phone'        => sanitize_text_field( $data['phone'] ?? '' ),
            'postal_code'  => sanitize_text_field( $data['postal_code'] ?? '' ),
            'prefecture'   => sanitize_text_field( $data['prefecture'] ?? '' ),
            'address'      => sanitize_text_field( $data['address'] ?? '' ),
            'website'      => esc_url_raw( $data['website'] ?? '' ),
            'description'  => sanitize_textarea_field( $data['description'] ?? '' ),
        );

        // パスワード変更が指定された場合
        if ( ! empty( $data['new_password'] ) ) {
            if ( strlen( $data['new_password'] ) < 8 ) {
                return new WP_Error( 'weak_password', 'パスワードは8文字以上で設定してください。' );
            }
            $update_data['password'] = password_hash( $data['new_password'], PASSWORD_BCRYPT );
        }

        $formats = array_fill( 0, count( $update_data ), '%s' );
        $result  = $wpdb->update(
            "{$wpdb->prefix}ucp_sellers",
            $update_data,
            array( 'id' => $seller_id ),
            $formats,
            array( '%d' )
        );

        if ( $result === false ) {
            return new WP_Error( 'db_error', '更新中にエラーが発生しました。' );
        }

        return true;
    }

    /**
     * 販売者の削除（紐づく車両・セッションも削除）
     * @param  int $seller_id
     * @return bool
     */
    public static function delete( $seller_id ) {
        global $wpdb;

        $seller_id = intval( $seller_id );

        // 紐づく車両の画像ファイルを削除
        $cars = $wpdb->get_results( $wpdb->prepare(
            "SELECT images FROM {$wpdb->prefix}ucp_cars WHERE seller_id = %d",
            $seller_id
        ) );
        foreach ( $cars as $car ) {
            if ( $car->images ) {
                $images = json_decode( $car->images, true );
                if ( is_array( $images ) ) {
                    foreach ( $images as $path ) {
                        UCP_Upload::delete_file( $path );
                    }
                }
            }
        }

        $wpdb->delete( "{$wpdb->prefix}ucp_cars",     array( 'seller_id' => $seller_id ), array( '%d' ) );
        $wpdb->delete( "{$wpdb->prefix}ucp_sessions", array( 'seller_id' => $seller_id ), array( '%d' ) );
        $wpdb->delete( "{$wpdb->prefix}ucp_sellers",  array( 'id'        => $seller_id ), array( '%d' ) );

        return true;
    }

    /**
     * 承認状態の更新
     * @param  int    $seller_id
     * @param  string $status   'approved'|'rejected'
     * @param  string $reason   否認理由（rejectの場合）
     * @return bool
     */
    public static function update_status( $seller_id, $status, $reason = '' ) {
        global $wpdb;

        $allowed = array( 'pending', 'approved', 'rejected' );
        if ( ! in_array( $status, $allowed, true ) ) {
            return false;
        }

        $result = $wpdb->update(
            "{$wpdb->prefix}ucp_sellers",
            array(
                'status'        => $status,
                'reject_reason' => sanitize_textarea_field( $reason ),
            ),
            array( 'id' => intval( $seller_id ) ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        // 販売者へ結果をメール通知
        if ( $result !== false ) {
            self::notify_seller_status( $seller_id, $status, $reason );
        }

        return $result !== false;
    }

    // ── メール通知 ─────────────────────────────────────────────────

    private static function notify_admin_new_seller( $seller_id ) {
        $seller   = UCP_Database::get_seller( $seller_id );
        $admin_email = get_option( 'admin_email' );
        $admin_url   = admin_url( 'admin.php?page=ucp-sellers&action=view&id=' . $seller_id );
        $site_name   = get_bloginfo( 'name' );

        $subject = "[{$site_name}] 新しい販売者登録申請があります";
        $message = "新しい販売者登録申請が届きました。\n\n"
            . "会社名・店舗名：{$seller->company_name}\n"
            . "担当者名：{$seller->contact_name}\n"
            . "メールアドレス：{$seller->email}\n\n"
            . "管理画面から確認・承認してください：\n{$admin_url}\n";

        wp_mail( $admin_email, $subject, $message );
    }

    private static function notify_seller_status( $seller_id, $status, $reason ) {
        $seller    = UCP_Database::get_seller( $seller_id );
        $site_name = get_bloginfo( 'name' );

        if ( $status === 'approved' ) {
            $subject = "[{$site_name}] 販売者登録が承認されました";
            $message = "{$seller->contact_name} 様\n\n"
                . "販売者登録が承認されました。\n"
                . "ログインして車両情報を登録できます。\n\n"
                . get_permalink( get_option( 'ucp_dashboard_page_id' ) ) . "\n";
        } else {
            $subject = "[{$site_name}] 販売者登録が否認されました";
            $message = "{$seller->contact_name} 様\n\n"
                . "申し訳ありませんが、販売者登録が否認されました。\n";
            if ( $reason ) {
                $message .= "理由：{$reason}\n";
            }
            $message .= "\nご不明な点はサイト管理者までお問い合わせください。\n";
        }

        wp_mail( $seller->email, $subject, $message );
    }
}
