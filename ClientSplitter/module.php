<?

/*
 * @addtogroup websocket
 * @{
 *
 * @package       Websocket
 * @file          module.php
 * @author        Michael Tröger <micha@nall-chan.net>
 * @copyright     2017 Michael Tröger
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 * @version       0.2
 *
 */

/**
 * ClientSplitter Klasse implementiert einen Splitter auf Basis der IP-Adresse eines Client.
 * Erweitert IPSModule.
 * 
 * @package       Websocket
 * @author        Michael Tröger <micha@nall-chan.net>
 * @copyright     2017 Michael Tröger
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 * @version       0.2
 * @example <b>Ohne</b>
 * @property int $LastPort

 */
class ClientSplitter extends IPSModule
{

    /**
     * Wert einer Eigenschaft aus den InstanceBuffer lesen.
     * 
     * @access public
     * @param string $name Propertyname
     * @return mixed Value of Name
     */
    public function __get($name)
    {
        return unserialize($this->GetBuffer($name));
    }

    /**
     * Wert einer Eigenschaft in den InstanceBuffer schreiben.
     * 
     * @access public
     * @param string $name Propertyname
     * @param mixed Value of Name
     */
    public function __set($name, $value)
    {
        $this->SetBuffer($name, serialize($value));
    }

    /**
     * Interne Funktion des SDK.
     *
     * @access public
     */
    public function Create()
    {
        parent::Create();
        $this->LastPort = 0;
        $this->RegisterPropertyString("ClientIP", "");
    }

    /**
     * Interne Funktion des SDK.
     * 
     * @access public
     */
    public function ApplyChanges()
    {
        $this->SetReceiveDataFilter('.*"ClientIP":"' . $this->ReadPropertyString('ClientIP') . '".*');
    }

################## DATAPOINTS CHILDS

    /**
     * Interne Funktion des SDK. Nimmt Daten von Childs entgegen und sendet Diese weiter.
     * 
     * @access public
     * @param string $JSONString
     * @result bool true wenn Daten gesendet werden konnten, sonst false.
     */
    public function ForwardData($JSONString)
    {
        $Data = json_decode($JSONString);
        $this->SendDebug("Forward", $Data->Buffer, 0);
        $DataNew['DataID'] = "{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}";
        $DataNew['Buffer'] = $Data->Buffer;
        $DataNew['ClientIP'] = $this->ReadPropertyString('ClientIP');
        $DataNew['ClientPort'] = (int) $this->LastPort;
        $JSONStringNew = json_encode($DataNew);
        $this->SendDataToParent($JSONStringNew);
    }

################## DATAPOINTS PARENT    

    /**
     * Empfängt Daten vom Parent.
     * 
     * @access public
     * @param string $JSONString Das empfangene JSON-kodierte Objekt vom Parent.

     */
    public function ReceiveData($JSONString)
    {
        $Data = json_decode($JSONString);
        $this->SendDebug("Receive", $Data->Buffer, 0);
        $this->LastPort = (int) $Data->ClientPort;
        $DataNew['DataID'] = "{018EF6B5-AB94-40C6-AA53-46943E824ACF}";
        $DataNew['Buffer'] = $Data->Buffer;
        $JSONStringNew = json_encode($DataNew);
        $this->SendDataToChildren($JSONStringNew);
    }

}

/** @} */