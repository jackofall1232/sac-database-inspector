<?php
/** @package WPDI */

use PHPUnit\Framework\TestCase;

final class OwnershipTest extends TestCase {

	public function test_critical_core_options_are_confirmed_and_protected() {
		$ownership = new WPDI_Ownership();
		$result    = $ownership->identify( 'active_plugins' );

		$this->assertSame( 'core', $result['owner_type'] );
		$this->assertSame( 'confirmed', $result['confidence'] );
		$this->assertTrue( $ownership->is_protected_option( 'active_plugins' ) );
		$this->assertTrue( $ownership->is_protected_option( 'theme_mods_twenty_twenty_six' ) );
		$this->assertTrue( $ownership->is_protected_option( 'wp_custom_core_like_option' ) );
	}
}
