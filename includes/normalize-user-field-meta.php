<?php
/**
 * Normalize multi-value User Field CSV cells to arrays.
 *
 * Always loaded (not gated on PMPRO_VERSION) so import can call it without
 * function_exists guards. Safe when PMPro is inactive — class_exists bails.
 *
 * @since 1.2.3
 *
 * @param mixed  $metavalue Value from the CSV cell (after maybe_unserialize).
 * @param string $metakey   User meta key / field name.
 * @return mixed Normalized value.
 */
function pmproiucsv_normalize_user_field_meta_value( $metavalue, $metakey ) {
	if ( ! is_string( $metavalue ) || '' === $metavalue ) {
		return $metavalue;
	}

	if ( ! class_exists( 'PMPro_Field_Group' ) ) {
		return $metavalue;
	}

	$field = PMPro_Field_Group::get_field( $metakey );
	if ( empty( $field ) ) {
		return $metavalue;
	}

	// Prefer core helper when present; keep a local type check for older PMPro.
	$is_multi = false;
	if ( method_exists( $field, 'stores_array_values' ) ) {
		$is_multi = $field->stores_array_values();
	} else {
		$is_multi = in_array( $field->type, array( 'checkbox_grouped', 'multiselect', 'select2' ), true )
			|| ( 'select' === $field->type && ! empty( $field->multiple ) );
	}

	if ( ! $is_multi ) {
		return $metavalue;
	}

	if ( method_exists( $field, 'get_values_as_array' ) ) {
		return $field->get_values_as_array( $metavalue );
	}

	// Fallback matches Members List export (comma-joined option keys; keys must not contain commas).
	if ( false !== strpos( $metavalue, ',' ) ) {
		$parts = array_map( 'trim', explode( ',', $metavalue ) );
		return array_values( array_filter( $parts, 'strlen' ) );
	}

	return array( $metavalue );
}
