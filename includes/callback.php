<?php

/**
 * @package Woody SSO
 * @author Léo POIROUX <leo@raccourci.fr>
 * @author Jeremy LEGENDRE <jeremy.legendre@raccourci.fr>
 */

defined('ABSPATH') or die('No script kiddies please!');

// Redirect the user back to the home page if logged in.
if (is_user_logged_in()) {
    wp_redirect(home_url());
    exit;
}

// Grab a copy of the options and set the redirect location.
$options = get_option('woody_sso_options');
$user_redirect_set = $options['redirect_to_dashboard'] == '1' ? get_dashboard_url() : site_url();
$user_redirect = apply_filters('wpssoc_user_redirect_url', $user_redirect_set);

if (!isset($_GET['code'])) {
    $params = array(
        'oauth'         => 'authorize',
        'response_type' => 'code',
        'client_id'     => $options['client_id'],
        'client_secret' => $options['client_secret'],
        'redirect_uri'  => site_url('/oauth/v2/auth?auth=sso'),
        'application'   => 'wordpress',
    );

    wp_redirect($options['server_url'] . '/oauth/v2/auth?' . http_build_query($params));
    exit;
}

// Handle the callback from the server is there is one.
if (!empty($_GET['code'])) {
    $code = sanitize_text_field($_GET['code']);
    $server_url = $options['server_url'] . '/oauth/v2/token';

    /**
     * default arg values to know :
     * 'redirection'   => 5,
     * 'httpversion' => '1.0',
     * 'blocking'    => true,
     * @link https://codex.wordpress.org/HTTP_API#Other_Arguments
     */

    $response = wp_remote_post($server_url, array(
        'method'      => 'POST',
        'timeout'     => 45,
        'headers'     => array(),
        'body'        => array(
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => $options['client_id'],
            'client_secret' => $options['client_secret'],
            'redirect_uri'  => site_url('/oauth/v2/auth?auth=sso'),
            'idp_application' => 'woody_' . WP_ENV,
            'site_key'      => WP_SITE_KEY
        ),
        'cookies'     => array(),
        'sslverify'   => false
    ));

    $tokens = json_decode($response['body']);

    if (isset($tokens->error)) {
        wp_die($tokens->error_description);
    }

    $server_url = $options['server_url'] . '/api/me?access_token=' . $tokens->access_token;

    $response = wp_remote_get($server_url, array(
        'timeout'     => 45,
        'headers'     => array(),
        'sslverify'   => false
    ));

    $user_info = json_decode($response['body']);
    $user_id = username_exists($user_info->login);

    $wpRoles = [];
    if (!empty($user_info)) {
        if (in_array('wp_admin', $user_info->roles)) {
            $wpRoles = ['administrator'];
        } else {
            foreach ($user_info->products as $product) {
                if ($product->technical_name === 'website' && $product->slug === 'wordpress' && $product->key === WP_SITE_KEY) {
                    foreach ($user_info->roles as $role) {
                        if (strpos($role, 'wp_') !== false) {
                            $wpRoles[] = str_replace('wp_', '', $role);
                        }
                    }
                    break;
                }
            }
        }
    }

    if (empty($wpRoles)) {
        wp_redirect(wp_login_url(home_url()) . '&error=restricted-access');
        exit;
    }

    if (!$user_id && email_exists($user_info->email) == false) {
        // Does not have an account... Register and then log the user in
        $random_password = wp_generate_password($length = 12, $include_standard_special_chars = false);
        $user_id = wp_create_user($user_info->login, $random_password, $user_info->email);

        // Trigger new user created action so that there can be modifications to what happens after the user is created.
        // This can be used to collect other information about the user.
        do_action('woody_sso_user_created', $user_info, 1);

        wp_clear_auth_cookie();
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);

        $user = new WP_User($user_id);
        foreach ($wpRoles as $role) {
            $user->add_role($role);
        }
    } else {
        // Already Registered... Log the User In
        $random_password = __('User already exists.  Password inherited.');
        $user = get_user_by('login', $user_info->login);
        $userInfos = get_userdata($user->ID);

        // remove old roles
        foreach ($userInfos->roles as $userRole) {
            $pos = array_search($userRole, $wpRoles);
            if ($pos !== false) {
                unset($wpRoles[$pos]);
            } else {
                $user->remove_role($userRole);
            }
        }

        // add new roles
        foreach ($wpRoles as $role) {
            $user->add_role($role);
        }

        // Trigger action when a user is logged in. This will help allow extensions to be used without modifying the
        // core plugin
        do_action('woody_sso_user_login', $user_info, 1);

        // User ID 1 is not allowed
        // if ('1' === $user->ID) {
        //     wp_die('For security reasons, this user can not use Single Sign On');
        // }

        wp_clear_auth_cookie();
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID);
    }

    if (is_user_logged_in()) {
        setcookie(WOODY_SSO_ACCESS_TOKEN, $tokens->access_token, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl());
        setcookie('woody_sso_refresh_token', $tokens->refresh_token, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl());
        setcookie('woody_sso_expiration_token', time() + $tokens->expires_in, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl());
        do_action('wp_login', $user->user_login, $user);
        wp_redirect($user_redirect);
        exit;
    }


    exit('Single Sign On Failed.');
}
