<?php

namespace CLead;

use Carbon_Fields\Container;
use Carbon_Fields\Field;
use IARAI\Logging;

class Plugin {


	public function __construct() {

		new Crons();
		new Terms();
        new Submissions();

		add_action( 'init', [ $this, 'init' ] );
		add_action( 'plugins_loaded', [ $this, 'plugins_loaded' ] );

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

		add_action( 'wp_ajax_iarai_filter_leaderboard', [ $this, 'ajax_filter_leaderboard' ] );
		add_action( 'wp_ajax_nopriv_iarai_filter_leaderboard', [ $this, 'ajax_filter_leaderboard' ] );

		add_action( 'posts_where', function ( $where, $wp_query ) {
			global $wpdb;
			if ( $search_term = $wp_query->get( '_title_filter' ) ) {
				$search_term           = $wpdb->esc_like( $search_term );
				$search_term           = ' \'%' . $search_term . '%\'';
				$title_filter_relation = ( strtoupper( $wp_query->get( '_title_filter_relation' ) ) === 'OR' ? 'OR' : 'AND' );
				$where                 .= ' ' . $title_filter_relation . ' ' . $wpdb->posts . '.post_title LIKE ' . $search_term;
			}

			return $where;
		}, 10, 2 );



		// create submissions post type. Private, not public
		// create taxonomy competitions. Private, not public
		add_action( 'init', [ $this, 'register_post_types' ] );

		// remove the html filtering
		remove_filter( 'pre_term_description', 'wp_filter_kses' );
		remove_filter( 'term_description', 'wp_kses_data' );

		// add wysiwyg description
		add_action( 'admin_head', [ $this, 'remove_default_category_description' ] );
		//add_action( 'competition_add_form_fields', [ $this, 'competition_display_meta' ] );
		//add_action( 'competition_edit_form_fields', [ $this, 'competition_display_meta' ] );

		/* Metabox */
		add_action( 'add_meta_boxes', [ $this, 'register_meta_boxes' ] );
		add_action( 'plugins_loaded', [ $this, 'register_custom_fields' ], 12 );

		add_filter( 'template_include', [ $this, 'research_display_type_template' ], 99 );

		// Export CSV
		add_action( 'admin_init', [ $this, 'export_csv' ] );

		// Email custom column
		add_filter( 'manage_edit-submission_columns', [ $this, 'custom_add_new_columns' ] );
		add_action( 'manage_submission_posts_custom_column', [ $this, 'custom_manage_new_columns' ], 10, 2 );

		// Show the taxonomy ID
		add_filter( "manage_edit-competition_columns", [ $this, 'add_tax_col' ] );
		add_filter( "manage_edit-competition_sortable_columns", [ $this, 'add_tax_col' ] );
		add_filter( "manage_competition_custom_column", [ $this, 'show_tax_id' ], 10, 3 );

		add_action( 'rest_api_init', function () {
			register_rest_route( 'competition/v1', '/main', array(
				'methods'  => 'GET',
				'callback' => [ $this, 'api_get_main_competition' ],
			) );
		} );
	}

	public function api_get_main_competition() {

		$terms = get_terms( array(
			'taxonomy'   => 'competition',
			'hide_empty' => false,
			'meta_key'   => '_competition_is_main',
			'meta_value' => 'yes'
		) );

		if ( empty( $terms ) ) {
			return [];
		}

		$competition = $terms[0];
		$data        = [];

		$challenges = get_terms( array(
			'taxonomy'   => 'competition',
			'hide_empty' => false,
			'parent'     => $competition->term_id

		) );

		$main_fields = [
			'competition_logo',
			'competition_main_bg_image',
			'competition_main_short_description',
			'competition_main_image',

		];

		$data['main'] = [];

		foreach ( $main_fields as $field ) {
			$data['main'][ $field ] = get_term_meta( $competition->term_id, '_' . $field, true );
		}

		$data['challenge'] = $challenges;
		$data['events']    = [];
		$data['connect']   = [];

		return $data;
	}

	public function plugins_loaded() {
		\Carbon_Fields\Carbon_Fields::boot();
	}

	public function init() {

		add_shortcode( 'iarai_submission_form', [ $this, 'shortcode_submission' ] );
		add_shortcode( 'iarai_leaderboard', [ $this, 'shortcode_leaderboard' ] );
	}

	public function add_tax_col( $new_columns ) {
		$new_columns['tax_id'] = 'ID';

		return $new_columns;
	}

	public function show_tax_id( $value, $name, $id ) {
		return 'tax_id' === $name ? $id : $value;
	}

	/**
	 * Enable leaderboard option Y/N
	 * Require file upload option Y/N
	 *
	 */
	public function register_custom_fields() {

		if ( class_exists( '\Carbon_Fields\Container' ) ) {

			$log_tag_options = function () {
				$data  = [ '' => '--Select tag--' ];
				$terms = get_terms(
					[
						'taxonomy'   => 'wp_log_type',
						'hide_empty' => false,
					]
				);
				if ( $terms ) {
					foreach ( $terms as $term ) {
						$data[ $term->slug ] = $term->name;
					}
				}

				return $data;
			};

			$is_type_challenge = array(
				'field'   => 'competition_type',
				'value'   => 'challenge',
				'compare' => '=',
			);


			$is_type_competition = array(
				'field'   => 'competition_type',
				'value'   => 'competition',
				'compare' => '=',
			);


			// TODO move challenge to competition fields
			// TODO Access public

			$competition_fields =
				Container::make( 'term_meta', __( 'Term Options', 'competitions-leaderboard' ) )
				         ->where( 'term_taxonomy', '=', 'competition' );

			$competition_fields
				->add_tab(
					__( 'Main' ),
					array(
//						Field::make( 'radio', 'competition_type', 'Type' )
//						     ->add_options( array(
//							     'competition' => 'Competition',
//							     'challenge'   => 'Challenge',
//
//						     ) ),
						Field::make( 'checkbox', 'competition_is_main', 'Main competition' )
						     ->set_help_text( 'Is this the current main competition?' )
						     ->set_conditional_logic( array(
							     $is_type_competition
						     ) ),

						Field::make( 'image', 'competition_logo', 'Logo' )
						     ->set_value_type( 'url' )
						     ->set_conditional_logic( array(
							     $is_type_competition
						     ) ),
						Field::make( 'image', 'competition_main_bg_image', 'Background image' )
						     ->set_value_type( 'url' )
						     ->set_conditional_logic( array(
							     $is_type_competition
						     ) ),

						Field::make( 'rich_text', 'competition_main_short_description', 'Short description' )
						     ->set_conditional_logic( array(
							     $is_type_competition
						     ) ),
						Field::make( 'image', 'competition_main_image', 'Main image' )
						     ->set_value_type( 'url' )
						     ->set_conditional_logic( array(
							     $is_type_competition
						     ) ),
						Field::make( 'text', 'competition_main_bullet_point1', 'Bullet point 1' )
						     ->set_conditional_logic( array(
							     $is_type_competition
						     ) ),
						Field::make( 'text', 'competition_main_bullet_point2', 'Bullet point 2' )
						     ->set_conditional_logic( array(
							     $is_type_competition
						     ) ),
						Field::make( 'text', 'competition_main_bullet_point3', 'Bullet point 3' )
						     ->set_conditional_logic( array(
							     $is_type_competition
						     ) ),
						Field::make( 'text', 'competition_main_video', 'Video' )
						     ->set_conditional_logic( array(
							     $is_type_competition
						     ) ),

					) )
				->add_tab(
					__( 'Data' ),
					array(
						Field::make( 'rich_text', 'competition_data_description', 'Data description' )
						     ->set_conditional_logic( array(
							     $is_type_competition
						     ) ),
						Field::make( 'text', 'competition_data_link', 'Get Data URL' )
						     ->set_attribute( 'type', 'url' )
						     ->set_conditional_logic( array(
							     $is_type_competition
						     ) ),
						Field::make( 'text', 'competition_data_github', 'Github URL' )
						     ->set_attribute( 'type', 'url' )
						     ->set_conditional_logic( array(
							     $is_type_competition
						     ) ),

					) )
				->add_tab(
					__( 'Challenge' ),
					array(
						Field::make( 'text', 'competition_challenge_name', 'Challenge name' ),
						Field::make( 'rich_text', 'competition_challenge_description', 'Challenge description' ),

					) )
				->add_tab(
					__( 'Timeline' ),
					array(
						Field::make( 'date', 'competition_challenge_timeline_1', 'Submission to leaderboard' ),
						Field::make( 'date', 'competition_challenge_timeline_2', 'Abstract and code sub.' ),
						Field::make( 'date', 'competition_challenge_timeline_3', 'Prerecorded presentation sub.' ),
						Field::make( 'date', 'competition_challenge_timeline_4', 'Announcement of the winners' ),

					) )
				->add_tab(
					__( 'Leaderboard' ),
					array(
						Field::make( 'select', 'competition_leaderboard', 'Enable Leaderboard' )
						     ->add_options( array(
							     'yes'    => 'Yes',
							     'editor' => 'Just for site Editors and Admins',
							     'no'     => 'No',
						     ) ),
						Field::make( 'select', 'enable_submissions', 'Enable Submissions' )
						     ->add_options( array(
							     'yes'    => 'Yes',
							     'editor' => 'Just for site Editors and Admins',
							     'guests' => 'Also for Guests',
							     'no'     => 'No',
						     ) ),
						Field::make( 'text', 'competition_limit_submit', 'Limit submissions number' )
						     ->set_attribute( 'type', 'number' )
						     ->set_conditional_logic( array(
							     array(
								     'field'   => 'enable_submissions',
								     'value'   => 'no',
								     'compare' => '!=',
							     )
						     ) ),
						Field::make( 'text', 'competition_file_types', 'Allow specific file types' )
						     ->set_help_text( 'Comma separated allowed file extensions(Ex: jpg,png,gif,pdf)' )
						     ->set_conditional_logic( array(
							     array(
								     'field'   => 'enable_submissions',
								     'value'   => 'no',
								     'compare' => '!=',
							     )
						     ) ),
						Field::make( 'select', 'enable_submission_deletion', 'Enable Submission Deletion' )
						     ->add_options( array(
							     'yes'    => 'Yes',
							     'editor' => 'Just for site Editors and Admins',
							     'no'     => 'No',
						     ) )
						     ->set_conditional_logic( array(
							     array(
								     'field'   => 'enable_submissions',
								     'value'   => 'no',
								     'compare' => '!=',
							     )
						     ) ),
						Field::make( 'date', 'competition_start_date', 'Competition Start Date' ),
						Field::make( 'date', 'competition_end_date', 'Competition End Date' ),
						Field::make( 'select', 'competition_log_tag' )
						     ->add_options( $log_tag_options ),
						Field::make( 'select', 'competition_stats_type', 'Download Statistics Method' )
						     ->add_options( array(
							     'local'     => 'Local Log',
							     'analytics' => 'Google Analytics',
						     ) ),
						Field::make( 'text', 'competition_google_label', 'Analytics Event Label' )
						     ->set_conditional_logic( array(
							     array(
								     'field'   => 'competition_stats_type',
								     'value'   => 'analytics',
								     'compare' => '=',
							     )
						     ) ),
						/*Field::make( 'text', 'competition_score_decimals', 'Score decimals' )
							 ->set_attribute( 'type', 'number' )*/
						Field::make( 'select', 'competition_score_sort', 'Leaderboard Score Sorting' )
						     ->add_options( array(
							     'asc'  => 'Ascending',
							     'desc' => 'Descending',
							     'abs'  => 'Absolute Zero',
						     ) ),
						Field::make( 'select', 'competition_cron_frequency', "Cron Frequency" )
						     ->add_options( [
							     '10' => '10 minutes',
							     '20' => '20 minutes',
							     '30' => '30 minutes'
						     ] ),
					) )
				->add_tab(
					__( 'Prizes' ),
					array(
						Field::make( 'complex', 'competition_prizes', 'Awards' )
						     ->set_layout( 'tabbed-horizontal' )
						     ->set_min( 1 )
						     ->set_max( 3 )
						     ->add_fields( array(
							     Field::make( 'text', 'prize', 'Prize' ),
						     ) ),
					) )
				->add_tab(
					__( 'Awards' ),
					array(
						Field::make( 'complex', 'competition_awards', 'Awards' )
						     ->set_layout( 'tabbed-horizontal' )
						     ->set_min( 1 )
						     ->set_max( 3 )
						     ->add_fields( array(
							     Field::make( 'text', 'team_name', 'Team Name' ),
							     Field::make( 'text', 'team_members', 'Team members' ),
							     Field::make( 'text', 'affiliations', 'Affiliations' ),
							     Field::make( 'text', 'award', 'Award' ),
						     ) ),
						Field::make( 'complex', 'competition_special_prizes', 'Special Prizes' )
						     ->set_layout( 'tabbed-horizontal' )
						     ->set_min( 1 )
						     ->set_max( 3 )
						     ->add_fields( array(
							     Field::make( 'text', 'title', 'Title' ),
							     Field::make( 'text', 'team_name', 'Team Name' ),
							     Field::make( 'text', 'affiliations', 'Affiliations' ),
							     Field::make( 'text', 'award', 'Award' ),
						     ) ),
					) )
				->add_tab(
					__( 'Connect' ),
					array(
						Field::make( 'text', 'competition_connect_forum', 'Forum link' )
						     ->set_conditional_logic( array(
							     $is_type_competition
						     ) ),
						Field::make( 'text', 'competition_connect_github', 'Github link' )
						     ->set_conditional_logic( array(
							     $is_type_competition
						     ) ),
						Field::make( 'complex', 'competition_connect_scientific_committee', 'Scientific Committee' )
						     ->set_layout( 'tabbed-horizontal' )
						     ->set_min( 1 )
							//->set_max( 3 )
							 ->add_fields( array(
								Field::make( 'text', 'name', 'Name' ),
								Field::make( 'image', 'image', 'Image' )
								     ->set_value_type( 'url' ),
								Field::make( 'text', 'description', 'Description' ),
							) )
						     ->set_conditional_logic( array(
							     $is_type_competition
						     ) ),
						Field::make( 'complex', 'competition_connect_organising_committee', 'Organising Committee' )
						     ->set_layout( 'tabbed-horizontal' )
						     ->set_min( 1 )
							//->set_max( 3 )
							 ->add_fields( array(
								Field::make( 'text', 'name', 'Name' ),
								Field::make( 'image', 'image', 'Image' )
								     ->set_value_type( 'url' ),
								Field::make( 'text', 'description', 'Description' ),
							) )
						     ->set_conditional_logic( array(
							     $is_type_competition
						     ) ),
						Field::make( 'text', 'competition_connect_contact', 'Contact email' )
						     ->set_conditional_logic( array(
							     $is_type_competition
						     ) ),
						Field::make( 'text', 'competition_connect_address', 'Contact Address' )
						     ->set_conditional_logic( array(
							     $is_type_competition
						     ) ),
					) )
				->add_tab(
					__( 'Deprecated' ),
					array(
						Field::make( 'rich_text', 'competition_pre_text', 'Before Text(Deprecated)' ),
					) );

		}
	}

	public function register_meta_boxes() {

		// Moderators
		add_meta_box(
			'submission_files_metabox',
			__( 'Submission info', 'bbpress' ),
			[ $this, 'file_info_metabox' ],
			'submission',
			'advanced',
			'default'
		);
	}

	/**
	 * @param \WP_Post $post
	 */
	public function file_info_metabox( $post ) {

		$score = self::get_score_number( $post->ID );
		if ( ! $score ) {
			$score = 'To be calculated';
		}
		echo '<p><strong>Score:</strong> ' . $score . '<br>';

		if ( get_post_meta( $post->ID, '_submission_file_path', true ) ) {
			echo '<p><strong>File location</strong>:<br>';
			echo get_post_meta( $post->ID, '_submission_file_path', true );
			echo '</p>';
		} else {
			echo '<p><strong>File location</strong> - NOT FOUND</p>';
		}

		if ( get_post_meta( $post->ID, '_submission_file_original_name', true ) ) {
			echo '<p><strong>Original file name</strong>:<br>';
			echo get_post_meta( $post->ID, '_submission_file_original_name', true );
			echo '</p>';
		} else {
			echo '<p><strong>Original file name:</strong> - NOT FOUND</p>';
		}
		if ( get_post_meta( $post->ID, '_submission_notes', true ) ) {
			echo '<p><strong>Notes</strong>:<br>';
			echo get_post_meta( $post->ID, '_submission_notes', true );
			echo '</p>';
		}
	}


	public function enqueue_scripts() {
		wp_register_script( 'iarai-submissions', CLEAD_URL . 'assets/js/submissions.js', [ 'jquery' ], false, true );

		wp_localize_script( 'iarai-submissions', 'iaraiSubmissionsParams', array(
			'ajaxurl'   => admin_url( 'admin-ajax.php' ),
			'ajaxNonce' => wp_create_nonce( 'iarai-submissions-nonce' )
		) );
	}

	public function register_post_types() {
		$labels = array(
			'name'               => _x( 'Submissions', 'post type general name', 'competitions-leaderboard' ),
			'singular_name'      => _x( 'Submission', 'post type singular name', 'competitions-leaderboard' ),
			'menu_name'          => _x( 'Submissions', 'admin menu', 'competitions-leaderboard' ),
			'name_admin_bar'     => _x( 'Submission', 'add new on admin bar', 'competitions-leaderboard' ),
			'add_new'            => _x( 'Add New', 'publication', 'competitions-leaderboard' ),
			'add_new_item'       => __( 'Add New Submission', 'competitions-leaderboard' ),
			'new_item'           => __( 'New Submission', 'competitions-leaderboard' ),
			'edit_item'          => __( 'Edit Submission', 'competitions-leaderboard' ),
			'view_item'          => __( 'View Submission', 'competitions-leaderboard' ),
			'all_items'          => __( 'All Submissions', 'competitions-leaderboard' ),
			'search_items'       => __( 'Search Submissions', 'competitions-leaderboard' ),
			'parent_item_colon'  => __( 'Parent Submissions:', 'competitions-leaderboard' ),
			'not_found'          => __( 'No submissions found.', 'competitions-leaderboard' ),
			'not_found_in_trash' => __( 'No submissions found in Trash.', 'competitions-leaderboard' )
		);

		$args = array(
			'labels'              => $labels,
			'description'         => '',
			'public'              => true,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'query_var'           => false,
			'show_in_rest'        => false,
			'has_archive'         => false,
			'hierarchical'        => false,
			'supports'            => array( 'title', 'author' )
		);

		register_post_type( 'submission', $args );

		$labels = array(
			'name'              => _x( 'Competitions', 'taxonomy general name', 'textdomain' ),
			'singular_name'     => _x( 'Competition', 'taxonomy singular name', 'textdomain' ),
			'search_items'      => __( 'Search Competitions', 'textdomain' ),
			'all_items'         => __( 'All Competitions', 'textdomain' ),
			'parent_item'       => __( 'Parent Competition', 'textdomain' ),
			'parent_item_colon' => __( 'Parent Competition', 'textdomain' ),
			'edit_item'         => __( 'Edit Competition', 'textdomain' ),
			'update_item'       => __( 'Update Competition', 'textdomain' ),
			'add_new_item'      => __( 'Add New Competition', 'textdomain' ),
			'new_item_name'     => __( 'New Competition', 'textdomain' ),
			'menu_name'         => __( 'Competitions', 'textdomain' ),
		);

		$args = array(
			'hierarchical'      => true,
			'labels'            => $labels,
			'show_ui'           => true,
			'show_admin_column' => true,
			'query_var'         => true,
			'show_in_rest'      => false,
			'rewrite'           => array( 'slug' => 'competitions' ),
		);

		register_taxonomy( 'competition', array( 'submission' ), $args );

		$labels = array(
			'name'              => _x( 'Teams', 'taxonomy general name', 'textdomain' ),
			'singular_name'     => _x( 'Team', 'taxonomy singular name', 'textdomain' ),
			'search_items'      => __( 'Search Teams', 'textdomain' ),
			'all_items'         => __( 'All Teams', 'textdomain' ),
			'parent_item'       => __( 'Parent Team', 'textdomain' ),
			'parent_item_colon' => __( 'Parent Team', 'textdomain' ),
			'edit_item'         => __( 'Edit Team', 'textdomain' ),
			'update_item'       => __( 'Update Team', 'textdomain' ),
			'add_new_item'      => __( 'Add New Team', 'textdomain' ),
			'new_item_name'     => __( 'New Team', 'textdomain' ),
			'menu_name'         => __( 'Teams', 'textdomain' ),
		);

		$args = array(
			'hierarchical'      => false,
			'labels'            => $labels,
			'show_ui'           => true,
			'show_admin_column' => true,
			'query_var'         => false,
			'show_in_rest'      => false,
			'public'            => false,
			'rewrite'           => false,
		);

		register_taxonomy( 'team', array( 'submission' ), $args );

		$labels = array(
			'name'              => _x( 'Challenges', 'taxonomy general name', 'textdomain' ),
			'singular_name'     => _x( 'Challenge', 'taxonomy singular name', 'textdomain' ),
			'search_items'      => __( 'Search Challenges', 'textdomain' ),
			'all_items'         => __( 'All Challenges', 'textdomain' ),
			'parent_item'       => __( 'Parent Challenge', 'textdomain' ),
			'parent_item_colon' => __( 'Parent Challenge', 'textdomain' ),
			'edit_item'         => __( 'Edit Challenge', 'textdomain' ),
			'update_item'       => __( 'Update Challenge', 'textdomain' ),
			'add_new_item'      => __( 'Add New Challenge', 'textdomain' ),
			'new_item_name'     => __( 'New Challenge', 'textdomain' ),
			'menu_name'         => __( 'Challenges', 'textdomain' ),
		);

		$args = array(
			'hierarchical'      => false,
			'labels'            => $labels,
			'show_ui'           => false,
			'show_admin_column' => true,
			'query_var'         => false,
			'show_in_rest'      => false,
			'public'            => false,
			'rewrite'           => false,
		);

		register_taxonomy( 'challenge', array( 'submission' ), $args );
	}

	/**
	 * @param false $category
	 */
	public function competition_display_meta( $category = false ) {

		$description = '';
		if ( is_object( $category ) ) {
			$description = html_entity_decode( stripcslashes( $category->description ) );
		}

		?>
        <tr class="form-field">
            <th scope="row" valign="top">
                <label for="cat_description"><?php esc_html_e( 'Description', 'competitions-leaderboard' ); ?></label>
            </th>
            <td>
                <div class="form-field term-meta-wrap">
					<?php

					$settings = array(
						'wpautop'       => true,
						'media_buttons' => true,
						'quicktags'     => true,
						'textarea_rows' => '15',
						'textarea_name' => 'description'
					);
					wp_editor( wp_kses_post( $description ), 'cat_description', $settings );

					?>
                </div>
            </td>
        </tr>

		<?php

	}

	public function remove_default_category_description() {
		global $current_screen;
		if ( $current_screen->id === 'edit-competition' ) {
			?>
            <script type="text/javascript">
                jQuery(function ($) {
                    $('textarea#tag-description, textarea#description').closest('.form-field').remove();

                    $('body').on('click', 'input[name="carbon_fields_compact_input[_competition_type]"]', function () {

                        setTimeout(function () {
                            $('.container-carbon_fields_container_term_options .cf-container__fields').each(function (index) {
                                if ($(this).find(' > .cf-field:not([hidden])').length > 0) {
                                    $('.cf-container__tabs-list > li').eq(index).show();
                                } else {
                                    $('.cf-container__tabs-list > li').eq(index).hide();
                                }
                            });
                        }, 400);


                    });
                })

            </script>
			<?php
		}
	}

	public function change_upload_dir( $dirs ) {

		$postfix = '';
		if ( $this->competition != '' ) {
			$postfix = '/' . $this->competition;
		}

		$dir = '/iarai-submissions';

		if ( defined( 'COMPETITION_DIR' ) && ! empty( COMPETITION_DIR ) ) {
			$dir = COMPETITION_DIR;
		}

		$dirs['subdir'] = $dir . $postfix;
		$dirs['path']   = $dirs['basedir'] . $dir . $postfix;
		$dirs['url']    = $dirs['baseurl'] . $dir . $postfix;

		return $dirs;
	}



	static function get_log_content( $id ) {
		$file_path = get_post_meta( $id, '_submission_file_path', true );
		if ( $file_path ) {
			$path_parts = pathinfo( $file_path );
			if ( ! isset( $path_parts['dirname'] ) ) {
				return false;
			}

			$log_path = $path_parts['dirname'] . '/' . $path_parts['filename'] . '.log';
			if ( file_exists( $log_path ) ) {
				return file_get_contents( $log_path );
			}
		}

		return false;
	}

	static function get_score_number( $post_id ) {

		if ( $score = get_post_meta( $post_id, '_score', true ) ) {
			return $score;
		}

		return false;
	}









	public function shortcode_submission( $atts = [] ) {
		extract( shortcode_atts( array(
			'competition' => '',
		), $atts ) );

		$competition       = ! empty( $competition ) ? $competition : get_queried_object_id();
		$submission_option = get_term_meta( $competition, '_enable_submissions', true );

		if ( ! is_user_logged_in() && $submission_option !== 'guests' ) {
			echo '<p class="alert alert-warning submissions-no-user">Please ' .
			     '<a class="kleo-show-login" href="' . wp_login_url() . '">login</a> to submit data.</p>';

			return '';
		}

		wp_enqueue_script( 'iarai-submissions' );

		ob_start();

		require_once CLEAD_PATH . 'templates/submission-form.php';

		return ob_get_clean();
	}

	static function get_leaderboard_row( $submission, $competition, $count = false ) {

		$user_id         = (int) $submission->post_author;
		$user            = get_user_by( 'id', $user_id );
		$name            = $user->display_name;
		$team            = wp_get_post_terms( $submission->ID, 'team' );
		$is_current_user = ( is_user_logged_in() && $user_id === get_current_user_id() ) ? true : false;

		if ( $team && ! empty( $team ) ) {
			$name = $team[0]->name . ' - ' . $name;
		}

		if ( $count === false ) {
			$saved_positions = get_transient( 'leaderboard_' . $competition );
			$saved_positions = (array) $saved_positions;
			$count           = isset( $saved_positions[ $submission->ID ] ) ? $saved_positions[ $submission->ID ] : '';
		}

		ob_start();
		?>
        <tr>
            <td class="submission-count"><?php echo $count; ?></td>
            <td><?php echo esc_html( get_the_title( $submission ) ); ?></td>
            <td><?php echo esc_html( $name ); ?></td>
            <td>
				<?php echo get_post_meta( $submission->ID, '_score', true ); ?>
				<?php if ( $is_current_user && self::get_log_content( $submission->ID ) !== false ) { ?>
                    <span data-placement="top" class="submission-log click-pop" data-toggle="popover"
                          data-title="Submission info"
                          data-content="<?php echo esc_attr( self::get_log_content( $submission->ID ) ); ?>">
							<i class="icon-info-circled"></i>
						</span>


				<?php } ?>
            </td>
            <td><?php echo get_the_date( 'M j, Y H:i', $submission->ID ) ?></td>
        </tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * @param null $competition
	 * @param string $search_term
	 * @param string $sort_order
	 *
	 * @return array|false|object
	 */
	public static function query_leaderboard( $competition = null, $search_term = '', $sort_order = 'ASC' ) {

		global $wpdb;

		if ( empty( $competition ) ) {
			return false;
		}

		$author_query = '';
		if ( is_user_logged_in() && isset( $_POST['current_user'] ) && (bool) $_POST['current_user'] === true ) {
			$author_query = " AND {$wpdb->prefix}posts.post_author IN (" . get_current_user_id() . ")";
		}

		$search_query = '';
		if ( ! empty( $search_term ) ) {
			$search_query = " AND (" .
			                "(mt1.meta_key = '_submission_notes' AND mt1.meta_value LIKE '%%%s%%')" .
			                "OR {$wpdb->prefix}posts.post_title LIKE '%%%s%%'" .
			                ")";
		}

		$submissions_query = "SELECT $wpdb->posts.* FROM $wpdb->posts" .
		                     " LEFT JOIN {$wpdb->prefix}term_relationships ON ({$wpdb->posts}.ID = {$wpdb->prefix}term_relationships.object_id)" .
		                     " INNER JOIN {$wpdb->prefix}postmeta ON ( {$wpdb->prefix}posts.ID = {$wpdb->prefix}postmeta.post_id )" .
		                     " INNER JOIN {$wpdb->prefix}postmeta AS mt1 ON ( {$wpdb->prefix}posts.ID = mt1.post_id )" .
		                     " WHERE" .
		                     " {$wpdb->prefix}term_relationships.term_taxonomy_id IN ($competition)" .
		                     $author_query .
		                     " AND ( {$wpdb->prefix}postmeta.meta_key = '_score' AND {$wpdb->prefix}postmeta.meta_value > '0' )" .
		                     $search_query .
		                     " AND {$wpdb->prefix}posts.post_type = 'submission'" .
		                     " AND {$wpdb->prefix}posts.post_status = 'publish'" .
		                     " GROUP BY {$wpdb->prefix}posts.ID" .
		                     " ORDER BY {$wpdb->prefix}postmeta.meta_value+0 " . $sort_order;

		if ( $search_term ) {
			$submissions_query = $wpdb->prepare(
				$submissions_query,
				$wpdb->esc_like( $search_term ), $wpdb->esc_like( $search_term ) );
		}

		return $wpdb->get_results( $submissions_query );
	}

	public function ajax_filter_leaderboard() {

		check_ajax_referer( 'iarai-submissions-nonce', 'security' );

		if ( isset( $_POST['action'] ) && $_POST['action'] === 'iarai_filter_leaderboard' ) {

			global $wpdb;
			$competition = (int) $_POST['competition'];

			// Submissions

			$search_term = $_POST['term'];
			$submissions = self::query_leaderboard( $competition, $search_term );

			if ( $submissions ) {
				$result = '';
				foreach ( $submissions as $submission ) {
					$result .= self::get_leaderboard_row( $submission, $competition );
				}
				wp_send_json_success( [ 'results' => $result ] );
				exit;
			}

		}
		wp_send_json_success( [ 'results' => false ] );
		exit;
	}

	public function shortcode_leaderboard( $atts = [] ) {
		extract( shortcode_atts( array(
			'competition' => '',
		), $atts ) );

		wp_enqueue_script( 'iarai-submissions' );

		ob_start();

		require_once CLEAD_PATH . 'templates/submission-leaderboard.php';

		return ob_get_clean();
	}

	/**
	 * Determines if the current user has permission to upload a file based on their current role and the values
	 * of the security nonce.
	 *
	 * @param string $nonce The WordPress-generated nonce.
	 * @param string $action The developer-generated action name.
	 *
	 * @return bool              True if the user has permission to save; otherwise, false.
	 */
	private function user_can_save( $nonce, $action ) {
		$is_nonce_set   = isset( $_POST[ $nonce ] );
		$is_valid_nonce = false;
		if ( $is_nonce_set ) {
			$is_valid_nonce = wp_verify_nonce( $_POST[ $nonce ], $action );
		}

		return ( $is_nonce_set && $is_valid_nonce );
	}

	public function research_display_type_template( $template ) {

		$file = CLEAD_PATH . 'templates/archive-competition.php';
		if ( is_tax( 'competition' ) && file_exists( $file ) ) {
			return $file;
		}

		return $template;
	}

	public function export_csv() {

		if ( ! ( isset( $_GET['post_type'] ) && $_GET['post_type'] === 'submission' ) ) {
			return;
		}

		add_action( 'admin_head-edit.php', function () {
			global $current_screen;

			?>
            <script type="text/javascript">
                jQuery(document).ready(function ($) {
                    jQuery(jQuery(".wrap h1")[0]).append("<a onclick=\"window.location='" + window.location.href + "&export-csv'\" id='iarai-export-csv' class='add-new-h2'>Export CSV</a>");
                });
            </script>
			<?php
		} );

		if ( ! isset( $_GET['export-csv'] ) ) {
			return;
		}

		$filename   = 'Submissions_' . time() . '.csv';
		$header_row = array(
			'Title',
			'User ID',
			'Username',
			'Email',
			'Team',
			'Competition ID',
			'Competition',
			'Score',
			'Submission ID',
			'Log',
			'Date',
		);
		$data_rows  = array();

		$args = [
			'post_status'    => 'publish',
			'posts_per_page' => - 1,
			'post_type'      => 'submission',
		];

		$submissions = get_posts( $args );

		foreach ( $submissions as $k => $submission ) {

			$terms            = get_the_terms( $submission->ID, 'team' );
			$competitions     = get_the_terms( $submission->ID, 'competition' );
			$terms_text       = '';
			$competition_text = '';
			$competition_id   = '';

			if ( $terms && ! is_wp_error( $terms ) ) {

				$terms_arr = [];

				foreach ( $terms as $term ) {
					$terms_arr[] = $term->name;
				}

				$terms_text = join( ", ", $terms_arr );
			}

			if ( $competitions && ! is_wp_error( $competitions ) ) {

				foreach ( $competitions as $competition ) {
					$competition_text = $competition->name;
					$competition_id   = $competition->term_id;
					break;
				}
			}

			$sub_id     = '';
			$path       = get_post_meta( $submission->ID, '_submission_file_path', true );
			$path_array = explode( '/', $path );
			if ( count( $path_array ) > 1 ) {
				$sub_id = str_replace( '.zip', '', end( $path_array ) );
			}

			$user        = get_user_by( 'id', $submission->post_author );
			$row         = array(
				$submission->post_title,
				$submission->post_author,
				$user->user_login,
				$user->user_email,
				$terms_text,
				$competition_id,
				$competition_text,
				self::get_score_number( $submission->ID ),
				$sub_id,
				( self::get_log_content( $submission->ID ) ? self::get_log_content( $submission->ID ) : '' ),
				get_the_date( 'Y-m-d H:i', $submission->ID )
			);
			$data_rows[] = $row;
		}
		ob_end_clean();
		$fh = @fopen( 'php://output', 'w' );
		header( "Content-Disposition: attachment; filename={$filename}" );
		fputcsv( $fh, $header_row );
		foreach ( $data_rows as $data_row ) {
			fputcsv( $fh, $data_row );
		}

		exit();
	}

	/**
	 * @param $columns
	 *
	 * @return mixed
	 */
	public function custom_add_new_columns( $columns ) {
		$columns['author_email'] = 'Email';

		return $columns;
	}

	/**
	 * @param $column_name
	 * @param $id
	 *
	 * @return void
	 */
	public function custom_manage_new_columns( $column_name, $id ) {
		if ( 'author_email' === $column_name ) {
			$current_item = get_post( $id );
			$author_id    = $current_item->post_author;
			$author_email = get_the_author_meta( 'user_email', $author_id );
			echo $author_email;
		}
	}

}
