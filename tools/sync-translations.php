<?php
/** Align the two catalogs with the current POT. Run after wp i18n make-pot. */
if ( PHP_SAPI !== 'cli' ) { exit( 'CLI only.' ); }
$wordpress = rtrim( $argv[1] ?? dirname( __DIR__, 4 ), '/\\' );
require_once $wordpress . '/wp-includes/pomo/po.php';
$languages = dirname( __DIR__ ) . '/languages/';
$domain = 'threadtandem-sync-for-telegram';
$template = new PO();
if ( ! $template->import_from_file( $languages . $domain . '.pot' ) ) { throw new RuntimeException( 'Cannot read POT.' ); }
$russian = new PO();
if ( ! $russian->import_from_file( $languages . $domain . '-ru_RU.po' ) ) { throw new RuntimeException( 'Cannot read Russian catalog.' ); }
foreach ( array( 'ru_RU', 'en_US' ) as $locale ) {
	$catalog = new PO();
	$catalog->headers = $template->headers;
	$catalog->set_header( 'Language', $locale );
	$catalog->set_header( 'Language-Team', $locale === 'ru_RU' ? 'Russian' : 'English (United States)' );
	$catalog->set_header( 'Last-Translator', 'ThreadTandem contributors' );
	$catalog->set_header( 'PO-Revision-Date', gmdate( 'Y-m-d H:iO' ) );
	$catalog->set_header( 'Plural-Forms', $locale === 'ru_RU' ? 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);' : 'nplurals=2; plural=(n != 1);' );
	foreach ( $template->entries as $key => $source ) {
		$entry = clone $source;
		if ( $locale === 'en_US' ) {
			$entry->translations = $entry->is_plural ? array( $entry->singular, $entry->plural ) : array( $entry->singular );
		} else {
			if ( empty( $russian->entries[ $key ]->translations[0] ) ) { throw new RuntimeException( 'Missing Russian translation: ' . $entry->singular ); }
			$entry->translations = $russian->entries[ $key ]->translations;
		}
		$entry->flags = array();
		$catalog->add_entry( $entry );
	}
	if ( ! $catalog->export_to_file( $languages . $domain . '-' . $locale . '.po' ) ) { throw new RuntimeException( 'Cannot write catalog: ' . $locale ); }
	echo $locale . ': ' . count( $catalog->entries ) . " strings.\n";
}
