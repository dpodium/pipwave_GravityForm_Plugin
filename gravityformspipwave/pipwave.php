<?php
/*
Plugin Name: Gravity Forms pipwave Standard Add-On
Plugin URI: https://www.pipwave.com/
Description: Simple, reliable, and cost-effective way to accept payments online. And it's free to use!
Version: 1.0
Author: pipwave
Author URI: https://www.pipwave.com/
------------------------------------------------------------------------
* Copyright 2009 -  rocketnotgenius
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 *
 */

define( 'GF_PIPWAVE_VERSION', 1.0 );

add_action( 'gform_loaded', array( 'GF_pipwave_Bootstrap', 'load' ),5 );

class GF_pipwave_Bootstrap {
    public static function load() {
        if ( !method_exists( 'GFForms', 'include_payment_addon_framework' ) ) {
            return;
        }
        require_once( 'class-gf-pipwave.php' );
        GFAddOn::register( 'GFpipwave' );
    }
}

function gf_pipwave() {
    return GFpipwave::get_instance();
}