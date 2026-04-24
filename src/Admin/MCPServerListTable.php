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

use ACROSSAI_MCP_MANAGER\Database\MCPServerTable;

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
			'source'      => __( 'Source', 'acrossai-mcp-manager' ),
			'status'      => __( 'Status', 'acrossai-mcp-manager' ),
			'actions'     => __( 'Actions', 'acrossai-mcp-manager' ),
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

		$rows = MCPServerTable::get_all();

		$this->items = array_map(
			function ( $row ) {
				return array(
					'id'              => (int) $row['id'],
					'name'            => $row['server_name'],
					'description'     => $row['description'],
					'enabled'         => (bool) $row['is_enabled'],
					'registered_from' => $row['registered_from'] ?? 'plugin',
				);
			},
			$rows
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

		// Delete action only available for database-registered servers.
		if ( 'database' === $item['registered_from'] ) {
			$delete_url = add_query_arg(
				array(
					'page'     => 'acrossai_mcp_manager',
					'action'   => 'delete',
					'server'   => $item['id'],
					'_wpnonce' => wp_create_nonce( 'acrossai_mcp_delete_' . $item['id'] ),
				),
				admin_url( 'admin.php' )
			);

			$row_actions['delete'] = sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\')">%s</a>',
				esc_url( $delete_url ),
				esc_js( __( 'Are you sure you want to delete this server? This cannot be undone.', 'acrossai-mcp-manager' ) ),
				__( 'Delete', 'acrossai-mcp-manager' )
			);
		}

		return sprintf(
			'<strong><a class="row-title" href="%s">%s</a></strong>%s',
			esc_url( $edit_url ),
			esc_html( $item['name'] ),
			$this->row_actions( $row_actions )
		);
	}

	/**
	 * Render the source column — badge showing where the server was registered from.
	 *
	 * @since 1.2.0
	 *
	 * @param array $item Row data.
	 *
	 * @return string
	 */
	public function column_source( $item ) {
		$source = $item['registered_from'] ?? 'plugin';

		$labels = array(
			'plugin'   => __( 'Plugin', 'acrossai-mcp-manager' ),
			'database' => __( 'Database', 'acrossai-mcp-manager' ),
			'theme'    => __( 'Theme', 'acrossai-mcp-manager' ),
			'core'     => __( 'Core', 'acrossai-mcp-manager' ),
		);

		$label = isset( $labels[ $source ] ) ? $labels[ $source ] : esc_html( $source );
		$class = 'acrossai-source-badge acrossai-source-' . sanitize_html_class( $source );

		return sprintf( '<span class="%s">%s</span>', esc_attr( $class ), esc_html( $label ) );
	}

	/**
	 * Render the status column — badge only (Active / Inactive).
	 *
	 * @since 1.0.0
	 *
	 * @param array $item Row data.
	 *
	 * @return string
	 */
	public function column_status( $item ) {
		if ( $item['enabled'] ) {
			return '<span class="acrossai-status-badge acrossai-status-active">' . esc_html__( 'Active', 'acrossai-mcp-manager' ) . '</span>';
		}

		return '<span class="acrossai-status-badge acrossai-status-inactive">' . esc_html__( 'Inactive', 'acrossai-mcp-manager' ) . '</span>';
	}

	/**
	 * Render the actions column — Enable / Disable button.
	 *
	 * @since 1.0.0
	 *
	 * @param array $item Row data.
	 *
	 * @return string
	 */
	public function column_actions( $item ) {
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

		if ( $item['enabled'] ) {
			return sprintf(
				'<a href="%s" class="button button-small acrossai-btn-disable">%s</a>',
				esc_url( $toggle_url ),
				esc_html__( 'Disable', 'acrossai-mcp-manager' )
			);
		}

		return sprintf(
			'<a href="%s" class="button button-small button-primary acrossai-btn-enable">%s</a>',
			esc_url( $toggle_url ),
			esc_html__( 'Enable', 'acrossai-mcp-manager' )
		);
	}
}
