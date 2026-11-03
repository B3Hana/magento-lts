<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    Mage_Catalog
 */

/**
 * @package    Mage_Catalog
 */
class Mage_Catalog_Model_Convert_Adapter_Catalog extends Mage_Dataflow_Model_Convert_Adapter_Abstract
{
    /**
     * @return object
     */
    #[Override]
    public function getResource()
    {
        if (!$this->_resource) {
            $this->_resource = Mage::getResourceSingleton('catalog/entity');
        }

        return $this->_resource;
    }

    /**
     * @return $this
     */
    public function load()
    {
        $this->setData([]);
        return $this;
    }

    /**
     * @return $this
     */
    public function save()
    {
        /*
        $res = $this->getResource();

        foreach (array('Attributes', 'Attribute Sets', 'Attribute Options', 'Products', 'Categories', ''))

        $this->setData

        echo "<pre>".print_r($this->getData(),1)."</pre>";

        */
        return $this;
    }
}
