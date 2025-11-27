<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stb_Taxonomies {

	public static function register_taxonomies(): void {
		register_taxonomy(
			'stb_skill',
			array( 'user', 'stb_location', 'stb_shift_template' ),
			array(
				'labels'       => array(
					'name'          => __( 'Skills', 'stb-core' ),
					'singular_name' => __( 'Skill', 'stb-core' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_rest' => true,
				'hierarchical' => false,
			)
		);
	}
}

