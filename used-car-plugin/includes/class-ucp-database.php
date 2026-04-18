<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UCP_Database {

    /**
     * プラグイン有効化時にテーブルを作成する
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── 販売者テーブル ───────────────────────────────────────────
        $sql_sellers = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ucp_sellers (
            id            INT          NOT NULL AUTO_INCREMENT,
            company_name  VARCHAR(255) NOT NULL COMMENT '会社名・店舗名',
            contact_name  VARCHAR(100) NOT NULL COMMENT '担当者名',
            email         VARCHAR(255) NOT NULL COMMENT 'メールアドレス（ログインID）',
            password      VARCHAR(255) NOT NULL COMMENT 'ハッシュ化パスワード',
            phone         VARCHAR(20)  NOT NULL COMMENT '電話番号',
            postal_code   VARCHAR(10)  NOT NULL COMMENT '郵便番号',
            prefecture    VARCHAR(50)  NOT NULL COMMENT '都道府県',
            address       TEXT         NOT NULL COMMENT '住所（市区町村以降）',
            website       VARCHAR(255) DEFAULT '' COMMENT 'ウェブサイトURL',
            description   TEXT                   COMMENT '店舗・会社紹介',
            status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' COMMENT '承認状態',
            reject_reason TEXT                   COMMENT '否認理由',
            created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY email (email),
            KEY status (status)
        ) $charset_collate;";

        // ── セッションテーブル ───────────────────────────────────────
        $sql_sessions = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ucp_sessions (
            id         INT         NOT NULL AUTO_INCREMENT,
            seller_id  INT         NOT NULL,
            token      VARCHAR(64) NOT NULL COMMENT 'セッショントークン（ランダム64文字hex）',
            expires_at DATETIME    NOT NULL,
            created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY token (token),
            KEY seller_id (seller_id)
        ) $charset_collate;";

        // ── 車両テーブル ─────────────────────────────────────────────
        $sql_cars = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ucp_cars (
            id                  INT          NOT NULL AUTO_INCREMENT,
            seller_id           INT          NOT NULL COMMENT '販売者ID',

            -- 基本情報
            make                VARCHAR(100) NOT NULL COMMENT 'メーカー',
            model               VARCHAR(100) NOT NULL COMMENT '車種名',
            grade               VARCHAR(100) DEFAULT '' COMMENT 'グレード',
            year                SMALLINT     NOT NULL COMMENT '年式（西暦）',
            month               TINYINT      DEFAULT NULL COMMENT '年式（月）',
            mileage             INT          NOT NULL COMMENT '走行距離（km）',
            price               INT          NOT NULL COMMENT '本体価格（万円）',

            -- ボディ情報
            body_type           VARCHAR(50)  DEFAULT '' COMMENT 'ボディタイプ',
            color               VARCHAR(50)  DEFAULT '' COMMENT 'ボディカラー',
            color_detail        VARCHAR(100) DEFAULT '' COMMENT 'カラー詳細（パールなど）',
            seating_capacity    TINYINT      DEFAULT NULL COMMENT '乗車定員',
            door_count          TINYINT      DEFAULT NULL COMMENT 'ドア数',

            -- エンジン・走行
            engine_displacement VARCHAR(20)  DEFAULT '' COMMENT '排気量（例: 1800cc）',
            fuel_type           VARCHAR(30)  DEFAULT '' COMMENT '燃料種別',
            transmission        VARCHAR(20)  DEFAULT '' COMMENT 'ミッション',
            drive_type          VARCHAR(20)  DEFAULT '' COMMENT '駆動方式',
            max_output          VARCHAR(30)  DEFAULT '' COMMENT '最高出力（例: 150PS）',
            fuel_efficiency     VARCHAR(20)  DEFAULT '' COMMENT '燃費（例: 18.6km/L）',

            -- 車検・状態
            inspection_date     DATE         DEFAULT NULL COMMENT '車検満了日',
            accident_history    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '修復歴 0:なし 1:あり',
            condition_rank      VARCHAR(10)  DEFAULT '' COMMENT 'コンディション評価 S/A/B/C',
            one_owner           TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'ワンオーナー 0:No 1:Yes',
            non_smoking         TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '禁煙車 0:No 1:Yes',

            -- 装備・オプション
            options_text        TEXT                   COMMENT '装備・オプション（カンマ区切り）',
            description         TEXT                   COMMENT '車両説明・アピールポイント',

            -- 所在地
            location_prefecture VARCHAR(50)  DEFAULT '' COMMENT '所在地（都道府県）',
            location_city       VARCHAR(100) DEFAULT '' COMMENT '所在地（市区町村）',

            -- 画像（JSONファイルパス配列、最大8枚）
            images              TEXT                   COMMENT '画像パスのJSON配列',

            -- 管理
            status              ENUM('pending','approved','rejected','sold') NOT NULL DEFAULT 'pending',
            reject_reason       TEXT                   COMMENT '否認理由',
            view_count          INT          NOT NULL DEFAULT 0,
            created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            KEY seller_id (seller_id),
            KEY status (status),
            KEY make (make),
            KEY year (year),
            KEY price (price)
        ) $charset_collate;";

        dbDelta( $sql_sellers );
        dbDelta( $sql_sessions );
        dbDelta( $sql_cars );

        update_option( 'ucp_db_version', UCP_VERSION );
    }

    // ── 汎用クエリヘルパー ───────────────────────────────────────────

    public static function get_seller( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ucp_sellers WHERE id = %d",
            intval( $id )
        ) );
    }

    public static function get_sellers( $args = array() ) {
        global $wpdb;
        $defaults = array(
            'status'   => '',
            'per_page' => 20,
            'page'     => 1,
            'search'   => '',
            'orderby'  => 'created_at',
            'order'    => 'DESC',
        );
        $args     = wp_parse_args( $args, $defaults );
        $where    = array( '1=1' );
        $values   = array();

        if ( $args['status'] ) {
            $where[]  = 'status = %s';
            $values[] = $args['status'];
        }
        if ( $args['search'] ) {
            $where[]  = '(company_name LIKE %s OR email LIKE %s)';
            $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $values[] = $like;
            $values[] = $like;
        }

        $where_sql = implode( ' AND ', $where );
        $offset    = ( absint( $args['page'] ) - 1 ) * absint( $args['per_page'] );
        $allowed_order = array( 'ASC', 'DESC' );
        $order     = in_array( strtoupper( $args['order'] ), $allowed_order, true ) ? strtoupper( $args['order'] ) : 'DESC';
        $orderby   = sanitize_sql_orderby( $args['orderby'] . ' ' . $order ) ?: 'created_at DESC';

        $sql = "SELECT * FROM {$wpdb->prefix}ucp_sellers WHERE {$where_sql} ORDER BY {$orderby} LIMIT %d OFFSET %d";
        $values[] = absint( $args['per_page'] );
        $values[] = $offset;

        return $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
    }

    public static function count_sellers( $args = array() ) {
        global $wpdb;
        $where  = array( '1=1' );
        $values = array();
        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $values[] = $args['status'];
        }
        $where_sql = implode( ' AND ', $where );
        if ( $values ) {
            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ucp_sellers WHERE {$where_sql}",
                $values
            ) );
        }
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ucp_sellers WHERE {$where_sql}" );
    }

    public static function get_car( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT c.*, s.company_name, s.phone, s.prefecture, s.address, s.website
             FROM {$wpdb->prefix}ucp_cars c
             LEFT JOIN {$wpdb->prefix}ucp_sellers s ON c.seller_id = s.id
             WHERE c.id = %d",
            intval( $id )
        ) );
    }

    public static function get_cars( $args = array() ) {
        global $wpdb;
        $defaults = array(
            'status'     => 'approved',
            'seller_id'  => 0,
            'make'       => '',
            'body_type'  => '',
            'fuel_type'  => '',
            'transmission' => '',
            'prefecture' => '',
            'year_min'   => 0,
            'year_max'   => 0,
            'price_min'  => 0,
            'price_max'  => 0,
            'mileage_max'=> 0,
            'keyword'    => '',
            'per_page'   => 12,
            'page'       => 1,
            'orderby'    => 'c.created_at',
            'order'      => 'DESC',
        );
        $args   = wp_parse_args( $args, $defaults );
        $where  = array( '1=1' );
        $values = array();

        if ( $args['status'] ) {
            $where[]  = 'c.status = %s';
            $values[] = $args['status'];
        }
        if ( $args['seller_id'] ) {
            $where[]  = 'c.seller_id = %d';
            $values[] = intval( $args['seller_id'] );
        }
        if ( $args['make'] ) {
            $where[]  = 'c.make = %s';
            $values[] = $args['make'];
        }
        if ( $args['body_type'] ) {
            $where[]  = 'c.body_type = %s';
            $values[] = $args['body_type'];
        }
        if ( $args['fuel_type'] ) {
            $where[]  = 'c.fuel_type = %s';
            $values[] = $args['fuel_type'];
        }
        if ( $args['transmission'] ) {
            $where[]  = 'c.transmission = %s';
            $values[] = $args['transmission'];
        }
        if ( $args['prefecture'] ) {
            $where[]  = 'c.location_prefecture = %s';
            $values[] = $args['prefecture'];
        }
        if ( $args['year_min'] ) {
            $where[]  = 'c.year >= %d';
            $values[] = intval( $args['year_min'] );
        }
        if ( $args['year_max'] ) {
            $where[]  = 'c.year <= %d';
            $values[] = intval( $args['year_max'] );
        }
        if ( $args['price_min'] ) {
            $where[]  = 'c.price >= %d';
            $values[] = intval( $args['price_min'] );
        }
        if ( $args['price_max'] ) {
            $where[]  = 'c.price <= %d';
            $values[] = intval( $args['price_max'] );
        }
        if ( $args['mileage_max'] ) {
            $where[]  = 'c.mileage <= %d';
            $values[] = intval( $args['mileage_max'] );
        }
        if ( $args['keyword'] ) {
            $like     = '%' . $wpdb->esc_like( $args['keyword'] ) . '%';
            $where[]  = '(c.make LIKE %s OR c.model LIKE %s OR c.grade LIKE %s OR c.description LIKE %s)';
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
        }

        $where_sql = implode( ' AND ', $where );
        $offset    = ( absint( $args['page'] ) - 1 ) * absint( $args['per_page'] );
        $allowed_order = array( 'ASC', 'DESC' );
        $order     = in_array( strtoupper( $args['order'] ), $allowed_order, true ) ? strtoupper( $args['order'] ) : 'DESC';

        $sql = "SELECT c.*, s.company_name, s.prefecture AS seller_prefecture
                FROM {$wpdb->prefix}ucp_cars c
                LEFT JOIN {$wpdb->prefix}ucp_sellers s ON c.seller_id = s.id
                WHERE {$where_sql}
                ORDER BY c.created_at {$order}
                LIMIT %d OFFSET %d";
        $values[] = absint( $args['per_page'] );
        $values[] = $offset;

        return $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
    }

    public static function count_cars( $args = array() ) {
        global $wpdb;
        $defaults = array(
            'status'      => 'approved',
            'seller_id'   => 0,
            'make'        => '',
            'body_type'   => '',
            'fuel_type'   => '',
            'transmission'=> '',
            'prefecture'  => '',
            'year_min'    => 0,
            'year_max'    => 0,
            'price_min'   => 0,
            'price_max'   => 0,
            'mileage_max' => 0,
            'keyword'     => '',
        );
        $args   = wp_parse_args( $args, $defaults );
        $where  = array( '1=1' );
        $values = array();

        if ( $args['status'] ) {
            $where[]  = 'c.status = %s';
            $values[] = $args['status'];
        }
        if ( $args['seller_id'] ) {
            $where[]  = 'c.seller_id = %d';
            $values[] = intval( $args['seller_id'] );
        }
        if ( $args['make'] ) {
            $where[]  = 'c.make = %s';
            $values[] = $args['make'];
        }
        if ( $args['body_type'] ) {
            $where[]  = 'c.body_type = %s';
            $values[] = $args['body_type'];
        }
        if ( $args['fuel_type'] ) {
            $where[]  = 'c.fuel_type = %s';
            $values[] = $args['fuel_type'];
        }
        if ( $args['transmission'] ) {
            $where[]  = 'c.transmission = %s';
            $values[] = $args['transmission'];
        }
        if ( $args['prefecture'] ) {
            $where[]  = 'c.location_prefecture = %s';
            $values[] = $args['prefecture'];
        }
        if ( $args['year_min'] ) {
            $where[]  = 'c.year >= %d';
            $values[] = intval( $args['year_min'] );
        }
        if ( $args['year_max'] ) {
            $where[]  = 'c.year <= %d';
            $values[] = intval( $args['year_max'] );
        }
        if ( $args['price_min'] ) {
            $where[]  = 'c.price >= %d';
            $values[] = intval( $args['price_min'] );
        }
        if ( $args['price_max'] ) {
            $where[]  = 'c.price <= %d';
            $values[] = intval( $args['price_max'] );
        }
        if ( $args['mileage_max'] ) {
            $where[]  = 'c.mileage <= %d';
            $values[] = intval( $args['mileage_max'] );
        }
        if ( $args['keyword'] ) {
            $like     = '%' . $wpdb->esc_like( $args['keyword'] ) . '%';
            $where[]  = '(c.make LIKE %s OR c.model LIKE %s OR c.grade LIKE %s OR c.description LIKE %s)';
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
        }

        $where_sql = implode( ' AND ', $where );
        $sql       = "SELECT COUNT(*) FROM {$wpdb->prefix}ucp_cars c WHERE {$where_sql}";

        if ( $values ) {
            return (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
        }
        return (int) $wpdb->get_var( $sql );
    }

    public static function get_distinct_makes() {
        global $wpdb;
        return $wpdb->get_col(
            "SELECT DISTINCT make FROM {$wpdb->prefix}ucp_cars WHERE status = 'approved' ORDER BY make ASC"
        );
    }
}
