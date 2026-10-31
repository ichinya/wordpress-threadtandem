<?php
/** Full catalogs and native site/profile language selection in the offline QA installation. */
if ( ! defined( 'WP_CLI' ) || ! get_option( 'treda_qa_sandbox' ) || DB_HOST !== '127.0.0.1:13316' ) { exit( 'A disposable local QA installation is required.' ); }
$checks = array();
$assert = function ( $condition, $label ) use ( &$checks ) {
	if ( ! $condition ) { throw new RuntimeException( $label ); }
	$checks[] = $label;
};
require_once ABSPATH . 'wp-includes/pomo/po.php';
require_once ABSPATH . 'wp-includes/pomo/mo.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
$domain = 'threadtandem-sync-for-telegram';
$original_user = get_current_user_id();
$original_user_locale = get_user_meta( 1, 'locale', true );
$original_site_locale = get_option( 'WPLANG' );
$original_settings = get_option( 'treda_settings' );
$original_screen = get_current_screen();
$original_switcher = $GLOBALS['wp_locale_switcher'];
$original_locale = get_locale();
$temporary_pack = null;
try {
	$plugin_header = get_file_data( TREDA_FILE, array( 'version' => 'Version' ) );
	$assert( TREDA_VERSION === $plugin_header['version'], 'Runtime version matches the plugin header' );
	$template = new PO();
	$assert( $template->import_from_file( TREDA_DIR . 'languages/' . $domain . '.pot' ), 'Current source template is readable' );
	foreach ( array( 'ru_RU', 'en_US' ) as $locale ) {
		$catalog = new PO(); $compiled = new MO();
		$assert( $catalog->import_from_file( TREDA_DIR . 'languages/' . $domain . '-' . $locale . '.po' ) && $compiled->import_from_file( TREDA_DIR . 'languages/' . $domain . '-' . $locale . '.mo' ), $locale . ': source and runtime catalogs are bundled' );
		$missing = array(); $placeholders = array();
		foreach ( $template->entries as $key => $entry ) {
			if ( empty( $catalog->entries[ $key ]->translations[0] ) || empty( $compiled->entries[ $key ]->translations[0] ) ) { $missing[] = $entry->singular; continue; }
			preg_match_all( '/%(?:\d+\$)?[sd]/', $entry->singular, $source_fields );
			preg_match_all( '/%(?:\d+\$)?[sd]/', $catalog->entries[ $key ]->translations[0], $translated_fields );
			sort( $source_fields[0] ); sort( $translated_fields[0] );
			if ( $source_fields[0] !== $translated_fields[0] ) { $placeholders[] = $entry->singular; }
		}
		$assert( ! $missing && count( $catalog->entries ) === count( $template->entries ), $locale . ': every current string is translated, without stale entries: ' . implode( ', ', $missing ) );
		$assert( ! $placeholders, $locale . ': formatted messages preserve all placeholders' );
		$assert( ! file_exists( WP_LANG_DIR . '/plugins/' . $domain . '-' . $locale . '.mo' ), $locale . ': verification uses no downloaded plugin language pack' );
		$assert( file_exists( TREDA_DIR . 'languages/' . $domain . '-' . $locale . '-' . md5( 'assets/editor.js' ) . '.json' ), $locale . ': bundled editor catalog is present' );
	}
	wp_set_current_user( 1 );
	set_current_screen( 'settings_page_threadtandem' );
	$settings = Treda_Settings::get(); $settings['article_link_text'] = ''; update_option( 'treda_settings', $settings );
	$assert( Treda_Settings::get()['article_link_text'] === '', 'An unset label stays empty, so saving unrelated settings does not freeze a translated default' );
	wp_register_script( 'treda-editor', plugins_url( 'assets/editor.js', TREDA_FILE ), array( 'wp-i18n' ), TREDA_VERSION, true );
	wp_set_script_translations( 'treda-editor', $domain, TREDA_DIR . 'languages' );
	$matrix = array( array( 'ru_RU', '', 'ru_RU' ), array( 'en_US', '', 'en_US' ), array( 'ru_RU', 'en_US', 'en_US' ), array( 'en_US', 'ru_RU', 'ru_RU' ) );
	foreach ( $matrix as $case ) {
		list( $site, $profile, $expected ) = $case;
		// Recreate a request's locale bootstrap after changing settings in this CLI process.
		// Merely changing profile metadata does not reload already loaded translations.
		restore_current_locale();
		remove_filter( 'locale', array( $GLOBALS['wp_locale_switcher'], 'filter_locale' ) );
		remove_filter( 'determine_locale', array( $GLOBALS['wp_locale_switcher'], 'filter_locale' ) );
		update_option( 'WPLANG', $site === 'en_US' ? '' : $site ); update_user_meta( 1, 'locale', $profile );
		$GLOBALS['locale'] = $site;
		$GLOBALS['wp_locale_switcher'] = new WP_Locale_Switcher(); $GLOBALS['wp_locale_switcher']->init();
		load_default_textdomain( $expected ); unload_textdomain( $domain, true );
		load_plugin_textdomain( $domain, false, dirname( plugin_basename( TREDA_FILE ) ) . '/languages' );
		$label = $site . '/' . ( $profile ?: 'site-default' );
		$ru = $expected === 'ru_RU';
		$assert( determine_locale() === $expected, $label . ': WordPress selects the profile language or site default' );
		$assert( __( 'Article link text', $domain ) === ( $ru ? 'Текст ссылки на статью' : 'Article link text' ), $label . ': PHP labels follow the selected interface language' );
		$assert( __( 'Bulk sending', $domain ) === ( $ru ? 'Массовая отправка' : 'Bulk sending' ), $label . ': bulk controls follow the same language' );
		$assert( __( 'Ignored publications', $domain ) === ( $ru ? 'Исключённые записи' : 'Ignored publications' ), $label . ': synchronization uses the complete catalog' );
		$json = load_script_textdomain( 'treda-editor', $domain, TREDA_DIR . 'languages' );
		$data = $json ? json_decode( $json, true ) : array();
		$assert( ( $data['locale_data']['messages']['Publish to Telegram'][0] ?? '' ) === ( $ru ? 'Публиковать в Telegram' : 'Publish to Telegram' ), $label . ': editor JavaScript uses the matching catalog' );
		$caption = Treda_Settings::article_link_text();
		$assert( $caption === ( $site === 'ru_RU' ? 'Читать статью' : 'Read the article' ), $label . ': public Telegram label follows the site, independently of the administrator: ' . wp_json_encode( array( 'caption' => $caption, 'stored' => Treda_Settings::get()['article_link_text'], 'site' => get_option( 'WPLANG' ), 'request' => determine_locale(), 'gettext' => __( 'Read the article', $domain ) ), JSON_UNESCAPED_UNICODE ) );
		$assert( determine_locale() === $expected, $label . ': computing public text restores the interface language' );
		ob_start(); Treda_Admin::render(); $html = ob_get_clean();
		$assert( strpos( $html, $ru ? 'Сохранить настройки' : 'Save settings' ) !== false && strpos( $html, $ru ? 'Что синхронизировать' : 'What to synchronize' ) !== false, $label . ': actual settings render in the selected language' );
		ob_start(); Treda_Sync::render(); $sync_html = ob_get_clean();
		$assert( strpos( $sync_html, $ru ? 'Отправить все записи в Telegram-канал' : 'Send all posts to the Telegram channel' ) !== false && strpos( $sync_html, $ru ? 'Связанные записи' : 'Linked articles' ) !== false, $label . ': synchronization and bulk sending render in the selected language' );
		$settings['article_link_text'] = 'Custom link / Своя подпись'; update_option( 'treda_settings', $settings );
		$assert( Treda_Settings::article_link_text() === 'Custom link / Своя подпись', $label . ': an explicitly saved label is preserved' );
		$settings['article_link_text'] = ''; update_option( 'treda_settings', $settings );
	}
	// Reproduce an approved WordPress.org pack that predates the new sync labels.
	$old_pack = new MO();
	$old_pack->set_header( 'Content-Type', 'text/plain; charset=UTF-8' );
	$old_pack->set_header( 'Plural-Forms', 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);' );
	$old_pack->add_entry( new Translation_Entry( array( 'singular' => 'Show', 'translations' => array( 'Показать из пакета' ) ) ) );
	$temporary_pack = WP_LANG_DIR . '/plugins/' . $domain . '-ru_RU.mo';
	wp_mkdir_p( dirname( $temporary_pack ) );
	$assert( $old_pack->export_to_file( $temporary_pack ), 'Older language-pack fixture is written only in the disposable installation' );
	unload_textdomain( $domain );
	Treda_Settings::load_translations( 'ru_RU' );
	$assert( __( 'Show', $domain ) === 'Показать из пакета', 'Existing official translations keep priority over the bundled fallback' );
	$assert( __( 'Article link text', $domain ) === 'Текст ссылки на статью', 'Missing strings in an older language pack fall back to the bundled Russian catalog' );
} finally {
	if ( $temporary_pack && file_exists( $temporary_pack ) ) { unlink( $temporary_pack ); }
	if ( $original_user_locale === '' ) { delete_user_meta( 1, 'locale' ); } else { update_user_meta( 1, 'locale', $original_user_locale ); }
	update_option( 'WPLANG', $original_site_locale ); update_option( 'treda_settings', $original_settings );
	restore_current_locale();
	remove_filter( 'locale', array( $GLOBALS['wp_locale_switcher'], 'filter_locale' ) );
	remove_filter( 'determine_locale', array( $GLOBALS['wp_locale_switcher'], 'filter_locale' ) );
	$GLOBALS['wp_locale_switcher'] = $original_switcher; $original_switcher->init(); $GLOBALS['locale'] = $original_locale;
	wp_set_current_user( $original_user ); $GLOBALS['current_screen'] = $original_screen;
}
echo wp_json_encode( array( 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'checks' => $checks ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . PHP_EOL;
WP_CLI::success( count( $checks ) . ' bilingual catalog and language-selection checks passed.' );
