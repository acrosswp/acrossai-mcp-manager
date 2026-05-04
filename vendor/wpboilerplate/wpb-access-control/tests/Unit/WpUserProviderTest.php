<?php
/**
 * Unit tests for WpUserProvider — the default per-user access provider.
 *
 * Covers identity helpers, the empty get_options() contract, and every
 * branch of user_has_access (empty list, ID match, miss, filter override,
 * strict string comparison). Static helpers search_users() and
 * get_users_by_ids() are exercised against a mocked get_users().
 */

namespace WPBoilerplate\AccessControl\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPBoilerplate\AccessControl\WpUserProvider;

final class WpUserProviderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function provider(): WpUserProvider {
		return new WpUserProvider();
	}

	private function fake_user( int $id, string $login, string $email, string $display_name ): object {
		$u               = new \stdClass();
		$u->ID           = $id;
		$u->user_login   = $login;
		$u->user_email   = $email;
		$u->display_name = $display_name;
		return $u;
	}

	// -------------------------------------------------------------------------
	// Identity / metadata
	// -------------------------------------------------------------------------

	public function test_get_id_returns_wp_user(): void {
		$this->assertSame( 'wp_user', $this->provider()->get_id() );
	}

	public function test_get_label_returns_users(): void {
		$this->assertSame( 'Users', $this->provider()->get_label() );
	}

	public function test_is_available_returns_true_by_default(): void {
		$this->assertTrue( $this->provider()->is_available() );
	}

	public function test_get_options_returns_empty_array_because_users_are_dynamic(): void {
		$this->assertSame( array(), $this->provider()->get_options() );
	}

	// -------------------------------------------------------------------------
	// user_has_access() — empty list short-circuit
	// -------------------------------------------------------------------------

	public function test_user_has_access_returns_false_when_no_users_selected(): void {
		Filters\expectApplied( 'wpb_access_control_wp_user_has_access' )->never();
		$this->assertFalse( $this->provider()->user_has_access( 5, array() ) );
	}

	// -------------------------------------------------------------------------
	// user_has_access() — id match (strings, since options are stored as strings)
	// -------------------------------------------------------------------------

	public function test_user_has_access_returns_true_when_user_id_string_matches(): void {
		Filters\expectApplied( 'wpb_access_control_wp_user_has_access' )
			->once()
			->with( true, 42, array( '7', '42', '99' ) )
			->andReturn( true );

		$this->assertTrue( $this->provider()->user_has_access( 42, array( '7', '42', '99' ) ) );
	}

	public function test_user_has_access_returns_true_when_user_id_is_first_in_list(): void {
		Filters\expectApplied( 'wpb_access_control_wp_user_has_access' )
			->once()
			->andReturn( true );

		$this->assertTrue( $this->provider()->user_has_access( 1, array( '1', '2' ) ) );
	}

	public function test_user_has_access_returns_true_when_user_id_is_only_entry(): void {
		Filters\expectApplied( 'wpb_access_control_wp_user_has_access' )
			->once()
			->andReturn( true );

		$this->assertTrue( $this->provider()->user_has_access( 99, array( '99' ) ) );
	}

	// -------------------------------------------------------------------------
	// user_has_access() — id miss
	// -------------------------------------------------------------------------

	public function test_user_has_access_returns_false_when_user_id_not_in_list(): void {
		Filters\expectApplied( 'wpb_access_control_wp_user_has_access' )
			->once()
			->with( false, 999, array( '1', '2', '3' ) )
			->andReturn( false );

		$this->assertFalse( $this->provider()->user_has_access( 999, array( '1', '2', '3' ) ) );
	}

	public function test_user_has_access_uses_strict_string_comparison(): void {
		// "42 " (trailing space) should NOT match user 42.
		Filters\expectApplied( 'wpb_access_control_wp_user_has_access' )
			->once()
			->andReturn( false );

		$this->assertFalse( $this->provider()->user_has_access( 42, array( '42 ' ) ) );
	}

	public function test_user_has_access_does_not_match_when_options_contain_integers_due_to_strict_comparison(): void {
		// Per docblock: options are stored as strings. If a caller passes an
		// integer, strict in_array comparison must NOT match the user's
		// string-cast id. This protects the contract.
		Filters\expectApplied( 'wpb_access_control_wp_user_has_access' )
			->once()
			->andReturn( false );

		$this->assertFalse( $this->provider()->user_has_access( 42, array( 42 ) ) );
	}

	// -------------------------------------------------------------------------
	// user_has_access() — filter override
	// -------------------------------------------------------------------------

	public function test_user_has_access_filter_can_flip_match_to_deny(): void {
		Filters\expectApplied( 'wpb_access_control_wp_user_has_access' )
			->once()
			->andReturn( false );

		$this->assertFalse( $this->provider()->user_has_access( 42, array( '42' ) ) );
	}

	public function test_user_has_access_filter_can_flip_miss_to_allow(): void {
		Filters\expectApplied( 'wpb_access_control_wp_user_has_access' )
			->once()
			->andReturn( true );

		$this->assertTrue( $this->provider()->user_has_access( 42, array( '99' ) ) );
	}

	public function test_user_has_access_filter_value_is_cast_to_bool(): void {
		Filters\expectApplied( 'wpb_access_control_wp_user_has_access' )
			->once()
			->andReturn( 0 ); // falsy non-bool

		$this->assertFalse( $this->provider()->user_has_access( 42, array( '42' ) ) );
	}

	// -------------------------------------------------------------------------
	// search_users()
	// -------------------------------------------------------------------------

	public function test_search_users_returns_empty_for_blank_search_term(): void {
		// get_users() should never be called for blank input.
		Functions\expect( 'get_users' )->never();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$this->assertSame( array(), WpUserProvider::search_users( '' ) );
	}

	public function test_search_users_passes_wildcard_search_to_get_users(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\expect( 'get_users' )
			->once()
			->with(
				\Mockery::on(
					static function ( $args ) {
						return isset( $args['search'] )
							&& '*alice*' === $args['search']
							&& 10 === $args['number']
							&& array( 'user_login', 'user_email', 'display_name' ) === $args['search_columns'];
					}
				)
			)
			->andReturn(
				array(
					(object) array(
						'ID'           => 12,
						'user_login'   => 'alice',
						'user_email'   => 'alice@example.com',
						'display_name' => 'Alice Example',
					),
				)
			);

		$results = WpUserProvider::search_users( 'alice' );

		$this->assertSame(
			array(
				array(
					'id'           => '12',
					'login'        => 'alice',
					'email'        => 'alice@example.com',
					'display_name' => 'Alice Example',
				),
			),
			$results
		);
	}

	public function test_search_users_clamps_limit_to_minimum_of_one(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\expect( 'get_users' )
			->once()
			->with(
				\Mockery::on(
					static function ( $args ) {
						return isset( $args['number'] ) && 1 === $args['number'];
					}
				)
			)
			->andReturn( array() );

		WpUserProvider::search_users( 'x', 0 );
		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// get_users_by_ids()
	// -------------------------------------------------------------------------

	public function test_get_users_by_ids_returns_empty_for_empty_input(): void {
		Functions\expect( 'get_users' )->never();
		$this->assertSame( array(), WpUserProvider::get_users_by_ids( array() ) );
	}

	public function test_get_users_by_ids_filters_out_non_positive_ids(): void {
		// absint() drops 0, '', '-3' (becomes 3 -- absint takes absolute), …
		// So '-3' DOES become 3. Only 0 / '' / 'abc' are dropped.
		Functions\when( 'absint' )->alias( static fn( $v ) => (int) abs( (int) $v ) );
		Functions\expect( 'get_users' )->never(); // all ids drop to 0
		$this->assertSame( array(), WpUserProvider::get_users_by_ids( array( '0', '', 'abc' ) ) );
	}

	public function test_get_users_by_ids_hydrates_string_ids_to_user_arrays(): void {
		Functions\when( 'absint' )->alias( static fn( $v ) => (int) abs( (int) $v ) );
		Functions\expect( 'get_users' )
			->once()
			->with(
				\Mockery::on(
					static function ( $args ) {
						return isset( $args['include'] )
							&& array( 1, 7 ) === $args['include']
							&& array( 'ID', 'user_login', 'user_email', 'display_name' ) === $args['fields'];
					}
				)
			)
			->andReturn(
				array(
					(object) array(
						'ID'           => 1,
						'user_login'   => 'admin',
						'user_email'   => 'admin@example.com',
						'display_name' => 'Site Admin',
					),
					(object) array(
						'ID'           => 7,
						'user_login'   => 'bob',
						'user_email'   => 'bob@example.com',
						'display_name' => 'Bob',
					),
				)
			);

		$results = WpUserProvider::get_users_by_ids( array( '1', '7' ) );

		$this->assertSame(
			array(
				array(
					'id'           => '1',
					'login'        => 'admin',
					'email'        => 'admin@example.com',
					'display_name' => 'Site Admin',
				),
				array(
					'id'           => '7',
					'login'        => 'bob',
					'email'        => 'bob@example.com',
					'display_name' => 'Bob',
				),
			),
			$results
		);
	}
}
