<?php
class Prominc_Shipping_Model_Shipping_Carrier_Ups extends Mage_Usa_Model_Shipping_Carrier_Ups
{

    /**
     * Prepare shipping rate result based on response
     *
     * @param mixed $response
     * @return Mage_Shipping_Model_Rate_Result
     */
    protected function _parseXmlResponse($xmlResponse)
    {
        $costArr = array();
        $priceArr = array();
        if (strlen(trim($xmlResponse))>0) {
            $xml = new Varien_Simplexml_Config();
            $xml->loadString($xmlResponse);
            $arr = $xml->getXpath("//RatingServiceSelectionResponse/Response/ResponseStatusCode/text()");
            $success = (int)$arr[0];
            if ($success===1) {
                $arr = $xml->getXpath("//RatingServiceSelectionResponse/RatedShipment");
                $allowedMethods = explode(",", $this->getConfigData('allowed_methods'));

                // Negotiated rates
                $negotiatedArr = $xml->getXpath("//RatingServiceSelectionResponse/RatedShipment/NegotiatedRates");
                $negotiatedActive = $this->getConfigFlag('negotiated_active')
                    && $this->getConfigData('shipper_number')
                    && !empty($negotiatedArr);

                $allowedCurrencies = Mage::getModel('directory/currency')->getConfigAllowCurrencies();

                foreach ($arr as $shipElement){
                    $code = (string)$shipElement->Service->Code;
                    if (in_array($code, $allowedMethods)) {

                        if ($negotiatedActive) {
                            $cost = $shipElement->NegotiatedRates->NetSummaryCharges->GrandTotal->MonetaryValue;
                        } else {
                            $cost = $shipElement->TotalCharges->MonetaryValue;
                        }

                        //convert price with Origin country currency code to base currency code
                        $successConversion = true;
                        $responseCurrencyCode = (string) $shipElement->TotalCharges->CurrencyCode;
                        if ($responseCurrencyCode) {
                            if (in_array($responseCurrencyCode, $allowedCurrencies)) {
                                $cost = (float) $cost * $this->_getBaseCurrencyRate($responseCurrencyCode);
                            } else {
                                $errorTitle = Mage::helper('directory')->__('Can\'t convert rate from "%s-%s".', $responseCurrencyCode, $this->_request->getPackageCurrency()->getCode());
                                $error = Mage::getModel('shipping/rate_result_error');
                                $error->setCarrier('ups');
                                $error->setCarrierTitle($this->getConfigData('title'));
                                $error->setErrorMessage($errorTitle);
                                $successConversion = false;
                            }
                        }

                        if ($successConversion) {
                            $costArr[$code] = $cost;
                            $priceArr[$code] = $this->getMethodPrice(floatval($cost),$code);
                        }
                    }
                }
            } else {
                $arr = $xml->getXpath("//RatingServiceSelectionResponse/Response/Error/ErrorDescription/text()");
                $errorTitle = (string)$arr[0][0];
                $error = Mage::getModel('shipping/rate_result_error');
                $error->setCarrier('ups');
                $error->setCarrierTitle($this->getConfigData('title'));
                $error->setErrorMessage($this->getConfigData('specificerrmsg'));
            }
        }
        $result = Mage::getModel('shipping/rate_result');
        $defaults = $this->getDefaults();
        if (empty($priceArr)) {
            $error = Mage::getModel('shipping/rate_result_error');
            $error->setCarrier('ups');
            $error->setCarrierTitle($this->getConfigData('title'));
            if(!isset($errorTitle)){
                $errorTitle = Mage::helper('usa')->__('Cannot retrieve shipping rates');
            }
            $error->setErrorMessage($this->getConfigData('specificerrmsg'));
            $result->append($error);
        } else {
            if( $this->getSaturdayDelivery() ) {
                $saturdayShippingMethods = explode( ",", Mage::getStoreConfig('shipping/saturday_delivery_ups/allowed_methods') );
            }
            foreach ($priceArr as $method=>$price) {
                if( $this->getSaturdayDelivery() && !in_array( $method, $saturdayShippingMethods ) ) {
                    continue;
                }

                $rate = Mage::getModel('shipping/rate_result_method');
                $rate->setCarrier('ups');
                $rate->setCarrierTitle($this->getConfigData('title'));
                // $rate->setMethod($method);
                $rate->setMethod( $method . ( $this->getSaturdayDelivery() ? '_sat' : '' ) );
                // $method_arr = $this->getShipmentByCode($method);  // string
                $method_arr = $this->getShipmentByCode($method) . ( $this->getSaturdayDelivery() ? ' Saturday' : '' );  // string
                $rate->setMethodTitle($method_arr);
                $rate->setCost($costArr[$method]);
                $rate->setPrice($price);

                $result->append($rate);
            }
        }
        return $result;
    }

    /**
     * Get xml rates
     *
     * @return Mage_Shipping_Model_Rate_Result
     */
    protected function _getXmlQuotes()
    {
        $url = $this->getConfigData('gateway_xml_url');
        if (!$url) {
            $url = $this->_defaultUrls['Rate'];
        }

        $this->setXMLAccessRequest();
        $xmlRequest=$this->_xmlAccessRequest;

        $r = $this->_rawRequest;
        $params = array(
            'accept_UPS_license_agreement' => 'yes',
            '10_action'      => $r->getAction(),
            '13_product'     => $r->getProduct(),
            '14_origCountry' => $r->getOrigCountry(),
            '15_origPostal'  => $r->getOrigPostal(),
            'origCity'       => $r->getOrigCity(),
            'origRegionCode' => $r->getOrigRegionCode(),
            '19_destPostal'  => Mage_Usa_Model_Shipping_Carrier_Abstract::USA_COUNTRY_ID == $r->getDestCountry() ?
                substr($r->getDestPostal(), 0, 5) :
                $r->getDestPostal(),
            '22_destCountry' => $r->getDestCountry(),
            'destRegionCode' => $r->getDestRegionCode(),
            '23_weight'      => $r->getWeight(),
            '47_rate_chart'  => $r->getPickup(),
            '48_container'   => $r->getContainer(),
            '49_residential' => $r->getDestType(),
        );

        if ($params['10_action'] == '4') {
            $params['10_action'] = 'Shop';
            $serviceCode = null; // Service code is not relevant when we're asking ALL possible services' rates
        } else {
            $params['10_action'] = 'Rate';
            $serviceCode = $r->getProduct() ? $r->getProduct() : '';
        }
        $serviceDescription = $serviceCode ? $this->getShipmentByCode($serviceCode) : '';

$xmlRequest .= <<< XMLRequest
<?xml version="1.0"?>
<RatingServiceSelectionRequest xml:lang="en-US">
  <Request>
    <TransactionReference>
      <CustomerContext>Rating and Service</CustomerContext>
      <XpciVersion>1.0</XpciVersion>
    </TransactionReference>
    <RequestAction>Rate</RequestAction>
    <RequestOption>{$params['10_action']}</RequestOption>
  </Request>
  <PickupType>
          <Code>{$params['47_rate_chart']['code']}</Code>
          <Description>{$params['47_rate_chart']['label']}</Description>
  </PickupType>

  <Shipment>
XMLRequest;

        if ($serviceCode !== null) {
            $xmlRequest .= "<Service>" .
                "<Code>{$serviceCode}</Code>" .
                "<Description>{$serviceDescription}</Description>" .
                "</Service>";
        }

if( $this->getSaturdayDelivery() ) {
      $xmlRequest .= <<< XMLRequest
<ShipmentServiceOptions>
    <SaturdayDelivery/>
</ShipmentServiceOptions>
XMLRequest;
}
      $xmlRequest .= <<< XMLRequest
      <Shipper>
XMLRequest;

        if ($this->getConfigFlag('negotiated_active') && ($shipper = $this->getConfigData('shipper_number')) ) {
            $xmlRequest .= "<ShipperNumber>{$shipper}</ShipperNumber>";
        }

        if ($r->getIsReturn()) {
            $shipperCity = '';
            $shipperPostalCode = $params['19_destPostal'];
            $shipperCountryCode = $params['22_destCountry'];
            $shipperStateProvince = $params['destRegionCode'];
        } else {
            $shipperCity = $params['origCity'];
            $shipperPostalCode = $params['15_origPostal'];
            $shipperCountryCode = $params['14_origCountry'];
            $shipperStateProvince = $params['origRegionCode'];
        }

$xmlRequest .= <<< XMLRequest
      <Address>
          <City>{$shipperCity}</City>
          <PostalCode>{$shipperPostalCode}</PostalCode>
          <CountryCode>{$shipperCountryCode}</CountryCode>
          <StateProvinceCode>{$shipperStateProvince}</StateProvinceCode>
      </Address>
    </Shipper>
    <ShipTo>
      <Address>
          <PostalCode>{$params['19_destPostal']}</PostalCode>
          <CountryCode>{$params['22_destCountry']}</CountryCode>
          <ResidentialAddress>{$params['49_residential']}</ResidentialAddress>
          <StateProvinceCode>{$params['destRegionCode']}</StateProvinceCode>
XMLRequest;

          $xmlRequest .= ($params['49_residential']==='01'
                  ? "<ResidentialAddressIndicator>{$params['49_residential']}</ResidentialAddressIndicator>"
                  : ''
          );

$xmlRequest .= <<< XMLRequest
      </Address>
    </ShipTo>


    <ShipFrom>
      <Address>
          <PostalCode>{$params['15_origPostal']}</PostalCode>
          <CountryCode>{$params['14_origCountry']}</CountryCode>
          <StateProvinceCode>{$params['origRegionCode']}</StateProvinceCode>
      </Address>
    </ShipFrom>

    <Package>
      <PackagingType><Code>{$params['48_container']}</Code></PackagingType>
      <PackageWeight>
         <UnitOfMeasurement><Code>{$r->getUnitMeasure()}</Code></UnitOfMeasurement>
        <Weight>{$params['23_weight']}</Weight>
      </PackageWeight>
    </Package>
XMLRequest;
        if ($this->getConfigFlag('negotiated_active')) {
            $xmlRequest .= "<RateInformation><NegotiatedRatesIndicator/></RateInformation>";
        }

$xmlRequest .= <<< XMLRequest
  </Shipment>
</RatingServiceSelectionRequest>
XMLRequest;

        $xmlResponse = $this->_getCachedQuotes($xmlRequest);
        if ($xmlResponse === null) {
            $debugData = array('request' => $xmlRequest);
            try {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlRequest);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->getConfigFlag('verify_peer'));
                $xmlResponse = curl_exec ($ch);

                $debugData['result'] = $xmlResponse;
                $this->_setCachedQuotes($xmlRequest, $xmlResponse);
            }
            catch (Exception $e) {
                $debugData['result'] = array('error' => $e->getMessage(), 'code' => $e->getCode());
                $xmlResponse = '';
            }
            $this->_debug($debugData);
        }

        return $this->_parseXmlResponse($xmlResponse);
    }

}
