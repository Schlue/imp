<?php
/**
 * Compose script for traditional (IMP) view.
 *
 * Copyright 1999-2009 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/gpl.html.
 *
 * @author  Chuck Hagenbuch <chuck@horde.org>
 * @author  Michael Slusarz <slusarz@horde.org>
 * @package IMP
 */

function _mailboxReturnURL($encode, $url = null)
{
    if (empty($url)) {
        $url = Horde::applicationUrl('mailbox.php');
    }

    foreach (array('start', 'page', 'mailbox', 'thismailbox') as $key) {
        if (($param = Util::getFormData($key))) {
            $url = Util::addParameter($url, $key, $param, $encode);
        }
    }

    return $url;
}

function _popupSuccess()
{
    require_once 'Horde/Menu.php';
    $menu = new Menu(HORDE_MENU_MASK_NONE);
    $menu->add(Horde::applicationUrl('compose.php'), _("New Message"), 'compose.png');
    $menu->add('', _("Close this window"), 'close.png', $GLOBALS['registry']->getImageDir('horde'), '', 'window.close();');
    require IMP_TEMPLATES . '/common-header.inc';
    $success_template = new IMP_Template();
    $success_template->set('menu', $menu->render());
    echo $success_template->fetch(IMP_TEMPLATES . '/compose/success.html');
    IMP::status();
    require $GLOBALS['registry']->get('templates', 'horde') . '/common-footer.inc';
}

function &_getIMPContents($index, $mailbox)
{
    if (empty($index)) {
        return false;
    }
    $imp_contents = &IMP_Contents::singleton($index . IMP::IDX_SEP . $mailbox);
    if (is_a($imp_contents, 'PEAR_Error')) {
        $GLOBALS['notification']->push(_("Could not retrieve the message from the mail server."), 'horde.error');
        return false;
    }
    return $imp_contents;
}


$compose_page = true;
$session_control = 'netscape';
require_once dirname(__FILE__) . '/lib/base.php';
require_once 'Horde/Help.php';
require_once 'Horde/Identity.php';
require_once 'Horde/Text/Filter.php';

/* The message text. */
$msg = '';

/* The headers of the message. */
$header = array(
    'in_reply_to' => Util::getFormData('in_reply_to'),
    'references' => Util::getFormData('references')
);

$get_sig = true;
$pgp_passphrase_dialog = $pgp_symmetric_passphrase_dialog = $showmenu = $smime_passphrase_dialog = false;
$cursor_pos = $oldrtemode = $rtemode = $siglocation = null;

/* Set the current identity. */
$identity = &Identity::singleton(array('imp', 'imp'));
if (!$prefs->isLocked('default_identity')) {
    $identity_id = Util::getFormData('identity');
    if (!is_null($identity_id)) {
        $identity->setDefault($identity_id);
    }
}

/* Catch submits if javascript is not present. */
if (!($actionID = Util::getFormData('actionID'))) {
    foreach (array('send_message', 'save_draft', 'cancel_compose', 'add_attachment', 'compose_expand_addr') as $val) {
        if (Util::getFormData('btn_' . $val)) {
            $actionID = $val;
            break;
        }
    }
}

if ($actionID) {
    switch ($actionID) {
    case 'mailto':
    case 'mailto_link':
    case 'draft':
    case 'reply':
    case 'reply_all':
    case 'reply_list':
    case 'forward_all':
    case 'forward_body':
    case 'forward_attachments':
    case 'redirect_compose':
    case 'fwd_digest':
        // These are all safe actions that might be invoked without a token.
        break;

    default:
        try {
            IMP::checkRequestToken('imp.compose', Util::getFormData('compose_requestToken'));
        } catch (Horde_Exception $e) {
            $notification->push($e);
            $actionID = null;
        }
    }
}

$save_sent_mail = Util::getFormData('save_sent_mail');
$sent_mail_folder = $identity->getValue('sent_mail_folder');
$index = Util::getFormData('index');
$reply_index = Util::getFormData('reply_index');
$thismailbox = Util::getFormData('thismailbox');

/* Check for duplicate submits. */
if ($token = Util::getFormData('compose_formToken')) {
    $tokenSource = isset($conf['token'])
        ? Horde_Token::factory($conf['token']['driver'], Horde::getDriverConfig('token', $conf['token']['driver']))
        : Horde_Token::factory('file');

    $verified = $tokenSource->verify($token);
    /* Notify and reset the actionID. */
    if (is_a($verified, 'PEAR_Error')) {
        $notification->push($verified);
        $actionID = null;
    } elseif (!$verified) {
        $notification->push(_("You have already submitted this page."), 'horde.error');
        $actionID = null;
    }
}

/* Set the current time zone. */
NLS::setTimeZone();

/* Determine if compose mode is disabled. */
$compose_disable = !empty($conf['hooks']['disable_compose']) &&
                   Horde::callHook('_imp_hook_disable_compose', array(true), 'imp');

/* Determine if mailboxes are readonly. */
$readonly_drafts = $readonly_sentmail = false;
$draft = IMP::folderPref($prefs->getValue('drafts_folder'), true);
if (!empty($draft)) {
    $readonly_drafts = $imp_imap->isReadOnly($draft);
}
$readonly_sentmail = $imp_imap->isReadOnly($sent_mail_folder);
if ($readonly_sentmail) {
    $save_sent_mail = false;
}

/* Initialize the IMP_Compose:: object. */
$imp_compose = &IMP_Compose::singleton(Util::getFormData('composeCache'));
$imp_compose->pgpAttachPubkey((bool) Util::getFormData('pgp_attach_pubkey'));
$imp_compose->userLinkAttachments((bool) Util::getFormData('link_attachments'));

$vcard = $imp_compose->attachVCard((bool) Util::getFormData('vcard'), $identity->getValue('fullname'));
if (is_a($vcard, 'PEAR_Error')) {
    $notification->push($vcard);
}

/* Init IMP_UI_Compose:: object. */
$imp_ui = new IMP_UI_Compose();

/* Set the default charset & encoding.
 * $charset - charset to use when sending messages
 * $encoding - best guessed charset offered to the user as the default value
 *             in the charset dropdown list. */
$charset = $prefs->isLocked('sending_charset') ? NLS::getEmailCharset() : Util::getFormData('charset');
$encoding = empty($charset) ? NLS::getEmailCharset() : $charset;

/* Is this a popup window? */
$has_js = $browser->hasFeature('javascript');
$isPopup = (($prefs->getValue('compose_popup') || Util::getFormData('popup')) && $has_js);

/* Determine the composition type - text or HTML.
   $rtemode is null if browser does not support it. */
$rtemode = null;
if ($_SESSION['imp']['rteavail']) {
    if ($prefs->isLocked('compose_html')) {
        $rtemode = $prefs->getValue('compose_html');
    } else {
        $rtemode = Util::getFormData('rtemode');
        if (is_null($rtemode)) {
            $rtemode = $prefs->getValue('compose_html');
        } else {
            $oldrtemode = Util::getFormData('oldrtemode');
            $get_sig = false;
        }
    }
}

/* Load stationery. */
$stationery_list = array();
if (!$prefs->isLocked('stationery')) {
    $stationery = null;
    $all_stationery = @unserialize($prefs->getValue('stationery', false));
    if (is_array($all_stationery)) {
        $all_stationery = String::convertCharset($all_stationery, $prefs->getCharset());
        foreach ($all_stationery as $id => $choice) {
            if (($choice['t'] == 'plain') ||
                (($choice['t'] == 'html') && $rtemode)) {
                if ($rtemode && $choice['t'] == 'plain') {
                    $choice['c'] = $imp_compose->text2html($choice['c']);
                }
                $stationery_list[$id] = $choice;
            }
        }
    }
}

/* Update the file attachment information. */
if ($_SESSION['imp']['file_upload']) {
    /* Only notify if we are reloading the compose screen. */
    $notify = ($actionID != 'send_message') && ($actionID != 'save_draft');

    $deleteList = Util::getPost('delattachments', array());

    /* Update the attachment information. */
    foreach (array_keys($imp_compose->getAttachments()) as $i) {
        if (!in_array($i, $deleteList)) {
            $description = Util::getFormData('file_description_' . $i);
            $imp_compose->updateAttachment($i, array('description' => $description));
        }
    }

    /* Delete attachments. */
    if (!empty($deleteList)) {
        $filenames = $imp_compose->deleteAttachment($deleteList);
        if ($notify) {
            foreach ($filenames as $val) {
                $notification->push(sprintf(_("Deleted the attachment \"%s\"."), Horde_Mime::decode($val)), 'horde.success');
            }
        }
    }

    /* Add new attachments. */
    if (!$imp_compose->addFilesFromUpload('upload_', $notify)) {
        $actionID = null;
    }
}

/* Run through the action handlers. */
$title = _("New Message");
switch ($actionID) {
case 'mailto':
    if (!($imp_contents = &_getIMPContents($index, $thismailbox))) {
        break;
    }
    $imp_headers = $imp_contents->getHeaderOb();
    $header['to'] = '';
    if (Util::getFormData('mailto')) {
        $header['to'] = $imp_headers->getValue('to');
    }
    if (empty($header['to'])) {
        ($header['to'] = Horde_Mime_Address::addrArray2String($imp_headers->getOb('from'))) ||
        ($header['to'] = Horde_Mime_Address::addrArray2String($imp_headers->getOb('reply-to')));
    }
    break;

case 'mailto_link':
    $args = IMP::getComposeArgs();
    if (isset($args['body'])) {
        $msg = $args['body'];
    }
    foreach (array('to', 'cc', 'bcc', 'subject') as $val) {
        if (isset($args[$val])) {
            $header[$val] = $args[$val];
        }
    }
    break;

case 'draft':
    $result = $imp_compose->resumeDraft($index . IMP::IDX_SEP . $thismailbox);
    if (is_a($result, 'PEAR_Error')) {
        $notification->push($result->getMessage(), 'horde.error');
    } else {
        if (!is_null($rtemode)) {
            $rtemode = ($result['mode'] == 'html');
        }
        $msg = $result['msg'];
        $header = array_merge($header, $result['header']);
        if (!is_null($result['identity']) &&
            ($result['identity'] != $identity->getDefault()) &&
            !$prefs->isLocked('default_identity')) {
            $identity->setDefault($result['identity']);
            $sent_mail_folder = $identity->getValue('sent_mail_folder');
        }
        $resume_draft = true;
    }
    $get_sig = false;
    break;

case 'compose_expand_addr':
case 'redirect_expand_addr':
    $header['to'] = $imp_ui->expandAddresses(Util::getFormData('to'), $imp_compose);
    if ($actionID == 'compose_expand_addr') {
        $header['cc'] = $imp_ui->expandAddresses(Util::getFormData('cc'), $imp_compose);
        $header['bcc'] = $imp_ui->expandAddresses(Util::getFormData('bcc'), $imp_compose);
    }
    $get_sig = false;
    break;

case 'reply':
case 'reply_all':
case 'reply_list':
    if (!($imp_contents = &_getIMPContents($index, $thismailbox))) {
        break;
    }

    $reply_msg = $imp_compose->replyMessage($actionID, $imp_contents, Util::getFormData('to'));
    $msg = $reply_msg['body'];
    $header = $reply_msg['headers'];
    $format = $reply_msg['format'];

    if (!is_null($rtemode)) {
        $rtemode = $rtemode || $format == 'html';
    }

    if ($actionID == 'reply') {
        $title = _("Reply:");
    } elseif ($actionID == 'reply_all') {
        $title = _("Reply to All:");
    } elseif ($actionID == 'reply_list') {
        $title = _("Reply to List:");
    }
    $title .= ' ' . $header['subject'];

    $encoding = empty($charset) ? $reply_msg['encoding'] : $charset;
    $reply_index = $index;
    break;

case 'forward_all':
case 'forward_body':
case 'forward_attachments':
    if (!($imp_contents = &_getIMPContents($index, $thismailbox))) {
        break;
    }

    $fwd_msg = $imp_ui->getForwardData($imp_compose, $imp_contents, $actionID, $index . IMP::IDX_SEP . $thismailbox);
    if ($actionID == 'forward_all') {
        $msg = '';
    } else {
        $msg = $fwd_msg['body'];
    }
    $header = $fwd_msg['headers'];
    $format = $fwd_msg['format'];
    $rtemode = ($rtemode || (!is_null($rtemode) && ($format == 'html')));
    $title = $header['title'];
    $encoding = empty($charset) ? $fwd_msg['encoding'] : $charset;
    break;

case 'redirect_compose':
    $title = _("Redirect this message");
    break;

case 'redirect_send':
    if (!($imp_contents = &_getIMPContents($index, $thismailbox))) {
        break;
    }

    $f_to = Util::getFormData('to', $imp_ui->getAddressList(Util::getFormData('to'), Util::getFormData('to_list'), Util::getFormData('to_field'), Util::getFormData('to_new')));

    try {
        $imp_ui->redirectMessage($f_to, $imp_compose, $imp_contents, $encoding);
        if ($isPopup) {
            if ($prefs->getValue('compose_confirm')) {
                $notification->push(_("Message redirected successfully."), 'horde.success');
                _popupSuccess();
            } else {
                Util::closeWindowJS();
            }
        } else {
            if ($prefs->getValue('compose_confirm')) {
                $notification->push(_("Message redirected successfully."), 'horde.success');
            }
            header('Location: ' . _mailboxReturnURL(false));
        }
        exit;
    } catch (Horde_Exception $e) {
        $notification->push($e, 'horde.error');
        $actionID = 'redirect_compose';
        $get_sig = false;
    }
    break;

case 'send_message':
    if ($compose_disable) {
        break;
    }

    $from = $identity->getFromLine(null, Util::getFormData('from'));
    if (is_a($from, 'PEAR_Error')) {
        $get_sig = false;
        $notification->push($from);
        break;
    }
    $header['from'] = $from;
    $header['replyto'] = $identity->getValue('replyto_addr');

    $header['to'] = $imp_ui->getAddressList(Util::getFormData('to'), Util::getFormData('to_list'), Util::getFormData('to_field'), Util::getFormData('to_new'));
    if ($prefs->getValue('compose_cc')) {
        $header['cc'] = $imp_ui->getAddressList(Util::getFormData('cc'), Util::getFormData('cc_list'), Util::getFormData('cc_field'), Util::getFormData('cc_new'));
    }
    if ($prefs->getValue('compose_bcc')) {
        $header['bcc'] = $imp_ui->getAddressList(Util::getFormData('bcc'), Util::getFormData('bcc_list'), Util::getFormData('bcc_field'), Util::getFormData('bcc_new'));
    }

    $message = Util::getFormData('message');
    $header['subject'] = Util::getFormData('subject', '');
    if ($smf = Util::getFormData('sent_mail_folder')) {
        $sent_mail_folder = $smf;
    }

    $options = array(
        'save_sent' => $save_sent_mail,
        'sent_folder' => $sent_mail_folder,
        'save_attachments' => Util::getFormData('save_attachments_select'),
        'reply_type' => Util::getFormData('reply_type'),
        'reply_index' => (empty($reply_index) ? null : $reply_index . IMP::IDX_SEP . $thismailbox),
        'encrypt' => Util::getFormData('encrypt_options'),
        'priority' => Util::getFormData('x_priority'),
        'readreceipt' => Util::getFormData('request_read_receipt')
    );

    try {
        $sent = $imp_compose->buildAndSendMessage($message, $header, $charset, $rtemode, $options);
    } catch (IMP_Compose_Exception $e) {
        $get_sig = false;
        $code = $e->getCode();
        $notification->push($e, strpos($code, 'horde.') === 0 ? $code : 'horde.error');
        // TODO
        switch ($e->encrypt) {
        case 'pgp_symmetric_passphrase_dialog':
            $pgp_symmetric_passphrase_dialog = true;
            break;

        case 'pgp_passphrase_dialog':
            $pgp_passphrase_dialog = true;
            break;

        case 'smime_passphrase_dialog':
            $smime_passphrase_dialog = true;
            break;
        }
        break;
    }

    if (Util::getFormData('resume_draft') &&
        $prefs->getValue('auto_delete_drafts') &&
        ($thismailbox == IMP::folderPref($prefs->getValue('drafts_folder'), true)))  {
        $imp_message = &IMP_Message::singleton();
        $idx_array = array($index . IMP::IDX_SEP . $thismailbox);
        if ($imp_message->delete($idx_array)) {
            $notification->push(_("The draft message was automatically deleted because it was successfully completed and sent."), 'horde.success');
        }
    }

    if ($isPopup) {
        if ($prefs->getValue('compose_confirm') || !$sent) {
            if ($sent) {
                $notification->push(_("Message sent successfully."), 'horde.success');
            }
            _popupSuccess();
        } else {
            Util::closeWindowJS();
        }
    } else {
        if ($prefs->getValue('compose_confirm') && $sent) {
            $notification->push(_("Message sent successfully."), 'horde.success');
        }
        header('Location: ' . _mailboxReturnURL(false));
    }
    exit;

case 'save_draft':
    if ($readonly_drafts) {
        break;
    }

    /* Set up the From address based on the identity. */
    $header['from'] = $identity->getFromLine(null, Util::getFormData('from'));
    if (is_a($header['from'], 'PEAR_Error')) {
        $get_sig = false;
        $notification->push($header['from']);
        break;
    }
    foreach (array('to', 'cc', 'bcc', 'subject') as $val) {
        $header[$val] = Util::getFormData($val);
    }
    $message = Util::getFormData('message', '');

    /* Save the draft. */
    try {
        $result = $imp_compose->saveDraft($header, $message, NLS::getCharset(), $rtemode);

        /* Closing draft if requested by preferences. */
        if ($isPopup) {
            if ($prefs->getValue('close_draft')) {
                Util::closeWindowJS();
                exit;
            } else {
                $notification->push($result, 'horde.success');
            }
        } else {
            $notification->push($result);
            header('Location: ' . _mailboxReturnURL(false));
            exit;
        }
    } catch (IMP_Compose_Exception $e) {
        $notification->push($e, 'horde.error');
    }

    $get_sig = false;
    break;

case 'fwd_digest':
    $indices = Util::getFormData('fwddigest');
    if (!empty($indices)) {
        $msglist = unserialize(urldecode($indices));
        $subject_header = $imp_compose->attachIMAPMessage($msglist);
        if ($subject_header === false) {
            // TODO: notification
        } else {
            $header['subject'] = $subject_header;
        }
    }
    break;

case 'cancel_compose':
    $imp_compose->deleteAllAttachments();
    if ($isPopup) {
        Util::closeWindowJS();
    } else {
        header('Location: ' . _mailboxReturnURL(false));
    }
    exit;

case 'selectlist_process':
    $select_id = Util::getFormData('selectlist_selectid');
    if (!empty($select_id) &&
        $registry->hasMethod('files/selectlistResults') &&
        $registry->hasMethod('files/returnFromSelectlist')) {
        $filelist = $registry->call('files/selectlistResults', array($select_id));
        if ($filelist && !is_a($filelist, 'PEAR_Error')) {
            $i = 0;
            foreach ($filelist as $val) {
                $data = $registry->call('files/returnFromSelectlist', array($select_id, $i++));
                if ($data && !is_a($data, 'PEAR_Error')) {
                    $part = new Horde_Mime_Part();
                    $part->setContents($data);
                    $part->setName(reset($val));
                    try {
                        $imp_compose->addMIMEPartAttachment($part);
                    } catch (IMP_Compose_Exception $e) {
                        $notification->push($e, 'horde.error');
                    }
                }
            }
        }
    }
    break;

case 'change_stationery':
    if (empty($stationery_list)) {
        break;
    }
    $stationery = Util::getFormData('stationery');
    if (strlen($stationery)) {
        $stationery = (int)$stationery;
        $stationery_content = $stationery_list[$stationery]['c'];
        $msg = Util::getFormData('message', '');
        if (strpos($stationery_content, '%s') !== false) {
            $sig = $identity->getSignature();
            if ($rtemode) {
                $sig = $imp_compose->text2html($sig);
                $stationery_content = $imp_compose->text2html($stationery_content);
            }
            $msg = str_replace(array("\r\n", $sig), array("\n", ''), $msg);
            $stationery_content = str_replace('%s', $sig, $stationery_content);
        }
        if (strpos($stationery_content, '%c') === false) {
            $msg .= $stationery_content;
        } else {
            $msg = str_replace('%c', $msg, $stationery_content);
        }
    }
    $get_sig = false;
    break;

case 'add_attachment':
    $get_sig = false;
    break;
}

/* Get the message cache ID. */
$composeCacheID = $imp_compose->getCacheId();

/* Are we in redirect mode? */
$redirect = in_array($actionID, array('redirect_compose', 'redirect_expand_addr'));

/* Attach autocompleters to the compose form elements. */
$spellcheck = false;
if ($has_js) {
    if ($redirect) {
        $imp_ui->attachAutoCompleter(array('to'));
    } else {
        $auto_complete = array('to');
        foreach (array('cc', 'bcc') as $val) {
            if ($prefs->getValue('compose_' . $val)) {
                $auto_complete[] = $val;
            }
        }
        $imp_ui->attachAutoCompleter($auto_complete);
        if (!empty($conf['spell']['driver'])) {
            try {
                Horde_SpellChecker::getInstance($conf['spell']['driver'], array());
                $spellcheck = true;
                $imp_ui->attachSpellChecker('imp', true);
            } catch (Exception $e) {
                Horde::logMessage($e, __FILE__, __LINE__, PEAR_LOG_ERR);
            }
        }
        Horde::addScriptFile('ieEscGuard.js', 'horde', true);
    }
}

$max_attach = $imp_compose->additionalAttachmentsAllowed();
$smf_check = !empty($conf['user']['select_sentmail_folder']) && !$prefs->isLocked('sent_mail_folder');

/* Get the URL to use for the cancel action. */
$cancel_url = '';
if ($isPopup) {
    /* If the attachments cache is not empty, we must reload this page
     * and delete the attachments. */
    if ($imp_compose->numberOfAttachments()) {
        $cancel_url = Util::addParameter(Horde::selfUrl(), array('actionID' => 'cancel_compose', 'composeCache' => $composeCacheID, 'popup' => 1), null, false);
    }
} else {
    /* If the attachments cache is not empty, we must reload this page and
       delete the attachments. */
    if ($imp_compose->numberOfAttachments()) {
        $cancel_url = Util::addParameter(_mailboxReturnURL(true, Horde::selfUrl()), array('actionID' => 'cancel_compose', 'composeCache' => $composeCacheID), null, false);
    } else {
        $cancel_url = _mailboxReturnURL(true);
    }
    $showmenu = true;
}

/* Grab any data that we were supplied with. */
if (empty($msg)) {
    $msg = Util::getFormData('message', Util::getFormData('body', ''));
    if ($browser->hasQuirk('double_linebreak_textarea')) {
        $msg = preg_replace('/(\r?\n){3}/', '$1', $msg);
    }
    $msg = "\n" . $msg;
}

/* Get the current signature. */
$sig = $identity->getSignature();

/* Convert from Text -> HTML or vice versa if RTE mode changed. */
if (!is_null($oldrtemode) && ($oldrtemode != $rtemode)) {
    if ($rtemode) {
        /* Try to find the signature, replace it with a placeholder,
         * HTML-ize the message, then replace the signature
         * placeholder with the HTML-ized signature, complete with
         * marker comment. */
        $msg = preg_replace('/' . preg_replace('/(?<!^)\s+/', '\\s+', preg_quote($sig, '/')) . '/', '##IMP_SIGNATURE##', $msg, 1);
        $msg = preg_replace('/\s+##IMP_SIGNATURE##/', '##IMP_SIGNATURE_WS####IMP_SIGNATURE##', $msg);
        $msg = $imp_compose->text2html($msg);
        $msg = str_replace(array('##IMP_SIGNATURE_WS##', '##IMP_SIGNATURE##'),
                           array('<p>&nbsp;</p>', '<p class="imp-signature"><!--begin_signature-->' . $imp_compose->text2html($sig) . '<!--end_signature--></p>'),
                           $msg);
    } else {
        $msg = Text_Filter::filter($msg, 'html2text');
    }
}

/* If this is the first page load for this compose item, add auto BCC
 * addresses. */
if (!$token && ($actionID != 'draft')) {
    $header['bcc'] = Horde_Mime_Address::addrArray2String($identity->getBccAddresses());
}

foreach (array('to', 'cc', 'bcc', 'subject') as $val) {
    if (!isset($header[$val])) {
        $header[$val] = Util::getFormData($val, $imp_ui->getAddressList(Util::getFormData($val), Util::getFormData($val . '_list'), Util::getFormData($val . '_field'), Util::getFormData($val . '_new')));
    }
}

if ($get_sig && isset($msg) && !empty($sig)) {
    if ($rtemode) {
        $sig = '<p>&nbsp;</p><p class="imp-signature"><!--begin_signature-->' . $imp_compose->text2html(trim($sig)) . '<!--end_signature--></p>';
    }

    if ($identity->getValue('sig_first')) {
        $siglocation = 0;
        $msg = "\n" . $sig . $msg;
    } else {
        $siglocation = String::length($msg);
        /* We always add a line break at the beginning, so if length is 1,
           ignore that line break (i.e. the message is empty). */
        if ($siglocation == 1) {
            $siglocation = 0;
        }
        $msg .= "\n" . $sig;
    }
}

/* Open the passphrase window here. */
if ($pgp_passphrase_dialog || $pgp_symmetric_passphrase_dialog) {
    if ($pgp_passphrase_dialog) {
        $notification->push(IMP::passphraseDialogJS('PGPPersonal', 'uniqSubmit(\'send_message\')'), 'javascript');
    } else {
        $notification->push(IMP::passphraseDialogJS('PGPSymmetric', 'uniqSubmit(\'send_message\')', array('symmetricid' => 'imp_compose_' . $composeCacheID)), 'javascript');
    }
} elseif ($smime_passphrase_dialog) {
    $notification->push(IMP::passphraseDialogJS('SMIMEPersonal', 'uniqSubmit(\'send_message\')'), 'javascript');
}

/* If PGP encryption is set by default, and we have a recipient list on first
 * load, make sure we have public keys for all recipients. */
$encrypt_options = Util::getFormData('encrypt_options');
if ($prefs->getValue('use_pgp')) {
    $default_encrypt = $prefs->getValue('default_encrypt');
    if (!$token &&
        in_array($default_encrypt, array(IMP::PGP_ENCRYPT, IMP::PGP_SIGNENC))) {
        try {
            $addrs = $imp_compose->recipientList($header);
            if (!empty($addrs['list'])) {
                $imp_pgp = &Horde_Crypt::singleton(array('imp', 'pgp'));
                foreach ($addrs['list'] as $val) {
                    $res = $imp_pgp->getPublicKey($val);
                    if (is_a($res, 'PEAR_Error')) {
                        $notification->push(_("PGP encryption cannot be used by default as public keys cannot be found for all recipients."), 'horde.warning');
                        $encrypt_options = ($default_encrypt == IMP::PGP_ENCRYPT) ? IMP::ENCRYPT_NONE : IMP::PGP_SIGN;
                        break;
                    }
                }
            }
        } catch (IMP_Compose_Exception $e) {}
    }
}

/* Determine the default cursor position in the compose text area. */
if (!$rtemode) {
    switch ($prefs->getValue('compose_cursor')) {
    case 'top':
    default:
        $cursor_pos = 0;
        break;

    case 'bottom':
        $cursor_pos = String::length($msg);
        break;

    case 'sig':
        if (!is_null($siglocation)) {
            $cursor_pos = $siglocation;
        } elseif (!empty($sig)) {
            $next_pos = $pos = 0;
            $sig_length = String::length($sig);
            do {
                $cursor_pos = $pos;
                $pos = strpos($msg, $sig, $next_pos);
                $next_pos = $pos + $sig_length;
            } while ($pos !== false);
        }
        break;
    };
}

/* Define some variables used in the javascript code. */
$js_code = array(
    'ImpCompose.cancel_url = \'' . $cancel_url . '\'',
    'ImpCompose.spellcheck = ' . intval($spellcheck && $prefs->getValue('compose_spellcheck')),
    'ImpCompose.cursor_pos = ' . (is_null($cursor_pos) ? 'null' : $cursor_pos),
    'ImpCompose.max_attachments = ' . (($max_attach === true) ? 'null' : $max_attach),
    'ImpCompose.popup = ' . intval($isPopup),
    'ImpCompose.redirect = ' . intval($redirect),
    'ImpCompose.rtemode = ' . intval($rtemode),
    'ImpCompose.smf_check = ' . intval($smf_check),
    'ImpCompose.reloaded = ' . intval($token)
);

/* Create javascript identities array. */
if (!$redirect) {
    $js_ident = array();
    foreach ($identity->getAllSignatures() as $ident => $sig) {
        $smf = $identity->getValue('sent_mail_folder', $ident);
        $js_ident[] = array(
            ($rtemode) ? str_replace(' target="_blank"', '', Text_Filter::filter($sig, 'text2html', array('parselevel' => TEXT_HTML_MICRO_LINKURL, 'class' => null, 'callback' => null))) : $sig,
            $identity->getValue('sig_first', $ident),
            ($smf_check) ? $smf : IMP::displayFolder($smf),
            $identity->saveSentmail($ident),
            Horde_Mime_Address::addrArray2String($identity->getBccAddresses($ident))
        );
    }
    $js_code[] = 'ImpCompose.identities = ' . Horde_Serialize::serialize($js_ident, Horde_Serialize::JSON, NLS::getCharset());
}


/* Set up the base template now. */
$t = new IMP_Template();
$t->setOption('gettext', true);
$t->set('post_action', Util::addParameter(Horde::applicationUrl('compose.php'), 'uniq', uniqid(mt_rand())));
$t->set('allow_compose', !$compose_disable);
if ($has_js) {
    $t->set('loading_img', Horde::img('loading.gif', _("Loading...")));
}

if ($redirect) {
    /* Prepare the redirect template. */
    $t->set('mailbox', htmlspecialchars($thismailbox));
    $t->set('index', htmlspecialchars($index));
    $t->set('status', Util::bufferOutput(array('IMP', 'status')));
    $t->set('title', htmlspecialchars($title));
    $t->set('token', IMP::getRequestToken('imp.compose'));

    if ($registry->hasMethod('contacts/search')) {
        $t->set('has_search', true);
        $t->set('abook', Horde::link('#', _("Address Book"), 'widget', null, 'window.open(\'' . Util::addParameter(Horde::applicationUrl('contacts.php'), array('formname' => 'redirect', 'to_only' => 1)) . '\', \'contacts\', \'toolbar=no,location=no,status=no,scrollbars=yes,resizable=yes,width=550,height=300,left=100,top=100\'); return false;') . Horde::img('addressbook_browse.png') . '<br />' . _("Address Book") . '</a>');
        if (!$has_js) {
            $t->set('expand', Horde::link('#', _("Expand Names"), 'widget', null, "$('actionID').value='redirect_expand_addr';uniqSubmit();return false;") . Horde::img('expand.png') . '<br />' . _("Expand Names") . '</a>', true);
        }
    }

    $t->set('to', Horde::label('to', _("To")));

    $tabindex = -1;
    if (is_array($header['to'])) {
        $t->set('multiple_to', true);
        $first_to = true;
        $to_hdrs = array();
        foreach ($header['to'] as $to_item) {
            $entry = array();
            if (count($to_item) > 1) {
                $entry['multiple'] = true;
                $entry['select_tabindex'] = ++$tabindex;
                $entry['select_name'] = htmlspecialchars($to_item[0]);
                $entry['select_to'] = array();
                for ($i = 1; $i < count($to_item); $i++) {
                    $entry['select_to'][] = array('val' => htmlspecialchars($to_item[$i]));
                }
            } else {
                $entry['multiple'] = null;
                $entry['input_value'] = htmlspecialchars($to_item);
            }
            $entry['input_tabindex'] = ++$tabindex;

            if ($first_to) {
                $first_to = false;
                $entry['help'] = Help::link('imp', 'compose-to');
            } else {
                $entry['help'] = null;
            }
            $to_hdrs[] = $entry;
        }
        $t->set('to_new_tabindex', ++$tabindex);
        $t->set('to_hdrs', $to_hdrs);
    } else {
        $t->set('input_tabindex', ++$tabindex);
        $t->set('input_value', htmlspecialchars($header['to']));
        $t->set('help', Help::link('imp', 'compose-to'));
    }

    $template_output = $t->fetch(IMP_TEMPLATES . '/compose/redirect.html');
} else {
    if (!($reply_type = Util::getFormData('reply_type'))) {
        switch ($actionID) {
        case 'reply':
        case 'reply_all':
        case 'reply_list':
            $reply_type = 'reply';
            break;

        case 'forward':
        case 'forward_all':
        case 'forward_body':
        case 'forward_attachments':
            $reply_type = 'forward';
            break;
        }
    }

    /* Prepare the compose template. */
    $tabindex = 0;

    $t->set('file_upload', $_SESSION['imp']['file_upload']);
    $t->set('forminput', Util::formInput());

    $hidden = array(
        'actionID' => '',
        'user' => $_SESSION['imp']['uniquser'],
        'compose_requestToken' => IMP::getRequestToken('imp.compose'),
        'compose_formToken' => Horde_Token::generateId('compose'),
        'composeCache' => $composeCacheID,
        'mailbox' => htmlspecialchars($imp_mbox['mailbox']),
        'thismailbox' => $thismailbox,
        'attachmentAction' => '',
        'oldrtemode' => $rtemode,
        'rtemode' => $rtemode,
        'index' => $index
    );

    if ($_SESSION['imp']['file_upload']) {
        $hidden['MAX_FILE_SIZE'] = $_SESSION['imp']['file_upload'];
    }
    foreach (array('page', 'start', 'popup') as $val) {
        $hidden[$val] = htmlspecialchars(Util::getFormData($val));
    }
    if ($reply_type) {
        $hidden['reply_type'] = $reply_type;
        $hidden['reply_index'] = $reply_index;
        $hidden['in_reply_to'] = htmlspecialchars($header['in_reply_to']);
        if ($reply_type == 'reply') {
            $hidden['references'] = htmlspecialchars($header['references']);
        }
    }
    if (!empty($resume_draft)) {
        $hidden['resume_draft'] = 1;
    }

    if ($browser->hasQuirk('broken_multipart_form')) {
        $hidden['msie_formdata_is_broken'] = '';
    }

    $hidden_val = array();
    foreach ($hidden as $key => $val) {
        $hidden_val[] = array('n' => $key, 'v' => $val);
    }
    $t->set('hidden', $hidden_val);

    $t->set('title', htmlspecialchars($title));
    $t->set('status', Util::bufferOutput(array('IMP', 'status')));
    $t->set('send_msg_ak', Horde::getAccessKeyAndTitle(_("_Send Message")));
    if ($conf['user']['allow_folders'] && !$readonly_drafts) {
        $t->set('save_draft_ak', Horde::getAccessKeyAndTitle(_("Save _Draft")));
    }
    $t->set('help_buttons', Help::link('imp', 'compose-buttons'));
    $t->set('di_locked', $prefs->isLocked('default_identity'));
    if ($t->get('di_locked')) {
        $t->set('help_compose-from', Help::link('imp', 'compose-from'));
        $t->set('fromaddr_locked', $prefs->isLocked('from_addr'));
        $t->set('from', htmlspecialchars($identity->getFromLine(null, Util::getFormData('from'))));
        if (!$t->get('fromaddr_locked')) {
            $t->set('fromaddr_tabindex', ++$tabindex);
        }
    } else {
        $select_list = $identity->getSelectList();
        $t->set('identity_label', Horde::label('identity', _("_Identity")));
        $t->set('help_compose-from', Help::link('imp', 'compose-identity'));
        $t->set('last_identity', $identity->getDefault());
        $t->set('count_select_list', count($select_list) > 1);
        if (count($select_list) > 1) {
            $t->set('selectlist_tabindex', ++$tabindex);
            $t_select_list = array();
            foreach ($select_list as $key => $select) {
                $t_select_list[] = array('value' => $key, 'selected' => ($key == $identity->getDefault()), 'label' => htmlspecialchars($select));
            }
            $t->set('select_list', $t_select_list);
        } else {
            $t->set('identity_default', $identity->getDefault());
            $t->set('identity_text', htmlspecialchars($select_list[0]));
        }
    }
    $t->set('label_to', Horde::label('to', _("_To")));

    $addr_array = array('to' => _("_To"));
    if ($prefs->getValue('compose_cc')) {
        $addr_array['cc'] = _("_Cc");
    }
    if ($prefs->getValue('compose_bcc')) {
        $addr_array['bcc'] = _("_Bcc");
    }

    $address_array = array();
    foreach ($addr_array as $val => $label) {
        $addr = array(
            'id' => $val,
            'label' => Horde::label($val, $label),
        );

        $first_addr = false;
        if (is_array($header[$val])) {
            $addr['multiple'] = true;
            $first_addr = true;
            $hdrs = array();
            foreach ($header[$val] as $item) {
                $entry = array();
                if (count($item) > 1) {
                    $entry['multiple'] = true;
                    $entry['select_tabindex'] = ++$tabindex;
                    $entry['select_name'] = htmlspecialchars($item[0]);
                    $entry['select_arr'] = array();
                    for ($i = 1; $i < count($item); $i++) {
                        $entry['select_arr'][] = array('val' => htmlspecialchars($item[$i]));
                    }
                    $entry['input_value'] = null;
                } else {
                    $entry['multiple'] = null;
                    $entry['input_value'] = htmlspecialchars($item);
                }
                $entry['input_tabindex'] = ++$tabindex;

                if ($first_addr) {
                    $first_addr = false;
                    $entry['help'] = Help::link('imp', 'compose-' . $val);
                } else {
                    $entry['help'] = null;
                }
                $hdrs[] = $entry;
            }
            $addr['hdrs'] = $hdrs;
        } else {
            $addr['multiple'] = false;
            $addr['input_tabindex'] = ++$tabindex;
            $addr['input_value'] = htmlspecialchars($header[$val]);
            $addr['help_compose'] = Help::link('imp', 'compose-' . $val);
        }
        $address_array[] = $addr;
    }
    $t->set('addr', $address_array);

    $t->set('subject_label', Horde::label('subject', _("S_ubject")));
    $t->set('subject_tabindex', ++$tabindex);
    $t->set('subject', htmlspecialchars($header['subject']));
    $t->set('help-subject', Help::link('imp', 'compose-subject'));

    $t->set('set_priority', $prefs->getValue('set_priority'));
    $t->set('unlocked_charset', !$prefs->isLocked('sending_charset'));
    if ($t->get('unlocked_charset')) {
        $t->set('charset_label', Horde::label('charset', _("C_harset")));
        $t->set('charset_tabindex', ++$tabindex);
        $charset_array = array();
        foreach ($nls['encodings'] as $charset => $label) {
            $charset_array[] = array('value' => $charset, 'selected' => (strtolower($charset) == strtolower($encoding)), 'label' => $label);
        }
        $t->set('charset_array', $charset_array);
        $t->set('help_compose_charset', Help::link('imp', 'compose-charset'));
    }
    if ($t->get('set_priority')) {
        $t->set('priority_label', Horde::label('priority', _("_Priority")));
        $t->set('priority_tabindex', ++$tabindex);

        $priority = Util::getFormData('x_priority', 3);
        $priorities = array(
            1 => '1 (' . _("Highest") . ')',
            2 => '2 (' . _("High") . ')',
            3 => '3 (' . _("Normal") . ')',
            4 => '4 (' . _("Low") . ')',
            5 => '5 (' . _("Lowest") . ')'
        );
        $priority_option = array();
        foreach ($priorities as $key => $val) {
            $priority_option[] = array('val' => $key, 'label' => $val, 'selected' => ($priority == $key));
        }
        $t->set('pri_opt', $priority_option);
        $t->set('help_priority', Help::link('imp', 'compose-priority'));
    }

    $t->set('stationery', !empty($stationery_list));
    if ($t->get('stationery')) {
        $t->set('stationery_label', Horde::label('stationery', _("Stationery")));
        $stationeries = array();
        foreach ($stationery_list as $id => $choice) {
            $stationeries[] = array('val' => $id, 'label' => $choice['n'], 'selected' => ($stationery === $id));
        }
        $t->set('stationeries', $stationeries);
    }

    $menu_view = $prefs->getValue('menu_view');
    $show_text = ($menu_view == 'text' || $menu_view == 'both');
    $compose_options = array();
    if ($registry->hasMethod('contacts/search')) {
        $compose_options[] = array(
            'url' => Horde::link('#', '', 'widget', null, 'window.open(\'' . Horde::applicationUrl('contacts.php') . '\', \'contacts\', \'toolbar=no,location=no,status=no,scrollbars=yes,resizable=yes,width=550,height=300,left=100,top=100\'); return false;'),
            'img' => Horde::img('addressbook_browse.png'),
            'label' => $show_text ? _("Address Book") : '');
        if (!$has_js) {
            $compose_options[] = array(
                'url' => Horde::link(
                    '#', '', 'widget', null,
                    "uniqSubmit('compose_expand_addr'); return false;",
                    '', '', array('name' => 'btn_compose_expand_addr')),
                'img' => Horde::img('expand.png'),
                'label' => $show_text ? _("Expand Names") : '');
        }
    }
    if ($spellcheck) {
        $compose_options[] = array(
            'url' => Horde::link('#', '', 'widget', '', 'return false', '', '',
                                 array('id' => 'spellcheck')),
            'img' => '', 'label' => '');
    }
    if ($_SESSION['imp']['file_upload']) {
        $compose_options[] = array(
            'url' => Horde::link('#attachments', '', 'widget', null,
                                 'Field.focus(\'upload_1\');return false;'),
            'img' => Horde::img('manage_attachments.png'),
            'label' => $show_text ? _("Attachments") : '');
    }
    $t->set('compose_options', $compose_options);
    $t->set('help_options', Help::link('imp', 'compose-options'));

    $t->set('ssm', ($conf['user']['allow_folders'] && !$prefs->isLocked('save_sent_mail')));
    if ($t->get('ssm')) {
        if ($readonly_sentmail) {
            $notification->push(sprintf(_("Cannot save sent-mail message to \"%s\" as that mailbox is read-only.", $sent_mail_folder), 'horde.warning'));
        }
        $t->set('ssm_selected', $token ? ($save_sent_mail == 'on') : $identity->saveSentmail());
        $t->set('ssm_label', Horde::label('ssm', _("Sa_ve a copy in ")));
        if ($smf = Util::getFormData('sent_mail_folder')) {
            $sent_mail_folder = $smf;
        }
        if (!empty($conf['user']['select_sentmail_folder']) &&
            !$prefs->isLocked('sent_mail_folder')) {
            $t->set('ssm_tabindex', ++$tabindex);
            $t->set('ssm_folders', IMP::flistSelect('', false, array('INBOX'), $sent_mail_folder));
        } else {
            if (!empty($sent_mail_folder)) {
                $sent_mail_folder = '&quot;' . IMP::displayFolder($sent_mail_folder) . '&quot;';
            }
            $t->set('ssm_folder', $sent_mail_folder);
            $t->set('ssm_folders', false);
        }
    }

    $d_read = $prefs->getValue('disposition_request_read');
    $t->set('rrr', $conf['compose']['allow_receipts'] && ($d_read != 'never'));
    if ($t->get('rrr')) {
       $t->set('rrr_selected', ($d_read != 'ask') || (Util::getFormData('request_read_receipt') == 'on'));
       $t->set('rrr_label', Horde::label('rrr', _("Request a _Read Receipt")));
    }

    $t->set('compose_html', (!is_null($rtemode) && !$prefs->isLocked('compose_html')));
    if ($t->get('compose_html')) {
        $t->set('html_img', Horde::img('compose.png', _("Switch Composition Method")));
        $t->set('html_switch', Horde::link('#', _("Switch Composition Method"), '', '', "$('rtemode').value='" . ($rtemode ? 0 : 1) . "';uniqSubmit();return false;"));
        $t->set('rtemode', $rtemode);
    }

    $t->set('message_label', Horde::label('message', _("Te_xt")));
    $t->set('message_tabindex', ++$tabindex);
    $t->set('message', htmlspecialchars($msg));

    $t->set('use_encrypt', ($prefs->getValue('use_pgp') || $prefs->getValue('use_smime')));
    if ($t->get('use_encrypt')) {
        $t->set('encrypt_label', Horde::label('encrypt_options', _("Encr_yption Options")));
        $t->set('encrypt_options', IMP::encryptList($encrypt_options));
        $t->set('help-encrypt', Help::link('imp', 'compose-options-encrypt'));
        $t->set('pgp_options', ($prefs->getValue('use_pgp') && $prefs->getValue('pgp_public_key')));
        if ($t->get('pgp_options')) {
            $t->set('pgp_attach_pubkey', Util::getFormData('pgp_attach_pubkey', $prefs->getValue('pgp_attach_pubkey')));
            $t->set('pap', Horde::label('pap', _("Attach a copy of your PGP public key to the message?")));
            $t->set('help-pubkey', Help::link('imp', 'pgp-compose-attach-pubkey'));
        }
    }
    if ($registry->hasMethod('contacts/ownVCard')) {
        $t->set('vcard', Horde::label('vcard', _("Attach your contact information to the message?")));
        $t->set('attach_vcard', Util::getFormData('vcard'));
    }
    if ($_SESSION['imp']['file_upload']) {
        $localeinfo = NLS::getLocaleInfo();
        if ($GLOBALS['registry']->hasMethod('files/selectlistLink')) {
            $res = $GLOBALS['registry']->call('files/selectlistLink', array(_("Attach Files"), 'widget', 'compose', true));
            $t->set('selectlistlink', (is_a($res, 'PEAR_Error')) ? null : $res);
        }
        $t->set('maxattachsize', !$imp_compose->maxAttachmentSize());
        if (!$t->get('maxattachsize')) {
            $t->set('maxattachmentnumber', !$max_attach);
            if (!$t->get('maxattachmentnumber')) {
                $t->set('file_tabindex', ++$tabindex);
            }
        }
        $t->set('attach_size', number_format($imp_compose->maxAttachmentSize(), 0, $localeinfo['decimal_point'], $localeinfo['thousands_sep']));
        $t->set('help-attachments', Help::link('imp', 'compose-attachments'));

        $save_attach = $prefs->getValue('save_attachments');
        $show_link_attach = ($conf['compose']['link_attachments'] && !$conf['compose']['link_all_attachments']);
        $show_save_attach = ($t->get('ssm') && (strpos($save_attach, 'prompt') === 0)
                             && (!$conf['compose']['link_attachments'] || !$conf['compose']['link_all_attachments']));
        $t->set('show_link_save_attach', ($show_link_attach || $show_save_attach));
        if ($t->get('show_link_save_attach')) {
            $attach_options = array();
            if ($show_save_attach) {
                $save_attach_val = Util::getFormData('save_attachments_select', ($save_attach == 'prompt_yes'));
                $attach_options[] = array('label' => _("Save Attachments with message in sent-mail folder?"), 'name' => 'save_attachments_select', 'select_yes' => ($save_attach_val == 1), 'select_no' => ($save_attach_val == 0), 'help' => Help::link('imp', 'compose-save-attachments'));
            }
            if ($show_link_attach) {
                $link_attach_val = Util::getFormData('link_attachments');
                $attach_options[] = array('label' => _("Link Attachments?"), 'name' => 'link_attachments', 'select_yes' => ($link_attach_val == 1), 'select_no' => ($link_attach_val == 0), 'help' => Help::link('imp', 'compose-link-attachments'));
            }
            $t->set('attach_options', $attach_options);
        }

        $t->set('numberattach', $imp_compose->numberOfAttachments());
        if ($t->get('numberattach')) {
            $atc = array();
            foreach ($imp_compose->getAttachments() as $atc_num => $data) {
                $mime = $data['part'];
                $type = $mime->getType();

                $entry = array(
                    'name' => $mime->getName(true),
                    'icon' => Horde_Mime_Viewer::getIcon($type),
                    'number' => $atc_num,
                    'type' => $type,
                    'size' => $mime->getSize(),
                    'description' => $mime->getDescription(true)
                );

                if ($type != 'application/octet-stream') {
                    $preview_url = Util::addParameter(Horde::applicationUrl('view.php'), array('actionID' => 'compose_attach_preview', 'id' => $atc_num, 'composeCache' => $composeCacheID));
                    $entry['name'] = Horde::link($preview_url, _("Preview") . ' ' . $entry['name'], 'link', 'compose_preview_window') . $entry['name'] . '</a>';
                }

                $atc[] = $entry;
            }
            $t->set('atc', $atc);
            $t->set('total_attach_size', number_format($imp_compose->sizeOfAttachments() / 1024, 2, $localeinfo['decimal_point'], $localeinfo['thousands_sep']));
            $t->set('perc_attach', ((!empty($conf['compose']['attach_size_limit'])) && ($conf['compose']['attach_size_limit'] > 0)));
            if ($t->get('perc_attach')) {
                $t->set('perc_attach', sprintf(_("%s%% of allowed size"), number_format($imp_compose->sizeOfAttachments() / $conf['compose']['attach_size_limit'] * 100, 2, $localeinfo['decimal_point'], $localeinfo['thousands_sep'])));
            }
            $t->set('help-current-attachments', Help::link('imp', 'compose-current-attachments'));
        }
    }

    $template_output = $t->fetch(IMP_TEMPLATES . '/compose/compose.html');
}

Horde::addScriptFile('prototype.js', 'horde', true);
Horde::addScriptFile('compose.js', 'imp', true);
require IMP_TEMPLATES . '/common-header.inc';
IMP::addInlineScript($js_code);
if ($showmenu) {
    IMP::menu();
}
echo $template_output;
if ($rtemode && !$redirect) {
    echo $imp_ui->initRTE();
}
require $registry->get('templates', 'horde') . '/common-footer.inc';
