( function () {
	'use strict';
	var root = document.querySelector( '.threadtandem-settings' );
	if ( ! root ) { return; }
	var strings = window.threadtandemAdmin || {};
	var form = document.getElementById( 'threadtandem-settings-form' );
	var status = root.querySelector( '[data-threadtandem-save-status]' );
	var savebar = root.querySelector( '.threadtandem-savebar' );
	var originalStatus = status.textContent;
	root.querySelector( 'a[href="#threadtandem-diagnostics"]' ).addEventListener( 'click', function () {
		document.getElementById( 'threadtandem-diagnostics' ).open = true;
	} );
	var snapshot = function () { return JSON.stringify( Array.from( new FormData( form ).entries() ) ); };
	var initial = snapshot();
	var actions = Array.from( root.querySelectorAll( '[data-threadtandem-action-form] button, [data-threadtandem-action-form] input[type=submit]' ) );
	actions.forEach( function ( button ) { button.dataset.initialDisabled = button.disabled ? '1' : '0'; } );
	function updateDirty() {
		var dirty = snapshot() !== initial;
		savebar.classList.toggle( 'is-dirty', dirty );
		status.textContent = dirty ? strings.unsaved : originalStatus;
		actions.forEach( function ( button ) { button.disabled = dirty || button.dataset.initialDisabled === '1'; } );
	}
	// Controls in the sidebar can belong to the settings form via the form attribute.
	root.addEventListener( 'input', function ( event ) { if ( event.target.form === form ) { updateDirty(); } } );
	root.addEventListener( 'change', function ( event ) { if ( event.target.form === form ) { updateDirty(); } } );
	var reveal = root.querySelector( '[data-threadtandem-token-toggle]' );
	if ( reveal ) {
		reveal.addEventListener( 'click', function () {
			var input = document.getElementById( 'threadtandem-token' );
			var visible = input.type === 'password';
			input.type = visible ? 'text' : 'password';
			reveal.textContent = visible ? strings.hide : strings.show;
			reveal.setAttribute( 'aria-pressed', visible ? 'true' : 'false' );
		} );
	}
	root.querySelectorAll( '[data-threadtandem-copy]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var input = document.getElementById( button.dataset.threadtandemCopy );
			var result = button.closest( 'details' ).querySelector( '.threadtandem-copy-status' );
			var manual = function () { input.focus(); input.select(); result.textContent = strings.copyManual; };
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( input.value ).then( function () { result.textContent = strings.copied; }, manual );
			} else { manual(); }
		} );
	} );
} )();
