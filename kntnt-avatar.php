<?php

/**
 * @wordpress-plugin
 * Plugin Name:       Kntnt Avatar
 * Plugin URI:        https://www.kntnt.com/
 * Description:       Shows local avatars instead of Gravatars.
 * Version:           1.0.0
 * Author:            Thomas Barregren
 * Author URI:        https://www.kntnt.com/
 * License:           GPL-3.0+
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.txt
 */


/*
 * If a user metadata field with the name `kntnt-avatar` contains the id of
 * an attachment, the image of the attachment is shown as avatar. The field
 * name can be replaced by implementing the filter `kntnt-avatar-metadata-key`:
 *
 *   add_filter( 'kntnt-avatar-metadata-key', function( $filter ) {
 *     return 'avatar_manager_custom_avatar';
 *   } );
 *
 * A default avatar can be provided either by adding and setting an option
 * named `kntnt-avatar-default-attachment` with the id of an attachment with
 * the default avatar, or by implementing the filter filter with the same name
 * returning the attachment id:
 *
 *   add_filter( 'kntnt-avatar-default-attachment', function ( $id, $args, $user ) {
 *     return 17635; // REPLACE WITH AVATAR ATTACHMENT ID
 *   }, 10, 3 );
 *
 */


namespace {

    defined( 'ABSPATH' ) && new Kntnt\Avatar\Plugin;

    if ( ! function_exists( 'get_avatar' ) ) {

        function get_avatar( $id_or_email, $size = 96, $default = '', $alt = '', $args = [] ) {
            return Kntnt\Avatar\Plugin::instance()->get_avatar( $id_or_email, $size, $default, $alt, $args );
        }

    }

}


namespace Kntnt\Avatar {

    class   Plugin {

        static $instance = null;

        private $avatar_metadata_key;

        public function __construct() {

            if ( self::$instance ) {
                die();
            }
            self::$instance = $this;

            add_action( 'muplugins_loaded', [ $this, 'run' ] );

        }

        public function run() {
            $this->avatar_metadata_key = apply_filters( 'kntnt-avatar-metadata-key', 'kntnt-avatar' );
        }

        public static function instance() {
            return self::$instance;
        }

        // Mimics get_avatar() in wp-includes/pluggable.php to be compatible
        // with plugins and themes depending on it. 
        public function get_avatar( $user, $size, $default, $alt, $args ) {

            $user = $this->user( $user );

            $args['size'] = ( is_numeric( $size ) ? abs( (int) $size ) : 96 ) ?: 96;
            $args['width'] = ( isset( $args['width'] ) && is_numeric( $args['width'] ) ? abs( (int) $args['width'] ) : $default ) ?: $args['size'];
            $args['height'] = ( isset( $args['height'] ) && is_numeric( $args['height'] ) ? abs( (int) $args['height'] ) : $default ) ?: $args['size'];

            switch ( $default ?: get_option( 'avatar_default', 'mystery' ) ) {
                case 'mm':
                case 'mystery':
                case 'mysteryman':
                    $args['default'] = 'mm';
                    break;
                case 'gravatar_default':
                    $args['default'] = false;
                    break;
            }

            $args['alt'] = $alt;

            $args['found_avatar'] = false;

            $defaults = [
                'force_default' => false,
                'rating' => get_option( 'avatar_rating' ),
                'scheme' => null,
                'class' => null,
                'force_display' => false,
                'loading' => wp_lazy_loading_enabled( 'img', 'get_avatar' ) ? 'lazy' : null,
                'extra_attr' => '',
                'processed_args' => null,
            ];

            $args = wp_parse_args( $args, $defaults );

            $args['force_default'] = (bool) $args['force_default'];

            $args['rating'] = strtolower( $args['rating'] );

            if ( is_null( $avatar = apply_filters( 'pre_get_avatar', null, $user, $args ) ) ) {

                if ( ! $args['force_display'] && ! get_option( 'show_avatars' ) ) {
                    return false;
                };

                $url2x = $this->get_avatar_url( $user, array_merge( $args, [
                    'size' => $args['size'] * 2,
                    'width' => $args['width'] * 2,
                    'height' => $args['height'] * 2,
                ] ) );

                $args = $this->get_avatar_data( $user, $args );

                if ( ! isset( $args['url'] ) || ! $args['url'] || is_wp_error( $args['url'] ) ) {
                    return false;
                }

                $class = [ 'avatar', 'avatar-' . (int) $args['size'], 'photo' ];

                if ( ! $args['found_avatar'] || $args['force_default'] ) {
                    $class[] = 'avatar-default';
                }

                if ( $args['class'] ) {
                    if ( is_array( $args['class'] ) ) {
                        $class = array_merge( $class, $args['class'] );
                    }
                    else {
                        $class[] = $args['class'];
                    }
                }

                $extra_attr = $args['extra_attr'];
                $loading = $args['loading'];

                if ( in_array( $loading, [ 'lazy', 'eager' ], true ) && ! preg_match( '/\bloading\s*=/', $extra_attr ) ) {
                    if ( ! empty( $extra_attr ) ) {
                        $extra_attr .= ' ';
                    }

                    $extra_attr .= "loading='{$loading}'";
                }

                $avatar = sprintf(
                    "<img alt='%s' src='%s' srcset='%s' class='%s' height='%d' width='%d' %s/>",
                    esc_attr( $args['alt'] ),
                    esc_url( $args['url'] ),
                    esc_url( $url2x ) . ' 2x',
                    esc_attr( join( ' ', $class ) ),
                    (int) $args['height'],
                    (int) $args['width'],
                    $extra_attr
                );

            }

            return apply_filters( 'get_avatar', $avatar, $user, $size, $default, $alt );

        }

        private function user( $user_identification ) {

            if ( is_object( $user_identification ) && isset( $user_identification->comment_ID ) ) {
                $user_identification = get_comment( $user_identification );
            }

            if ( $user_identification instanceof \WP_User ) {
                $user = $user_identification;
            }
            else if ( is_numeric( $user_identification ) ) {
                $user = get_user_by( 'id', absint( $user_identification ) );
            }
            else if ( is_string( $user_identification ) ) {
                if ( ! strpos( $user_identification, '@md5.gravatar.com' ) ) {
                    $user = false;
                }
                else {
                    $user = get_user_by( 'email', $user_identification );
                }
            }
            else if ( $user_identification instanceof \WP_Post ) {
                $user = get_user_by( 'id', (int) $user_identification->post_author );
            }
            else if ( $user_identification instanceof \WP_Comment ) {
                if ( ! is_avatar_comment_type( get_comment_type( $user_identification ) ) ) {
                    $user = false;
                }
                if ( ! empty( $user_identification->user_id ) ) {
                    $user = get_user_by( 'id', (int) $user_identification->user_id );
                }
                if ( ( ! $user || is_wp_error( $user ) ) && ! empty( $user_identification->comment_author_email ) ) {
                    $user = get_user_by( 'email', $user_identification->comment_author_email );
                }
            }
            else {
                $user = false;
            }

            return $user;

        }

        private function get_avatar_url( $user, $args ) {
            $args = $this->get_avatar_data( $user, $args );
            return isset( $args['url'] ) ? $args['url'] : false;
        }

        private function get_avatar_data( $user, $args ) {

            $args = apply_filters( 'pre_get_avatar_data', $args, $user );

            if ( empty( $args['url'] ) ) {

                if ( $user && ! $args['force_default'] ) {
                    $id = get_user_meta( $user->ID, $this->avatar_metadata_key, true );
                    $args['found_avatar'] = true;
                }
                else {
                    $id = false;
                }


                if ( ! $id ) {
                    $id = apply_filters( 'kntnt-avatar-default-attachment', get_option( 'kntnt-avatar-default-attachment' ), $args, $user );
                }

                if ( $id ) {
                    if ( $src = wp_get_attachment_image_src( $id, [ $args['width'], $args['height'] ] ) ) {
                        $args['url'] = apply_filters( 'get_avatar_url', $src[0], $user, $args );
                    }
                }

            }

            return apply_filters( 'get_avatar_data', $args, $user );

        }

    }

}
