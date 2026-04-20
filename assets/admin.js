/**
 * AcrossAI MCP Manager Admin JavaScript
 */

(function() {
	'use strict';

	const MCPAdmin = {
		/**
		 * Initialize
		 */
		init() {
			this.setupEventListeners();
			this.setupClipboardButtons();
			this.setupPasswordToggle();
			this.setupGeneratePassword();
			this.loadStoredPasswords();
			this.loadExistingPasswords();
			this.loadClientConfigurations();
		},

		/**
		 * Load existing passwords from REST API
		 */
		loadExistingPasswords() {
			const url = new URL(acrossaiMcpManagerData.rest_url + 'list-app-passwords', window.location.origin);
			url.searchParams.append('_wpnonce', acrossaiMcpManagerData.nonce);

			fetch(url.toString(), {
				method: 'GET',
				credentials: 'same-origin',
			})
			.then(response => response.json())
			.then(data => {
				if (data.success && Array.isArray(data.passwords)) {
					data.passwords.forEach((pwd) => {
						// Extract client type from password name (e.g., "MCP Manager - VS Code" -> "vscode")
						if (pwd.name.includes('VS Code')) {
							this.markPasswordExists('vscode');
						} else if (pwd.name.includes('Claude')) {
							this.markPasswordExists('claude');
						} else if (pwd.name.includes('GitHub Codex')) {
							this.markPasswordExists('codex');
						} else if (pwd.name.includes('Custom')) {
							this.markPasswordExists('custom');
						}
					});
				}
			})
			.catch(err => console.log('Failed to load existing passwords:', err));
		},

		/**
		 * Load client configurations
		 */
		loadClientConfigurations() {
			const clients = ['vscode', 'claude', 'codex', 'chatgpt', 'custom'];
			clients.forEach((clientId) => {
				this.loadClientConfiguration(clientId);
			});
		},

		/**
		 * Load configuration for a specific client
		 */
		loadClientConfiguration(clientId) {
			const url = new URL(acrossaiMcpManagerData.rest_url + 'get-client-config/' + clientId, window.location.origin);
			url.searchParams.append('_wpnonce', acrossaiMcpManagerData.nonce);

			fetch(url.toString(), {
				method: 'GET',
				credentials: 'same-origin',
			})
			.then(response => response.json())
			.then(data => {
				console.log('Config data for ' + clientId + ':', data);
				if (data.success && data.full_config) {
					// Display full configuration with top-level key
					const configJson = document.getElementById('config_json_' + clientId);
					if (configJson) {
						configJson.value = JSON.stringify(data.full_config, null, 2);
					}
					
					// Display config file path
					const configPath = document.getElementById('config_path_' + clientId);
					if (configPath) {
						configPath.textContent = data.config_file_path;
					}
					
					// Display top-level key
					const topLevelKey = document.getElementById('top_level_key_' + clientId);
					if (topLevelKey) {
						topLevelKey.textContent = '"' + data.top_level_key + '"';
					}
				}
			})
			.catch(err => console.log('Failed to load configuration for ' + clientId + ':', err));
		},

		/**
		 * Mark that a password exists for this client
		 */
		markPasswordExists(clientId) {
			const button = document.querySelector(`.generate-app-password[data-client="${clientId}"]`);
			if (button) {
				button.textContent = 'Password Generated ✓ - Generate New';
				button.classList.add('has-password');
			}
		},

		/**
		 * Load any stored passwords from sessionStorage on page load
		 */
		loadStoredPasswords() {
			const clients = ['vscode', 'claude', 'codex', 'chatgpt', 'custom'];
			clients.forEach((clientId) => {
				const password = sessionStorage.getItem(`acrossai_mcp_password_${clientId}`);
				if (password) {
					const passwordField = document.getElementById(`password_${clientId}`);
					if (passwordField) {
						passwordField.value = password;
						this.updateConfig(clientId, password);
					}
				}
			});
		},

		/**
		 * Setup event listeners for tabs
		 */
		setupEventListeners() {
			const tabs = document.querySelectorAll('.mcp-tab');
			tabs.forEach((tab) => {
				tab.addEventListener('click', (e) => {
					e.preventDefault();
					this.selectTab(tab.dataset.tab);
				});
			});
		},

		/**
		 * Select a tab
		 */
		selectTab(tabId) {
			// Hide all tabs
			document.querySelectorAll('.mcp-tab-content').forEach((content) => {
				content.style.display = 'none';
				content.classList.remove('active');
			});

			// Remove active class from all tabs
			document.querySelectorAll('.mcp-tab').forEach((tab) => {
				tab.classList.remove('active');
			});

			// Show selected tab
			const content = document.getElementById(`tab-${tabId}`);
			const tab = document.querySelector(`.mcp-tab[data-tab="${tabId}"]`);
			if (content) {
				content.style.display = 'block';
				content.classList.add('active');
			}
			if (tab) {
				tab.classList.add('active');
			}
		},

		/**
		 * Setup password visibility toggle
		 */
		setupPasswordToggle() {
			const toggles = document.querySelectorAll('.password-toggle');
			toggles.forEach((toggle) => {
				toggle.addEventListener('click', (e) => {
					e.preventDefault();
					const field = document.getElementById(toggle.dataset.target);
					if (field) {
						field.type = field.type === 'password' ? 'text' : 'password';
						toggle.textContent = field.type === 'password' ? '👁️ Show' : '👁️ Hide';
					}
				});
			});
		},

		/**
		 * Setup clipboard buttons
		 */
		setupClipboardButtons() {
			const buttons = document.querySelectorAll('.copy-to-clipboard');
			buttons.forEach((button) => {
				button.addEventListener('click', (e) => {
					e.preventDefault();
					const target = document.getElementById(button.dataset.field);
					if (target && target.value) {
						navigator.clipboard.writeText(target.value).then(() => {
							const originalText = button.textContent;
							button.textContent = '✓ Copied!';
							setTimeout(() => {
								button.textContent = originalText;
							}, 2000);
						});
					}
				});
			});
		},

		/**
		 * Setup password generation
		 */
		setupGeneratePassword() {
			const buttons = document.querySelectorAll('.generate-app-password');
			buttons.forEach((button) => {
				button.addEventListener('click', (e) => {
					e.preventDefault();
					const clientId = button.dataset.client;
					this.generatePassword(clientId);
				});
			});
		},

		/**
		 * Generate password for a client
		 */
		generatePassword(clientId) {
			const url = new URL(acrossaiMcpManagerData.rest_url + 'generate-app-password', window.location.origin);
			url.searchParams.append('_wpnonce', acrossaiMcpManagerData.nonce);

			const data = new URLSearchParams();
			data.append('client', clientId);

			fetch(url.toString(), {
				method: 'POST',
				credentials: 'same-origin',
				body: data,
			})
			.then(response => response.json())
			.then(result => {
				if (result.success && result.password) {
					// Store password in sessionStorage
					sessionStorage.setItem(`acrossai_mcp_password_${clientId}`, result.password);

					// Update password field
					const passwordField = document.getElementById(`password_${clientId}`);
					if (passwordField) {
						passwordField.value = result.password;
						passwordField.type = 'password';
					}

					// Update config with password
					this.updateConfig(clientId, result.password);

					// Update button
					this.markPasswordExists(clientId);

					// Show success message
					alert(result.message);
				} else {
					alert('Error: ' + (result.message || 'Failed to generate password'));
				}
			})
			.catch(err => {
				console.error('Error:', err);
				alert('An error occurred: ' + err.message);
			});
		},

		/**
		 * Update configuration with actual password
		 */
		updateConfig(clientId, password) {
			const configField = document.getElementById(`config_json_${clientId}`);
			if (configField && configField.value) {
				try {
					const config = JSON.parse(configField.value);
					
					// Update password in nested structure based on top-level key
					if (config.servers && config.servers['mcp-wordpress']) {
						config.servers['mcp-wordpress'].env.WP_API_PASSWORD = password;
					} else if (config.mcpServers && config.mcpServers['mcp-wordpress']) {
						config.mcpServers['mcp-wordpress'].env.WP_API_PASSWORD = password;
					}
					
					configField.value = JSON.stringify(config, null, 2);
				} catch (e) {
					console.error('Error parsing config:', e);
				}
			}
		},
	};

	// Initialize when DOM is ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => {
			MCPAdmin.init();
		});
	} else {
		MCPAdmin.init();
	}
})();
