<?php
/*
Plugin Name: BeerXML Shortcode
Plugin URI: http://wordpress.org/extend/plugins/beerxml-shortcode/
Description: Automatically insert/display beer recipes by linking to a BeerXML document.
Author: Derek Springer
Author URI: http://www.fivebladesbrewing.com/beerxml-plugin-wordpress/
Version: 0.4
License: GPL2 or later
Text Domain: beerxml-shortcode
*/

/**
 * Class wrapper for BeerXML shortcode
 */
class BeerXML_Shortcode {

	/**
	 * A simple call to init when constructed
	 */
	function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'init', array( $this, 'beer_style' ) );
	}

	/**
	 * BeerXML initialization routines
	 */
	function init() {
		// I18n
		load_plugin_textdomain(
			'beerxml-shortcode',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

		if ( ! defined( 'BEERXML_URL' ) )
			define( 'BEERXML_URL', plugin_dir_url( __FILE__ ) );

		if ( ! defined( 'BEERXML_PATH' ) )
			define( 'BEERXML_PATH', plugin_dir_path( __FILE__ ) );

		if ( ! defined( 'BEERXML_BASENAME' ) )
			define( 'BEERXML_BASENAME', plugin_basename( __FILE__ ) );

		require_once( BEERXML_PATH . '/includes/mime.php' );
		if ( is_admin() ) {
			require_once( BEERXML_PATH . '/includes/admin.php' );
			return;
		}

		require_once( BEERXML_PATH . '/includes/classes.php' );

		add_shortcode( 'beerxml', array( $this, 'beerxml_shortcode' ) );
	}

	/**
	 * Register Custom Taxonomy for Beer Style
	 */
	function beer_style() {
		$labels = array(
			'name'                       => __( 'Beer Styles', 'beerxml-shortcode' ),
			'singular_name'              => __( 'Beer Style', 'beerxml-shortcode' ),
			'menu_name'                  => __( 'Beer Style', 'beerxml-shortcode' ),
			'all_items'                  => __( 'All Beer Styles', 'beerxml-shortcode' ),
			'parent_item'                => __( 'Parent Beer Style', 'beerxml-shortcode' ),
			'parent_item_colon'          => __( 'Parent Beer Style:', 'beerxml-shortcode' ),
			'new_item_name'              => __( 'New Beer Style Name', 'beerxml-shortcode' ),
			'add_new_item'               => __( 'Add New Beer Style', 'beerxml-shortcode' ),
			'edit_item'                  => __( 'Edit Beer Style', 'beerxml-shortcode' ),
			'update_item'                => __( 'Update Beer Style', 'beerxml-shortcode' ),
			'separate_items_with_commas' => __( 'Separate beer styles with commas', 'beerxml-shortcode' ),
			'search_items'               => __( 'Search Beer Styles', 'beerxml-shortcode' ),
			'add_or_remove_items'        => __( 'Add or remove beer styles', 'beerxml-shortcode' ),
			'choose_from_most_used'      => __( 'Choose from the most used beer styles', 'beerxml-shortcode' ),
			'not_found'                  => __( 'Not Found', 'beerxml-shortcode' ),
		);

		$args = array(
			'labels'                     => $labels,
			'hierarchical'               => false,
			'public'                     => true,
			'show_ui'                    => true,
			'show_admin_column'          => true,
			'show_in_nav_menus'          => true,
			'show_tagcloud'              => true,
			'update_count_callback' 	 => '_update_post_term_count',
			'query_var'					 => true,
			'rewrite'           		 => array( 'slug' => 'beer-style' ),
		);

		register_taxonomy( 'beer_style', 'post', $args );
	}

	/**
	 * Shortcode for BeerXML
	 * [beerxml recipe=http://example.com/wp-content/uploads/2012/08/bowie-brown.xml cache=10800 metric=true download=true style=true]
	 *
	 * @param  array $atts shortcode attributes
	 *                     recipe - URL to BeerXML document
	 *                     cache - number of seconds to cache recipe
	 *                     metric - true  -> use metric values
	 *                              false -> use U.S. values
	 *                     download - true -> include link to BeerXML file
	 *                     style - true -> include style details
	 * @return string HTML to be inserted in shortcode's place
	 */
	function beerxml_shortcode( $atts ) {
		global $post;

		if ( ! is_array( $atts ) ) {
			return '<!-- BeerXML shortcode passed invalid attributes -->';
		}

		if ( ! isset( $atts['recipe'] ) && ! isset( $atts[0] ) ) {
			return '<!-- BeerXML shortcode source not set -->';
		}

		extract( shortcode_atts( array(
			'recipe'   => null,
			'cache'    => get_option( 'beerxml_shortcode_cache', 60*60*12 ), // cache for 12 hours
			'metric'   => 2 == get_option( 'beerxml_shortcode_units', 1 ), // units
			'download' => get_option( 'beerxml_shortcode_download', 1 ), // include download link
			'style'    => get_option( 'beerxml_shortcode_style', 1 ), // include style details
		), $atts ) );

		if ( ! isset( $recipe ) ) {
			$recipe = $atts[0];
		}

		$recipe = esc_url_raw( $recipe );
		$recipe_filename = pathinfo( $recipe, PATHINFO_FILENAME );
		$recipe_id = "beerxml_shortcode_recipe-{$post->ID}_{$recipe_filename}";

		$cache  = intval( esc_attr( $cache ) );
		if ( -1 == $cache ) { // clear cache if set to -1
			delete_transient( $recipe_id );
			$cache = 0;
		}

		$metric = filter_var( esc_attr( $metric ), FILTER_VALIDATE_BOOLEAN );
		$download = filter_var( esc_attr( $download ), FILTER_VALIDATE_BOOLEAN );
		$style = filter_var( esc_attr( $style ), FILTER_VALIDATE_BOOLEAN );

		if ( ! $cache || false === ( $beer_xml = get_transient( $recipe_id ) ) ) {
			$beer_xml = new BeerXML( $recipe );
		} else {
			// result was in cache, just use that
			return $beer_xml;
		}

		if ( ! $beer_xml->recipes ) { // empty recipe
			return '<!-- Error parsing BeerXML document -->';
		}

		/***************
		 * Recipe Details
		 **************/
		if ( $metric ) {
			$beer_xml->recipes[0]->batch_size = round( $beer_xml->recipes[0]->batch_size, 1 );
			$t_vol = __( 'L', 'beerxml-shortcode' );
		} else {
			$beer_xml->recipes[0]->batch_size = round( $beer_xml->recipes[0]->batch_size * 0.264172, 1 );
			$t_vol = __( 'gal', 'beerxml-shortcode' );
		}

		$btime = round( $beer_xml->recipes[0]->boil_time );
		$t_details = __( 'Recipe Details', 'beerxml-shortcode' );
		$t_size    = __( 'Batch Size', 'beerxml-shortcode' );
		$t_boil    = __( 'Boil Time', 'beerxml-shortcode' );
		$t_time    = __( 'min', 'beerxml-shortcode' );
		$t_ibu     = __( 'IBU', 'beerxml-shortcode' );
		$t_srm     = __( 'SRM', 'beerxml-shortcode' );
		$t_og      = __( 'Est. OG', 'beerxml-shortcode' );
		$t_fg      = __( 'Est. FG', 'beerxml-shortcode' );
		$t_abv     = __( 'ABV', 'beerxml-shortcode' );
		$details = <<<DETAILS
		<div class='beerxml-details'>
			<h3>$t_details</h3>
			<table>
				<thead>
					<tr>
						<th>$t_size</th>
						<th>$t_boil</th>
						<th>$t_ibu</th>
						<th>$t_srm</th>
						<th>$t_og</th>
						<th>$t_fg</th>
						<th>$t_abv</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>{$beer_xml->recipes[0]->batch_size} $t_vol</td>
						<td>$btime $t_time</td>
						<td>{$beer_xml->recipes[0]->ibu}</td>
						<td>{$beer_xml->recipes[0]->est_color}</td>
						<td>{$beer_xml->recipes[0]->est_og}</td>
						<td>{$beer_xml->recipes[0]->est_fg}</td>
						<td>{$beer_xml->recipes[0]->est_abv}</td>
					</tr>
				</tbody>
			</table>
		</div>
DETAILS;

		/***************
		 * Style Details
		 **************/
		$style_details = '';
		$t_name = __( 'Name', 'beerxml-shortcode' );

		if ( $style && $beer_xml->recipes[0]->style ) {
			$t_style = __( 'Style Details', 'beerxml-shortcode' );
			$t_category = __( 'Cat.', 'beerxml-shortcode' );
			$t_og_range = __( 'OG Range', 'beerxml-shortcode' );
			$t_fg_range = __( 'FG Range', 'beerxml-shortcode' );
			$t_ibu_range = __( 'IBU', 'beerxml-shortcode' );
			$t_srm_range = __( 'SRM', 'beerxml-shortcode' );
			$t_carb_range = __( 'Carb', 'beerxml-shortcode' );
			$t_abv_range = __( 'ABV', 'beerxml-shortcode' );
			$style_details = <<<STYLE
			<div class='beerxml-style'>
				<h3>$t_style</h3>
				<table>
					<thead>
						<tr>
							<th>$t_name</th>
							<th>$t_category</th>
							<th>$t_og_range</th>
							<th>$t_fg_range</th>
							<th>$t_ibu_range</th>
							<th>$t_srm_range</th>
							<th>$t_carb_range</th>
							<th>$t_abv_range</th>
						</tr>
					</thead>
					<tbody>
						{$this->build_style( $beer_xml->recipes[0]->style )}
					</tbody>
				</table>
			</div>
STYLE;
		}

		/***************
		 * Fermentables Details
		 **************/
		$fermentables = '';
		$total = BeerXML_Fermentable::calculate_total( $beer_xml->recipes[0]->fermentables );
		foreach ( $beer_xml->recipes[0]->fermentables as $fermentable ) {
			$fermentables .= $this->build_fermentable( $fermentable, $total, $metric );
		}

		$t_fermentables = __( 'Fermentables', 'beerxml-shortcode' );
		$t_amount = __( 'Amount', 'beerxml-shortcode' );
		$fermentables = <<<FERMENTABLES
		<div class='beerxml-fermentables'>
			<h3>$t_fermentables</h3>
			<table>
				<thead>
					<tr>
						<th>$t_name</th>
						<th>$t_amount</th>
						<th>%</th>
					</tr>
				</thead>
				<tbody>
					$fermentables
				</tbody>
			</table>
		</div>
FERMENTABLES;

		/***************
		 * Hops Details
		 **************/
		$hops = '';
		if ( $beer_xml->recipes[0]->hops ) {
			foreach ( $beer_xml->recipes[0]->hops as $hop ) {
				$hops .= $this->build_hop( $hop, $metric );
			}

			$t_hops  = __( 'Hops', 'beerxml-shortcode' );
			$t_time  = __( 'Time', 'beerxml-shortcode' );
			$t_use   = __( 'Use', 'beerxml-shortcode' );
			$t_form  = __( 'Form', 'beerxml-shortcode' );
			$t_alpha = __( 'Alpha %', 'beerxml-shortcode' );
			$hops = <<<HOPS
			<div class='beerxml-hops'>
				<h3>$t_hops</h3>
				<table>
					<thead>
						<tr>
							<th>$t_name</th>
							<th>$t_amount</th>
							<th>$t_time</th>
							<th>$t_use</th>
							<th>$t_form</th>
							<th>$t_alpha</th>
						</tr>
					</thead>
					<tbody>
						$hops
					</tbody>
				</table>
			</div>
HOPS;
		}

		/***************
		 * Miscs
		 **************/
		$miscs = '';
		if ( $beer_xml->recipes[0]->miscs ) {
			foreach ( $beer_xml->recipes[0]->miscs as $misc ) {
				$miscs .= $this->build_misc( $misc, $metric );
			}

			$t_miscs = __( 'Miscs', 'beerxml-shortcode' );
			$t_type = __( 'Type', 'beerxml-shortcode' );
			$miscs = <<<MISCS
			<div class='beerxml-miscs'>
				<h3>$t_miscs</h3>
				<table>
					<thead>
						<tr>
							<th>$t_name</th>
							<th>$t_amount</th>
							<th>$t_time</th>
							<th>$t_use</th>
							<th>$t_type</th>
						</tr>
					</thead>
					<tbody>
						$miscs
					</tbody>
				</table>
			</div>
MISCS;
		}

		/***************
		 * Yeast Details
		 **************/
		$yeasts = '';
		if ( $beer_xml->recipes[0]->yeasts ) {
			foreach ( $beer_xml->recipes[0]->yeasts as $yeast ) {
				$yeasts .= $this->build_yeast( $yeast, $metric );
			}

			$t_yeast       = __( 'Yeast', 'beerxml-shortcode' );
			$t_lab         = __( 'Lab', 'beerxml-shortcode' );
			$t_attenuation = __( 'Attenuation', 'beerxml-shortcode' );
			$t_temperature = __( 'Temperature', 'beerxml-shortcode' );
			$yeasts = <<<YEASTS
			<div class='beerxml-yeasts'>
				<h3>$t_yeast</h3>
				<table>
					<thead>
						<tr>
							<th>$t_name</th>
							<th>$t_lab</th>
							<th>$t_attenuation</th>
							<th>$t_temperature</th>
						</tr>
					</thead>
					<tbody>
						$yeasts
					</tbody>
				</table>
			</div>
YEASTS;
		}

		/***************
		 * Notes
		 **************/
		$notes = '';
		if ( $beer_xml->recipes[0]->notes ) {
			$t_notes = __( 'Notes', 'beerxml-shortcode' );
			$formatted_notes = preg_replace( '/\n/', '<br />', $beer_xml->recipes[0]->notes );
			$notes = <<<NOTES
			<div class='beerxml-notes'>
				<h3>$t_notes</h3>
				<table>
					<tbody>
						<tr>
							<td>$formatted_notes</td>
						</tr>
					</tbody>
				</table>
			</div>
NOTES;
		}

		/***************
		 * Download link
		 **************/
		$link = '';
		if ( $download ) {
			$t_download = __( 'Download', 'beerxml-shortcode' );
			$t_link = __( 'Download this recipe\'s BeerXML file', 'beerxml-shortcode' );
			$link = <<<LINK
			<div class="beerxml-download">
				<h3>$t_download</h3>
				<table>
					<tbody>
						<tr>
							<td><a href="$recipe" download="$recipe_filename">$t_link</a></td>
						</tr>
					</tbody>
				</table>
			</div>
LINK;
		}

		// stick 'em all together
		$html = <<<HTML
		<div class='beerxml-recipe'>
			$details
			$style_details
			$fermentables
			$hops
			$miscs
			$yeasts
			$notes
			$link
		</div>
HTML;

		if ( $cache && $beer_xml->recipes ) {
			set_transient( $recipe_id, $html, $cache );
		}

		return $html;
	}

	/**
	 * Build style row
	 * @param  BeerXML_Style 		$style fermentable to display
	 */
	static function build_style( $style ) {
		global $post;

		$category = $style->category_number . ' ' . $style->style_letter;
		$og_range = round( $style->og_min, 3 ) . ' - ' . round( $style->og_max, 3 );
		$fg_range = round( $style->fg_min, 3 ) . ' - ' . round( $style->fg_max, 3 );
		$ibu_range = round( $style->ibu_min, 1 ) . ' - ' . round( $style->ibu_max, 1 );
		$srm_range = round( $style->color_min, 1 ) . ' - ' . round( $style->color_max, 1 );
		$carb_range = round( $style->carb_min, 1 ) . ' - ' . round( $style->carb_max, 1 );
		$abv_range = round( $style->abv_min, 1 ) . ' - ' . round( $style->abv_max, 1 );

		if ( ! term_exists( $style->name, 'beer_style' ) ) {
			wp_insert_term( $style->name, 'beer_style' );
		}

		wp_set_object_terms( $post->ID, $style->name, 'beer_style' );

		$catlist = get_the_terms( $post->ID, 'beer_style' );
		$catlist = array_values( $catlist );
		if ( $catlist && ! is_wp_error( $catlist ) ) {
			$link = get_term_link( $catlist[0]->term_id, 'beer_style' );
			$catname = "<a href='{$link}'>{$catlist[0]->name}</a>";
		}

		return <<<STYLE
		<tr>
			<td>$catname</td>
			<td>$category</td>
			<td>$og_range</td>
			<td>$fg_range</td>
			<td>$ibu_range</td>
			<td>$srm_range</td>
			<td>$carb_range</td>
			<td>$abv_range %</td>
		</tr>
STYLE;
	}

	/**
	 * Build fermentable row
	 * @param  BeerXML_Fermentable  $fermentable fermentable to display
	 * @param  boolean $metric      true to display values in metric
	 * @return string               table row containing fermentable details
	 */
	static function build_fermentable( $fermentable, $total, $metric = false ) {
		$percentage = round( $fermentable->percentage( $total ), 2 );
		if ( $metric ) {
			$fermentable->amount = round( $fermentable->amount, 3 );
			$t_weight = __( 'kg', 'beerxml-shortcode' );
		} else {
			$fermentable->amount = round( $fermentable->amount * 2.20462, 3 );
			$t_weight = __( 'lbs', 'beerxml-shortcode' );
		}

		return <<<FERMENTABLE
		<tr>
			<td>$fermentable->name</td>
			<td>$fermentable->amount $t_weight</td>
			<td>$percentage</td>
		</tr>
FERMENTABLE;
	}

	/**
	 * Build hop row
	 * @param  BeerXML_Hop          $hop hop to display
	 * @param  boolean $metric      true to display values in metric
	 * @return string               table row containing hop details
	 */
	static function build_hop( $hop, $metric = false ) {
		if ( $metric ) {
			$hop->amount = round( $hop->amount * 1000, 1 );
			$t_weight = __( 'g', 'beerxml-shortcode' );
		} else {
			$hop->amount = round( $hop->amount * 35.274, 2 );
			$t_weight = __( 'oz', 'beerxml-shortcode' );
		}

		if ( $hop->time >= 1440 ) {
			$hop->time = round( $hop->time / 1440, 1);
			$t_time = _n( 'day', 'days', $hop->time, 'beerxml-shortcode' );
		} else {
			$hop->time = round( $hop->time );
			$t_time = __( 'min', 'beerxml-shortcode' );
		}

		$hop->alpha = round( $hop->alpha, 1 );

		return <<<HOP
		<tr>
			<td>$hop->name</td>
			<td>$hop->amount $t_weight</td>
			<td>$hop->time $t_time</td>
			<td>$hop->use</td>
			<td>$hop->form</td>
			<td>$hop->alpha</td>
		</tr>
HOP;
	}

	/**
	 * Build misc row
	 * @param  BeerXML_Misc         hop misc to display
	 * @return string               table row containing hop details
	 */
	static function build_misc( $misc, $metric = false ) {
		if ( $misc->time >= 1440 ) {
			$misc->time = round( $misc->time / 1440, 1);
			$t_time = _n( 'day', 'days', $misc->time, 'beerxml-shortcode' );
		} else {
			$misc->time = round( $misc->time );
			$t_time = __( 'min', 'beerxml-shortcode' );
		}

		$amount = '';
		if ( ! empty( $misc->display_amount ) ) {
			$amount = $misc->display_amount;
		} else {
			if ( $metric ) {
				$misc->amount = round( $misc->amount * 1000, 1 );
				$t_weight = __( 'g', 'beerxml-shortcode' );
			} else {
				$misc->amount = round( $misc->amount * 35.274, 2 );
				$t_weight = __( 'oz', 'beerxml-shortcode' );
			}

			$amount = "{$misc->amount} $t_weight";
		}

		return <<<MISC
		<tr>
			<td>$misc->name</td>
			<td>$amount</td>
			<td>$misc->time $t_time</td>
			<td>$misc->use</td>
			<td>$misc->type</td>
		</tr>
MISC;
	}

	/**
	 * Build yeast row
	 * @param  BeerXML_Yeast        $yeast yeast to display
	 * @param  boolean $metric      true to display values in metric
	 * @return string               table row containing yeast details
	 */
	static function build_yeast( $yeast, $metric = false ) {
		if ( $metric ) {
			$yeast->min_temperature = round( $yeast->min_temperature, 2 );
			$yeast->max_temperature = round( $yeast->max_temperature, 2 );
			$t_temp = __( 'C', 'beerxml-shortcode' );
		} else {
			$yeast->min_temperature = round( ( $yeast->min_temperature * (9/5) ) + 32, 1 );
			$yeast->max_temperature = round( ( $yeast->max_temperature * (9/5) ) + 32, 1 );
			$t_temp = __( 'F', 'beerxml-shortcode' );
		}

		$yeast->attenuation = round( $yeast->attenuation );

		return <<<YEAST
		<tr>
			<td>$yeast->name ({$yeast->product_id})</td>
			<td>$yeast->laboratory</td>
			<td>{$yeast->attenuation}%</td>
			<td>{$yeast->min_temperature}°$t_temp - {$yeast->max_temperature}°$t_temp</td>
		</tr>
YEAST;
	}
}

// The fun starts here!
new BeerXML_Shortcode();
