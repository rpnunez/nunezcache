<?php
/**
 * state.php tests.
 *
 * @package NunezCache
 * Purpose: Prove the drop-in state file has the slots later slices fill, and starts off.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

/**
 * Covers defaults and atomic read/write of state.php.
 */
class StateFileTest extends NunezCache_Test_Case {

	/**
	 * Defaults are off, with the named slots and a 10 hour TTL.
	 */
	public function test_defaults_are_off_and_include_later_slots(): void {
		$defaults = NunezCache_State_File::defaults();

		$this->assertSame(
			array(
				'enabled',
				'paused',
				'generation',
				'bypass_token',
				'ttl',
				'extra_path_prefixes',
				'tracking_parameters',
			),
			array_keys($defaults)
		);
		$this->assertFalse($defaults['enabled']);
		$this->assertFalse($defaults['paused']);
		$this->assertSame(1, $defaults['generation']);
		$this->assertSame('', $defaults['bypass_token']);
		$this->assertSame(36000, $defaults['ttl']);
		$this->assertSame(array(), $defaults['extra_path_prefixes']);
		$this->assertSame(array(), $defaults['tracking_parameters']);
	}

	/**
	 * A missing file reads as disabled defaults.
	 */
	public function test_missing_file_reads_defaults(): void {
		$state = new NunezCache_State_File($this->temp_dir() . '/state.php');
		$read  = $state->read();

		$this->assertFalse($read['enabled']);
		$this->assertFalse($read['paused']);
		$this->assertSame(36000, $read['ttl']);
	}

	/**
	 * Round trip keeps every slot and forces boolean flags.
	 */
	public function test_write_and_read_round_trip(): void {
		$path  = $this->temp_dir() . '/state.php';
		$state = new NunezCache_State_File($path);
		$ok    = $state->write(
			array(
				'enabled'               => true,
				'paused'                => false,
				'generation'            => 3,
				'bypass_token'          => 'token-value',
				'ttl'                   => 120,
				'extra_path_prefixes'   => array( '/private/' ),
				'tracking_parameters'   => array( 'fbclid' ),
			)
		);

		$this->assertTrue($ok);
		$read = $state->read();
		$this->assertTrue($read['enabled']);
		$this->assertFalse($read['paused']);
		$this->assertSame(3, $read['generation']);
		$this->assertSame('token-value', $read['bypass_token']);
		$this->assertSame(120, $read['ttl']);
		$this->assertSame(array( '/private/' ), $read['extra_path_prefixes']);
		$this->assertSame(array( 'fbclid' ), $read['tracking_parameters']);
		$this->assertStringNotContainsString('eval', (string) file_get_contents($path));
	}

	/**
	 * A file that does not return an array falls back to defaults.
	 */
	public function test_non_array_file_falls_back(): void {
		$path = $this->temp_dir() . '/state.php';
		file_put_contents($path, "<?php\nreturn 'nope';\n");
		$state = new NunezCache_State_File($path);

		$this->assertFalse($state->read()['enabled']);
	}
}
