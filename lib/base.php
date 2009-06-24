<?php
/**
 * IMP base inclusion file. This file brings in all of the dependencies that
 * every IMP script will need, and sets up objects that all scripts use.
 *
 * The following variables, defined in the script that calls this one, are
 * used:
 *   $authentication  - The type of authentication to use:
 *                      'horde' - Only use horde authentication
 *                      'none'  - Do not authenticate
 *                      Default - Authenticate to IMAP/POP server
 *   $compose_page    - If true, we are on IMP's compose page
 *   $dimp_logout     - Logout and redirect to the login page.
 *   $login_page      - If true, we are on IMP's login page
 *   $mimp_debug      - If true, output text/plain version of page.
 *   $no_compress     - Controls whether the page should be compressed
 *   $session_control - Sets special session control limitations
 *
 * Global variables defined:
 *   $imp_imap    - An IMP_Imap object
 *   $imp_mbox    - Current mailbox information
 *   $imp_notify  - A Horde_Notification_Listener object
 *   $imp_search  - An IMP_Search object
 *   $mimp_render - (MIMP view only) A Horde_Mobile object
 *
 * Copyright 1999-2009 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/gpl.html.
 *
 * @package IMP
 */

// Determine BASE directories.
require_once dirname(__FILE__) . '/base.load.php';

// Load the Horde Framework core.
require_once HORDE_BASE . '/lib/core.php';

// Registry.
$s_ctrl = 0;
switch (Horde_Util::nonInputVar('session_control')) {
case 'netscape':
    if ($browser->isBrowser('mozilla')) {
        session_cache_limiter('private, must-revalidate');
    }
    break;

case 'none':
    $s_ctrl = Registry::SESSION_NONE;
    break;

case 'readonly':
    $s_ctrl = Registry::SESSION_READONLY;
    break;
}
$registry = &Registry::singleton($s_ctrl);

// We explicitly do not check application permissions for the compose
// and login pages, since those are handled below and need to fall through
// to IMP-specific code.
$compose_page = Horde_Util::nonInputVar('compose_page');
if (is_a(($pushed = $registry->pushApp('imp', !(defined('AUTH_HANDLER') || $compose_page))), 'PEAR_Error')) {
    if ($pushed->getCode() == 'permission_denied') {
        Horde::authenticationFailureRedirect();
    }
    Horde::fatal($pushed, __FILE__, __LINE__, false);
}
$conf = &$GLOBALS['conf'];
if (!defined('IMP_TEMPLATES')) {
    define('IMP_TEMPLATES', $registry->get('templates'));
}

// Initialize global $imp_imap object.
if (!isset($GLOBALS['imp_imap'])) {
    $GLOBALS['imp_imap'] = new IMP_Imap();
}

// Start compression.
if (!Horde_Util::nonInputVar('no_compress')) {
    Horde::compressOutput();
}

// If IMP isn't responsible for Horde auth, and no one is logged into
// Horde, redirect to the login screen. If this is a compose window
// that just timed out, store the draft.
if (!(Auth::isAuthenticated() || (Auth::getProvider() == 'imp'))) {
    if ($compose_page) {
        $imp_compose = &IMP_Compose::singleton();
        $imp_compose->sessionExpireDraft();
    }
    Horde::authenticationFailureRedirect();
}

// Determine view mode.
$viewmode = isset($_SESSION['imp']['view'])
    ? $_SESSION['imp']['view']
    : 'imp';

$authentication = Horde_Util::nonInputVar('authentication', 0);
if ($authentication !== 'none') {
    // If we've gotten to this point and have valid login credentials
    // but don't actually have an IMP session, then we need to go
    // through redirect.php to ensure that everything gets set up
    // properly. Single-signon and transparent authentication setups
    // are likely to trigger this case.
    if (empty($_SESSION['imp'])) {
        if ($compose_page) {
            $imp_compose = &IMP_Compose::singleton();
            $imp_compose->sessionExpireDraft();
            require IMP_BASE . '/login.php';
        } else {
            require IMP_BASE . '/redirect.php';
        }
        exit;
    }

    if ($compose_page) {
        if (!IMP::checkAuthentication(true, ($authentication === 'horde'))) {
            $imp_compose = &IMP_Compose::singleton();
            $imp_compose->sessionExpireDraft();
            require IMP_BASE . '/login.php';
            exit;
        }
    } elseif ($viewmode == 'dimp') {
        // Handle session timeouts
        if (!IMP::checkAuthentication(true)) {
            switch (Horde_Util::nonInputVar('session_timeout')) {
            case 'json':
                $GLOBALS['notification']->push(null, 'dimp.timeout');
                Horde::sendHTTPResponse(Horde::prepareResponse(), 'json');

            case 'none':
                exit;

            default:
                Horde::redirect(Horde_Util::addParameter(Horde::url($GLOBALS['registry']->get('webroot', 'imp') . '/redirect.php'), 'url', Horde::selfUrl(true)));
            }
        }
    } else {
        IMP::checkAuthentication(false, ($authentication === 'horde'));
    }

    /* Some stuff that only needs to be initialized if we are
     * authenticated. */
    // Initialize some message parsing variables.
    if (!empty($GLOBALS['conf']['mailformat']['brokenrfc2231'])) {
        Horde_Mime::$brokenRFC2231 = true;
    }

    // Set default message character set, if necessary
    if ($def_charset = $GLOBALS['prefs']->getValue('default_msg_charset')) {
        Horde_Mime_Part::$defaultCharset = $def_charset;
        Horde_Mime_Headers::$defaultCharset = $def_charset;
    }
}

// Handle logout requests
if (($viewmode == 'dimp') && Horde_Util::nonInputVar('dimp_logout')) {
    Horde::redirect(str_replace('&amp;', '&', IMP::getLogoutUrl()));
}

// Notification system.
$notification = &Horde_Notification::singleton();
if (($viewmode == 'mimp') ||
    (Horde_Util::nonInputVar('login_page') && $GLOBALS['browser']->isMobile())) {
    $GLOBALS['imp_notify'] = &$notification->attach('status', null, 'Horde_Notification_Listener_Mobile');
} elseif ($viewmode == 'dimp') {
    $GLOBALS['imp_notify'] = &$notification->attach('status', null, 'IMP_Notification_Listener_StatusDimp');
} else {
    $GLOBALS['imp_notify'] = &$notification->attach('status', null, 'IMP_Notification_Listener_StatusImp');
    $notification->attach('audio');
}

// Initialize global $imp_mbox array.
$GLOBALS['imp_mbox'] = IMP::getCurrentMailboxInfo();

// Initialize IMP_Search object.
$GLOBALS['imp_search'] = new IMP_Search(array('id' => (isset($_SESSION['imp']) && IMP_Search::isSearchMbox($GLOBALS['imp_mbox']['mailbox'])) ? $GLOBALS['imp_mbox']['mailbox'] : null));

if ($viewmode == 'mimp') {
    // Mobile markup renderer.
    $debug = Horde_Util::nonInputVar('mimp_debug');
    $GLOBALS['mimp_render'] = new Horde_Mobile(null, $debug);
    $GLOBALS['mimp_render']->set('debug', !empty($debug));
}
