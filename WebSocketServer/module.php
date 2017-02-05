<?

require_once(__DIR__ . "/../WebsocketClass.php");  // diverse Klassen

use PTLS\TLSContext;
use PTLS\Exceptions\TLSAlertException;

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
 * WebsocketServer Klasse implementiert das Websocket-Protokoll für einen ServerSocket.
 * Erweitert IPSModule.
 * 
 * @package       Websocket
 * @author        Michael Tröger <micha@nall-chan.net>
 * @copyright     2017 Michael Tröger
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 * @version       0.2
 * @example <b>Ohne</b>
 * @property WebSocket_ClientList $Clients
 * @property string {$ClientIP}
 * @property TLS {"TLS_".$ClientIP} TLS-Object
 * @property array {"BuffersTLS_".$ClientIP}
 */
class WebsocketServer extends IPSModule
{

    use DebugHelper;

    /**
     * Wert einer Eigenschaft aus den InstanceBuffer lesen.
     * 
     * @access public
     * @param string $name Propertyname
     * @return mixed Value of Name
     */
    public function __get($name)
    {
        if (strpos($name, 'TLS') === 0)
        {
            $Lines = "";
            foreach ($this->{"Buffers" . $name} as $Buffer)
            {
                $Lines .= $this->{$name . 'Part' . $Buffer};
            }
            return unserialize($Lines);
        }
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
//        $this->SetBuffer($name, serialize($value));
        $Data = serialize($value);
        if (strpos($name, 'TLS') === 0)
        {
//            $this->SendDebug("TLS SIZE", strlen($Data), 0);
            $OldBuffers = $this->{"Buffers" . $name};
//            $this->SendDebug('TLSBuffers old', count($OldBuffers), 0);
            $Lines = str_split($Data, 8000);
            foreach ($Lines as $BufferIndex => $BufferLine)
            {
                $this->{$name . 'Part' . $BufferIndex} = $BufferLine;
            }
            $NewBuffers = array_keys($Lines);
            $this->{"Buffers" . $name} = $NewBuffers;
//            $this->SendDebug('TLSBuffers new', count($NewBuffers), 0);
            $DelBuffers = array_diff_key($OldBuffers, $NewBuffers);
//            $this->SendDebug('TLSBuffers del', count($DelBuffers), 0);
            foreach ($DelBuffers as $DelBuffer)
            {
                $this->{$name . 'Part' . $DelBuffer} = "";
//                $this->SendDebug('TLSBuffers' . $DelBuffer, 'DELETE', 0);
            }
            return;
        }
        $this->SetBuffer($name, $Data);
    }

    /**
     * Interne Funktion des SDK.
     *
     * @access public
     */
    public function Create()
    {
        parent::Create();
        $this->Clients = new WebSocket_ClientList();
        $this->RegisterPropertyInteger("Interval", 0);
        $this->RegisterPropertyString("URI", "/");
        $this->RegisterPropertyBoolean("BasisAuth", false);
        $this->RegisterPropertyString("Username", "");
        $this->RegisterPropertyString("Password", "");
        $this->RegisterPropertyBoolean("TLS", false);
        $this->RegisterTimer('KeepAlive', 0, 'WSS_KeepAlive($_IPS[\'TARGET\']);');
        // TLS Config
//TODO
    }

    /**
     * Interne Funktion des SDK.
     * 
     * @access public
     */
    public function ApplyChanges()
    {
        
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
        $Client = $this->Clients->GetByIP($Data->ClientIP);
        $this->SendDebug("Forward", utf8_decode($Data->Buffer), 0);
        $this->Send(utf8_decode($Data->Buffer), $this->{'OpCode' . $Client->ClientIP}, $Client);
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
        //$this->SendDebug("Receive", $JSONString, 0);        
        unset($Data->DataID);
        $NewData = utf8_decode($Data->Buffer);
        $Clients = $this->Clients;
        $Client = $Clients->GetByIP($Data->ClientIP);
        if (($Client === false) or ( preg_match("/^GET ?([^?#]*) HTTP\/1.1\r\n/", $NewData, $match)))
        { // neu oder neu verbunden!
            $this->SendDebug(($Client ? "RECONNECT" : "NEW") . ' CLIENT', $Data, 0);

            $Client = new Websocket_Client($Data->ClientIP, $Data->ClientPort);
            $Clients->Update($Client);
            $this->{'Buffer' . $Client->ClientIP} = "";
            if ($this->ReadPropertyBoolean('TLS'))
            {
                
            }
        }
        // jetzt bekannt :)
        if ($Client->State == WebSocketState::HandshakeReceived)
        {
            $NewData = $this->{'Buffer' . $Client->ClientIP} . $NewData;
            $CheckData = $this->ReceiveHandshake($NewData);
            if ($CheckData === false) // Daten komplett, aber defekt.
            {
                $Clients->Remove($Client->ClientIP);
                $this->Clients = $Clients;
                // irgendwas war falsch, mit Fehler antworten.
                $this->SendHandshake(403, $NewData, $Client); // Welchen Fehlercode ?
                $this->{'Buffer' . $Client->ClientIP} = "";
            }
            elseif ($CheckData === true) // Daten komplett und heil.
            {
                $Client->State = WebSocketState::Connected; // jetzt verbunden
                $this->Clients = $Clients;
                $this->SendHandshake(101, $NewData, $Client); //Handshake senden
                $this->SendDebug('SUCCESSFULLY CONNECT', $Client, 0);
            }
            else // Daten nicht komplett, buffern.
            {
                $this->Clients = $Clients;
                $this->{$Data->ClientIP} = $CheckData;
            }
        }
        elseif ($Client->State == WebSocketState::Connected)
        { // bekannt und verbunden
            // OpCode auswerten und Daten austauschen
            $Client->Timestamp = time();
            $this->Clients = $Clients;

            $this->SendDebug('ReceivePacket ' . $Client->ClientIP, $NewData, 1);
            $NewData = $this->{'Buffer' . $Client->ClientIP} . $NewData;
            $Frame = new WebSocketFrame($NewData);
            $this->{'Buffer' . $Client->ClientIP} = $Frame->Tail;
            $Frame->Tail = null;
            $this->DecodeFrame($Frame, $Client);
            return;
        }
        return;
    }

    private function ReceiveHandshake(string $Data)
    {
        $this->SendDebug('Receive Handshake', $Data, 0);
        if (preg_match("/^GET ?([^?#]*) HTTP\/1.1\r\n/", $Data, $match))
        {
            if (substr($Data, -4) != "\r\n\r\n")
            {
                $this->SendDebug('WAIT', $Data, 0);
                return $Data;
            }

            if (trim($match[1]) != trim($this->ReadPropertyString('URI')))
            {
                $this->SendDebug('Wrong URI requested', $Data, 0);
                return false;
            }

            if ($this->ReadPropertyBoolean("BasisAuth"))
            {
                $realm = base64_encode($this->ReadPropertyString("Username") . ':' . $this->ReadPropertyString("Password"));
                if (preg_match("/Authorization: Basic (.*)\r\n/", $Data, $match))
                {
                    if ($match[1] != $realm)
                    {
                        $this->SendDebug('Unauthorized Connection:', base64_decode($match[1]), 0);
                        return false;
                    }
                }
                else
                {
                    $this->SendDebug('Authorization missing', '', 0);
                    return false;
                }
//					header('WWW-Authenticate: Basic Realm="Geofency WebHook"');
//					header('HTTP/1.0 401 Unauthorized');
                //openssl
            }
            if (preg_match("/Connection: (.*)\r\n/", $Data, $match))
            {
                if (strtolower($match[1]) != 'upgrade')
                {
                    $this->SendDebug('WRONG Connection:', $match[1], 0);
                    return false;
                }
            }
            else
            {
                $this->SendDebug('MISSING', 'Connection: Upgrade', 0);
                return false;
            }

            if (preg_match("/Upgrade: (.*)\r\n/", $Data, $match))
            {
                if (strtolower($match[1]) != 'websocket')
                {
                    $this->SendDebug('WRONG Upgrade:', $match[1], 0);
                    return false;
                }
            }
            else
            {
                $this->SendDebug('MISSING', 'Upgrade: websocket', 0);
                return false;
            }


            if (preg_match("/Sec-WebSocket-Version: (.*)\r\n/", $Data, $match))
            {
                if (strpos($match[1], '13') === false)
                {
                    $this->SendDebug('WRONG Version:', $match[1], 0);
                    return false;
                }
            }
            else
            {
                $this->SendDebug('MISSING', 'Sec-WebSocket-Version', 0);
                return false;
            }

            if (!preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $Data, $match))
            {
                $this->SendDebug('MISSING', 'Sec-WebSocket-Key', 0);
                return false;
            }

            return true;
        }
        $this->SendDebug('Invalid HTTP-Request', $Data, 0);

        return false;
    }

    private function SendHandshake(int $Code, string $Data, Websocket_Client $Client)
    {
        preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $Data, $match);
        $SendKey = base64_encode(sha1($match[1] . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));

        $Header[] = 'HTTP/1.1 ' . $Code . ' Web Socket Protocol Handshake';
        $Header[] = 'Connection: Upgrade';
        $Header[] = 'Date: ';
        $Header[] = 'Sec-WebSocket-Accept: ' . $SendKey;
        $Header[] = 'Server: IP-Symcon Websocket Gateway';
        $Header[] = 'Upgrade: websocket';
        $Header[] = "\r\n";
        $SendHeader = implode("\r\n", $Header);
        $this->SendDebug("SendHandshake " . $Client->ClientIP, $SendHeader, 0);
        $SendData = $this->MakeFrame($Client, $SendHeader);
        $this->SendDataToParent($SendData);
    }

    private function MakeFrame(Websocket_Client $Client, $Data)
    {
        $SendData['DataID'] = "{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}";
        $SendData['Buffer'] = utf8_encode($Data);
        $SendData['ClientIP'] = $Client->ClientIP;
        $SendData['ClientPort'] = $Client->ClientPort;
        return json_encode($SendData);
    }

    /**
     * Dekodiert die empfangenen Daten und sendet sie an die Childs.
     * 
     * @access private
     * @param WebSocketFrame $Frame Ein Objekt welches einen kompletten Frame enthält.
     */
    private function DecodeFrame(WebSocketFrame $Frame, Websocket_Client $Client)
    {
        $this->SendDebug('DECODE', $Frame, ($Frame->OpCode == WebSocketOPCode::continuation) ? $this->PayloadTyp - 1 : $Frame->OpCode - 1);
        switch ($Frame->OpCode)
        {
            case WebSocketOPCode::ping:
                $this->SendPong($Client, $Frame->Payload);
                return;
            case WebSocketOPCode::close: // fertig
                $Clients = $this->Clients;
                $Clients->Remove($Client->ClientIP);
                $this->Clients = $Clients;
                $this->SendDisconnect($Client);
                return;
            case WebSocketOPCode::text:
            case WebSocketOPCode::binary:
                $this->{'OpCode' . $Client->ClientIP} = $Frame->OpCode;
                $Data = $Frame->Payload;
                break;
            case WebSocketOPCode::continuation:
                $Data = $this->{'Buffer' . $Client->ClientIP} . $Frame->Payload;
                break;
            case WebSocketOPCode::pong:
                $this->{'Pong' . $Client->ClientIP} = $Frame->Payload;
                $this->{'WaitForPong' . $Client->ClientIP} = true;
                return;
        }

        if ($Frame->Fin)
        {
            $this->SendDataToChilds($Data, $Client); // RAW Childs
        }
        else
        {
            $this->{'Buffer' . $Client->ClientIP} = $Data;
        }
    }

    /**
     * Versendet ein String
     * 
     * @access public
     * @param string $Text
     */
    public function SendPing(string $ClientIP, string $Text)
    {
        $Client = $this->Clients->GetByIP($ClientIP);
        if ($Client === false)
        {
            $this->SendDebug('Unknow client', $ClientIP, 0);
            trigger_error('Unknow client', E_USER_NOTICE);
            return false;
        }
        if ($Client->State != WebSocketState::Connected)
        {
            $this->SendDebug('Client not connected', $ClientIP, 0);
            trigger_error('Client not connected', E_USER_NOTICE);
            return false;
        }
        $this->SendDebug('Send Ping' . $Client->ClientIP, $Text, 0);
        $this->Send($Text, WebSocketOPCode::ping, $Client);
        $Result = $this->WaitForPong($Client->ClientIP);
        $this->{'Pong' . $Client->ClientIP} = "";
        if ($Result === false)
        {
            $this->SendDebug('Timeout ' . $Client->ClientIP, "", 0);
            trigger_error('Timeout', E_USER_NOTICE);
            $this->Clients->Remove($Client->ClientIP);
            return false;
        }

        if ($Result !== $Text)
        {
            $this->SendDebug('Error in Pong ' . $Client->ClientIP, $Result, 0);
            trigger_error('Wrong pong received', E_USER_NOTICE);
            $this->Clients->Remove($Client->ClientIP);
            return false;
        }
        return true;
    }

    private function SendPong(Websocket_Client $Client, string $Payload = null)
    {
        $this->Send($Payload, WebSocketOPCode::pong, $Client);
    }

    private function SendDisconnect(Websocket_Client $Client)
    {
        $this->Send("", WebSocketOPCode::close, $Client);
    }

    /**
     * Wartet auf eine Handshake-Antwort.
     * 
     * @access private
     */
    private function WaitForPong(string $ClientIP)
    {
        for ($i = 0; $i < 1000; $i++)
        {
            if ($this->{'WaitForPong' . $ClientIP} === true)
            {
                $Payload = $this->{'Pong' . $ClientIP};
                $this->{'Pong' . $ClientIP} = "";
                return $Payload;
            }
            IPS_Sleep(5);
        }
        return false;
    }

    /**
     * Versendet RawData mit OpCode an den IO.
     * 
     * @access protected
     * @param string $RawData 
     * @param WebSocketOPCode $OPCode
     */
    protected function Send(string $RawData, int $OPCode, Websocket_Client $Client, $Fin = true)
    {

        $WSFrame = new WebSocketFrame($OPCode, $RawData);
        $WSFrame->Fin = $Fin;
        $Frame = $WSFrame->ToFrame();
        $this->SendDebug('Send', $WSFrame, 0);
        $SendData = $this->MakeFrame($Client, $Frame);
        $this->SendDataToParent($SendData);
    }

    /**
     * Sendet die Rohdaten an die Childs.
     * 
     * @access private
     * @param string $RawData
     */
    private function SendDataToChilds(string $RawData, Websocket_Client $Client)
    {
        $JSON['DataID'] = '{7A1272A4-CBDB-46EF-BFC6-DCF4A53D2FC7}'; //ServerSocket Receive
        $JSON['Buffer'] = utf8_encode($RawData);
        $JSON['ClientIP'] = $Client->ClientIP;
        $JSON['ClientPort'] = $Client->ClientPort;
        $Data = json_encode($JSON);
        $this->SendDataToChildren($Data);

        /*  Funktioniert nicht :(
          $JSON['DataID'] = '{FD7FF32C-331E-4F6B-8BA8-F73982EF5AA7}';
          $JSON['Buffer'] = utf8_encode($RawData);
          $JSON['EventID'] = $this->PayloadTyp-1;
          $Data = json_encode($JSON);
          $this->SendDataToChildren($Data);
         */
    }

}

/**
 * Enthält die Daten eines Client.
 */
class Websocket_Client
{

    /**
     * IP-Adresse des Node.
     * @var string
     * @access public
     */
    public $ClientIP;

    /**
     * Port des Client
     * @var int
     * @access public
     */
    public $ClientPort;

    /**
     * Verbindungsstatus des Client
     * @var WebSocketState
     * @access public
     */
    public $State;

    /**
     * Letzer Zeitpunkt der Datenübertragung.
     * @var Timestamp
     * @access public
     */
    public $Timestamp;

    /**
     * Liefert die Daten welche behalten werden müssen.
     * @access public
     */
    public function __sleep()
    {
        return array('ClientPort', 'State', 'Timestamp');
    }

    public function __construct(string $ClientIP, int $ClientPort, $State = WebSocketState::HandshakeReceived)
    {
        $this->ClientIP = $ClientIP;
        $this->ClientPort = $ClientPort;
        $this->State = $State;
        $this->Timestamp = time();
    }

}

/**
 * WebSocket_ClientList ist eine Klasse welche ein Array von Websocket_Clients enthält.
 *
 */
class WebSocket_ClientList
{

    /**
     * Array mit allen Items.
     * @var array
     * @access public
     */
    public $Items = array();

    /**
     * Liefert die Daten welche behalten werden müssen.
     * @access public
     */
    public function __sleep()
    {
        return array('Items');
    }

    /**
     * Update für einen Eintrag in $Items.
     * @access public
     * @param TXB_Node $Node Das neue Objekt.
     */
    public function Update(Websocket_Client $Client)
    {
        $this->Items[$Client->ClientIP] = $Client;
    }

    /**
     * Löscht einen Eintrag aus $Items.
     * @access public
     * @param string $ClientIP Der Index des zu löschenden Items.
     */
    public function Remove(string $ClientIP)
    {
        if (isset($this->Items[$ClientIP]))
            unset($this->Items[$ClientIP]);
    }

    /**
     * Liefert einen bestimmten Eintrag aus den Items.
     * @access public
     * @param string $ClientIP
     * @return Websocket_Client
     */
    public function GetByIP(string $ClientIP)
    {
        if (!isset($this->Items[$ClientIP]))
            return false;
        $Client = $this->Items[$ClientIP];
        $Client->ClientIP = $ClientIP;
        return $Client;
    }

    /**
     * Liefert einen bestimmten Eintrag wo als nächstes das Timeout auftritt.
     * @access public
     * @param int $Offset
     * @return Websocket_Client
     */
    public function GetNextTimeout(int $Offset)
    {
        $Timestamp = time() + $Offset;
        foreach ($this->Items as $ClientIP => $Client)
        {
            if ($Client->Timestamp == 0)
                continue;
            if ($Client->Timestamp < $Timestamp)
            {
                $Timestamp = $Client->Timestamp;
                $FoundClient = $Client;
                $FoundClient->ClientIP = $ClientIP;
            }
        }
        return $FoundClient;
    }

}

/** @} */