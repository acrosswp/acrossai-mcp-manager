<?php
/**
 * Settings Renderer utility class.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Admin
 */

namespace ACROSSAI_MCP_MANAGER\Admin;

/**
 * Utility class for rendering configuration.
 *
 * @since 1.0.0
 */
class SettingsRenderer {

	/**
	 * Generate LLM configuration.
	 *
	 * Generates the MCP configuration for LLM clients.
	 *
	 * @since 1.0.0
	 *
	 * @param string $site_url Site URL.
	 *
	 * @return string JSON configuration string.
	 */
	public static function generate_llm_config( $site_url ) {
		// Remove trailing slashes from site URL.
		$site_url = rtrim( $site_url, '/' );

		// Build configuration array.
		$config = array(
			'mcpServers' => array(
				'wordpress' => array(
					'command' => 'npx',
					'args'    => array(
						'-y',
						'@wporg/mcp',
					),
					'env'     => array(
						'WP_URL' => $site_url,
					),
				),
			),
		);

		// Encode as JSON with pretty printing.
		return wp_json_encode(
			$config,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
	}
}
