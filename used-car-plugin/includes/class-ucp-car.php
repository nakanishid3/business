<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UCP_Car {

    /** ボディタイプ選択肢 */
    public static function get_body_types() {
        return array(
            'sedan'        => 'セダン',
            'suv'          => 'SUV',
            'minivan'      => 'ミニバン',
            'wagon'        => 'ステーションワゴン',
            'hatchback'    => 'ハッチバック',
            'coupe'        => 'クーペ',
            'convertible'  => 'オープンカー',
            'kei'          => '軽自動車',
            'truck'        => 'トラック',
            'van'          => 'バン',
            'other'        => 'その他',
        );
    }

    /** 燃料種別 */
    public static function get_fuel_types() {
        return array(
            'gasoline'     => 'ガソリン',
            'diesel'       => 'ディーゼル',
            'hybrid'       => 'ハイブリッド',
            'phev'         => 'プラグインハイブリッド（PHEV）',
            'electric'     => '電気（EV）',
            'lpg'          => 'LPG',
            'other'        => 'その他',
        );
    }

    /** ミッション種別 */
    public static function get_transmissions() {
        return array(
            'at'  => 'AT（オートマ）',
            'cvt' => 'CVT',
            'mt'  => 'MT（マニュアル）',
            'dct' => 'DCT（ツインクラッチ）',
            'other' => 'その他',
        );
    }

    /** 駆動方式 */
    public static function get_drive_types() {
        return array(
            '2wd_ff' => '2WD（FF）',
            '2wd_fr' => '2WD（FR）',
            '2wd_mr' => '2WD（MR）',
            '2wd_rr' => '2WD（RR）',
            '4wd'    => '4WD',
            'awd'    => 'AWD',
            'other'  => 'その他',
        );
    }

    /** コンディション評価 */
    public static function get_condition_ranks() {
        return array(
            'S' => 'S（新車同様）',
            'A' => 'A（傷なし・良好）',
            'B' => 'B（軽微な傷あり）',
            'C' => 'C（目立つ傷あり）',
        );
    }

    /** 都道府県リスト */
    public static function get_prefectures() {
        return array(
            '北海道', '青森県', '岩手県', '宮城県', '秋田県',
            '山形県', '福島県', '茨城県', '栃木県', '群馬県',
            '埼玉県', '千葉県', '東京都', '神奈川県', '新潟県',
            '富山県', '石川県', '福井県', '山梨県', '長野県',
            '岐阜県', '静岡県', '愛知県', '三重県', '滋賀県',
            '京都府', '大阪府', '兵庫県', '奈良県', '和歌山県',
            '鳥取県', '島根県', '岡山県', '広島県', '山口県',
            '徳島県', '香川県', '愛媛県', '高知県', '福岡県',
            '佐賀県', '長崎県', '熊本県', '大分県', '宮崎県',
            '鹿児島県', '沖縄県',
        );
    }

    /**
     * 車両の新規登録
     * @param  int   $seller_id
     * @param  array $data
     * @return int|WP_Error
     */
    public static function create( $seller_id, $data ) {
        global $wpdb;

        $seller_id = intval( $seller_id );

        $validation = self::validate( $data );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        $insert_data = self::sanitize_data( $data );
        $insert_data['seller_id'] = $seller_id;
        $insert_data['status']    = 'pending';

        $formats = self::get_formats( $insert_data );

        $result = $wpdb->insert(
            "{$wpdb->prefix}ucp_cars",
            $insert_data,
            $formats
        );

        if ( ! $result ) {
            return new WP_Error( 'db_error', '登録中にエラーが発生しました。' );
        }

        $car_id = $wpdb->insert_id;

        // 管理者へ通知
        self::notify_admin_new_car( $car_id );

        return $car_id;
    }

    /**
     * 車両情報の更新
     * @param  int   $car_id
     * @param  int   $seller_id   所有者チェック用
     * @param  array $data
     * @return bool|WP_Error
     */
    public static function update( $car_id, $seller_id, $data ) {
        global $wpdb;

        $car_id    = intval( $car_id );
        $seller_id = intval( $seller_id );

        // 所有者確認
        $car = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ucp_cars WHERE id = %d AND seller_id = %d",
            $car_id,
            $seller_id
        ) );

        if ( ! $car ) {
            return new WP_Error( 'not_found', '車両が見つかりません。' );
        }

        $validation = self::validate( $data );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        $update_data = self::sanitize_data( $data );
        // 編集後は再審査に
        $update_data['status'] = 'pending';

        $formats = self::get_formats( $update_data );

        $result = $wpdb->update(
            "{$wpdb->prefix}ucp_cars",
            $update_data,
            array( 'id' => $car_id, 'seller_id' => $seller_id ),
            $formats,
            array( '%d', '%d' )
        );

        if ( $result === false ) {
            return new WP_Error( 'db_error', '更新中にエラーが発生しました。' );
        }

        // 管理者へ通知
        self::notify_admin_new_car( $car_id, true );

        return true;
    }

    /**
     * 車両の削除
     * @param  int $car_id
     * @param  int $seller_id  所有者チェック用（管理者は0を渡す）
     * @return bool|WP_Error
     */
    public static function delete( $car_id, $seller_id = 0 ) {
        global $wpdb;

        $car_id = intval( $car_id );

        // 画像取得してから削除
        $car = UCP_Database::get_car( $car_id );
        if ( ! $car ) {
            return new WP_Error( 'not_found', '車両が見つかりません。' );
        }

        // 所有者チェック（管理者は0を渡せばスキップ）
        if ( $seller_id && intval( $car->seller_id ) !== intval( $seller_id ) ) {
            return new WP_Error( 'forbidden', 'この車両を削除する権限がありません。' );
        }

        // 画像ファイルを削除
        if ( $car->images ) {
            $images = json_decode( $car->images, true );
            if ( is_array( $images ) ) {
                foreach ( $images as $path ) {
                    UCP_Upload::delete_file( $path );
                }
            }
        }

        $wpdb->delete(
            "{$wpdb->prefix}ucp_cars",
            array( 'id' => $car_id ),
            array( '%d' )
        );

        return true;
    }

    /**
     * 承認状態の更新（管理者用）
     * @param  int    $car_id
     * @param  string $status
     * @param  string $reason
     * @return bool
     */
    public static function update_status( $car_id, $status, $reason = '' ) {
        global $wpdb;

        $allowed = array( 'pending', 'approved', 'rejected', 'sold' );
        if ( ! in_array( $status, $allowed, true ) ) {
            return false;
        }

        $result = $wpdb->update(
            "{$wpdb->prefix}ucp_cars",
            array(
                'status'        => $status,
                'reject_reason' => sanitize_textarea_field( $reason ),
            ),
            array( 'id' => intval( $car_id ) ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        if ( $result !== false ) {
            self::notify_seller_car_status( $car_id, $status, $reason );
        }

        return $result !== false;
    }

    /**
     * 閲覧数をインクリメント
     */
    public static function increment_view( $car_id ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}ucp_cars SET view_count = view_count + 1 WHERE id = %d",
            intval( $car_id )
        ) );
    }

    // ── バリデーション ──────────────────────────────────────────────

    private static function validate( $data ) {
        if ( empty( $data['make'] ) ) {
            return new WP_Error( 'required', 'メーカーを入力してください。' );
        }
        if ( empty( $data['model'] ) ) {
            return new WP_Error( 'required', '車種名を入力してください。' );
        }
        $year = intval( $data['year'] ?? 0 );
        if ( $year < 1950 || $year > intval( gmdate( 'Y' ) ) + 1 ) {
            return new WP_Error( 'invalid_year', '年式が正しくありません。' );
        }
        if ( intval( $data['mileage'] ?? -1 ) < 0 ) {
            return new WP_Error( 'invalid_mileage', '走行距離を正しく入力してください。' );
        }
        if ( intval( $data['price'] ?? -1 ) < 0 ) {
            return new WP_Error( 'invalid_price', '価格を正しく入力してください。' );
        }
        return true;
    }

    // ── データのサニタイズ ──────────────────────────────────────────

    private static function sanitize_data( $data ) {
        return array(
            'make'                => sanitize_text_field( $data['make'] ?? '' ),
            'model'               => sanitize_text_field( $data['model'] ?? '' ),
            'grade'               => sanitize_text_field( $data['grade'] ?? '' ),
            'year'                => intval( $data['year'] ?? 0 ),
            'month'               => ! empty( $data['month'] ) ? intval( $data['month'] ) : null,
            'mileage'             => intval( $data['mileage'] ?? 0 ),
            'price'               => intval( $data['price'] ?? 0 ),
            'body_type'           => sanitize_text_field( $data['body_type'] ?? '' ),
            'color'               => sanitize_text_field( $data['color'] ?? '' ),
            'color_detail'        => sanitize_text_field( $data['color_detail'] ?? '' ),
            'seating_capacity'    => ! empty( $data['seating_capacity'] ) ? intval( $data['seating_capacity'] ) : null,
            'door_count'          => ! empty( $data['door_count'] ) ? intval( $data['door_count'] ) : null,
            'engine_displacement' => sanitize_text_field( $data['engine_displacement'] ?? '' ),
            'fuel_type'           => sanitize_text_field( $data['fuel_type'] ?? '' ),
            'transmission'        => sanitize_text_field( $data['transmission'] ?? '' ),
            'drive_type'          => sanitize_text_field( $data['drive_type'] ?? '' ),
            'max_output'          => sanitize_text_field( $data['max_output'] ?? '' ),
            'fuel_efficiency'     => sanitize_text_field( $data['fuel_efficiency'] ?? '' ),
            'inspection_date'     => ! empty( $data['inspection_date'] ) ? sanitize_text_field( $data['inspection_date'] ) : null,
            'accident_history'    => isset( $data['accident_history'] ) ? 1 : 0,
            'condition_rank'      => sanitize_text_field( $data['condition_rank'] ?? '' ),
            'one_owner'           => isset( $data['one_owner'] ) ? 1 : 0,
            'non_smoking'         => isset( $data['non_smoking'] ) ? 1 : 0,
            'options_text'        => sanitize_textarea_field( $data['options_text'] ?? '' ),
            'description'         => sanitize_textarea_field( $data['description'] ?? '' ),
            'location_prefecture' => sanitize_text_field( $data['location_prefecture'] ?? '' ),
            'location_city'       => sanitize_text_field( $data['location_city'] ?? '' ),
            'images'              => $data['images'] ?? '[]',
        );
    }

    private static function get_formats( $data ) {
        $format_map = array(
            'seller_id'           => '%d',
            'year'                => '%d',
            'month'               => '%d',
            'mileage'             => '%d',
            'price'               => '%d',
            'seating_capacity'    => '%d',
            'door_count'          => '%d',
            'accident_history'    => '%d',
            'one_owner'           => '%d',
            'non_smoking'         => '%d',
            'view_count'          => '%d',
        );
        $formats = array();
        foreach ( array_keys( $data ) as $key ) {
            $formats[] = isset( $format_map[ $key ] ) ? $format_map[ $key ] : '%s';
        }
        return $formats;
    }

    // ── メール通知 ─────────────────────────────────────────────────

    private static function notify_admin_new_car( $car_id, $is_update = false ) {
        $car       = UCP_Database::get_car( $car_id );
        $admin_email = get_option( 'admin_email' );
        $admin_url   = admin_url( 'admin.php?page=ucp-cars&action=view&id=' . $car_id );
        $site_name   = get_bloginfo( 'name' );
        $verb        = $is_update ? '更新' : '新規登録';

        $subject = "[{$site_name}] 車両情報の{$verb}申請があります";
        $message = "車両情報の{$verb}申請が届きました。\n\n"
            . "車両：{$car->make} {$car->model} {$car->year}年式\n"
            . "価格：" . number_format( $car->price ) . "万円\n"
            . "販売者：{$car->company_name}\n\n"
            . "管理画面から確認・承認してください：\n{$admin_url}\n";

        wp_mail( $admin_email, $subject, $message );
    }

    private static function notify_seller_car_status( $car_id, $status, $reason ) {
        $car    = UCP_Database::get_car( $car_id );
        $seller = UCP_Database::get_seller( $car->seller_id );
        if ( ! $seller ) {
            return;
        }
        $site_name = get_bloginfo( 'name' );

        if ( $status === 'approved' ) {
            $subject = "[{$site_name}] 車両情報が公開されました";
            $message = "{$seller->contact_name} 様\n\n"
                . "以下の車両情報が審査を通過し、公開されました。\n\n"
                . "車両：{$car->make} {$car->model} {$car->year}年式\n";
        } elseif ( $status === 'rejected' ) {
            $subject = "[{$site_name}] 車両情報が否認されました";
            $message = "{$seller->contact_name} 様\n\n"
                . "以下の車両情報が否認されました。\n\n"
                . "車両：{$car->make} {$car->model} {$car->year}年式\n";
            if ( $reason ) {
                $message .= "理由：{$reason}\n";
            }
        } elseif ( $status === 'sold' ) {
            $subject = "[{$site_name}] 車両情報のステータスが「成約済み」に変更されました";
            $message = "{$seller->contact_name} 様\n\n"
                . "車両：{$car->make} {$car->model} {$car->year}年式\n"
                . "のステータスが「成約済み」に変更されました。\n";
        } else {
            return;
        }

        wp_mail( $seller->email, $subject, $message );
    }
}
