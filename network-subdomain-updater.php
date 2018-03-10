<?php
/**
 * @wordpress-plugin
 * Plugin Name:       Network Sub-Domain Updater
 * Description:       Update network (multisite) sub-domains after MySQL data import.
 * Version:           1.0.1
 * Author:            Daniel M. Hendricks
 * Author URI:        https://github.com/dmhendricks/wordpress-network-subdomain-updater-plugin/
 * License:           GPL-2.0
 * License URI:       https://opensource.org/licenses/GPL-2.0
 */
namespace CloudVerve\NetworkSubdomainUpdater;

class SubdomainUpdate {

  private $plugin_link = 'https://github.com/dmhendricks/wordpress-network-subdomain-updater-plugin';

  function __construct() {

    define( __NAMESPACE__ . '\VERSION', '1.0.1' );

    // If NETWORK_LOCAL_DOMAIN isn't defined, do nothing.
    if( ( !defined( 'NETWORK_LOCAL_DOMAIN' ) && !trim( NETWORK_LOCAL_DOMAIN ) ) || !defined( 'SITE_ID_CURRENT_SITE' ) ) return;

    // If network domain hasn't changed, do nothing.
    global $wpdb;
    $current_domain = current( $wpdb->get_col( $wpdb->prepare( "SELECT domain FROM $wpdb->site WHERE id = %d", SITE_ID_CURRENT_SITE ) ) );
    $local_domain = strtolower( trim( NETWORK_LOCAL_DOMAIN ) );
    if( !$current_domain || $current_domain == $local_domain ) return;

    // Update admin e-mail address, if defined
    if( defined( 'WP_ADMIN_EMAIL' ) && WP_ADMIN_EMAIL ) {
      update_option( 'admin_email', WP_ADMIN_EMAIL );
    }

    // Update site sub-domains to match local domain
    $this->update_network_domain( $wpdb, $current_domain, $local_domain );

    // Send e-mail message to admin, if enabled
    if( defined( 'NETWORK_LOCAL_UPDATE_NOTIFY' ) && NETWORK_LOCAL_UPDATE_NOTIFY ) {
      $this->send_email_notification( $local_domain );
    }

  }

  /**
    * Update admin e-mail address, if defined
    *
    * @param object $wpdb Passed as reference so we don't reinstantiate another
    * @param string $current_domain The current primary network domain
    * @param string $new_domain The new domain to replace the $current_domain with
    * @since 1.0.0
    */
  private function update_network_domain( &$wpdb, $current_domain, $new_domain ) {

    $scheme = defined( 'NETWORK_LOCAL_DOMAIN_SCHEME' ) && NETWORK_LOCAL_DOMAIN_SCHEME ? strtolower( trim( NETWORK_LOCAL_DOMAIN_SCHEME ) ) : null;

    // [wp_blogs.domain] Update each site's domain
    $blogs = array();
    $sites = $wpdb->get_results( $wpdb->prepare( "SELECT blog_id, domain FROM $wpdb->blogs WHERE site_id = %d", SITE_ID_CURRENT_SITE ) );
    foreach( $sites as $site ) {
      $wpdb->update( $wpdb->blogs, [ 'domain' => str_ireplace( $current_domain, $new_domain, $site->domain ) ], [ 'site_id' => SITE_ID_CURRENT_SITE, 'blog_id' => $site->blog_id ], [ '%s' ], [ '%d' ] );
      $blogs[] = $site->blog_id;
    }

    // [wp_X_options] Update home / siteurl for each site
    foreach( $blogs as $blog_id ) {
      $current_url = get_blog_option( $blog_id, 'siteurl' );
      $new_site_url = $this->set_url_scheme( $scheme, str_ireplace( $current_domain, $new_domain, $current_url ) );
      update_blog_option( $blog_id, 'home', $new_site_url );
      update_blog_option( $blog_id, 'siteurl', $new_site_url );
    }

    // [wp_options] Update home and siteurl values
    $new_domain_url = rtrim( $this->set_url_scheme( $scheme, str_ireplace( $current_domain, $new_domain, network_site_url() ) ), '/' );
    update_option( 'home', $new_domain_url );
    update_option( 'siteurl', $new_domain_url );

    // [wp_site.domain] Update current site domain
    $wpdb->update( $wpdb->site, [ 'domain' => $new_domain ], [ 'id' => SITE_ID_CURRENT_SITE ], [ '%s' ], [ '%d' ] );

    // [wp_sitemeta.siteurl] Update siteurl value
    $wpdb->update( $wpdb->sitemeta, [ 'siteurl' => $new_domain ], [ 'site_id' => SITE_ID_CURRENT_SITE ], [ '%s' ], [ '%d' ] );


  }

  /**
    * Send e-mail notification, if enabled
    *
    * @param string $local_domain The current primary network domain
    * @since 1.0.0
    */
  private function send_email_notification( $local_domain ) {

    $default_email = get_option( 'admin_email' );
    $default_subject = sprintf( '[%s] Network Domain Updated', $local_domain );
    $default_message = sprintf( "This is an automated message sent by Network Sub-Domain Updater for WordPress v%s:\n%s", constant( __NAMESPACE__ . '\VERSION' ), $this->plugin_link );

    if( is_array( NETWORK_LOCAL_UPDATE_NOTIFY ) || is_bool( NETWORK_LOCAL_UPDATE_NOTIFY ) ) {

      // If NETWORK_LOCAL_UPDATE_NOTIFY is not defined, use defaults
      $notify_email = isset( NETWORK_LOCAL_UPDATE_NOTIFY['email'] ) ? NETWORK_LOCAL_UPDATE_NOTIFY['email'] : $default_email;
      $notify_subject = isset( NETWORK_LOCAL_UPDATE_NOTIFY['subject'] ) ? NETWORK_LOCAL_UPDATE_NOTIFY['subject'] : $default_subject;
      $notify_message = isset( NETWORK_LOCAL_UPDATE_NOTIFY['message'] ) ? NETWORK_LOCAL_UPDATE_NOTIFY['message'] : $default_message;
      mail( $notify_email, $notify_subject, $notify_message );

    } else {

      // Send to NETWORK_LOCAL_UPDATE_NOTIFY if valid e-mail address, else WordPress admin e-mail
      $notify_email = filter_var( NETWORK_LOCAL_UPDATE_NOTIFY, FILTER_VALIDATE_EMAIL ) ? NETWORK_LOCAL_UPDATE_NOTIFY : $default_email;
      mail( $notify_email, $default_subject, $default_message );

    }

  }

  /**
    * Modify URL to force a specific protocol scheme, if defined
    *
    * @param string $scheme The protocol scheme to force ('http' or 'https').
    *     If null or empty, $url will be returned unmodified.
    * @param string $url The URL to be modified (if $scheme is not null or empty)
    * @return string The resulting URL
    * @since 1.0.0
    */
  private function set_url_scheme( $scheme, $url ) {

    if( !$scheme || !filter_var( $url, FILTER_VALIDATE_URL ) ) return $url;

    $url = explode( '//', $url );
    if( $url[0] ) $url[0] = $scheme . ':';
    return implode( '//', $url );

  }

}
new SubdomainUpdate();
