<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WPO\WC\PDF_Invoices\Compatibility\WC_Core as WCX;
use WPO\WC\PDF_Invoices\Compatibility\Order as WCX_Order;
use WPO\WC\PDF_Invoices\Compatibility\Product as WCX_Product;

/*
|--------------------------------------------------------------------------
| Document getter functions
|--------------------------------------------------------------------------
|
| Global functions to get the document object for an order
|
*/

function wcpdf_filter_order_ids( $order_ids, $document_type ) {
	$order_ids = apply_filters( 'wpo_wcpdf_process_order_ids', $order_ids, $document_type );
	// filter out trashed orders
	foreach ( $order_ids as $key => $order_id ) {
		$order_status = get_post_status( $order_id );
		if ( $order_status == 'trash' ) {
			unset( $order_ids[ $key ] );
		}
	}
	return $order_ids;
}

function wcpdf_get_document( $document_type, $order, $init = false, $process_action = true ) {
	// $order can be one of the following:
	// - WC Order object
	// - array of order ids
	// - null if order not loaded or loaded later
	if ( !empty( $order ) ) {
		if ( is_object( $order ) ) {
			// we filter order_ids for objects too:
			// an order object may need to be converted to several refunds for example
			$order_ids = array( WCX_Order::get_id( $order ) );
			$filtered_order_ids = wcpdf_filter_order_ids( $order_ids, $document_type );
			// check if something has changed
			$order_id_diff = array_diff( $filtered_order_ids, $order_ids );
			if ( empty( $order_id_diff ) && count( $order_ids ) == count( $filtered_order_ids ) ) {
				// nothing changed, load document with Order object
				if ($process_action === true) {
					do_action( 'wpo_wcpdf_process_template_order', $document_type, WCX_Order::get_id( $order ) );
				}
				$document = WPO_WCPDF()->documents->get_document( $document_type, $order );

				if ( $init && !$document->exists() ) {
					$document->init();
					$document->save();
				}
				// $document->read_data( $order ); // isn't data already read from construct?
				return $document;
			} else {
				// order ids array changed, continue processing that array
				$order_ids = $filtered_order_ids;
			}
		} elseif ( is_array( $order ) ) {
			$order_ids = wcpdf_filter_order_ids( $order, $document_type );
		} else {
			return false;
		}

		if ( empty( $order_ids ) ) {
			// No orders to export for this document type
			return false;
		}

		// if we only have one order, it's simple
		if ( count( $order_ids ) == 1 ) {
			$order_id = array_pop ( $order_ids );
			if ($process_action === true) {
				do_action( 'wpo_wcpdf_process_template_order', $document_type, $order_id );
			}
			$order = WCX::get_order( $order_id );

			$document = WPO_WCPDF()->documents->get_document( $document_type, $order );
			if ( $init && !$document->exists() ) {
				$document->init();
				$document->save();
			}
		// otherwise we use bulk class to wrap multiple documents in one
		} else {
			$document = wcpdf_get_bulk_document( $document_type, $order_ids );
		}
	} else {
		// orderless document (used as wrapper for bulk, for example)
		$document = WPO_WCPDF()->documents->get_document( $document_type, $order );
	}

	return $document;
}

function wcpdf_get_bulk_document( $document_type, $order_ids ) {
	return new \WPO\WC\PDF_Invoices\Documents\Bulk_Document( $document_type, $order_ids );
}

function wcpdf_get_invoice( $order, $init = false ) {
	return wcpdf_get_document( 'invoice', $order, $init );
}

function wcpdf_get_packing_slip( $order, $init = false ) {
	return wcpdf_get_document( 'packing-slip', $order, $init );
}

/**
 * Load HTML into (pluggable) PDF library, DomPDF 0.6 by default
 * Use wpo_wcpdf_pdf_maker filter to change the PDF class (which can wrap another PDF library).
 * @return WC_Logger
 */
function wcpdf_get_pdf_maker( $html, $settings = array() ) {
	if ( ! class_exists( '\\WPO\\WC\\PDF_Invoices\\PDF_Maker' ) ) {
		include_once( WPO_WCPDF()->plugin_path() . '/includes/class-wcpdf-pdf-maker.php' );
	}
	$class = apply_filters( 'wpo_wcpdf_pdf_maker', '\\WPO\\WC\\PDF_Invoices\\PDF_Maker' );
	return new $class( $html, $settings );
}

function wcpdf_pdf_headers( $filename, $mode = 'inline', $pdf = null ) {
	switch ($mode) {
		case 'download':
			header('Content-Description: File Transfer');
			header('Content-Type: application/pdf');
			header('Content-Disposition: attachment; filename="'.$filename.'"'); 
			header('Content-Transfer-Encoding: binary');
			header('Connection: Keep-Alive');
			header('Expires: 0');
			header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
			header('Pragma: public');
			break;
		case 'inline':
		default:
			header('Content-type: application/pdf');
			header('Content-Disposition: inline; filename="'.$filename.'"');
			break;
	}
}

/**
 * Wrapper for deprecated functions so we can apply some extra logic.
 *
 * @since  2.0
 * @param  string $function
 * @param  string $version
 * @param  string $replacement
 */
function wcpdf_deprecated_function( $function, $version, $replacement = null ) {
	if ( apply_filters( 'wcpdf_disable_deprecation_notices', false ) ) {
		return;
	}
	// if the deprecated function is called from one of our filters, $this should be $document
	$filter = current_filter();
	$global_wcpdf_filters = array( 'wp_ajax_generate_wpo_wcpdf' );
	if ( !in_array($filter, $global_wcpdf_filters) && strpos($filter, 'wpo_wcpdf') !== false && strpos($replacement, '$this') !== false ) {
		$replacement = str_replace('$this', '$document', $replacement);
		$replacement = "{$replacement} - check that the \$document parameter is included in your action or filter ($filter)!";
	}
	if ( is_ajax() ) {
		do_action( 'deprecated_function_run', $function, $replacement, $version );
		$log_string  = "The {$function} function is deprecated since version {$version}.";
		$log_string .= $replacement ? " Replace with {$replacement}." : '';
		error_log( $log_string );
	} else {
		_deprecated_function( $function, $version, $replacement );
	}
}
