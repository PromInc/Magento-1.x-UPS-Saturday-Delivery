<?php
class Prominc_Shipping_Model_Carrier_Ups_Source_Method
{


    public function toOptionArray()
    {
        $ups = Mage::getSingleton('usa/shipping_carrier_ups');
        $arr = array();
        foreach ($ups->getCode('originShipment', 'Shipments Originating in United States') as $k=>$v) {
            $arr[] = array('value'=>$k, 'label'=>Mage::helper('usa')->__($v));
        }
        return $arr;
    }


}
