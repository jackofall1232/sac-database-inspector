/**
 * SAC Database Inspector admin interactions.
 *
 * @package WPDI
 */

( function( $ ) {
	'use strict';

	var WPDI = {
		init: function() {
			$( '.wpdi-cleanup-btn' ).on( 'click', this.cleanup );
			$( '#wpdi-refresh' ).on( 'click', this.refresh );
			$( '.wpdi-preview-option' ).on( 'click', this.preview );
			$( '.wpdi-preview-close' ).on( 'click', function() {
				$( '#wpdi-option-preview' ).prop( 'hidden', true );
			} );
			$( '.wpdi-autoload-toggle' ).on( 'click', this.changeAutoload );
			$( '.wpdi-restore-snapshot' ).on( 'click', this.restore );
			$( '#wpdi-ai-explain' ).on( 'click', this.explain );
			$( '.wpdi-review-dismiss' ).on( 'click', this.dismissReview );
			$( '.wpdi-review-link' ).on( 'click', this.dismissReviewSilently );
			if ( wpdiData.readOnly ) {
				$( '.wpdi-cleanup-btn, .wpdi-autoload-toggle, .wpdi-restore-snapshot' ).prop( 'disabled', true );
			}
		},

		request: function( data, $button ) {
			if ( $button ) {
				$button.prop( 'disabled', true ).addClass( 'loading' );
			}
			data.nonce = wpdiData.nonce;
			return $.post( wpdiData.ajaxUrl, data ).always( function() {
				if ( $button ) {
					$button.prop( 'disabled', false ).removeClass( 'loading' );
				}
			} );
		},

		cleanup: function( event ) {
			event.preventDefault();
			var $button = $( event.currentTarget );
			if ( wpdiData.readOnly || ! window.confirm( wpdiData.i18n.confirmProceed ) ) {
				return;
			}
			WPDI.request( {
				action: 'wpdi_cleanup',
				cleanup_action: $button.data( 'action' ),
				confirmed: '1'
			}, $button ).done( WPDI.handleMutation );
		},

		refresh: function( event ) {
			event.preventDefault();
			var $button = $( event.currentTarget );
			WPDI.request( { action: 'wpdi_get_stats' }, $button ).done( function( response ) {
				if ( ! response.success ) {
					WPDI.showError( response );
					return;
				}
				var stats = response.data;
				$( '.wpdi-score-value' ).first().text( stats.health_score );
				var values = [ WPDI.formatBytes( stats.total_db_size ), WPDI.formatBytes( stats.autoload_size ), Number( stats.autoload_count ).toLocaleString(), stats.object_cache_enabled ? 'Yes' : 'No' ];
				$( '.wpdi-stat-value' ).each( function( index ) {
					if ( values[ index ] !== undefined ) {
						$( this ).text( values[ index ] );
					}
				} );
				WPDI.toast( 'Statistics refreshed.', 'success' );
			} ).fail( WPDI.networkError );
		},

		preview: function( event ) {
			event.preventDefault();
			var $button = $( event.currentTarget );
			WPDI.request( { action: 'wpdi_option_preview', option_name: $button.data( 'option' ) }, $button ).done( function( response ) {
				if ( ! response.success ) {
					WPDI.showError( response );
					return;
				}
				var suffix = response.data.truncated ? '\n\n[preview truncated]' : '';
				$( '#wpdi-option-preview pre' ).text( response.data.name + '\n\n' + response.data.preview + suffix );
				$( '#wpdi-option-preview' ).prop( 'hidden', false )[ 0 ].scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
			} ).fail( WPDI.networkError );
		},

		changeAutoload: function( event ) {
			event.preventDefault();
			var $button = $( event.currentTarget );
			if ( wpdiData.readOnly || ! window.confirm( wpdiData.i18n.confirmProceed ) ) {
				return;
			}
			WPDI.request( { action: 'wpdi_change_autoload', option_name: $button.data( 'option' ), enabled: String( $button.data( 'enabled' ) ), confirmed: '1' }, $button ).done( WPDI.handleMutation );
		},

		restore: function( event ) {
			event.preventDefault();
			var $button = $( event.currentTarget );
			if ( wpdiData.readOnly || ! window.confirm( wpdiData.i18n.confirmRestore ) ) {
				return;
			}
			WPDI.request( { action: 'wpdi_restore_snapshot', snapshot_id: $button.data( 'snapshot' ), confirmed: '1' }, $button ).done( WPDI.handleMutation );
		},

		explain: function( event ) {
			event.preventDefault();
			var $button = $( event.currentTarget );
			var $result = $( '#wpdi-ai-result' );
			$result.prop( 'hidden', false ).find( 'pre' ).text( wpdiData.i18n.aiWorking );
			WPDI.request( { action: 'wpdi_ai_explain', focus: $( '#wpdi-ai-focus' ).val() }, $button ).done( function( response ) {
				if ( ! response.success ) {
					$result.prop( 'hidden', true );
					WPDI.showError( response );
					return;
				}
				$result.find( 'pre' ).text( response.data.explanation );
			} ).fail( function() {
				$result.prop( 'hidden', true );
				WPDI.networkError();
			} );
		},

		dismissReview: function( event ) {
			event.preventDefault();
			var $button = $( event.currentTarget );
			WPDI.request( { action: 'wpdi_dismiss_review' }, $button ).done( function() {
				$button.closest( '.wpdi-review-banner' ).slideUp( 200, function() {
					$( this ).remove();
				} );
			} ).fail( WPDI.networkError );
		},

		dismissReviewSilently: function( event ) {
			// The review link opens in a new tab; record the dismissal in the background
			// and only hide the banner once the server has stored it, so a failed
			// request cannot silently lose the permanent dismissal.
			var $banner = $( event.currentTarget ).closest( '.wpdi-review-banner' );
			$.post( wpdiData.ajaxUrl, { action: 'wpdi_dismiss_review', nonce: wpdiData.nonce } ).done( function() {
				$banner.slideUp( 200, function() {
					$banner.remove();
				} );
			} );
		},

		handleMutation: function( response ) {
			if ( response.success ) {
				WPDI.toast( response.data.message || 'Operation completed.', 'success' );
				window.setTimeout( function() { window.location.reload(); }, 900 );
				return;
			}
			WPDI.showError( response );
		},

		showError: function( response ) {
			var message = wpdiData.i18n.error;
			if ( response && response.data ) {
				message = typeof response.data === 'string' ? response.data : ( response.data.message || message );
			}
			WPDI.toast( message, 'error' );
		},

		networkError: function( xhr ) {
			var response = xhr && xhr.responseJSON ? xhr.responseJSON : null;
			WPDI.showError( response );
		},

		formatBytes: function( bytes ) {
			bytes = Number( bytes ) || 0;
			if ( bytes >= 1073741824 ) { return ( bytes / 1073741824 ).toFixed( 2 ) + ' GB'; }
			if ( bytes >= 1048576 ) { return ( bytes / 1048576 ).toFixed( 2 ) + ' MB'; }
			if ( bytes >= 1024 ) { return ( bytes / 1024 ).toFixed( 2 ) + ' KB'; }
			return bytes + ' B';
		},

		toast: function( message, type ) {
			var $toast = $( '<div class="wpdi-toast" role="status"></div>' ).text( message ).addClass( type || '' );
			$( 'body' ).append( $toast );
			window.setTimeout( function() { $toast.fadeOut( 250, function() { $toast.remove(); } ); }, 4000 );
		}
	};

	$( function() { WPDI.init(); } );
} )( jQuery );
