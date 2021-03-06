<?php

/**
 * Class CriticalCSS
 */
class WP_CriticalCSS {
	/**
	 *
	 */
	const VERSION = '0.4.3';

	/**
	 *
	 */
	const LANG_DOMAIN = 'wp_criticalcss';

	/**
	 *
	 */
	const OPTIONNAME = 'wp_criticalcss';

	/**
	 * @var bool
	 */
	public static $nocache = false;
	/**
	 * @var bool
	 */
	protected static $purge_lock = false;
	/**
	 * @var \WeDevs_Settings_API
	 */
	private static $_settings_ui;
	/**
	 * @var WP_CriticalCSS_Web_Check_Background_Process
	 */
	private static $_web_check_queue;
	/**
	 * @var WP_CriticalCSS_API_Background_Process
	 */
	private static $_api_queue;
	/**
	 * @var
	 */
	private static $_queue_table;
	/**
	 * @var array
	 */
	private static $_settings = array();

	public static function wp_head() {
		if ( get_query_var( 'nocache' ) ):
			?>
            <meta name="robots" content="noindex, nofollow"/>
			<?php
		endif;
	}

	/**
	 * @param $redirect_url
	 *
	 * @return bool
	 */
	public static function redirect_canonical( $redirect_url ) {
		global $wp_query;
		if ( ! array_diff( array_keys( $wp_query->query ), array( 'nocache' ) ) ) {
			$redirect_url = false;
		}

		return $redirect_url;
	}

	/**
	 * @param \WP $wp
	 */
	public static function parse_request( WP &$wp ) {
		if ( isset( $wp->query_vars['nocache'] ) ) {
			self::$nocache = $wp->query_vars['nocache'];
			unset( $wp->query_vars['nocache'] );
		}
	}

	/**
	 * @param $vars
	 *
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[] = 'nocache';

		return $vars;
	}

	/**
	 * @param $vars
	 *
	 * @return mixed
	 */
	public static function update_request( $vars ) {
		if ( isset( $vars['nocache'] ) ) {
			$vars['nocache'] = true;
		}

		return $vars;
	}

	/**
	 *
	 */
	public static function wp_action() {
		set_query_var( 'nocache', self::$nocache );
		if ( self::has_external_integration() ) {
			self::external_integration();
		}
	}

	/**
	 * @return bool
	 */
	public static function has_external_integration() {
		// // Compatibility with WP Rocket ASYNC CSS preloader integration
		if ( class_exists( 'Rocket_Async_Css_The_Preloader' ) ) {
			return true;
		}
		// WP-Rocket integration
		if ( function_exists( 'get_rocket_option' ) ) {
			return true;
		}

		return false;
	}

	/**
	 *
	 */
	public static function external_integration() {
		if ( get_query_var( 'nocache' ) ) {
			// Compatibility with WP Rocket ASYNC CSS preloader integration
			if ( class_exists( 'Rocket_Async_Css_The_Preloader' ) ) {
				remove_action( 'wp_enqueue_scripts', array(
					'Rocket_Async_Css_The_Preloader',
					'add_window_resize_js',
				) );
				remove_action( 'rocket_buffer', array( 'Rocket_Async_Css_The_Preloader', 'inject_div' ) );
			}
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
		}
		// Compatibility with WP Rocket
		if ( function_exists( 'get_rocket_option' ) && ! self::$purge_lock ) {
			add_action( 'after_rocket_clean_domain', array( __CLASS__, 'reset_web_check_transients' ) );
			add_action( 'after_rocket_clean_post', array( __CLASS__, 'reset_web_check_post_transient' ) );
			add_action( 'after_rocket_clean_term', array( __CLASS__, 'reset_web_check_term_transient' ) );
			add_action( 'after_rocket_clean_home', array( __CLASS__, 'reset_web_check_home_transient' ) );
		}
	}

	/**
	 *
	 */
	public static function activate() {
		global $wpdb;
		$settings    = self::get_settings();
		$no_version  = ! empty( $settings ) && empty( $settings['version'] );
		$version_0_3 = false;
		$version_0_4 = false;
		if ( ! $no_version ) {
			$version     = $settings['version'];
			$version_0_3 = version_compare( '0.3.0', $version ) === 1;
			$version_0_4 = version_compare( '0.4.0', $version ) === 1;
		}
		if ( $no_version || $version_0_3 || $version_0_4 ) {
			$wpdb->get_results( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_criticalcss_%', '_transient_timeout_criticalcss_%' ) );
			remove_action( 'update_option_criticalcss', array( __CLASS__, 'after_options_updated' ) );
			if ( isset( $settings['disable_autopurge'] ) ) {
				unset( $settings['disable_autopurge'] );
				self::update_settings( $settings );
			}
			if ( isset( $settings['expire'] ) ) {
				unset( $settings['expire'] );
				self::update_settings( $settings );
			}
		}
		if ( $version_0_4 ) {
			$wpdb->get_results( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '%wp_criticalcss_api%', '%wp_criticalcss_web_check%' ) );
			self::reset_web_check_transients();
		}
		self::update_settings( array_merge( array(
			'web_check_interval' => DAY_IN_SECONDS,
		), self::get_settings(), array( 'version' => self::VERSION ) ) );

		self::init();
		self::init_action();

		self::$_web_check_queue->create_table();
		self::$_api_queue->create_table();

		flush_rewrite_rules();
	}

	/**
	 * @return array
	 */
	public static function get_settings() {
		$settings = array();
		if ( is_multisite() ) {
			$settings = get_site_option( self::OPTIONNAME, array() );
			if ( empty( $settings ) ) {
				$settings = get_option( self::OPTIONNAME, array() );
			}
		} else {
			$settings = get_option( self::OPTIONNAME, array() );
		}

		return $settings;
	}

	/**
	 * @param array $settings
	 *
	 * @return bool
	 */
	public static function update_settings( array $settings ) {
		if ( is_multisite() ) {
			return update_site_option( self::OPTIONNAME, $settings );
		} else {
			return update_option( self::OPTIONNAME, $settings );
		}
	}

	/**
	 *
	 */
	public static function reset_web_check_transients() {
		global $wpdb;
		$wpdb->get_results( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_criticalcss_web_check_%', '_transient_timeout_criticalcss_web_check_%' ) );
		wp_cache_flush();
	}

	/**
	 *
	 */
	public static function init() {
		self::$_settings = self::get_settings();
		if ( empty( self::$_settings_ui ) ) {
			self::$_settings_ui = new WP_CriticalCSS_Settings_API();
		}
		if ( empty( self::$_web_check_queue ) ) {
			self::$_web_check_queue = new WP_CriticalCSS_Web_Check_Background_Process();
		}
		if ( empty( self::$_api_queue ) ) {
			self::$_api_queue = new WP_CriticalCSS_API_Background_Process();
		}
		if ( ! is_admin() ) {
			add_action( 'wp_print_styles', array( __CLASS__, 'print_styles' ), 7 );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
			add_action( 'network_admin_menu', array( __CLASS__, 'settings_init' ) );
		} else {
			add_action( 'admin_menu', array( __CLASS__, 'settings_init' ) );
		}
		add_action( 'pre_update_option_wp_criticalcss', array( __CLASS__, 'sync_options' ), 10, 2 );
		add_action( 'pre_update_site_option_wp_criticalcss', array( __CLASS__, 'sync_options' ), 10, 2 );

		add_action( 'after_switch_theme', array( __CLASS__, 'reset_web_check_transients' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'reset_web_check_transients' ) );
		add_action( 'post_updated', array( __CLASS__, 'reset_web_check_post_transient' ) );
		add_action( 'edited_term', array( __CLASS__, 'reset_web_check_term_transient' ) );
		add_action( 'request', array( __CLASS__, 'update_request' ) );
		if ( is_admin() ) {
			add_action( 'wp_loaded', array( __CLASS__, 'wp_action' ) );
		} else {
			add_action( 'wp', array( __CLASS__, 'wp_action' ) );
			add_action( 'wp_head', array( __CLASS__, 'wp_head' ) );
		}
		add_action( 'init', array( __CLASS__, 'init_action' ) );
		/*
		 * Prevent a 404 on homepage if a static page is set.
		 * Will store query_var outside \WP_Query temporarily so we don't need to do any extra routing logic and will appear as if it was not set.
		 */
		add_action( 'parse_request', array( __CLASS__, 'parse_request' ) );
		// Don't fix url or try to guess url if we are using nocache on the homepage
		add_filter( 'redirect_canonical', array( __CLASS__, 'redirect_canonical' ) );
	}

	/**
	 *
	 */
	public static function init_action() {
		add_rewrite_endpoint( 'nocache', E_ALL );
		add_rewrite_rule( 'nocache/?$', 'index.php?nocache=1', 'top' );
	}

	/**
	 *
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * @param array $object
	 *
	 * @return false|mixed|string|\WP_Error
	 */
	public static function get_permalink( array $object ) {
		self::disable_relative_plugin_filters();
		switch ( $object['type'] ) {
			case 'post':
				$url = get_permalink( $object['object_id'] );
				break;
			case 'term':
				$url = get_term_link( $object['object_id'] );
				break;
			case 'author':
				$url = get_author_posts_url( $object['object_id'] );
				break;
			case 'url':
				$url = $object['url'];
				break;
			default:
				$url = $object['url'];
		}
		self::enable_relative_plugin_filters();

		if ( $url instanceof WP_Error ) {
			return false;
		}

		$url_parts         = parse_url( $url );
		$url_parts['path'] = trailingslashit( $url_parts['path'] ) . 'nocache/';
		if ( class_exists( 'http\Url' ) ) {
			$url = new \http\Url( $url_parts );
			$url = $url->toString();
		} else {
			if ( ! function_exists( 'http_build_url' ) ) {
				require_once plugin_dir_path( __FILE__ ) . 'http_build_url.php';
			}
			$url = http_build_url( $url_parts );
		}

		return $url;
	}

	/**
	 *
	 */
	protected static function disable_relative_plugin_filters() {
		if ( class_exists( 'MP_WP_Root_Relative_URLS' ) ) {
			remove_filter( 'post_link', array( 'MP_WP_Root_Relative_URLS', 'proper_root_relative_url' ), 1 );
			remove_filter( 'page_link', array( 'MP_WP_Root_Relative_URLS', 'proper_root_relative_url' ), 1 );
			remove_filter( 'attachment_link', array( 'MP_WP_Root_Relative_URLS', 'proper_root_relative_url' ), 1 );
			remove_filter( 'post_type_link', array( 'MP_WP_Root_Relative_URLS', 'proper_root_relative_url' ), 1 );
			remove_filter( 'get_the_author_url', array( 'MP_WP_Root_Relative_URLS', 'dynamic_rss_absolute_url' ), 1 );
		}
	}

	/**
	 *
	 */
	protected static function enable_relative_plugin_filters() {
		if ( class_exists( 'MP_WP_Root_Relative_URLS' ) ) {
			add_filter( 'post_link', array( 'MP_WP_Root_Relative_URLS', 'proper_root_relative_url' ), 1 );
			add_filter( 'page_link', array( 'MP_WP_Root_Relative_URLS', 'proper_root_relative_url' ), 1 );
			add_filter( 'attachment_link', array( 'MP_WP_Root_Relative_URLS', 'proper_root_relative_url' ), 1 );
			add_filter( 'post_type_link', array( 'MP_WP_Root_Relative_URLS', 'proper_root_relative_url' ), 1 );
			add_filter( 'get_the_author_url', array( 'MP_WP_Root_Relative_URLS', 'dynamic_rss_absolute_url' ), 1, 2 );
		}
	}

	/**
	 * @param $type
	 * @param $object_id
	 * @param $url
	 */
	public static function purge_page_cache( $type, $object_id, $url ) {
		global $wpe_varnish_servers;
		$url = preg_replace( '#nocache/$#', '', $url );
// WP Engine Support
		if ( class_exists( 'WPECommon' ) ) {
			if ( 'post' == $type ) {
				WpeCommon::purge_varnish_cache( $object_id );
			} else {
				$blog_url       = home_url();
				$blog_url_parts = @parse_url( $blog_url );
				$blog_domain    = $blog_url_parts['host'];
				$purge_domains  = array( $blog_domain );
				$object_parts   = parse_url( $url );
				$object_uri     = rtrim( $object_parts   ['path'], '/' ) . "(.*)";
				if ( ! empty( $object_parts['query'] ) ) {
					$object_uri .= "?" . $object_parts['query'];
				}
				$paths         = array( $object_uri );
				$purge_domains = array_unique( array_merge( $purge_domains, WpeCommon::get_blog_domains() ) );
				if ( defined( 'WPE_CLUSTER_TYPE' ) && WPE_CLUSTER_TYPE == "pod" ) {
					$wpe_varnish_servers = array( "localhost" );
				} // Ordinarily, the $wpe_varnish_servers are set during apply. Just in case, let's figure out a fallback plan.
				else if ( ! isset( $wpe_varnish_servers ) ) {
					if ( ! defined( 'WPE_CLUSTER_ID' ) || ! WPE_CLUSTER_ID ) {
						$lbmaster = "lbmaster";
					} else if ( WPE_CLUSTER_ID >= 4 ) {
						$lbmaster = "localhost"; // so the current user sees the purge
					} else {
						$lbmaster = "lbmaster-" . WPE_CLUSTER_ID;
					}
					$wpe_varnish_servers = array( $lbmaster );
				}
				$path_regex          = '(' . join( '|', $paths ) . ')';
				$hostname            = $purge_domains[0];
				$purge_domains       = array_map( 'preg_quote', $purge_domains );
				$purge_domain_chunks = array_chunk( $purge_domains, 100 );
				foreach ( $purge_domain_chunks as $chunk ) {
					$purge_domain_regex = '^(' . join( '|', $chunk ) . ')$';
// Tell Varnish.
					foreach ( $wpe_varnish_servers as $varnish ) {
						$headers = array( 'X-Purge-Path' => $path_regex, 'X-Purge-Host' => $purge_domain_regex );
						WpeCommon::http_request_async( 'PURGE', $varnish, 9002, $hostname, '/', $headers, 0 );
					}
				}
			}
			sleep( 1 );
		}
// WP-Rocket Support
		if ( function_exists( 'rocket_clean_files' ) ) {
			if ( 'post' == $type ) {
				rocket_clean_post( $object_id );
			}
			if ( 'term' == $type ) {
				rocket_clean_term( $object_id, get_term( $object_id )->taxonomy );
			}
			if ( 'url' == $type ) {
				rocket_clean_files( $url );
			}
		}
	}

	/**
	 *
	 */
	public static function print_styles() {
		if ( ! get_query_var( 'nocache' ) && ! is_404() ) {
			$cache        = self::get_cache();
			$style_handle = null;
			if ( ! empty( $cache ) ) {
				// Enable CDN in CSS for WP-Rocket
				if ( function_exists( 'rocket_cdn_css_properties' ) ) {
					$cache = rocket_cdn_css_properties( $cache );
				}
				// Compatibility with WP Rocket ASYNC CSS preloader integration
				if ( class_exists( 'Rocket_Async_Css_The_Preloader' ) ) {
					remove_action( 'wp_enqueue_scripts', array(
						'Rocket_Async_Css_The_Preloader',
						'add_window_resize_js',
					) );
					remove_action( 'rocket_buffer', array( 'Rocket_Async_Css_The_Preloader', 'inject_div' ) );
				}
				?>
                <style type="text/css" id="criticalcss" data-no-minify="1"><?= $cache ?></style>
				<?php
			}
			$type  = self::get_current_page_type();
			$hash  = self::get_item_hash( $type );
			$check = get_transient( "criticalcss_web_check_$hash" );
			if ( empty( $check ) ) {
				if ( ! self::$_web_check_queue->get_item_exists( $type ) ) {
					self::$_web_check_queue->push_to_queue( $type )->save();
					set_transient( "criticalcss_web_check_${hash}", true, self::get_expire_period() );
				}
			}

		}
	}

	/**
	 * @param array $item
	 *
	 * @return string
	 */
	public static function get_cache( $item = array() ) {
		return self::get_item_data( $item, 'cache' );
	}

	/**
	 * @param array $item
	 * @param       $name
	 *
	 * @return mixed|null
	 */
	public static function get_item_data( $item = array(), $name ) {
		$value = null;
		if ( empty( $item ) ) {
			$item = self::get_current_page_type();
		}
		if ( 'url' == $item['type'] ) {
			$name  = "criticalcss_url_{$name}_" . md5( $item['url'] );
			$value = get_transient( $name );
		} else {
			$name = "criticalcss_{$name}";
			switch ( $item['type'] ) {
				case 'post':
					$value = get_post_meta( $item['object_id'], $name, true );
					break;
				case 'term':
					$value = get_term_meta( $item['object_id'], $name, true );
					break;
				case 'author':
					$value = get_user_meta( $item['object_id'], $name, true );
					break;

			}
		}

		return $value;
	}

	/**
	 * @return array
	 */
	protected static function get_current_page_type() {
		global $wp;
		global $query_string;
		$object_id = 0;
		if ( is_home() ) {
			$page_for_posts = get_option( 'page_for_posts' );
			if ( ! empty( $page_for_posts ) ) {
				$object_id = $page_for_posts;
				$type      = 'post';
			}
		} else if ( is_front_page() ) {
			$page_on_front = get_option( 'page_on_front' );
			if ( ! empty( $page_on_front ) ) {
				$object_id = $page_on_front;
				$type      = 'post';
			}
		} else if ( is_singular() ) {
			$object_id = get_the_ID();
			$type      = 'post';
		} else if ( is_tax() || is_category() || is_tag() ) {
			$object_id = get_queried_object()->term_id;
			$type      = 'term';
		} else if ( is_author() ) {
			$object_id = get_the_author_meta( 'ID' );
			$type      = 'author';

		}

		if ( ! isset( $type ) ) {
			self::disable_relative_plugin_filters();
			$query = array();
			wp_parse_str( $query_string, $query );
			$url = add_query_arg( $query, site_url( $wp->request ) );
			self::enable_relative_plugin_filters();

			$type = 'url';
		}
		$object_id = absint( $object_id );

		return compact( 'object_id', 'type', 'url' );
	}

	public static function get_item_hash( $item ) {
		extract( $item );
		$type = compact( 'object_id', 'type', 'url' );

		return md5( serialize( $type ) );
	}

	/**
	 * @return int
	 */
	public static function get_expire_period() {
// WP-Rocket integration
		if ( function_exists( 'get_rocket_purge_cron_interval' ) ) {
			return get_rocket_purge_cron_interval();
		}
		$settings = self::get_settings();

		return absint( self::$_settings['web_check_interval'] );
	}

	/**
	 * @param array $item
	 *
	 * @return string
	 */
	public static function get_html_hash( $item = array() ) {
		return self::get_item_data( $item, 'html_hash' );
	}

	/**
	 * @param        $item
	 * @param string $css
	 *
	 * @return void
	 * @internal param array $type
	 */
	public static function set_cache( $item, $css ) {
		self::set_item_data( $item, 'cache', $css );
	}

	/**
	 * @param     $item
	 * @param     $name
	 * @param     $value
	 * @param int $expires
	 */
	public static function set_item_data( $item, $name, $value, $expires = 0 ) {
		if ( 'url' == $item['type'] ) {
			$name = "criticalcss_url_{$name}_" . md5( $item['url'] );
			set_transient( $name, $value, $expires );
		} else {
			$name  = "criticalcss_{$name}";
			$value = wp_slash( $value );
			switch ( $item['type'] ) {
				case 'post':
					update_post_meta( $item['object_id'], $name, $value );
					break;
				case 'term':
					update_term_meta( $item['object_id'], $name, $value );
					break;
				case 'author':
					update_user_meta( $item['object_id'], $name, $value );
					break;
			}
		}
	}

	/**
	 * @param array $item
	 *
	 * @return string
	 */
	public static function get_css_hash( $item = array() ) {
		return self::get_item_data( $item, 'css_hash' );
	}

	/**
	 * @param        $item
	 * @param string $hash
	 *
	 * @return void
	 * @internal param array $type
	 */
	public static function set_css_hash( $item, $hash ) {
		self::set_item_data( $item, 'css_hash', $hash );
	}

	/**
	 * @param        $item
	 * @param string $hash
	 *
	 * @return void
	 * @internal param array $type
	 */
	public static function set_html_hash( $item, $hash ) {
		self::set_item_data( $item, 'html_hash', $hash );
	}

	/**
	 *
	 */
	public static function settings_init() {
		$hook = add_options_page( 'WP Critical CSS', 'WP Critical CSS', 'manage_options', 'wp_criticalcss', array(
			__CLASS__,
			'settings_ui',
		) );
		add_action( "load-$hook", array( __CLASS__, 'screen_option' ) );
		self::$_settings_ui->add_section( array( 'id' => self::OPTIONNAME, 'title' => 'WP Critical CSS Options' ) );
		self::$_settings_ui->add_field( self::OPTIONNAME, array(
			'name'              => 'apikey',
			'label'             => 'API Key',
			'type'              => 'text',
			'sanitize_callback' => array( __CLASS__, 'validate_criticalcss_apikey' ),
			'desc'              => __( 'API Key for CriticalCSS.com. Please view yours at <a href="https://www.criticalcss.com/account/api-keys?aff=3">CriticalCSS.com</a>', self::LANG_DOMAIN ),
		) );
		self::$_settings_ui->add_field( self::OPTIONNAME, array(
			'name'  => 'force_web_check',
			'label' => 'Force Web Check',
			'type'  => 'checkbox',
			'desc'  => __( 'Force a web check on all pages for css changes. This will run for new web requests.', self::LANG_DOMAIN ),
		) );
		if ( ! self::has_external_integration() ) {
			self::$_settings_ui->add_field( self::OPTIONNAME, array(
				'name'  => 'web_check_interval',
				'label' => 'Web Check Interval',
				'type'  => 'number',
				'desc'  => __( 'How often in seconds web pages should be checked for changes to re-generate CSS', self::LANG_DOMAIN ),
			) );
		}
		self::$_settings_ui->admin_init();
	}

	/**
	 *
	 */
	public static function settings_ui() {
		self::$_settings_ui->add_section( array(
			'id'    => 'wp_criticalcss_queue',
			'title' => 'WP Critical CSS Queue',
			'form'  => false,
		) );

		ob_start();

		?>
        <style type="text/css">
            .queue > th {
                display: none;
            }
        </style>
        <form method="post">
			<?php
			self::$_queue_table->prepare_items();
			self::$_queue_table->display();
			?>
        </form>
		<?php
		self::$_settings_ui->add_field( 'wp_criticalcss_queue', array(
			'name'  => 'queue',
			'label' => null,
			'type'  => 'html',
			'desc'  => ob_get_clean(),
		) );

		self::$_settings_ui->admin_init();
		self::$_settings_ui->show_navigation();
		self::$_settings_ui->show_forms();
		?>

		<?php
	}

	/**
	 * @param $options
	 *
	 * @return bool
	 */
	public static function validate_criticalcss_apikey( $options ) {
		$valid = true;
		if ( empty( $options['apikey'] ) ) {
			$valid = false;
			add_settings_error( 'apikey', 'invalid_apikey', __( 'API Key is empty', self::LANG_DOMAIN ) );
		}
		if ( ! $valid ) {
			return $valid;
		}
		$api = new WP_CriticalCSS_API( $options['apikey'] );
		if ( ! $api->ping() ) {
			add_settings_error( 'apikey', 'invalid_apikey', 'CriticalCSS.com API Key is invalid' );
			$valid = false;
		}

		return ! $valid ? $valid : $options['apikey'];
	}

	/**
	 * @param $value
	 * @param $old_value
	 *
	 * @return array
	 */
	public static function sync_options( $value, $old_value ) {
		if ( ! is_array( $old_value ) ) {
			$old_value = array();
		}

		$value = array_merge( $old_value, $value );

		if ( isset( $value['force_web_check'] ) && 'on' == $value['force_web_check'] ) {
			$value['force_web_check'] = 'off';
			self::reset_web_check_transients();
		}

		return $value;
	}

	public static function reset_web_check_post_transient( $post ) {
		$post = get_post( $post );
		$hash = self::get_item_hash( array( 'object_id' => $post->ID, 'type' => 'post' ) );
		delete_transient( "criticalcss_web_check_${hash}" );
	}

	/**
	 * @param $term
	 *
	 * @internal param \WP_Term $post
	 */
	public static function reset_web_check_term_transient( $term ) {
		$term = get_term( $term );
		$hash = self::get_item_hash( array( 'object_id' => $term->term_id, 'type' => 'term' ) );
		delete_transient( "criticalcss_web_check_${hash}" );
	}

	/**
	 * @internal param \WP_Term $post
	 */
	public static function reset_web_check_home_transient() {
		$page_for_posts = get_option( 'page_for_posts' );
		if ( ! empty( $page_for_posts ) ) {
			$post_id = $page_for_posts;
		}
		if ( empty( $post_id ) || ( ! empty( $post_id ) && get_permalink( $post_id ) != site_url() ) ) {
			$page_on_front = get_option( 'page_on_front' );
			if ( ! empty( $page_on_front ) ) {
				$post_id = $page_on_front;
			} else {
				$post_id = false;
			}
		}
		if ( ! empty( $post_id ) && get_permalink( $post_id ) == site_url() ) {
			$hash = self::get_item_hash( array( 'object_id' => $post_id, 'type' => 'post' ) );
		} else {
			$hash = self::get_item_hash( array( 'type' => 'url', 'url' => site_url() ) );
		}
		delete_transient( "criticalcss_web_check_${hash}" );
	}

	/**
	 *
	 */
	public static function screen_option() {
		add_screen_option( 'per_page', array(
			'label'   => 'Queue Items',
			'default' => 20,
			'option'  => 'queue_items_per_page',
		) );
		self::$_queue_table = new WP_CriticalCSS_Queue_List_Table( self::$_api_queue );
	}

	/**
	 * @param \WP_Admin_Bar $wp_admin_bar
	 */
	public static function admin_menu( WP_Admin_Bar $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$action = 'purge_criticalcss_cache';
		$wp_admin_bar->add_menu( array(
			'id'    => "$action",
			'title' => 'Purge CriticalCSS Cache',
			'href'  => wp_nonce_url( add_query_arg( array(
				'_wp_http_referer' => urlencode( wp_unslash( $_SERVER['REQUEST_URI'] ) ),
				'action'           => $action,
			), admin_url( 'admin-post.php' ) ), $action ),
		) );
	}

	/**
	 * @return bool
	 */
	public static function get_purge_lock() {
		self::$purge_lock;
	}

	/**
	 * @param bool $purge_lock
	 */
	public static function set_purge_lock( $purge_lock ) {
		self::$purge_lock = $purge_lock;
	}
}