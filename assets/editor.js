( function ( wp ) {
	'use strict';

	// WordPress 6.6+ exposes document panels through wp.editor.
	var Panel = wp.editor && wp.editor.PluginDocumentSettingPanel;
	if ( ! Panel && wp.editPost ) {
		Panel = wp.editPost.PluginDocumentSettingPanel;
	}
	if ( ! Panel ) {
		return;
	}

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var config = window.tredaEditor || {};

	function TredaPanel() {
		var state = wp.data.useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			return {
				postType: editor.getCurrentPostType(),
				meta: editor.getEditedPostAttribute( 'meta' ) || {}
			};
		}, [] );
		var editPost = wp.data.useDispatch( 'core/editor' ).editPost;

		if ( state.postType !== 'post' ) {
			return null;
		}

		return el(
			Panel,
			{ name: 'treda-telegram', title: __( 'Treda · Telegram', 'treda' ) },
			el( wp.components.ToggleControl, {
				label: __( 'Publish to Telegram', 'treda' ),
				checked: state.meta._treda_disabled !== '1',
				onChange: function ( enabled ) {
					editPost( { meta: Object.assign( {}, state.meta, { _treda_disabled: enabled ? '0' : '1' } ) } );
				}
			} ),
			! config.publishingEnabled && el( 'p', {}, __( 'Automatic publishing is disabled in Treda settings.', 'treda' ) ),
			config.publicationUrl ? el(
				'p',
				{},
				el( 'a', { href: config.publicationUrl, target: '_blank', rel: 'noopener noreferrer' }, __( 'Open publication', 'treda' ) )
			) : el( 'p', {}, __( 'No publication is linked yet.', 'treda' ) ),
			el( 'p', {}, __( 'Saving this post queues its changes in the background.', 'treda' ) )
		);
	}

	wp.plugins.registerPlugin( 'treda', { render: TredaPanel } );
} )( window.wp );
