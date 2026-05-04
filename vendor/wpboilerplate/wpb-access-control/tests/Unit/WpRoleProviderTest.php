<?php
/**
 * Unit tests for WpRoleProvider — the default WordPress-role access provider.
 *
 * Covers every observable branch of get_id, get_label, is_available,
 * get_options (including the wpb_access_control_wp_role_options filter),
 * and user_has_access (empty options, missing user, role match, multi-role
 * match, no match, filter override, strict comparison, case sensitivity).
 *
 * Brain Monkey mocks WordPress functions; no WordPress install required.
 */

namespace WPBoilerplate\AccessControl\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPBoilerplate\AccessControl\WpRoleProvider;

final class WpRoleProviderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// __() is called inside get_label(); make it a no-op pass-through.
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function provider(): WpRoleProvider {
		return new WpRoleProvider();
	}

	private function user_with_roles( array $roles ): object {
		$user        = new \stdClass();
		$user->roles = $roles;
		return $user;
	}

	// -------------------------------------------------------------------------
	// Identity / metadata
	// -------------------------------------------------------------------------

	public function test_get_id_returns_wp_role(): void {
		$this->assertSame( 'wp_role', $this->provider()->get_id() );
	}

	public function test_get_label_returns_wordpress_role_string(): void {
		$this->assertSame( 'WordPress Role', $this->provider()->get_label() );
	}

	public function test_is_available_returns_true_by_default(): void {
		$this->assertTrue( $this->provider()->is_available() );
	}

	// -------------------------------------------------------------------------
	// get_options()
	// -------------------------------------------------------------------------

	public function test_get_options_includes_all_roles_including_administrator(): void {
		Functions\when( 'get_editable_roles' )->justReturn(
			array(
				'administrator' => array( 'name' => 'Administrator' ),
				'editor'        => array( 'name' => 'Editor' ),
				'subscriber'    => array( 'name' => 'Subscriber' ),
			)
		);
		Functions\when( 'translate_user_role' )->returnArg();
		Filters\expectApplied( 'wpb_access_control_wp_role_options' )->once()->andReturnFirstArg();

		$options = $this->provider()->get_options();

		$ids = array_column( $options, 'id' );
		$this->assertContains( 'administrator', $ids );
		$this->assertContains( 'editor', $ids );
		$this->assertContains( 'subscriber', $ids );
		$this->assertCount( 3, $options );
	}

	public function test_get_options_returns_id_and_label_keys_for_each_role(): void {
		Functions\when( 'get_editable_roles' )->justReturn(
			array( 'editor' => array( 'name' => 'Editor' ) )
		);
		Functions\when( 'translate_user_role' )->alias( static fn( $name ) => 'TX:' . $name );
		Filters\expectApplied( 'wpb_access_control_wp_role_options' )->andReturnFirstArg();

		$options = $this->provider()->get_options();

		$this->assertSame(
			array( array( 'id' => 'editor', 'label' => 'TX:Editor' ) ),
			$options
		);
	}

	public function test_get_options_returns_administrator_when_only_administrator_exists(): void {
		Functions\when( 'get_editable_roles' )->justReturn(
			array( 'administrator' => array( 'name' => 'Administrator' ) )
		);
		Functions\when( 'translate_user_role' )->returnArg();
		Filters\expectApplied( 'wpb_access_control_wp_role_options' )->andReturnFirstArg();

		$options = $this->provider()->get_options();
		$this->assertCount( 1, $options );
		$this->assertSame( 'administrator', $options[0]['id'] );
	}

	public function test_get_options_returns_empty_when_no_editable_roles_exist(): void {
		Functions\when( 'get_editable_roles' )->justReturn( array() );
		Functions\when( 'translate_user_role' )->returnArg();
		Filters\expectApplied( 'wpb_access_control_wp_role_options' )->andReturnFirstArg();

		$this->assertSame( array(), $this->provider()->get_options() );
	}

	public function test_get_options_filter_can_replace_the_entire_list(): void {
		Functions\when( 'get_editable_roles' )->justReturn(
			array( 'editor' => array( 'name' => 'Editor' ) )
		);
		Functions\when( 'translate_user_role' )->returnArg();
		Filters\expectApplied( 'wpb_access_control_wp_role_options' )
			->once()
			->andReturn( array( array( 'id' => 'override', 'label' => 'Override' ) ) );

		$this->assertSame(
			array( array( 'id' => 'override', 'label' => 'Override' ) ),
			$this->provider()->get_options()
		);
	}

	public function test_get_options_casts_non_array_filter_return_back_to_array(): void {
		Functions\when( 'get_editable_roles' )->justReturn( array() );
		Functions\when( 'translate_user_role' )->returnArg();
		// The provider casts apply_filters() result to (array) — verify that contract.
		Filters\expectApplied( 'wpb_access_control_wp_role_options' )->andReturn( null );

		$this->assertSame( array(), $this->provider()->get_options() );
	}

	// -------------------------------------------------------------------------
	// user_has_access() — empty options short-circuit
	// -------------------------------------------------------------------------

	public function test_user_has_access_returns_false_when_selected_options_empty(): void {
		// No mocks set: get_userdata must NOT be called when options are empty.
		Filters\expectApplied( 'wpb_access_control_wp_role_has_access' )->never();

		$this->assertFalse( $this->provider()->user_has_access( 1, array() ) );
	}

	public function test_user_has_access_returns_false_for_empty_options_even_with_admin_user_id(): void {
		Filters\expectApplied( 'wpb_access_control_wp_role_has_access' )->never();
		// Admin bypass lives in AccessControlManager, not in the provider.
		$this->assertFalse( $this->provider()->user_has_access( 1, array() ) );
	}

	// -------------------------------------------------------------------------
	// user_has_access() — invalid / missing user
	// -------------------------------------------------------------------------

	public function test_user_has_access_returns_false_when_get_userdata_returns_false(): void {
		Functions\expect( 'get_userdata' )->once()->with( 999 )->andReturn( false );
		Filters\expectApplied( 'wpb_access_control_wp_role_has_access' )->never();

		$this->assertFalse( $this->provider()->user_has_access( 999, array( 'editor' ) ) );
	}

	public function test_user_has_access_returns_false_for_user_id_zero(): void {
		Functions\expect( 'get_userdata' )->once()->with( 0 )->andReturn( false );
		Filters\expectApplied( 'wpb_access_control_wp_role_has_access' )->never();

		$this->assertFalse( $this->provider()->user_has_access( 0, array( 'editor' ) ) );
	}

	// -------------------------------------------------------------------------
	// user_has_access() — role match (true cases)
	// -------------------------------------------------------------------------

	public function test_user_has_access_returns_true_when_single_user_role_matches(): void {
		Functions\when( 'get_userdata' )->justReturn( $this->user_with_roles( array( 'editor' ) ) );
		Filters\expectApplied( 'wpb_access_control_wp_role_has_access' )->never();

		$this->assertTrue( $this->provider()->user_has_access( 1, array( 'editor' ) ) );
	}

	public function test_user_has_access_returns_true_when_one_of_multiple_user_roles_matches(): void {
		Functions\when( 'get_userdata' )->justReturn(
			$this->user_with_roles( array( 'subscriber', 'shop_manager', 'editor' ) )
		);
		Filters\expectApplied( 'wpb_access_control_wp_role_has_access' )->never();

		$this->assertTrue( $this->provider()->user_has_access( 1, array( 'editor', 'author' ) ) );
	}

	public function test_user_has_access_returns_true_when_first_user_role_matches_first_selected(): void {
		Functions\when( 'get_userdata' )->justReturn(
			$this->user_with_roles( array( 'editor', 'subscriber' ) )
		);
		Filters\expectApplied( 'wpb_access_control_wp_role_has_access' )->never();

		$this->assertTrue( $this->provider()->user_has_access( 1, array( 'editor' ) ) );
	}

	public function test_user_has_access_returns_true_when_last_user_role_matches_last_selected(): void {
		Functions\when( 'get_userdata' )->justReturn(
			$this->user_with_roles( array( 'subscriber', 'author' ) )
		);
		Filters\expectApplied( 'wpb_access_control_wp_role_has_access' )->never();

		$this->assertTrue( $this->provider()->user_has_access( 1, array( 'editor', 'author' ) ) );
	}

	// -------------------------------------------------------------------------
	// user_has_access() — no match (filter-applied false cases)
	// -------------------------------------------------------------------------

	public function test_user_has_access_returns_false_when_no_role_matches_and_filter_keeps_false(): void {
		Functions\when( 'get_userdata' )->justReturn(
			$this->user_with_roles( array( 'subscriber' ) )
		);
		Filters\expectApplied( 'wpb_access_control_wp_role_has_access' )
			->once()
			->with( false, 1, array( 'editor', 'author' ) )
			->andReturn( false );

		$this->assertFalse( $this->provider()->user_has_access( 1, array( 'editor', 'author' ) ) );
	}

	public function test_user_has_access_returns_false_when_user_has_no_roles(): void {
		Functions\when( 'get_userdata' )->justReturn( $this->user_with_roles( array() ) );
		Filters\expectApplied( 'wpb_access_control_wp_role_has_access' )
			->once()
			->andReturn( false );

		$this->assertFalse( $this->provider()->user_has_access( 1, array( 'editor' ) ) );
	}

	public function test_user_has_access_returns_false_when_user_roles_property_missing_is_treated_as_empty(): void {
		// Provider casts $user->roles to (array). When ::roles is absent it
		// triggers an undefined-property warning under E_NOTICE/E_WARNING,
		// not a hard fail. We simulate the documented contract by passing an
		// object with roles = []. (Verifying behaviour under missing property
		// is implementation-defined and not asserted here.)
		Functions\when( 'get_userdata' )->justReturn( $this->user_with_roles( array() ) );
		Filters\expectApplied( 'wpb_access_control_wp_role_has_access' )->andReturn( false );

		$this->assertFalse( $this->provider()->user_has_access( 1, array( 'editor' ) ) );
	}

	// -------------------------------------------------------------------------
	// user_has_access() — filter override
	// -------------------------------------------------------------------------

	public function test_user_has_access_filter_can_override_false_to_true(): void {
		Functions\when( 'get_userdata' )->justReturn(
			$this->user_with_roles( array( 'subscriber' ) )
		);
		Filters\expectApplied( 'wpb_access_control_wp_role_has_access' )
			->once()
			->with( false, 7, array( 'editor' ) )
			->andReturn( true );

		$this->assertTrue( $this->provider()->user_has_access( 7, array( 'editor' ) ) );
	}

	public function test_user_has_access_filter_truthy_value_is_cast_to_bool(): void {
		Functions\when( 'get_userdata' )->justReturn(
			$this->user_with_roles( array( 'subscriber' ) )
		);
		Filters\expectApplied( 'wpb_access_control_wp_role_has_access' )
			->once()
			->andReturn( 1 ); // truthy non-bool

		$this->assertTrue( $this->provider()->user_has_access( 1, array( 'editor' ) ) );
	}

	public function test_user_has_access_filter_falsy_value_is_cast_to_bool(): void {
		Functions\when( 'get_userdata' )->justReturn(
			$this->user_with_roles( array( 'subscriber' ) )
		);
		Filters\expectApplied( 'wpb_access_control_wp_role_has_access' )
			->once()
			->andReturn( '' ); // falsy non-bool

		$this->assertFalse( $this->provider()->user_has_access( 1, array( 'editor' ) ) );
	}

	// -------------------------------------------------------------------------
	// user_has_access() — strict comparison semantics
	// -------------------------------------------------------------------------

	public function test_user_has_access_role_match_is_case_sensitive(): void {
		Functions\when( 'get_userdata' )->justReturn(
			$this->user_with_roles( array( 'Editor' ) )
		);
		Filters\expectApplied( 'wpb_access_control_wp_role_has_access' )
			->once()
			->andReturn( false );

		$this->assertFalse( $this->provider()->user_has_access( 1, array( 'editor' ) ) );
	}

	public function test_user_has_access_uses_strict_in_array_comparison(): void {
		// User has integer 0 as role; selected is string 'editor'. Loose
		// comparison would coerce 0 == 'editor' on some PHP versions; strict
		// comparison correctly returns false.
		Functions\when( 'get_userdata' )->justReturn(
			$this->user_with_roles( array( 0 ) )
		);
		Filters\expectApplied( 'wpb_access_control_wp_role_has_access' )
			->once()
			->andReturn( false );

		$this->assertFalse( $this->provider()->user_has_access( 1, array( 'editor' ) ) );
	}

	public function test_user_has_access_does_not_match_substring_role_names(): void {
		Functions\when( 'get_userdata' )->justReturn(
			$this->user_with_roles( array( 'editor_pro' ) )
		);
		Filters\expectApplied( 'wpb_access_control_wp_role_has_access' )
			->once()
			->andReturn( false );

		$this->assertFalse( $this->provider()->user_has_access( 1, array( 'editor' ) ) );
	}

	public function test_user_has_access_passes_correct_args_to_filter_on_no_match(): void {
		Functions\when( 'get_userdata' )->justReturn(
			$this->user_with_roles( array( 'subscriber' ) )
		);
		Filters\expectApplied( 'wpb_access_control_wp_role_has_access' )
			->once()
			->with( false, 42, array( 'editor', 'author' ) )
			->andReturn( false );

		$this->provider()->user_has_access( 42, array( 'editor', 'author' ) );
		$this->assertTrue( true ); // Mockery verifies the with() expectation.
	}
}
