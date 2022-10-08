<?php
/**
 * Copyright 2013-2017 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (GPL). If you
 * did not receive this file, see http://www.horde.org/licenses/gpl.
 *
 * @category  Horde
 * @copyright 2013-2017 Horde LLC
 * @license   http://www.horde.org/licenses/gpl GPL
 * @package   IMP
 */

/**
 * Abstract object handling folder tree prefereces.
 *
 * @author    Michael Slusarz <slusarz@horde.org>
 * @category  Horde
 * @copyright 2013-2017 Horde LLC
 * @license   http://www.horde.org/licenses/gpl GPL
 * @package   IMP
 *
 * @property-read boolean $locked  True if pref is locked.
 */
class IMP_Ftree_Prefs implements ArrayAccess, Horde_Shutdown_Task
{
    /**
     * Preference data.
     *
     * @var array
     */
    protected $_data = array();

    /**
     * Is the preference locked?
     *
     * @var boolean
     */
    protected $_locked;

    /**
     */
    public function __get($name)
    {
        switch ($name) {
        case 'locked':
            return $this->_locked;
        }
    }

    /* ArrayAccess methods. */

    /**
     */
    public function offsetExists($offset): bool
    {
        return true;
    }

    /**
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return isset($this->_data[strval($offset)]);
    }

    /**
     */
    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        if (!$this->locked && ($this[$offset] != $value)) {
            if ($value) {
                $this->_data[strval($offset)] = true;
            } else {
                unset($this->_data[strval($offset)]);
            }

            Horde_Shutdown::add($this);
        }
    }

    /**
     */
    public function offsetUnset($offset): void
    {
        $this[$offset] = false;
    }

    /* Horde_Shutdown_Task method. */

    /**
     */
    public function shutdown()
    {
    }

}
