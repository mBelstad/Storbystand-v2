<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stb_PWA {

	const SW_HASH_OPTION = 'stb_pwa_sw_hash';

	const QUERY_VAR = 'stb_pwa_asset';

	/**
	 * Wire up hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_write_service_worker' ) );
		add_action( 'init', array( __CLASS__, 'register_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_manifest' ), 0 );
		add_action( 'wp_head', array( __CLASS__, 'inject_manifest_link' ) );
		add_action( 'wp_head', array( __CLASS__, 'inject_theme_color' ), 5 );
		add_action( 'wp_footer', array( __CLASS__, 'inject_bootstrap_script' ) );
	}

	/**
	 * Register rewrites for the manifest and service worker.
	 */
	public static function register_rewrite_rules(): void {
		add_rewrite_rule( '^app-sw\.js/?$', 'index.php?' . self::QUERY_VAR . '=sw', 'top' );
		add_rewrite_rule( '^stb-manifest\.json/?$', 'index.php?' . self::QUERY_VAR . '=manifest', 'top' );
	}

	/**
	 * Add query var flag.
	 */
	public static function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Serve manifest payloads.
	 */
	public static function maybe_render_manifest(): void {
		if ( 'manifest' !== get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		self::render_manifest();
		exit;
	}

	/**
	 * Output the manifest tag.
	 */
	public static function inject_manifest_link(): void {
		if ( is_admin() ) {
			return;
		}

		printf(
			'<link rel="manifest" href="%s" crossorigin="use-credentials" />' . "\n",
			esc_url( home_url( '/stb-manifest.json' ) )
		);
	}

	/**
	 * Output theme-color meta for Chrome UI tinting.
	 */
	public static function inject_theme_color(): void {
		if ( is_admin() ) {
			return;
		}

		echo '<meta name="theme-color" content="#0f172a" />' . "\n";
	}

	/**
	 * Register the service worker on the front-end.
	 */
	public static function inject_bootstrap_script(): void {
		if ( is_admin() ) {
			return;
		}
		?>
		<script>
		(function() {
			if (!('serviceWorker' in navigator)) {
				return;
			}
			const swUrl = '<?php echo esc_js( home_url( '/app-sw.js' ) ); ?>';
			window.addEventListener('load', function() {
				navigator.serviceWorker.register(swUrl).catch(function(error) {
					console.warn('Storbystand PWA registration failed', error);
				});
			});
		}());
		</script>
		<?php
	}

	/**
	 * Serve the manifest JSON.
	 */
	protected static function render_manifest(): void {
		nocache_headers();

		$icons = self::manifest_icons();

		$manifest = array(
			'name'             => get_bloginfo( 'name' ),
			'short_name'       => 'Storbystand',
			'start_url'        => home_url( '/' ),
			'scope'            => home_url( '/' ),
			'display'          => 'standalone',
			'background_color' => '#0f172a',
			'theme_color'      => '#0f172a',
			'lang'             => get_locale(),
			'description'      => __( 'Offline-ready dashboard for the Storbystand shift program.', 'stb-pwa' ),
			'icons'            => $icons,
		);

		wp_send_json( $manifest );
	}

	/**
	 * Build the icon array for the manifest.
	 */
	protected static function manifest_icons(): array {
		$icons = array();
		$site_icon_512 = get_site_icon_url( 512 );
		$site_icon_192 = get_site_icon_url( 192 );

		if ( $site_icon_192 ) {
			$icons[] = array(
				'src'   => esc_url_raw( $site_icon_192 ),
				'sizes' => '192x192',
				'type'  => 'image/png',
			);
		} else {
			$icons[] = array(
				'src'     => esc_url_raw( STB_PWA_URL . 'assets/icons/icon-192.svg' ),
				'sizes'   => '192x192',
				'type'    => 'image/svg+xml',
				'purpose' => 'any maskable',
			);
		}

		if ( $site_icon_512 ) {
			$icons[] = array(
				'src'     => esc_url_raw( $site_icon_512 ),
				'sizes'   => '512x512',
				'type'    => 'image/png',
				'purpose' => 'any maskable',
			);
		} else {
			$icons[] = array(
				'src'     => esc_url_raw( STB_PWA_URL . 'assets/icons/icon-512.svg' ),
				'sizes'   => '512x512',
				'type'    => 'image/svg+xml',
				'purpose' => 'any maskable',
			);
		}

		return $icons;
	}

	/**
	 * Render SW JS string.
	 */
	protected static function service_worker_script(): string {
		$offline_url = esc_url_raw( STB_PWA_URL . 'assets/offline-shell.html' );
		$cache_name  = 'stb-pwa-' . STB_PWA_VERSION;
		$api_base    = esc_url_raw( home_url( '/wp-json/' ) );

		return "
const CACHE_NAME = '{$cache_name}';
const OFFLINE_URL = '{$offline_url}';
const PRECACHE = [
	'{$offline_url}',
	'{$api_base}'
];

self.addEventListener('install', event => {
	event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(PRECACHE)));
	self.skipWaiting();
});

self.addEventListener('activate', event => {
	event.waitUntil(
		caches.keys().then(keys => Promise.all(
			keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key))
		))
	);
	self.clients.claim();
});

async function networkFirst(request) {
	const cache = await caches.open(CACHE_NAME);
	try {
		const fresh = await fetch(request);
		cache.put(request, fresh.clone());
		return fresh;
	} catch (error) {
		const cached = await cache.match(request);
		return cached || Promise.reject(error);
	}
}

async function cacheFirst(request) {
	const cache = await caches.open(CACHE_NAME);
	const cached = await cache.match(request);
	if (cached) {
		return cached;
	}
	const response = await fetch(request);
	if (request.method === 'GET' && response && response.status === 200) {
		cache.put(request, response.clone());
	}
	return response;
}

self.addEventListener('fetch', event => {
	const { request } = event;

	if (request.mode === 'navigate') {
		event.respondWith(
			fetch(request).catch(() => caches.match(OFFLINE_URL))
		);
		return;
	}

	if (request.url.includes('/wp-json/') || request.url.includes('/jet-engine/')) {
		event.respondWith(networkFirst(request));
		return;
	}

	event.respondWith(cacheFirst(request));
});
";
	}

	/**
	 * Flush rewrites on activation.
	 */
	public static function activate(): void {
		self::maybe_write_service_worker( true );
		self::register_rewrite_rules();
		flush_rewrite_rules();
	}

	/**
	 * Flush rewrites on deactivation.
	 */
	public static function deactivate(): void {
		self::remove_service_worker_file();
		flush_rewrite_rules();
	}

	/**
	 * Ensure the root-level service worker is present and current.
	 *
	 * @param bool $force Force writing even if hash matches.
	 */
	public static function maybe_write_service_worker( bool $force = false ): void {
		$target   = self::service_worker_public_path();
		$contents = self::service_worker_script();
		$hash     = md5( $contents );

		if ( ! $force && file_exists( $target ) && get_option( self::SW_HASH_OPTION ) === $hash ) {
			return;
		}

		$written = @file_put_contents( $target, $contents ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false !== $written ) {
			update_option( self::SW_HASH_OPTION, $hash, false );
		}
	}

	/**
	 * Remove the generated service worker file.
	 */
	protected static function remove_service_worker_file(): void {
		$target = self::service_worker_public_path();

		if ( file_exists( $target ) ) {
			@unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		delete_option( self::SW_HASH_OPTION );
	}

	/**
	 * Absolute path to the generated service worker file.
	 */
	protected static function service_worker_public_path(): string {
		return trailingslashit( ABSPATH ) . 'app-sw.js';
	}
}

