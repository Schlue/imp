<?php
/**
 * Copyright 2012-2017 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (GPL). If you
 * did not receive this file, see http://www.horde.org/licenses/gpl.
 *
 * @category  Horde
 * @copyright 2012-2017 Horde LLC
 * @license   http://www.horde.org/licenses/gpl GPL
 * @package   IMP
 */

/**
 * This class manages the sortpref preference.
 *
 * @author    Michael Slusarz <slusarz@horde.org>
 * @category  Horde
 * @copyright 2012-2017 Horde LLC
 * @license   http://www.horde.org/licenses/gpl GPL
 * @package   IMP
 */
class IMP_Prefs_Sort implements ArrayAccess, IteratorAggregate
{
    /* Preference name in backend. */
    const SORTPREF = 'sortpref';

    /**
     * The sortpref value.
     *
     * @var array
     */
    protected $_sortpref = array();

    /**
     * Constructor.
     */
    public function __construct()
    {
        global $prefs;

        $serializedPref = $prefs->getValue(self::SORTPREF);
        // Only unserialize non-empty strings. Disallow yielding any classes.
        if (!empty($serializedPref && is_string($serializedPref))) {
            $sortpref = @unserialize($serializedPref, ['allowed_classes' => false]);
            if (is_array($sortpref)) {
                $this->_sortpref = $sortpref;
            }
        }
    }

    /**
     * Garbage collection.
     */
    public function gc()
    {
        foreach (IMP_Mailbox::get(array_keys($this->_sortpref)) as $val) {
            /* Purge if mailbox doesn't exist or this is a search query (not
             * a virtual folder). */
            if (!$val->exists || $val->query) {
                unset($this[strval($val)]);
            }
        }
    }

    /**
     * Upgrade the preference from IMP 4 value.
     */
    public function upgradePrefs()
    {
        global $prefs;

        if (!$prefs->isDefault(self::SORTPREF)) {
            foreach ($this->_sortpref as $key => $val) {
                if (($sb = $this->newSortbyValue($val['b'])) !== null) {
                    $this->_sortpref[$key]['b'] = $sb;
                }
            }

            $this->_save();
        }
    }

    /**
     * Get the new sortby pref value for IMP 5.
     *
     * @param integer $sortby  The old (IMP 4) value.
     *
     * @return integer  Null if no change or else the converted sort value.
     */
    public function newSortbyValue($sortby)
    {
        switch ($sortby) {
        case 1: // SORTARRIVAL
            /* Sortarrival was the same thing as sequence sort in IMP 4. */
            return Horde_Imap_Client::SORT_SEQUENCE;

        case 2: // SORTDATE
            return IMP::IMAP_SORT_DATE;

        case 161: // SORTTHREAD
            return Horde_Imap_Client::SORT_THREAD;
        }

        return null;
    }

    /**
     * Save the preference to the backend.
     */
    protected function _save()
    {
        $GLOBALS['prefs']->setValue(self::SORTPREF, serialize($this->_sortpref));
    }

    /* ArrayAccess methods. */

    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return isset($this->_sortpref[$offset]);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset): IMP_Prefs_Sort_Sortpref
    {
        $ob = $this->_offsetGet($offset);

        try {
            $GLOBALS['injector']->getInstance('Horde_Core_Hooks')->callHook(
                'mbox_sort',
                'imp',
                array($ob)
            );
        } catch (Horde_Exception_HookNotSet $e) {}

        return $ob;
    }

    /**
     */
    protected function _offsetGet($offset): IMP_Prefs_Sort_Sortpref
    {
        return new IMP_Prefs_Sort_Sortpref(
            $offset,
            isset($this->_sortpref[$offset]['b']) ? $this->_sortpref[$offset]['b'] : null,
            isset($this->_sortpref[$offset]['d']) ? $this->_sortpref[$offset]['d'] : null
        );
    }

    /**
     * Alter a sortpref entry.
     *
     * @param string $offset  The mailbox name.
     * @param array $value    An array with two possible keys: 'by' and 'dir'.
     */
    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        if (empty($value)) {
            return;
        }

        $ob = $this->_offsetGet($offset);

        if (isset($value['by'])) {
            $ob->sortby = $value['by'];
        }
        if (isset($value['dir'])) {
            $ob->sortdir = $value['dir'];
        }

        $this->_sortpref[$offset] = $ob->toArray();
        $this->_save();
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        if (isset($this->_sortpref[$offset])) {
            unset($this->_sortpref[$offset]);
            $this->_save();
        }
    }

    /* IteratorAggregate method. */

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->_sortpref);
    }

}
