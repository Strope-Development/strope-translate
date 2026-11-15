/**
 * Handle the response to a click on a Languages metabox button.
 *
 * @package Strope-Translate
 */

jQuery(
	function ( $ ) {
		$( '#ml_box' ).on(
			'click',
			'.strope-button',
			function () {
				var value = $( this ).hasClass( 'wp-ui-text-highlight' );
				var id = $( this ).attr( 'id' );
				var post_id = $( '#htr_lang_' + id.replace( 'strope_sync_post[', '' ).replace( ']', '' ) ).val();

				if ( 'undefined' == typeof( post_id ) || 0 == post_id || value || confirm( strope_sync_post.confirm_text ) ) {
					var data = {
						action:     'toggle_' + id,
						value:      value,
						post_type:  $( '#post_type' ).val(),
						_strope_nonce: $( '#_strope_nonce' ).val()
					}

					$.post(
						ajaxurl,
						data,
						function ( response ) {
							// Target a non existing WP HTML id to avoid a conflict with WP ajax requests.
							var res = wpAjax.parseAjaxResponse( response, 'strope-ajax-response' );
							$.each(
								res.responses,
								function () {
									id = id.replace( '[', '\\[' ).replace( ']', '\\]' );
									$( '#' + id ).toggleClass( 'wp-ui-text-highlight' ).attr( 'title', this.data ).children( 'span' ).text( this.data );
									$( 'input[name="' + id + '"]' ).val( ! data['value'] );
								}
							);
						}
					);
				}
			}
		);
	}
);
