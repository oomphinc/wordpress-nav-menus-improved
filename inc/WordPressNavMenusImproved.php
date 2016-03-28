<?php

namespace OomphInc;

class WordPressNavMenusImproved {

	const CPT_TYPE = 'cpt_archive';

	protected static $orig_uri;

	static function init() {
		// add_meta_boxes action does not fire on the nav menu page
		add_action( 'admin_head-nav-menus.php', [ __CLASS__, 'nav_menu_add_mbs' ] );
		add_filter( 'wp_setup_nav_menu_item', [ __CLASS__, 'nav_menu_item' ] );
		add_filter( 'wp_nav_menu_objects', [ __CLASS__, 'submenu_objects' ], 10, 2 );
		add_filter( 'pre_wp_nav_menu', [ __CLASS__, 'current_menu_item_overrides' ] );
		add_filter( 'wp_nav_menu_objects', [ __CLASS__, 'reset_overrides' ], 99 );
	}

	/**
	 * Fool the nav menu parser into thinking we are on a different page.
	 * (e.g. the CPT archive page for CPT singles)
	 * @filter pre_wp_nav_menu
	 */
	static function current_menu_item_overrides() {
		// if we are on a single CPT, fake that we are on the CPT archive for the purposes of the menu
		if ( ( is_single() || is_post_type_archive() ) && ( $post_type = get_query_var( 'post_type' ) ) ) {
			self::$orig_uri = $_SERVER['REQUEST_URI'];
			$_SERVER['REQUEST_URI'] = get_post_type_archive_link( $post_type );
		}
		// making this explicit, because this filter needs null to resume normal operations
		return null;
	}

	/**
	 * Reset anything we messed with before setting up menu.
	 * @filter wp_nav_menu_objects
	 */
	static function reset_overrides( $passthru ) {
		// reset the page URL!
		if ( isset( self::$orig_uri ) ) {
			$_SERVER['REQUEST_URI'] = self::$orig_uri;
			self::$orig_uri = null;
		}
		return $passthru;
	}

	/**
	 * Add meta boxes for new menu item types.
	 * @action admin_head-nav-menus.php (only admin head on nav-menus.php)
	 */
	static function nav_menu_add_mbs() {
		add_meta_box( 'add-cpt-archive', __( 'CPT Archive' ), [ __CLASS__, 'cpt_archive_mb' ], 'nav-menus', 'side', 'default' );
	}

	/**
	 * Filter a nav menu item object.
	 * @param  object $item nav menu item
	 * @filter wp_setup_nav_menu_item
	 */
	static function nav_menu_item( $item ) {
		if ( $item->type === self::CPT_TYPE ) {
			$post_type = get_post_type_object( $item->object );
			if ( $post_type ) {
				$item->type_label = sprintf( _x( '%1$s Archive', 'Post type archive' ), $post_type->labels->name );
				$item->url = get_post_type_archive_link( $item->object );
				// if being displayed on the front-end, masquerade as a custom type so that the proper 'active' classes will be applied to the hierarchy
				if ( !is_admin() ) {
					$item->type = $item->object = 'custom';
				}
			}
		}
		return $item;
	}

	/**
	 * Meta box callback for CPT Archive menu item.
	 */
	static function cpt_archive_mb() {
		$post_types = get_post_types( [ 'show_in_nav_menus' => true, 'has_archive' => true ], 'object' );

		if ( $post_types ) {
			$items = [];

			foreach ( $post_types as $post_type ) {
				$items[] = (object) [
					'object_id' => -1,
					'db_id' => 0,
					'object' => $post_type->query_var,
					'menu_item_parent' => 0,
					'type' => self::CPT_TYPE,
					'title' => $post_type->labels->name,
					'url' => '',
					'target' => '',
					'attr_title' => '',
					'classes' => [],
					'xfn' => '',
				];
			}

			$walker = new \Walker_Nav_Menu_Checklist( [] );

			?>
			<div id="posttype-archive" class="posttypediv">
				<div id="tabs-panel-posttype-archive" class="tabs-panel tabs-panel-active">
					<ul id="posttype-archive-checklist" class="categorychecklist form-no-clear">
						<?php
						echo walk_nav_menu_tree( array_map( 'wp_setup_nav_menu_item', $items ), 0, (object) [ 'walker' => $walker ] );
						?>
					</ul>
				</div>
			</div>

			<p class="button-controls">
				<span class="add-to-menu">
					<input type="submit" class="button-secondary submit-add-to-menu right" value="<?php esc_attr_e( 'Add to Menu' ); ?>" name="add-posttype-archive-menu-item" id="submit-posttype-archive">
					<span class="spinner"></span>
				</span>
			</p>
			<?php
		}
	}

	/**
	 * Return all menu items that are a descendent of the provided menu object id.
	 * @param  array  $sorted_menu_items   nav menu objects, must be sorted to work properly
	 * @param  int  $root_id           id of menu object that will act as the root
	 * @param  boolean $with_self       include the menu object itself that is acting as root
	 * @param  boolean $only_expand_immediate_children   only expand immediate children of root and current item
	 * @return array    filtered objects
	 */
	static function children_of_root( $sorted_menu_items, $root_id, $with_self = false, $only_expand_immediate_children = false ) {
		$items = [];
		// save only items in the root's subtree
		$parent_ids = [ $root_id ];
		foreach ( $sorted_menu_items as $key => $menu_item ) {
			// is this menu item a child of one of the parents or is the root item itself, if desired?
			if ( in_array( $menu_item->menu_item_parent, $parent_ids ) || ( $with_self && $menu_item->ID == $root_id ) ) {
				// this item is part of the tree, but should it be added as a valid parent for subsequent items to be allowed into the tree?
				if ( !$only_expand_immediate_children || $menu_item->current_item_ancestor || $menu_item->current ) {
					$parent_ids[] = $menu_item->ID;
				}
				$items[] = $menu_item;
			}
		}
		return $items;
	}

	/**
	 * When requesting a submenu, filter down for only those items
	 * @param  array $menu_items
	 * @param  object $args       menu args
	 * @filter wp_nav_menu_objects
	 */
	static function submenu_objects( $sorted_menu_items, $args ) {
		if ( isset( $args->sub_menu ) && $args->sub_menu ) {
			$root_id = 0;

			// explicit root nav item id is passed
			if ( isset( $args->root_id ) ) {
				$root_id = $args->root_id;

			// use the direct parent of the current item
			} else if ( isset( $args->direct_parent ) && $args->direct_parent ) {
				// find the current parent element id
				foreach ( $sorted_menu_items as $menu_item ) {
					if ( $menu_item->current_item_parent ) {
						$root_id = $menu_item->ID;
						break;
					}
				}

			// or use the root parent of the current item
			} else {
				// find the current root element id
				foreach ( $sorted_menu_items as $menu_item ) {
					if ( $menu_item->menu_item_parent == 0 && ( $menu_item->current_item_ancestor || $menu_item->current ) ) {
						$root_id = $menu_item->ID;
						break;
					}
				}
			}

			// no submenu for current page!
			if ( !( $root_id = apply_filters( 'wpnmi_submenu_root_id', $root_id, $sorted_menu_items, $args ) ) ) {
				return apply_filters( 'wpnmi_submenu_no_items', [], $sorted_menu_items, $args );
			}

			return self::children_of_root( $sorted_menu_items, $root_id, isset( $args->show_parent ) && $args->show_parent, isset( $args->immediate_children_only ) && $args->immediate_children_only );
		}
		return $sorted_menu_items;
	}

}
