<?php
/**
 * Copyright 2006-2013 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.horde.org/licenses/gpl.
 *
 * @category  Horde
 * @copyright 2006-2013 Horde LLC
 * @license   http://www.horde.org/licenses/gpl GPL
 * @package   IMP
 */

/**
 * Common code dealing with message parsing relating to UI display.
 *
 * @author    Michael Slusarz <slusarz@horde.org>
 * @category  Horde
 * @copyright 2006-2013 Horde LLC
 * @license   http://www.horde.org/licenses/gpl GPL
 * @package   IMP
 */
class IMP_Message_Ui
{
    /**
     * Return a list of "basic" headers w/gettext translations.
     *
     * @return array  Header name -> gettext translation mapping.
     */
    public function basicHeaders()
    {
        return array(
            'date'      =>  _("Date"),
            'from'      =>  _("From"),
            'to'        =>  _("To"),
            'cc'        =>  _("Cc"),
            'bcc'       =>  _("Bcc"),
            'reply-to'  =>  _("Reply-To"),
            'subject'   =>  _("Subject")
        );
    }

    /**
     * Get the list of user-defined headers to display.
     *
     * @return array  The list of user-defined headers.
     */
    public function getUserHeaders()
    {
        $user_hdrs = $GLOBALS['prefs']->getValue('mail_hdr');

        /* Split the list of headers by new lines and sort the list of headers
         * to make sure there are no duplicates. */
        if (is_array($user_hdrs)) {
            $user_hdrs = implode("\n", $user_hdrs);
        }
        $user_hdrs = trim($user_hdrs);
        if (empty($user_hdrs)) {
            return array();
        }

        $user_hdrs = array_filter(array_keys(array_flip(array_map('trim', preg_split("/[\n\r]+/", str_replace(':', '', $user_hdrs))))));
        natcasesort($user_hdrs);

        return $user_hdrs;
    }

    /**
     * Check if we need to send a MDN, and send if needed.
     *
     * @param IMP_Mailbox $mailbox         The mailbox of the message.
     * @param integer $uid                 The UID of the message.
     * @param Horde_Mime_Headers $headers  The headers of the message.
     * @param boolean $confirmed           Has the MDN request been confirmed?
     *
     * @return boolean  True if the MDN request needs to be confirmed.
     */
    public function MDNCheck(IMP_Mailbox $mailbox, $uid, $headers,
                             $confirmed = false)
    {
        global $conf, $injector, $prefs;

        $imp_imap = $mailbox->imp_imap;
        $maillog = $injector->getInstance('IMP_Maillog');
        $pref_val = $prefs->getValue('send_mdn');

        if (!$pref_val || $mailbox->readonly) {
            return false;
        }

        /* Check to see if an MDN has been requested. */
        $mdn = new Horde_Mime_Mdn($headers);
        $return_addr = $mdn->getMdnReturnAddr();
        if (!$return_addr) {
            return false;
        }

        $msg_id = $headers->getValue('message-id');
        $mdn_flag = $mdn_sent = false;

        /* See if we have already processed this message. */
        /* 1st test: MDNSent keyword (RFC 3503 [3.1]). */
        if ($mailbox->permflags->allowed('$mdnsent')) {
            $mdn_flag = true;

            $query = new Horde_Imap_Client_Fetch_Query();
            $query->flags();

            try {
                $res = $imp_imap->fetch($mailbox, $query, array(
                    'ids' => $imp_imap->getIdsOb($uid)
                ));
                $mdn_sent = in_array('$mdnsent', $res->first()->getFlags());
            } catch (IMP_Imap_Exception $e) {}
        } elseif ($maillog) {
            /* 2nd test: Use Maillog as a fallback. */
            $mdn_sent = $maillog->sentMDN($msg_id, 'displayed');
        }

        if ($mdn_sent) {
            return false;
        }

        /* See if we need to query the user. */
        if (!$confirmed &&
            ((intval($pref_val) == 1) ||
             $mdn->userConfirmationNeeded())) {
            try {
                if (Horde::callHook('mdn_check', array($headers), 'imp')) {
                    return true;
                }
            } catch (Horde_Exception_HookNotSet $e) {
                return true;
            }
        }

        /* Send out the MDN now. */
        try {
            $mdn->generate(
                false,
                $confirmed,
                'displayed',
                $conf['server']['name'],
                $injector->getInstance('IMP_Mail'),
                array(
                    'charset' => 'UTF-8',
                    'from_addr' => $injector->getInstance('Horde_Core_Factory_Identity')->create()->getDefaultFromAddress()
                )
            );
            if ($maillog) {
                $maillog->log($maillog::MDN, $msg_id, 'displayed');
            }
            $success = true;

            if ($mdn_flag) {
                $injector->getInstance('IMP_Message')->flag(array(
                    'add' => array(Horde_Imap_Client::FLAG_MDNSENT)
                ), $mailbox->getIndicesOb($uid));
            }
        } catch (Exception $e) {
            $success = false;
        }

        $injector->getInstance('IMP_Sentmail')->log(IMP_Sentmail::MDN, '', $return_addr, $success);

        return false;
    }

    /**
     * Adds the local time string to the date header.
     *
     * @param Horde_Imap_Client_DateTime $date  The date object.
     *
     * @return string  The local formatted time string.
     */
    public function getLocalTime(Horde_Imap_Client_DateTime $date)
    {
        $time_str = strftime($GLOBALS['prefs']->getValue('time_format'), strval($date));
        $tz = strftime('%Z');

        if ((date('Y') != $date->format('Y')) ||
            (date('M') != $date->format('M')) ||
            (date('d') != $date->format('d'))) {
            /* Not today, use the date. */
            $date_str = strftime($GLOBALS['prefs']->getValue('date_format'), strval($date));
            return sprintf('%s (%s %s)', $date_str, $time_str, $tz);
        }

        /* Else, it's today, use the time only. */
        return sprintf(_("Today, %s %s"), $time_str, $tz);
    }

    /**
     * Returns e-mail information for a mailing list.
     *
     * @param Horde_Mime_Headers $headers  A Horde_Mime_Headers object.
     *
     * @return array  An array with 2 elements: 'exists' and 'reply_list'.
     */
    public function getListInformation($headers)
    {
        $ret = array('exists' => false, 'reply_list' => null);

        if ($headers->listHeadersExist()) {
            $ret['exists'] = true;

            /* See if the List-Post header provides an e-mail address for the
             * list. */
            if ($val = $headers->getValue('list-post')) {
                foreach ($GLOBALS['injector']->getInstance('Horde_ListHeaders')->parse('list-post', $val) as $val2) {
                    if ($val2 instanceof Horde_ListHeaders_NoPost) {
                        break;
                    } elseif (stripos($val2->url, 'mailto:') === 0) {
                        $ret['reply_list'] = substr($val2->url, 7);
                        break;
                    }
                }
            }
        }

        return $ret;
    }

    /**
     * Builds a string containing a list of addresses.
     *
     * @param Horde_Mail_Rfc822_List $addrlist  An address list.
     * @param Horde_Url $addURL                 The self URL.
     * @param boolean $link                     Link each address to the
     *                                          compose screen?
     *
     * @return string  String containing the formatted address list.
     */
    public function buildAddressLinks(Horde_Mail_Rfc822_List $addrlist,
                                      $addURL = null, $link = true)
    {
        global $prefs, $registry;

        $add_link = null;
        $addr_array = array();
        $minimal = ($registry->getView() == Horde_Registry::VIEW_MINIMAL);

        /* Set up the add address icon link if contact manager is
         * available. */
        if (!is_null($addURL) && $link && $prefs->getValue('add_source')) {
            try {
                $add_link = $registry->hasMethod('contacts/import')
                    ? $addURL->copy()->add('actionID', 'add_address')
                    : null;
            } catch (Horde_Exception $e) {}
        }

        $addrlist->setIteratorFilter();
        foreach ($addrlist->base_addresses as $ob) {
            if ($ob instanceof Horde_Mail_Rfc822_Group) {
                $group_array = array();
                foreach ($ob->addresses as $ad) {
                    $ret = $minimal
                        ? strval($ad)
                        : htmlspecialchars(strval($ad));

                    if ($link) {
                        $clink = new IMP_Compose_Link(array('to' => strval($ad)));
                        $ret = Horde::link($clink->link(), sprintf(_("New Message to %s"), strval($ad))) . htmlspecialchars(strval($ad)) . '</a>';
                    }

                    /* Append the add address icon to every address if contact
                     * manager is available. */
                    if ($add_link) {
                        $curr_link = $add_link->copy()->add(array(
                            'address' => $ad->bare_address,
                            'name' => $ad->personal
                        ));
                        $ret .= Horde::link($curr_link, sprintf(_("Add %s to my Address Book"), $ad->bare_address)) .
                            '<span class="iconImg addrbookaddImg"></span></a>';
                    }

                    $group_array[] = $ret;
                }

                $groupname = $minimal
                    ? $ob->groupname
                    : htmlspecialchars($ob->groupname);

                $addr_array[] = $groupname . ':' . (count($group_array) ? ' ' . implode(', ', $group_array) : '');
            } else {
                $ret = $minimal
                    ? strval($ob)
                    : htmlspecialchars(strval($ob));

                if ($link) {
                    $clink = new IMP_Compose_Link(array('to' => strval($ob)));
                    $ret = Horde::link($clink->link(), sprintf(_("New Message to %s"), strval($ob))) . htmlspecialchars(strval($ob)) . '</a>';
                }

                /* Append the add address icon to every address if contact
                 * manager is available. */
                if ($add_link) {
                    $curr_link = $add_link->copy()->add(array(
                        'address' => $ob->bare_address,
                        'name' => $ob->personal
                    ));
                    $ret .= Horde::link($curr_link, sprintf(_("Add %s to my Address Book"), $ob->bare_address)) .
                        '<span class="iconImg addrbookaddImg"></span></a>';
                }

                $addr_array[] = $ret;
            }
        }

        if ($minimal) {
            return implode(', ', $addr_array);
        }

        /* If left with an empty address list ($ret), inform the user that the
         * recipient list is purposely "undisclosed". */
        if (empty($addr_array)) {
            $ret = _("Undisclosed Recipients");
        } else {
            /* Build the address line. */
            $addr_count = count($addr_array);
            $ret = '<span class="nowrap">' . implode(',</span> <span class="nowrap">', $addr_array) . '</span>';
            if ($link && $addr_count > 15) {
                $ret = '<span>' .
                    '<span onclick="[ this, this.next(), this.next(1) ].invoke(\'toggle\')" class="widget largeaddrlist">' . sprintf(_("Show Addresses (%d)"), $addr_count) . '</span>' .
                    '<span onclick="[ this, this.previous(), this.next() ].invoke(\'toggle\')" class="widget largeaddrlist" style="display:none">' . _("Hide Addresses") . '</span>' .
                    '<span style="display:none">' .
                    $ret . '</span></span>';
            }
        }

        return $ret;
    }

    /**
     * Increment mailbox index after deleting a message?
     *
     * @param IMP_Mailbox $mailbox  Current mailbox.
     *
     * @return boolean  If true, increments index.
     */
    public function moveAfterAction(IMP_Mailbox $mailbox)
    {
        return (!$mailbox->hideDeletedMsgs() &&
                !$GLOBALS['prefs']->getValue('use_trash'));
    }

}
