<?php
/**
 * Settings Manager.
 *
 * @package SermonManager\Admin
 */

namespace SermonManager\Admin;

use SermonManager\Plugin;

defined( 'ABSPATH' ) or die;

/**
 * Settings Manager main class.
 *
 * @since 2.16.0
 */
class Settings_Manager {
	/**
	 * Setting pages
	 *
	 * @var array
	 */
	private $settings = array();

	/**
	 * Save admin fields.
	 *
	 * Loops though the Sermon Manager options array and outputs each field.
	 *
	 * @param array $options Options array to output.
	 * @param array $data    Optional. Data to use for saving. Defaults to $_POST.
	 *
	 * @return bool
	 */
	public function save_fields( $options, $data = null ) {
		if ( is_null( $data ) ) {
			$data = $_POST;
		}
		if ( empty( $data ) ) {
			return false;
		}

		// Options to update will be stored here and saved later.
		$update_options = array();

		// Loop options and get values to save.
		foreach ( $options as $option ) {
			if ( ! isset( $option['id'] ) || ! isset( $option['type'] ) ) {
				continue;
			}

			if ( substr( $option['id'], 0, 2 ) === '__' && strlen( $option['id'] ) > 2 ) {
				continue;
			}

			// Get posted value.
			if ( strstr( $option['id'], '[' ) ) {
				parse_str( $option['id'], $option_name_array );
				$option_name  = current( array_keys( $option_name_array ) );
				$setting_name = key( $option_name_array[ $option_name ] );
				$raw_value    = isset( $data[ $option_name ][ $setting_name ] ) ? wp_unslash( $data[ $option_name ][ $setting_name ] ) : null;
			} else {
				$option_name  = $option['id'];
				$setting_name = '';
				$raw_value    = isset( $data[ $option['id'] ] ) ? wp_unslash( $data[ $option['id'] ] ) : null;
			}

			// Format the value based on option type.
			switch ( $option['type'] ) {
				case 'checkbox':
					$value = '1' === $raw_value || 'yes' === $raw_value ? 'yes' : 'no';
					break;
				case 'textarea':
					$value = wp_kses_post( trim( $raw_value ) );
					break;
				case 'multiselect':
					$value = array_filter( array_map( 'sm_clean', (array) $raw_value ) );
					break;
				case 'select':
					if ( '%pages%' === $option['options'] ) {
						$pages             = get_pages();
						$option['options'] = array(
							0 => '-- ' . __( 'None', 'sermon-manager-for-wordpress' ) . ' --',
						);

						foreach ( $pages as $page ) {
							$option['options'][ $page->ID ] = $page->post_title;
						}
					}

					$allowed_values = empty( $option['options'] ) ? array() : array_keys( $option['options'] );
					if ( empty( $option['default'] ) && empty( $allowed_values ) ) {
						$value = null;
						break;
					}
					$default = ( empty( $option['default'] ) ? $allowed_values[0] : $option['default'] );
					$value   = in_array( $raw_value, $allowed_values ) ? $raw_value : $default;
					break;
				default:
					$value = sm_clean( $raw_value );
					break;
			}

			// Execute functions if it has any set.
			if ( isset( $option['callback'] ) ) {
				$fn_value = $this->_execute_functions( $option['callback'], $value, $option );

				$value = null === $fn_value ? $value : $fn_value;
			}

			/**
			 * Sanitize the value of an option.
			 *
			 * @since 2.9
			 */
			$value = apply_filters( 'sm_admin_settings_sanitize_option', $value, $option, $raw_value );

			/**
			 * Sanitize the value of an option by option name.
			 *
			 * @since 2.9
			 */
			$value = apply_filters( "sm_admin_settings_sanitize_option_$option_name", $value, $option, $raw_value );

			if ( is_null( $value ) ) {
				continue;
			}

			// Check if option is an array and handle that differently to single values.
			if ( $option_name && $setting_name ) {
				if ( ! isset( $update_options[ $option_name ] ) ) {
					$update_options[ $option_name ] = get_option( $option_name, array() );
				}
				if ( ! is_array( $update_options[ $option_name ] ) ) {
					$update_options[ $option_name ] = array();
				}
				$update_options[ $option_name ][ $setting_name ] = $value;
			} else {
				$update_options[ $option_name ] = $value;
			}
		}

		// Save all options in our array.
		foreach ( $update_options as $name => $value ) {
			update_option( 'sermonmanager_' . $name, $value );
		}

		return true;
	}

	/**
	 * Executes functions if the option has one assigned.
	 *
	 * @since 2.16.0
	 *
	 * @param string $function The function name.
	 * @param mixed  $value    The option value.
	 * @param string $option   The option name.
	 *
	 * @todo  Add logical error reporting.
	 *
	 * @return bool|mixed False on failure, function value otherwise.
	 */
	private function _execute_functions( $function = '', $value = '', $option = '' ) {
		if ( function_exists( $function ) ) {
			return call_user_func( $function, $value, $option );
		}

		return false;
	}

	/**
	 * Output admin fields.
	 *
	 * Loops though the Sermon Manager options array and outputs each field.
	 *
	 * @param array[] $options Opens array to output.
	 */
	public function output_fields( $options ) {
		foreach ( $options as $value ) {
			if ( ! isset( $value['type'] ) ) {
				continue;
			}
			if ( ! isset( $value['id'] ) ) {
				$value['id'] = '';
			}
			if ( ! isset( $value['title'] ) ) {
				$value['title'] = isset( $value['name'] ) ? $value['name'] : '';
			}
			if ( ! isset( $value['class'] ) ) {
				$value['class'] = '';
			}
			if ( ! isset( $value['css'] ) ) {
				$value['css'] = '';
			}
			if ( ! isset( $value['default'] ) ) {
				$value['default'] = '';
			}
			if ( ! isset( $value['desc'] ) ) {
				$value['desc'] = '';
			}
			if ( ! isset( $value['desc_tip'] ) ) {
				$value['desc_tip'] = false;
			}
			if ( ! isset( $value['placeholder'] ) ) {
				$value['placeholder'] = '';
			}

			// Custom attribute handling.
			$custom_attributes = array();

			if ( ! empty( $value['custom_attributes'] ) && is_array( $value['custom_attributes'] ) ) {
				foreach ( $value['custom_attributes'] as $attribute => $attribute_value ) {
					$custom_attributes[] = esc_attr( $attribute ) . '="' . esc_attr( $attribute_value ) . '"';
				}
			}

			// Get descriptions.
			$field_description = $this->get_field_description( $value );
			$description       = $field_description['description'];
			$tooltip_html      = $field_description['tooltip_html'];

			// Fill out pages for pages selection.
			if ( isset( $value['options'] ) && '%pages%' === $value['options'] ) {
				$pages            = get_pages();
				$value['options'] = array(
					0 => '-- ' . __( 'None', 'sermon-manager-for-wordpress' ) . ' --',
				);

				foreach ( $pages as $page ) {
					$value['options'][ $page->ID ] = $page->post_title;
				}
			}

			// Switch based on type.
			switch ( $value['type'] ) {
				// Section Titles.
				case 'title':
					if ( ! empty( $value['title'] ) ) {
						echo '<h2 class="forminp-title">' . esc_html( $value['title'] ) . '</h2>';
					}
					if ( ! empty( $value['desc'] ) ) {
						echo wpautop( wptexturize( wp_kses_post( $value['desc'] ) ) );
					}
					echo '<table class="form-table">' . "\n\n";
					if ( ! empty( $value['id'] ) ) {
						do_action( 'sm_settings_' . sanitize_title( $value['id'] ) );
					}
					break;

				// Section Ends.
				case 'sectionend':
					if ( ! empty( $value['id'] ) ) {
						do_action( 'sm_settings_' . sanitize_title( $value['id'] ) . '_end' );
					}
					echo '</table>';
					if ( ! empty( $value['id'] ) ) {
						do_action( 'sm_settings_' . sanitize_title( $value['id'] ) . '_after' );
					}
					break;

				// Standard text inputs and subtypes like 'number'.
				case 'text':
				case 'email':
				case 'number':
				case 'password':
					if ( substr( $value['id'], 0, 2 ) === '__' && strlen( $value['id'] ) > 2 ) {
						$option_value = $value['value'];
					} else {
						$option_value = $this->get_option( $value['id'], $value['default'] );
					}

					?>
					<tr valign="top">
						<th scope="row" class="titledesc">
							<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?></label>
							<?php echo $tooltip_html; ?>
						</th>
						<td class="forminp forminp-<?php echo sanitize_title( $value['type'] ); ?>">
							<input
									name="<?php echo esc_attr( $value['id'] ); ?>"
									id="<?php echo esc_attr( $value['id'] ); ?>"
									type="<?php echo esc_attr( $value['type'] ); ?>"
									style="<?php echo esc_attr( $value['css'] ); ?>"
									value="<?php echo esc_attr( $option_value ); ?>"
									class="<?php echo esc_attr( $value['class'] ); ?>"
									placeholder="<?php echo esc_attr( $value['placeholder'] ); ?>"
								<?php echo implode( ' ', $custom_attributes ); ?>
							/> <?php echo $description; ?>
						</td>
					</tr>
					<?php
					break;

				// Color picker.
				case 'color':
					$option_value = $this->get_option( $value['id'], $value['default'] );

					?>
					<tr valign="top">
						<th scope="row" class="titledesc">
							<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?></label>
							<?php echo $tooltip_html; ?>
						</th>
						<td class="forminp forminp-<?php echo sanitize_title( $value['type'] ); ?>">&lrm;
							<span class="colorpickpreview"
									style="background: <?php echo esc_attr( $option_value ); ?>"></span>
							<input
									name="<?php echo esc_attr( $value['id'] ); ?>"
									id="<?php echo esc_attr( $value['id'] ); ?>"
									type="text"
									dir="ltr"
									style="<?php echo esc_attr( $value['css'] ); ?>"
									value="<?php echo esc_attr( $option_value ); ?>"
									class="<?php echo esc_attr( $value['class'] ); ?>colorpick"
									placeholder="<?php echo esc_attr( $value['placeholder'] ); ?>"
								<?php echo implode( ' ', $custom_attributes ); ?>
							/>&lrm; <?php echo $description; ?>
							<div id="colorPickerDiv_<?php echo esc_attr( $value['id'] ); ?>" class="colorpickdiv"
									style="z-index: 100;background:#eee;border:1px solid #ccc;position:absolute;display:none;"></div>
						</td>
					</tr>
					<?php
					break;

				// Textarea.
				case 'textarea':
					$option_value = $this->get_option( $value['id'], $value['default'] );

					?>
					<tr valign="top">
						<th scope="row" class="titledesc">
							<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?></label>
							<?php echo $tooltip_html; ?>
						</th>
						<td class="forminp forminp-<?php echo sanitize_title( $value['type'] ); ?>">
							<?php echo $description; ?>

							<textarea
									name="<?php echo esc_attr( $value['id'] ); ?>"
									id="<?php echo esc_attr( $value['id'] ); ?>"
									style="<?php echo esc_attr( $value['css'] ); ?>"
									class="<?php echo esc_attr( $value['class'] ); ?>"
									placeholder="<?php echo esc_attr( $value['placeholder'] ); ?>"
								<?php echo implode( ' ', $custom_attributes ); ?>
							><?php echo esc_textarea( $option_value ); ?></textarea>
						</td>
					</tr>
					<?php
					break;

				// Select boxes.
				case 'select':
				case 'multiselect':
					$option_value = $this->get_option( $value['id'], $value['default'] );
					?>
					<tr valign="top">
						<th scope="row" class="titledesc">
							<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?></label>
							<?php echo $tooltip_html; ?>
						</th>
						<td class="forminp forminp-<?php echo sanitize_title( $value['type'] ); ?>">
							<select
									name="<?php echo esc_attr( $value['id'] ); ?><?php echo ( 'multiselect' === $value['type'] ) ? '[]' : ''; ?>"
									id="<?php echo esc_attr( $value['id'] ); ?>"
									style="<?php echo esc_attr( $value['css'] ); ?>"
									class="<?php echo esc_attr( $value['class'] ); ?>"
								<?php echo implode( ' ', $custom_attributes ); ?>
								<?php echo ( 'multiselect' == $value['type'] ) ? 'multiple="multiple"' : ''; ?>
							>
								<?php
								foreach ( $value['options'] as $key => $val ) {
									?>
									<option value="<?php echo esc_attr( $key ); ?>"
										<?php

										if ( is_array( $option_value ) ) {
											selected( in_array( $key, $option_value ), true );
										} else {
											selected( $option_value, $key );
										}

										?>
									><?php echo $val; ?></option>
									<?php
								}
								?>
							</select> <?php echo $description; ?>
						</td>
					</tr>
					<?php
					break;

				// Radio inputs.
				case 'radio':
					$option_value = $this->get_option( $value['id'], $value['default'] );

					?>
					<tr valign="top">
						<th scope="row" class="titledesc">
							<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?></label>
							<?php echo $tooltip_html; ?>
						</th>
						<td class="forminp forminp-<?php echo sanitize_title( $value['type'] ); ?>">
							<fieldset>
								<?php echo $description; ?>
								<ul>
									<?php
									foreach ( $value['options'] as $key => $val ) {
										?>
										<li>
											<label>
												<input
														name="<?php echo esc_attr( $value['id'] ); ?>"
														value="<?php echo $key; ?>"
														type="radio"
														style="<?php echo esc_attr( $value['css'] ); ?>"
														class="<?php echo esc_attr( $value['class'] ); ?>"
													<?php echo implode( ' ', $custom_attributes ); ?>
													<?php checked( $key, $option_value ); ?>
												/> <?php echo $val; ?>
											</label>
										</li>
										<?php
									}
									?>
								</ul>
							</fieldset>
						</td>
					</tr>
					<?php
					break;

				// Checkbox input.
				case 'checkbox':
					$option_value = is_bool( $this->get_option( $value['id'], $value['default'] ) ) ? ( $this->get_option( $value['id'], $value['default'] ) ? 'yes' : 'no' ) : $this->get_option( $value['id'], $value['default'] );

					$visbility_class = array();
					if ( ! isset( $value['hide_if_checked'] ) ) {
						$value['hide_if_checked'] = false;
					}
					if ( ! isset( $value['show_if_checked'] ) ) {
						$value['show_if_checked'] = false;
					}
					if ( 'yes' == $value['hide_if_checked'] || 'yes' == $value['show_if_checked'] ) {
						$visbility_class[] = 'hidden_option';
					}
					if ( 'option' == $value['hide_if_checked'] ) {
						$visbility_class[] = 'hide_options_if_checked';
					}
					if ( 'option' == $value['show_if_checked'] ) {
						$visbility_class[] = 'show_options_if_checked';
					}
					?>
					<tr valign="top" class="<?php echo esc_attr( implode( ' ', $visbility_class ) ); ?>">
						<!--suppress XmlDefaultAttributeValue -->
						<th scope="row" class="titledesc"><?php echo esc_html( $value['title'] ); ?></th>
						<td class="forminp forminp-checkbox">
							<fieldset>
								<?php

								if ( ! empty( $value['title'] ) ) {
									?>
									<legend class="screen-reader-text">
										<span><?php echo esc_html( $value['title'] ); ?></span>
									</legend>
									<?php
								}
								?>
								<label for="<?php echo $value['id']; ?>">
									<input
											name="<?php echo esc_attr( $value['id'] ); ?>"
											id="<?php echo esc_attr( $value['id'] ); ?>"
											type="checkbox"
											class="<?php echo esc_attr( isset( $value['class'] ) ? $value['class'] : '' ); ?>"
											value="1"
										<?php checked( $option_value, 'yes' ); ?>
										<?php echo implode( ' ', $custom_attributes ); ?>
									/> <?php echo $description; ?>
								</label> <?php echo $tooltip_html; ?>
							</fieldset>
						</td>
					</tr>
					<?php
					break;

				// Image upload select.
				case 'image':
					$option_value = $this->get_option( $value['id'], $value['default'] );

					?>
					<tr valign="top">
						<!--suppress XmlDefaultAttributeValue -->
						<th scope="row" class="titledesc">
							<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?></label>
							<?php echo $tooltip_html; ?>
						</th>
						<td class="forminp forminp-<?php echo sanitize_title( $value['type'] ); ?>">
							<div class="image-picker-form-container">
								<input
										name="<?php echo esc_attr( $value['id'] ); ?>"
										id="<?php echo esc_attr( $value['id'] ); ?>"
										type="text"
										style="<?php echo esc_attr( $value['css'] ); ?>"
										value="<?php echo esc_attr( $option_value ); ?>"
										class="<?php echo esc_attr( $value['class'] ); ?>"
										placeholder="<?php echo esc_attr( $value['placeholder'] ); ?>"
									<?php echo implode( ' ', $custom_attributes ); ?>
								/>
								<a
										id="upload_<?php echo esc_attr( $value['id'] ); ?>"
										href="#"
										class="button upload-image"
										title="Choose Default Image">
									<img
											src="<?php echo admin_url( 'images/media-button.png' ); ?>"
											width="15"
											height="15"
											class="upload_image_button"
									/>
									&nbsp;Upload Image
								</a>
							</div>
							<?php echo $description; ?>
							<div id="default-image-thumb-load" style="width: 250px;">
								<br/>
								<?php if ( ! empty( $option_value ) ) : ?>
									<img style="width: inherit;"
											src="<?php echo esc_attr( $option_value ); ?>"
											alt="default image"
									/>
								<?php endif; ?>
							</div>
						</td>
					</tr>
					<?php
					break;
				case 'description':
					?>
					<tr valign="top">
						<td class="forminp forminp-<?php echo sanitize_title( $value['type'] ); ?>" colspan="2">
							<p><?php echo $value['desc']; ?></p>
						</td>
					</tr>
					<?php
					break;
				case 'separator':
					?>
					<tr valign="top">
						<td class="forminp forminp-<?php echo sanitize_title( $value['type'] ); ?>" colspan="2">
							<hr/>
						</td>
					</tr>
					<?php
					break;
				case 'separator_title':
					?>
					<tr valign="top">
						<td class="forminp forminp-<?php echo sanitize_title( $value['type'] ); ?>" colspan="2">
							<h2><?php echo esc_html( $value['title'] ); ?></h2>
						</td>
					</tr>
					<?php
					break;
				// Default: run an action.
				default:
					do_action( 'sm_admin_field_' . $value['type'], $value );
					break;
			}
		}
	}

	/**
	 * Helper function to get the formatted description and tip HTML for a
	 * given form field
	 *
	 * @param  array $value The form field value array.
	 *
	 * @return array The description and tip as a 2 element array
	 */
	public function get_field_description( $value ) {
		$description  = '';
		$tooltip_html = '';

		if ( true === $value['desc_tip'] ) {
			$tooltip_html = $value['desc'];
		} elseif ( ! empty( $value['desc_tip'] ) ) {
			$description  = $value['desc'];
			$tooltip_html = $value['desc_tip'];
		} elseif ( ! empty( $value['desc'] ) ) {
			$description = $value['desc'];
		}

		if ( $description && in_array( $value['type'], array( 'textarea', 'radio' ) ) ) {
			$description = '<p style="margin-top:0">' . wp_kses_post( $description ) . '</p>';
		} elseif ( $description && in_array( $value['type'], array( 'checkbox' ) ) ) {
			$description = wp_kses_post( $description );
		} elseif ( $description && in_array( $value['type'], array( 'select', 'multiselect' ) ) ) {
			$description = '<p class="description">' . $description . '</p>';
		} elseif ( $description ) {
			$description = '<span class="description">' . wp_kses_post( $description ) . '</span>';
		}

		if ( $tooltip_html && in_array( $value['type'], array( 'checkbox' ) ) ) {
			$tooltip_html = '<p class="description">' . $tooltip_html . '</p>';
		} elseif ( $tooltip_html ) {
			$tooltip_html = sm_help_tip( $tooltip_html );
		}

		return array(
			'description'  => $description,
			'tooltip_html' => $tooltip_html,
		);
	}

	/**
	 * Get a setting from the settings API.
	 *
	 * @param string $option_name The option name.
	 * @param mixed  $default     The default value to return. Default empty string.
	 *
	 * @return mixed
	 */
	public function get_option( $option_name, $default = '' ) {
		// Check if it is an array value.
		if ( strstr( $option_name, '[' ) ) {

			parse_str( $option_name, $option_array );

			// Option name is first key.
			$option_name = current( array_keys( $option_array ) );

			// Get value.
			$option_values = get_option( 'sermonmanager_' . $option_name, '' );

			$key = key( $option_array[ $option_name ] );

			if ( isset( $option_values[ $key ] ) ) {
				$option_value = $option_values[ $key ];
			} else {
				$option_value = null;
			}
		} else {
			// Single value.
			$option_value = get_option( 'sermonmanager_' . $option_name, null );
		}

		if ( is_array( $option_value ) ) {
			$option_value = array_map( 'stripslashes', $option_value );
		} elseif ( ! is_null( $option_value ) ) {
			$option_value = stripslashes( $option_value );
		}

		if ( 'no' === $option_value || 'yes' === $option_value ) {
			$option_value = 'yes' === $option_value ? true : false;
		}

		return ( null === $option_value ) ? $default : $option_value;
	}

	/**
	 * Do stuff before the page load.
	 *
	 * @since 2.16.0
	 */
	public function __construct() {
		add_action( 'current_screen', function () {
			global $current_section, $current_tab;

			$screen    = \get_current_screen();
			$screen_id = isset( $screen->id ) ? $screen->id : '';

			if ( 'wpfc_sermon_page_sm-settings' === $screen_id ) {
				wp_enqueue_media();

				// Save settings if data has been posted.
				if ( ! empty( $_POST ) ) {
					$this->save_settings();
				}

				// Add any posted messages.
				if ( ! empty( $_GET['sm_error'] ) ) {
					Plugin::instance()->notices_manager->add_error( stripslashes( $_GET['sm_error'] ), 'settings', false );
				}

				if ( ! empty( $_GET['sm_message'] ) ) {
					Plugin::instance()->notices_manager->add_info( stripslashes( $_GET['sm_message'] ), 'settings', false );
				}
			}

			// Get current tab/section.
			$current_tab     = empty( $_GET['tab'] ) ? 'general' : sanitize_title( $_GET['tab'] );
			$current_section = empty( $_REQUEST['section'] ) ? '' : sanitize_title( $_REQUEST['section'] );

			switch ( $current_tab ) {
				case 'podcast':
					wp_enqueue_script( 'sm_settings_podcast' ); // todo: i18n the script & make it more dynamic.
					break;
				case 'verse':
					wp_enqueue_script( 'sm_settings_verse' );
					break;
			}
		} );
	}

	/**
	 * Settings page.
	 *
	 * Handles the display of the main Sermon Manager settings page in admin.
	 */
	public function output() {
		do_action( 'sm_settings_start' );

		// Include settings pages.
		$this->get_settings_pages();

		// Get tabs for the settings page.
		/* @noinspection PhpUnusedLocalVariableInspection */
		$tabs = apply_filters( 'sm_settings_tabs_array', array() );

		include 'views/html-admin-settings.php';
	}

	/**
	 * Include the settings page classes.
	 */
	public function get_settings_pages() {
		if ( empty( $this->settings ) ) {
			$settings = array();

			include_once 'settings/class-sm-settings-page.php';

			$settings[] = include 'settings/class-sm-settings-general.php';
			$settings[] = include 'settings/class-sm-settings-display.php';
			$settings[] = include 'settings/class-sm-settings-podcast.php';
			$settings[] = include 'settings/class-sm-settings-verse.php';
			$settings[] = include 'settings/class-sm-settings-debug.php';

			/**
			 * Allows you to include additional tabs in settings.
			 *
			 * @param array $settings Existing settings pages.
			 *
			 * @since 2.9.0
			 */
			$this->settings = apply_filters( 'sm_get_settings_pages', $settings );
		}

		return $this->settings;
	}

	/**
	 * Save the settings.
	 */
	public function save_settings() {
		global $current_tab, $wpdb;

		if ( empty( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'sm-settings' ) ) {
			die( __( 'Action failed. Please refresh the page and retry.', 'sermon-manager-for-wordpress' ) );
		}

		/**
		 * Flush rewrite rules on archive page slug change.
		 */
		if ( 'general' === $current_tab && $this->get_option( 'archive_slug' ) !== $_POST['archive_slug'] ) {
			flush_rewrite_rules( true );
		}

		// Trigger actions.
		do_action( 'sm_settings_save_' . $current_tab );
		do_action( 'sn_update_options_' . $current_tab );
		do_action( 'sm_update_options' );

		Plugin::instance()->notices_manager->add_success( __( 'Your settings have been saved.', 'sermon-manager-for-wordpress' ), 'settings', false );

		// Clear any unwanted data and flush rules.
		wp_schedule_single_event( time(), 'sm_flush_rewrite_rules' );

		/**
		 * Pass any false value to `sm_clear_feed_transients` filter to skip clearing transients.
		 */
		if ( 'podcast' === $current_tab && apply_filters( 'sm_clear_feed_transients', true ) ) {
			$wpdb->query( "DELETE FROM `$wpdb->options` WHERE `option_name` LIKE ('_transient_feed_%') OR `option_name` LIKE ('_transient_timeout_feed_%')" );
		}

		do_action( 'sm_settings_saved' );
	}
}
