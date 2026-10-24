( function () {
	'use strict';
	var root = document.querySelector( '.treda-settings' );
	if ( ! root ) { return; }
	var strings = window.tredaAdmin || {};
	var form = document.getElementById( 'treda-settings-form' );
	var status = root.querySelector( '[data-treda-save-status]' );
	var savebar = root.querySelector( '.treda-savebar' );
	var originalStatus = status.textContent;
	root.querySelector( 'a[href="#treda-diagnostics"]' ).addEventListener( 'click', function () {
		document.getElementById( 'treda-diagnostics' ).open = true;
	} );
	var snapshot = function () { return JSON.stringify( Array.from( new FormData( form ).entries() ) ); };
	var initial = snapshot();
	var actions = Array.from( root.querySelectorAll( '[data-treda-action-form] button, [data-treda-action-form] input[type=submit]' ) );
	actions.forEach( function ( button ) { button.dataset.initialDisabled = button.disabled ? '1' : '0'; } );
	function updateDirty() {
		var dirty = snapshot() !== initial;
		savebar.classList.toggle( 'is-dirty', dirty );
		status.textContent = dirty ? strings.unsaved : originalStatus;
		actions.forEach( function ( button ) { button.disabled = dirty || button.dataset.initialDisabled === '1'; } );
	}
	form.addEventListener( 'input', updateDirty );
	form.addEventListener( 'change', updateDirty );
	var reveal = root.querySelector( '[data-treda-token-toggle]' );
	if ( reveal ) {
		reveal.addEventListener( 'click', function () {
			var input = document.getElementById( 'treda-token' );
			var visible = input.type === 'password';
			input.type = visible ? 'text' : 'password';
			reveal.textContent = visible ? strings.hide : strings.show;
			reveal.setAttribute( 'aria-pressed', visible ? 'true' : 'false' );
		} );
	}
	root.querySelectorAll( '[data-treda-copy]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var input = document.getElementById( button.dataset.tredaCopy );
			var result = button.closest( 'details' ).querySelector( '.treda-copy-status' );
			var manual = function () { input.focus(); input.select(); result.textContent = strings.copyManual; };
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( input.value ).then( function () { result.textContent = strings.copied; }, manual );
			} else { manual(); }
		} );
	} );
} )();
