<?php
/**
 * MCP Server List Table.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Admin
 */

namespace ACROSSAI_MCP_MANAGER\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Displays MCP servers in a standard WordPress list table.
 *
 * @since 1.0.0
 */
class MCPServerListTable extends \WP_List_Table {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'mcp_server',
				'plural'   => 'mcp_servers',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Define table columns.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'name'        => __( 'Server Name', 'acrossai-mcp-manager' ),
			'description' => __( 'Description', 'acrossai-mcp-manager' ),
			'status'      => __( 'Status', 'acrossai-mcp-manager' ),
		);
	}

	/**
	 * Prepare items for display.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$enabled = (bool) get_option( 'acrossai_mcp_manager_enabled', false );

		$this->items = array(
			array(
				'id'          => 'default',
				'name'        => __( 'Default MCP Server', 'acrossai-mcp-manager' ),
				'description' => __( 'WordPress MCP Adapter integration for AI clients (VS Code, Claude, GitHub Codex, ChatGPT).', 'acrossai-mcp-manager' ),
				'enabled'     => $enabled,
			),
		);
	}

	/**
	 * Default column renderer.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $item        Row data.
	 * @param string $column_name Column key.
	 *
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		if ( 'description' === $column_name ) {
			return esc_html( $item['description'] );
		}
		return '';
	}

	/**
	 * Render the name column with row actions.
	 *
	 * @since 1.0.0
	 *
	 * @param array $item Row data.
	 *
	 * @return string
	 */
	public function column_name( $item ) {
		$edit_url = add_query_arg(
			array(
				'page'   => 'acrossai_mcp_manager',
				'action' => 'edit',
				'server' => $item['id'],
			),
			admin_url( 'admin.php' )
		);

		$row_actions = array(
			'edit' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $edit_url ),
				__( 'Edit MCP', 'acrossai-mcp-manager' )
			),
		);

		return sprintf(
			'<strong><a class="row-title" href="%s">%s</a></strong>%s',
			esc_url( $edit_url ),
			esc_html( $item['name'] ),
			$this->row_actions( $row_actions )
		);
	}

	/**
	 * Render the status column with enable/disable toggle.
	 *
	 * @since 1.0.0
	 *
	 * @param array $item Row data.
	 *
	 * @return string
	 */
	public function column_status( $item ) {
		$enabled    = $item['enabled'];
		$nonce      = wp_create_nonce( 'acrossai_mcp_toggle_' . $item['id'] );
		$toggle_url = add_query_arg(
			array(
				'page'     => 'acrossai_mcp_manager',
				'action'   => 'toggle_status',
				'server'   => $item['id'],
				'_wpnonce' => $nonce,
			),
			admin_url( 'admin.php' )
		);

		if ( $enabled ) {
			$badge_html   = '<span class="acrossai-status-badge acrossai-status-active">' . esc_html__( 'Active', 'acrossai-mcp-manager' ) . '</span>';
			$button_html  = sprintf(
				'<a href="%s" class="button button-small acrossai-btn-disable">%s</a>',
				esc_url( $toggle_url ),
				esc_html__( 'Disable', 'acrossai-mcp-manager' )
			);
		} else {
			$badge_html   = '<span class="acrossai-status-badge acrossai-status-inactive">' . esc_html__( 'Inactive', 'acrossai-mcp-manager' ) . '</span>';
			$button_html  = sprintf(
				'<a href="%s" class="button button-small button-primary acrossai-btn-enable">%s</a>',
				esc_url( $toggle_url ),
				esc_html__( 'Enable', 'acrossai-mcp-manager' )
			);
		}

		return $badge_html . '&nbsp;&nbsp;' . $button_html;
	}
}
