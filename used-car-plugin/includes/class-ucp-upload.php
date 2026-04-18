<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UCP_Upload {

    const MAX_IMAGES     = 8;
    const MAX_FILE_SIZE  = 5242880; // 5MB
    const ALLOWED_TYPES  = array( 'image/jpeg', 'image/png', 'image/webp' );
    const UPLOAD_SUBDIR  = 'used-car-plugin';

    /**
     * アップロードディレクトリを取得・作成
     * @return array ['basedir'=>..., 'baseurl'=>...]
     */
    public static function get_upload_dir() {
        $wp_upload = wp_upload_dir();
        $basedir   = trailingslashit( $wp_upload['basedir'] ) . self::UPLOAD_SUBDIR;
        $baseurl   = trailingslashit( $wp_upload['baseurl'] ) . self::UPLOAD_SUBDIR;

        if ( ! file_exists( $basedir ) ) {
            wp_mkdir_p( $basedir );
            // .htaccess: PHP実行を禁止
            file_put_contents( $basedir . '/.htaccess', "Options -ExecCGI\nAddHandler cgi-script .php .pl .py .cgi\n" );
            // index.php: ディレクトリリスティングを禁止
            file_put_contents( $basedir . '/index.php', '<?php // Silence is golden.' );
        }

        return array( 'basedir' => $basedir, 'baseurl' => $baseurl );
    }

    /**
     * 画像ファイルをアップロード（単体）
     * @param  array  $file    $_FILES 要素
     * @param  int    $car_id  車両ID（ディレクトリ分け用）
     * @return array|WP_Error  成功時: ['url'=>..., 'path'=>...], 失敗時: WP_Error
     */
    public static function upload_image( $file, $car_id ) {
        // ファイルサイズチェック
        if ( $file['size'] > self::MAX_FILE_SIZE ) {
            return new WP_Error( 'file_too_large', 'ファイルサイズは5MB以下にしてください。' );
        }

        // MIMEタイプチェック（finfo使用）
        if ( function_exists( 'finfo_open' ) ) {
            $finfo     = finfo_open( FILEINFO_MIME_TYPE );
            $mime_type = finfo_file( $finfo, $file['tmp_name'] );
            finfo_close( $finfo );
        } else {
            $mime_type = $file['type'];
        }

        if ( ! in_array( $mime_type, self::ALLOWED_TYPES, true ) ) {
            return new WP_Error( 'invalid_type', '対応形式はJPEG・PNG・WebPのみです。' );
        }

        // 拡張子を決定
        $ext_map = array(
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        );
        $ext = $ext_map[ $mime_type ];

        // アップロード先ディレクトリ
        $upload    = self::get_upload_dir();
        $car_dir   = trailingslashit( $upload['basedir'] ) . intval( $car_id );
        $car_url   = trailingslashit( $upload['baseurl'] ) . intval( $car_id );

        if ( ! file_exists( $car_dir ) ) {
            wp_mkdir_p( $car_dir );
        }

        // ユニークなファイル名を生成
        $filename  = wp_unique_filename( $car_dir, bin2hex( random_bytes( 8 ) ) . '.' . $ext );
        $dest_path = $car_dir . '/' . $filename;
        $dest_url  = $car_url . '/' . $filename;

        // 移動
        if ( ! move_uploaded_file( $file['tmp_name'], $dest_path ) ) {
            return new WP_Error( 'upload_failed', 'ファイルの保存に失敗しました。' );
        }

        // ファイルパーミッションを設定
        chmod( $dest_path, 0644 );

        return array(
            'url'  => $dest_url,
            'path' => $dest_path,
        );
    }

    /**
     * 複数ファイルのアップロード（$_FILESの'images'フィールド）
     * @param  array $files     $_FILES['images']
     * @param  int   $car_id    車両ID
     * @param  array $existing  既存の画像URLリスト
     * @return array ['urls'=>[...], 'errors'=>[...]]
     */
    public static function upload_multiple( $files, $car_id, $existing = array() ) {
        $uploaded = $existing;
        $errors   = array();

        // 正規化（multiple inputの場合、配列になる）
        if ( ! isset( $files['name'] ) ) {
            return array( 'urls' => $uploaded, 'errors' => array( '無効なファイルデータです。' ) );
        }

        $count = is_array( $files['name'] ) ? count( $files['name'] ) : 1;

        for ( $i = 0; $i < $count; $i++ ) {
            if ( count( $uploaded ) >= self::MAX_IMAGES ) {
                $errors[] = '画像は最大' . self::MAX_IMAGES . '枚までです。';
                break;
            }

            $single = array(
                'name'     => is_array( $files['name'] )     ? $files['name'][ $i ]     : $files['name'],
                'type'     => is_array( $files['type'] )     ? $files['type'][ $i ]     : $files['type'],
                'tmp_name' => is_array( $files['tmp_name'] ) ? $files['tmp_name'][ $i ] : $files['tmp_name'],
                'error'    => is_array( $files['error'] )    ? $files['error'][ $i ]    : $files['error'],
                'size'     => is_array( $files['size'] )     ? $files['size'][ $i ]     : $files['size'],
            );

            if ( $single['error'] === UPLOAD_ERR_NO_FILE ) {
                continue;
            }

            if ( $single['error'] !== UPLOAD_ERR_OK ) {
                $errors[] = 'ファイルのアップロード中にエラーが発生しました（エラーコード: ' . $single['error'] . '）。';
                continue;
            }

            $result = self::upload_image( $single, $car_id );

            if ( is_wp_error( $result ) ) {
                $errors[] = $result->get_error_message();
            } else {
                $uploaded[] = $result['url'];
            }
        }

        return array( 'urls' => $uploaded, 'errors' => $errors );
    }

    /**
     * ファイルを削除する
     * @param  string $url  ファイルURL
     * @return bool
     */
    public static function delete_file( $url ) {
        $upload  = self::get_upload_dir();
        $baseurl = trailingslashit( $upload['baseurl'] );

        if ( strpos( $url, $baseurl ) !== 0 ) {
            return false; // プラグイン管理外のファイルは削除しない
        }

        $relative = substr( $url, strlen( $baseurl ) );
        $path     = trailingslashit( $upload['basedir'] ) . $relative;

        // パストラバーサル防止
        $real_base = realpath( $upload['basedir'] );
        $real_path = realpath( $path );

        if ( $real_base && $real_path && strpos( $real_path, $real_base ) === 0 && file_exists( $real_path ) ) {
            return wp_delete_file( $real_path );
        }

        return false;
    }

    /**
     * 車両の指定インデックスの画像を削除し、JSONを更新して返す
     * @param  string $images_json  現在の images JSON
     * @param  int    $index        削除するインデックス
     * @return string 更新後の JSON
     */
    public static function remove_image_by_index( $images_json, $index ) {
        $images = json_decode( $images_json, true );
        if ( ! is_array( $images ) ) {
            return '[]';
        }

        $index = intval( $index );
        if ( isset( $images[ $index ] ) ) {
            self::delete_file( $images[ $index ] );
            array_splice( $images, $index, 1 );
        }

        return wp_json_encode( array_values( $images ) );
    }
}
